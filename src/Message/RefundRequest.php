<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * POST /refund_demands: refunds all or part of a `succeeded` payment demand.
 *
 * `amount_cents` is always sent, so a partial refund never becomes a full one, and
 * `amount_currency` never is: Edge copies the payment's currency. The caller supplies
 * and stores the idempotency key. Edge replays the refund a key made when the payment,
 * amount, reason and note match, and answers 422 when they don't
 * (`Core.Transactions.create_and_enqueue_refund_demand/2`).
 *
 * An unclear answer (no response, a 5xx, or a 2xx that isn't this refund) is sent
 * again once with the same key. If that is unclear too, or a 4xx that may only mean
 * the first request was still in flight, the payment's refunds are listed and searched
 * for the key. The key's absence only proves nothing was created when both requests
 * got an answer from Edge itself. After no response or a 502, 503 or 504, a request
 * may still be running with its refund uncommitted, so the outcome stays unresolved,
 * as it does when the listing can't be read.
 */
class RefundRequest extends AbstractRequest
{
    /**
     * `Core.Transactions.RefundDemand.reasons/0`. `openapi.json` leaves out `custom`.
     */
    public const REASONS = [
        'service_not_delivered',
        'duplicate_charge',
        'unauthorized_transaction',
        'technical_issue',
        'customer_canceled',
        'dissatisfied_experience',
        'compliance_issue',
        'custom',
    ];

    public const DEFAULT_REASON = 'custom';

    /**
     * Edge attribute or relationship => request parameter.
     */
    private const FIELDS = [
        'amount_cents' => 'amount',
        'amount_currency' => 'currency',
        'idempotency_key' => 'idempotencyKey',
        'reason' => 'reason',
        'reason_note' => 'reasonNote',
        'payment_demand' => 'transactionReference',
    ];

    /**
     * Edge only requires a refund to be positive.
     */
    protected int $minimumAmountCents = 1;

    private int $attempts = 0;

    /**
     * One of REASONS. Defaults to `custom`.
     */
    public function getReason(): ?string
    {
        return $this->getParameter('reason');
    }

    public function setReason(?string $value): static
    {
        return $this->setParameter('reason', $value);
    }

    /**
     * An optional note, up to 500 characters. Left out when empty.
     */
    public function getReasonNote(): ?string
    {
        return $this->getParameter('reasonNote');
    }

    public function setReasonNote(?string $value): static
    {
        return $this->setParameter('reasonNote', $value);
    }

    public function getFieldForAttribute(string $attribute): string
    {
        return self::FIELDS[$attribute] ?? $attribute;
    }

    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidRequestException
     */
    protected function getRequestData(): array
    {
        $paymentDemand = $this->requireString('transactionReference');
        $idempotencyKey = $this->requireString('idempotencyKey');

        $this->validate('amount', 'currency');

        $attributes = [
            'reason' => $this->resolveReason(),
            'amount_cents' => (int) $this->getAmountInteger(),
            'idempotency_key' => $idempotencyKey,
        ];

        $note = trim((string) $this->getReasonNote());

        if ($note !== '') {
            $attributes['reason_note'] = $note;
        }

        return $this->resourceDocument(
            AbstractRefundResponse::TYPE,
            $attributes,
            ['payment_demand' => [AbstractPaymentDemandResponse::TYPE, $paymentDemand]]
        );
    }

    /**
     * @throws IdempotencyConflictException when Edge holds a refund for this key with
     *                                      another amount, reason or note
     */
    public function send(): RefundResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array{data: array{
     *     attributes: array<string, mixed>,
     *     relationships: array{payment_demand: array{data: array{id: string}}}
     * }} $data
     *
     * @throws IdempotencyConflictException when Edge holds a refund for this key with
     *                                      another amount, reason or note
     * @throws InvalidRequestException when the document has no key or payment demand
     */
    public function sendData($data): RefundResponse
    {
        $this->attempts = 0;
        $sent = $data['data']['attributes'];
        $paymentDemand = $data['data']['relationships']['payment_demand']['data']['id'];
        $idempotencyKey = $sent['idempotency_key'] ?? null;

        // A refund without a key isn't idempotent, so resending or listing can't be safe.
        if (!is_string($idempotencyKey) || $idempotencyKey === '' || $paymentDemand === '') {
            throw new InvalidRequestException(
                'A refund document needs an idempotency_key and a payment_demand. Build it with getData().'
            );
        }

        $first = $this->post($data);

        if (!$this->isUnclear($first, $paymentDemand, $idempotencyKey)) {
            return $this->isRejection($first)
                ? $this->respond($first->getHttpResult(), RefundResponse::OUTCOME_REJECTED)
                : $this->acceptRefund($first->getHttpResult(), $sent);
        }

        $second = $this->post($data);

        if ($this->isOwnRefund($second, $paymentDemand, $idempotencyKey)) {
            return $this->acceptRefund($second->getHttpResult(), $sent);
        }

        // Still unclear, or a 4xx that can't be trusted after an unclear first attempt.
        $listing = new ListRefundsResponse(
            $this,
            $this->sendGet(AbstractRefundResponse::TYPE, ['filter' => ['payment_demand' => $paymentDemand]]),
            $paymentDemand
        );

        if (!$listing->isSuccessful()) {
            return $this->respond($second->getHttpResult(), RefundResponse::OUTCOME_UNRESOLVED, $listing);
        }

        $found = $listing->findByIdempotencyKey($idempotencyKey);

        if ($found !== null) {
            return $this->acceptRefund($listing->getHttpResult(), $sent, $listing, $found);
        }

        // A request Edge may still be running holds the payment demand locked with its
        // refund uncommitted, and the listing can't see it yet.
        if ($this->mayStillBeRunning($first) || $this->mayStillBeRunning($second)) {
            return $this->respond($second->getHttpResult(), RefundResponse::OUTCOME_UNRESOLVED, $listing);
        }

        // Both requests finished on Edge and the key isn't there, so neither created a refund.
        return $this->respond(
            $second->getHttpResult(),
            $this->isRejection($second) ? RefundResponse::OUTCOME_REJECTED : RefundResponse::OUTCOME_NOT_CREATED,
            $listing
        );
    }

    /**
     * @throws InvalidFieldException
     */
    private function resolveReason(): string
    {
        $reason = trim((string) $this->getReason());

        if ($reason === '') {
            return self::DEFAULT_REASON;
        }

        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidFieldException(
                'reason',
                sprintf('The reason parameter must be one of: %s.', implode(', ', self::REASONS))
            );
        }

        return $reason;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function post(array $document): FetchRefundResponse
    {
        $this->attempts++;

        return new FetchRefundResponse($this, $this->sendPost(AbstractRefundResponse::TYPE, $document));
    }

    /**
     * Whether the answer to a create leaves its outcome unknown: no response, a 5xx, or
     * a 2xx that isn't a refund for this payment and key.
     */
    private function isUnclear(FetchRefundResponse $response, string $paymentDemand, string $idempotencyKey): bool
    {
        $status = $response->getHttpStatus();

        if ($status === null || $status >= 500) {
            return true;
        }

        if ($status >= 200 && $status < 300) {
            return !$this->isOwnRefund($response, $paymentDemand, $idempotencyKey);
        }

        return false;
    }

    /**
     * A 2xx refund demand made with this key, for this payment.
     */
    private function isOwnRefund(FetchRefundResponse $response, string $paymentDemand, string $idempotencyKey): bool
    {
        $status = (int) $response->getHttpStatus();
        $key = $response->getIdempotencyKey();
        $related = $response->getPaymentDemandReference();

        return $status >= 200 && $status < 300
            && $response->getResourceId() !== null
            && $key !== null && $idempotencyKey !== '' && hash_equals($key, $idempotencyKey)
            && $related !== null && strcasecmp($related, $paymentDemand) === 0;
    }

    /**
     * Whether Edge may still be processing the request: no response arrived, or a
     * gateway in front of Edge gave up waiting. Edge's own answers, including a 500,
     * come after its transaction has committed or rolled back.
     */
    private function mayStillBeRunning(FetchRefundResponse $response): bool
    {
        return in_array($response->getHttpStatus(), [null, 502, 503, 504], true);
    }

    /**
     * A definite answer that nothing was created: any status other than a 2xx or 5xx.
     */
    private function isRejection(FetchRefundResponse $response): bool
    {
        $status = $response->getHttpStatus();

        return $status !== null && ($status < 200 || ($status >= 300 && $status < 500));
    }

    /**
     * A refund Edge returned for this key, checked against what was sent.
     *
     * @param array<string, mixed> $sent
     * @param array<string, mixed>|null $listed the listing entry that is the refund
     *
     * @throws IdempotencyConflictException
     */
    private function acceptRefund(
        HttpResult $result,
        array $sent,
        ?ListRefundsResponse $listing = null,
        ?array $listed = null
    ): RefundResponse {
        $response = $this->respond($result, RefundResponse::OUTCOME_REFUND, $listing, $listed);
        $this->assertSameFacts($response, $sent);

        return $response;
    }

    /**
     * Edge compares the payment, amount, reason and note before replaying a key, so a
     * refund that differs was made for another request.
     *
     * @param array<string, mixed> $sent
     *
     * @throws IdempotencyConflictException
     */
    private function assertSameFacts(RefundResponse $response, array $sent): void
    {
        $mismatches = [];

        foreach (['amount_cents', 'reason', 'reason_note'] as $name) {
            $expected = $sent[$name] ?? null;
            $actual = $response->getAttribute($name);

            if ($expected !== $actual) {
                $mismatches[$name] = ['sent' => $expected, 'edge' => $actual];
            }
        }

        if ($mismatches !== []) {
            throw new IdempotencyConflictException($response, $mismatches);
        }
    }

    /**
     * @param array<string, mixed>|null $listed the listing entry that is the refund
     */
    private function respond(
        HttpResult $result,
        string $outcome,
        ?ListRefundsResponse $listing = null,
        ?array $listed = null
    ): RefundResponse {
        return $this->response = new RefundResponse($this, $result, $outcome, $this->attempts, $listing, $listed);
    }
}
