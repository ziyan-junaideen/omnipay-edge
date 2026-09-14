<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\DemandMismatchException;
use Omnipay\Edge\PaymentState;

/**
 * Confirms a payment demand once the browser reports `payment_method_verified`:
 * `PATCH /payment_demands/{transactionReference}/confirm`.
 *
 * Safe to call twice and safe when a response is lost. The demand is read first and
 * only confirmed when it matches the expected payment, has a verified card and is in
 * a state Edge can confirm. An unclear answer to the confirm is resolved by reading
 * the demand again, and the confirm is repeated at most once, only when the demand
 * provably hasn't changed.
 *
 * A successful confirm leaves the demand `pending`, which is not paid: Edge has not
 * sent it to the card network yet, and a decline arrives later as `failed`.
 *
 * A `failed` demand is retried on the same id once the shopper verifies a card again.
 * The declined card stays `confirmed` and Edge doesn't say which card an attempt used,
 * so a retry is only sent when `previousCardReference` names the card of the last
 * attempt and the demand now has another one. A reload or a late duplicate submit
 * never authorises a declined card again.
 */
class CompletePurchaseRequest extends AbstractRequest
{
    /**
     * States Edge accepts a confirm from: an unconfirmed intent, or a failed demand
     * being retried (`Core.Transactions.Payment.is_confirmable_intent/1` and
     * `is_confirmable_demand/1`).
     */
    private const CONFIRMABLE_STATES = [PaymentState::INCOMPLETE, PaymentState::READY, PaymentState::FAILED];

    /**
     * States that show a confirm has already landed.
     */
    private const CONFIRMED_STATES = [PaymentState::PENDING, PaymentState::PROCESSING, PaymentState::SUCCEEDED];

    /**
     * States a confirm can answer with. Anything else in a 2xx is treated as unclear.
     */
    private const CONFIRM_RESPONSE_STATES = [
        PaymentState::PENDING,
        PaymentState::PROCESSING,
        PaymentState::SUCCEEDED,
        PaymentState::FAILED,
    ];

    private int $confirmAttempts = 0;

    private ?string $attemptedCardReference = null;

    /**
     * The card the last confirm for this demand was sent for, as returned by
     * CompletePurchaseResponse::getAttemptedCardReference(). Required to retry a
     * `failed` demand: the retry is only sent when the demand's card differs from it.
     */
    public function getPreviousCardReference(): ?string
    {
        return $this->getParameter('previousCardReference');
    }

    public function setPreviousCardReference(?string $value): static
    {
        return $this->setParameter('previousCardReference', $value);
    }

    /**
     * The confirm document. Edge requires `attributes` to be a JSON object and the id
     * to match the URL.
     *
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidRequestException
     */
    protected function getRequestData(): array
    {
        $id = $this->requireString('transactionReference');
        $this->requireString('idempotencyKey');
        $this->validate('amount', 'currency');

        return $this->resourceDocument(AbstractPaymentDemandResponse::TYPE, [], [], $id);
    }

    /**
     * @throws DemandMismatchException when the demand doesn't match the amount, currency
     *                                 or idempotency key; nothing is confirmed
     */
    public function send(): CompletePurchaseResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array{data: array{id: string}} $data
     *
     * @throws DemandMismatchException when the demand doesn't match the amount, currency
     *                                 or idempotency key; nothing is confirmed
     */
    public function sendData($data): CompletePurchaseResponse
    {
        $this->confirmAttempts = 0;
        $this->attemptedCardReference = null;
        $id = $data['data']['id'];

        $demand = $this->readDemand($id);

        if ($demand->getPaymentState() === null) {
            return $this->respond($demand, CompletePurchaseResponse::OUTCOME_NOT_READ);
        }

        $this->assertMatches($demand);

        $state = $demand->getProcessorState();

        if (in_array($state, self::CONFIRMABLE_STATES, true)) {
            return $this->isPaymentMethodVerified($demand) && $this->isRetryAllowed($demand)
                ? $this->confirm($id, $data, $demand, true)
                : $this->respond($demand, CompletePurchaseResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED);
        }

        // A confirm already landed (a duplicate submit), or the demand has settled.
        $paymentState = PaymentState::fromProcessorState($state);

        if ($paymentState->getKind() === PaymentState::KIND_DEMAND) {
            return $this->respond($demand, CompletePurchaseResponse::OUTCOME_DEMAND);
        }

        // `confirmed` and `canceled` intents, and states this version doesn't know.
        return $this->respond($demand, CompletePurchaseResponse::OUTCOME_NOT_CONFIRMABLE);
    }

