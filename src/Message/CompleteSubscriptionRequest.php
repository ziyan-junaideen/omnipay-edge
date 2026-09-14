<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\SubscriptionMismatchException;

/**
 * Confirms a subscription intent once the browser reports `payment_method_verified`:
 * `PATCH /payment_subscriptions/{subscriptionReference}/confirm`.
 *
 * On an active subscription the same endpoint retries its last failed charge instead,
 * so a confirm is only ever sent to a resource just read as an intent. The intent is
 * read first and only confirmed when it matches the expected subscription and has a
 * verified card. A subscription already confirmed is reported without a confirm.
 *
 * An unclear answer is resolved by reading the resource again. The confirm is repeated
 * at most once, and only while the resource still reads as an intent: Edge creates the
 * subscription with the intent's id, so a confirm that did land can't create a second
 * one, and a read never shows an intent once the subscription exists.
 *
 * A confirmed subscription is `pending` until its first charge succeeds. The first
 * charge is created at confirm (a prorated charge) or by a background job, so the
 * subscription's charges are listed once it exists (getCharges()).
 */
class CompleteSubscriptionRequest extends AbstractSubscriptionRequest
{
    private int $confirmAttempts = 0;

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
        $id = $this->requireString('subscriptionReference');
        $this->requireString('idempotencyKey');
        $this->validate('amount', 'currency');

        return $this->resourceDocument(AbstractSubscriptionResponse::TYPE, [], [], $id);
    }

    /**
     * @throws SubscriptionMismatchException when the subscription doesn't match the
     *                                       amount, currency or idempotency key; nothing
     *                                       is confirmed
     */
    public function send(): CompleteSubscriptionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array{data: array{id: string}} $data
     *
     * @throws SubscriptionMismatchException when the subscription doesn't match the
     *                                       amount, currency or idempotency key; nothing
     *                                       is confirmed
     */
    public function sendData($data): CompleteSubscriptionResponse
    {
        $this->confirmAttempts = 0;
        $id = $data['data']['id'];

        $read = $this->readSubscription($id, true);

        if ($read->getResource() === null) {
            return $this->respond($read, CompleteSubscriptionResponse::OUTCOME_NOT_READ);
        }

        $this->assertMatches($read);

        if ($read->isSubscription()) {
            // A confirm already landed. Never confirm again: it would retry a charge.
            return $this->respondWithCharges($read, $id);
        }

        if (!$read->isIntent()) {
            return $this->respond($read, CompleteSubscriptionResponse::OUTCOME_NOT_CONFIRMABLE);
        }

        return self::isPaymentMethodVerified($read)
            ? $this->confirm($id, $data, true)
            : $this->respond($read, CompleteSubscriptionResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED);
    }

    /**
     * @param array{data: array{id: string}} $document
     */
    private function confirm(string $id, array $document, bool $mayRepeat): CompleteSubscriptionResponse
    {
        $this->confirmAttempts++;

        $confirmed = new FetchSubscriptionResponse(
            $this,
            $this->sendPatch(self::path(AbstractSubscriptionResponse::TYPE, $id, 'confirm'), $document)
        );

        if (!$this->isUnclear($confirmed, $id)) {
            return $confirmed->isSubscription()
                ? $this->respondWithCharges($confirmed, $id)
                : $this->respond($confirmed, CompleteSubscriptionResponse::OUTCOME_REJECTED);
        }

        // The confirm may or may not have landed. Read the resource to find out.
        $after = $this->readSubscription($id, true);

        if ($after->isSubscription()) {
            return $this->respondWithCharges($after, $id);
        }

        if ($after->isIntent() && $mayRepeat) {
            return self::isPaymentMethodVerified($after)
                ? $this->confirm($id, $document, false)
                : $this->respond($after, CompleteSubscriptionResponse::OUTCOME_PAYMENT_METHOD_UNVERIFIED);
        }

        // Unreadable, or still an intent after the one repeat. The confirm's own answer
        // may describe another resource, so it is never the one reported.
        return $this->respond($after, CompleteSubscriptionResponse::OUTCOME_UNRESOLVED);
    }

    /**
     * Whether the answer to a confirm leaves its outcome unknown: no response, a 405
     * (no longer an intent, perhaps because another confirm landed), a 5xx, or a 2xx
     * that isn't this subscription.
     */
    private function isUnclear(FetchSubscriptionResponse $response, string $id): bool
    {
        $status = $response->getHttpStatus();

        if ($status === null || $status === 405 || $status >= 500) {
            return true;
        }

        if ($status < 200 || $status >= 300) {
            return false;
        }

        $resourceId = $response->getResourceId();

        return $resourceId === null || strcasecmp($resourceId, $id) !== 0 || !$response->isSubscription();
    }

    /**
     * @throws SubscriptionMismatchException
     */
    private function assertMatches(FetchSubscriptionResponse $subscription): void
    {
        $mismatches = [];
        $details = [];

        $amount = (int) $this->getAmountInteger();

        if ($subscription->getAmountCents() !== $amount) {
            $mismatches[] = 'amount_cents';
            $details['amount_cents'] = sprintf(
                'expected %d, Edge has %s',
                $amount,
                self::describe($subscription->getAmountCents())
            );
        }

        $currency = (string) $this->getCurrency();

        if ($subscription->getCurrency() !== $currency) {
            $mismatches[] = 'amount_currency';
            $details['amount_currency'] = sprintf(
                'expected %s, Edge has %s',
                $currency,
                self::describe($subscription->getCurrency())
            );
        }

        // Neither key goes into the message.
        if (!hash_equals($this->requireString('idempotencyKey'), (string) $subscription->getIdempotencyKey())) {
            $mismatches[] = 'idempotency_key';
        }

        if ($mismatches !== []) {
            throw new SubscriptionMismatchException($subscription, $mismatches, $details);
        }
    }

    /**
     * The included payment method has been verified in the payment form. Edge doesn't
     * check this on confirm.
     */
    private static function isPaymentMethodVerified(FetchSubscriptionResponse $subscription): bool
    {
        $attributes = $subscription->getPaymentMethod()['attributes'] ?? null;

        return is_array($attributes) && ($attributes['external_state'] ?? null) === 'confirmed';
    }

    private function respondWithCharges(
        FetchSubscriptionResponse $subscription,
        string $id
    ): CompleteSubscriptionResponse {
        return $this->respond(
            $subscription,
            CompleteSubscriptionResponse::OUTCOME_SUBSCRIPTION,
            $this->listCharges($id)
        );
    }

    private function respond(
        FetchSubscriptionResponse $response,
        string $outcome,
        ?ListSubscriptionChargesResponse $charges = null
    ): CompleteSubscriptionResponse {
        return $this->response = new CompleteSubscriptionResponse(
            $this,
            $response->getHttpResult(),
            $outcome,
            $this->confirmAttempts,
            $charges
        );
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'nothing';
    }
}