    /**
     * @param array{data: array{id: string}} $document
     */
    private function confirm(
        string $id,
        array $document,
        FetchTransactionResponse $before,
        bool $mayRetry
    ): CompletePurchaseResponse {
        $this->confirmAttempts++;
        $this->attemptedCardReference = $before->getCardReference();

        $confirmed = new FetchTransactionResponse(
            $this,
            $this->sendPatch(self::path(AbstractPaymentDemandResponse::TYPE, $id, 'confirm'), $document)
        );

        if (!$this->isUnclear($confirmed, $id)) {
            return $this->respond(
                $confirmed,
                $confirmed->getPaymentState() === null
                    ? CompletePurchaseResponse::OUTCOME_REJECTED
                    : CompletePurchaseResponse::OUTCOME_DEMAND
            );
        }

        // The confirm may or may not have landed. Read the demand to find out.
        $after = $this->readDemand($id);

        if ($after->getPaymentState() === null) {
            // Not the confirm's answer: a malformed 2xx may describe another demand.
            return $this->respond($after, CompletePurchaseResponse::OUTCOME_UNRESOLVED);
        }

        $state = $after->getProcessorState();

        if (in_array($state, self::CONFIRMED_STATES, true)) {
            return $this->respond($after, CompletePurchaseResponse::OUTCOME_DEMAND);
        }

        // An unchanged demand still has the card this call just confirmed, so a repeat
        // is the same attempt. A sandbox retry can go pending, processing and failed within seconds, so the
        // state alone doesn't prove nothing happened. An unchanged updated_at does.
        $updatedAt = $after->getUpdatedAt();
        $unchanged = $state === $before->getProcessorState()
            && $updatedAt !== null
            && $updatedAt === $before->getUpdatedAt();

        if ($unchanged && $mayRetry && in_array($state, self::CONFIRMABLE_STATES, true)) {
            return $this->isPaymentMethodVerified($after)
                ? $this->confirm($id, $document, $after, false)
                : $this->respond($after, CompletePurchaseResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED);
        }

        if (!$unchanged && $state === PaymentState::FAILED) {
            // The confirm landed and the card network declined it.
            return $this->respond($after, CompletePurchaseResponse::OUTCOME_DEMAND);
        }

        return $this->respond($after, CompletePurchaseResponse::OUTCOME_UNRESOLVED);
    }

    /**
     * Whether the answer to a confirm leaves its outcome unknown: no response, a 405
     * (the demand was no longer confirmable, perhaps because another confirm landed),
     * a 5xx, or a 2xx that isn't this demand in a state a confirm leads to.
     */
    private function isUnclear(FetchTransactionResponse $response, string $id): bool
    {
        $status = $response->getHttpStatus();

        if ($status === null || $status === 405 || $status >= 500) {
            return true;
        }

        if ($status < 200 || $status >= 300) {
            return false;
        }

        $resourceId = $response->getResourceId();

        return $resourceId === null
            || strcasecmp($resourceId, $id) !== 0
            || !in_array($response->getProcessorState(), self::CONFIRM_RESPONSE_STATES, true);
    }

    private function readDemand(string $id): FetchTransactionResponse
    {
        return new FetchTransactionResponse(
            $this,
            $this->sendGet(self::path(AbstractPaymentDemandResponse::TYPE, $id), ['include' => 'payment_method'])
        );
    }

    /**
     * @throws DemandMismatchException
     */
    private function assertMatches(FetchTransactionResponse $demand): void
    {
        $mismatches = [];
        $details = [];

        $amount = (int) $this->getAmountInteger();

        if ($demand->getAmountCents() !== $amount) {
            $mismatches[] = 'amount_cents';
            $details['amount_cents'] = sprintf(
                'expected %d, Edge has %s',
                $amount,
                self::describe($demand->getAmountCents())
            );
        }

        $currency = (string) $this->getCurrency();

        if ($demand->getCurrency() !== $currency) {
            $mismatches[] = 'amount_currency';
            $details['amount_currency'] = sprintf(
                'expected %s, Edge has %s',
                $currency,
                self::describe($demand->getCurrency())
            );
        }

        // Neither key goes into the message.
        if (!hash_equals($this->requireString('idempotencyKey'), (string) $demand->getIdempotencyKey())) {
            $mismatches[] = 'idempotency_key';
        }

        if ($mismatches !== []) {
            throw new DemandMismatchException($demand, $mismatches, $details);
        }
    }

    /**
     * The included payment method has been verified in the payment form. Edge doesn't
     * check this on confirm, and a demand without one fails validation for its missing
     * 3DS fields.
     */
    private function isPaymentMethodVerified(FetchTransactionResponse $demand): bool
    {
        $attributes = $demand->getPaymentMethod()['attributes'] ?? null;

        return is_array($attributes) && ($attributes['external_state'] ?? null) === 'confirmed';
    }

    /**
     * A `failed` demand may only be confirmed again with a card other than the one
     * the last attempt used. Re-verifying in the payment form always creates a new
     * payment method, so a real retry has a new id.
     */
    private function isRetryAllowed(FetchTransactionResponse $demand): bool
    {
        if ($demand->getProcessorState() !== PaymentState::FAILED) {
            return true;
        }

        $previous = trim((string) $this->getPreviousCardReference());
        $current = $demand->getCardReference();

        return $previous !== '' && $current !== null && strcasecmp($current, $previous) !== 0;
    }

    private function respond(FetchTransactionResponse $response, string $outcome): CompletePurchaseResponse
    {
        return $this->response = new CompletePurchaseResponse(
            $this,
            $response->getHttpResult(),
            $outcome,
            $this->confirmAttempts,
            $this->attemptedCardReference
        );
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'nothing';
    }
}
