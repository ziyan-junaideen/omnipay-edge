<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\PaymentState;

/**
 * Retries the failed latest charge of an active subscription:
 * `PATCH /payment_subscriptions/{subscriptionReference}/confirm`.
 *
 * Edge retries the same payment demand, with the card on file, when the subscription
 * is `active` and its last charge `failed` (`PaymentSubscriptionsController.confirm/2`).
 * The subscription and its charges are read first, and nothing is sent unless the
 * subscription is active, its latest charge failed and no charge is still in progress.
 *
 * The confirm is sent at most once. Edge doesn't lock the demand while it retries, so
 * an unclear answer is resolved by listing the charges again rather than by repeating
 * it: a changed charge means the retry landed, and an unchanged one leaves the outcome
 * unresolved.
 *
 * A subscription whose first charge failed is still `pending`, and Edge can't retry it.
 */
class RetrySubscriptionChargeRequest extends AbstractSubscriptionRequest
{
    private int $attempts = 0;

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

        return $this->resourceDocument(AbstractSubscriptionResponse::TYPE, [], [], $id);
    }

    public function send(): RetrySubscriptionChargeResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array{data: array{id: string}} $data
     */
    public function sendData($data): RetrySubscriptionChargeResponse
    {
        $this->attempts = 0;
        $id = $data['data']['id'];

        $subscription = $this->readSubscription($id, false);

        if ($subscription->getResource() === null) {
            return $this->respond($subscription, RetrySubscriptionChargeResponse::OUTCOME_NOT_READ);
        }

        $reason = self::subscriptionRefusal($subscription);

        if ($reason !== null) {
            return $this->respond(
                $subscription,
                RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE,
                null,
                null,
                $reason
            );
        }

        $charges = $this->listCharges($id);

        if (!$charges->isSuccessful()) {
            return $this->respond($subscription, RetrySubscriptionChargeResponse::OUTCOME_NOT_READ, $charges);
        }

        $latest = $charges->getLatestCharge();

        if ($charges->hasChargeInProgress() || $latest === null || self::state($latest) !== PaymentState::FAILED) {
            $reason = $charges->hasChargeInProgress()
                ? RetrySubscriptionChargeResponse::MESSAGE_CHARGE_IN_PROGRESS
                : RetrySubscriptionChargeResponse::MESSAGE_NOTHING_TO_RETRY;

            return $this->respond(
                $subscription,
                RetrySubscriptionChargeResponse::OUTCOME_NOT_RETRYABLE,
                $charges,
                $latest,
                $reason
            );
        }

        $this->attempts++;

        $confirmed = new FetchSubscriptionResponse(
            $this,
            $this->sendPatch(self::path(AbstractSubscriptionResponse::TYPE, $id, 'confirm'), $data)
        );
        $status = $confirmed->getHttpStatus();

        // Edge's own refusal, including a 405 when it doesn't see a failed last charge.
        if ($status !== null && $status >= 300 && $status < 500) {
            return $this->respond($confirmed, RetrySubscriptionChargeResponse::OUTCOME_REJECTED, $charges, $latest);
        }

        return $this->readBack($id, $subscription, $confirmed, $latest);
    }

    /**
     * Lists the charges again after a 2xx, a 5xx or no response. Edge puts a retried
     * charge back to `pending` before it answers, so a charge that is no longer the
     * failed one read before shows the retry landed.
     *
     * @param array<string, mixed> $before the failed charge the retry was sent for
     */
    private function readBack(
        string $id,
        FetchSubscriptionResponse $subscription,
        FetchSubscriptionResponse $confirmed,
        array $before
    ): RetrySubscriptionChargeResponse {
        $status = $confirmed->getHttpStatus();
        $accepted = $status !== null && $status < 300 && strcasecmp((string) $confirmed->getResourceId(), $id) === 0;
        $base = $accepted ? $confirmed : $subscription;

        $after = $this->listCharges($id);
        $charge = $after->getCharge((string) $before['id']);

        if ($charge === null) {
            // The listing can't be read: Edge's own acceptance still counts.
            return $accepted
                ? $this->respond($base, RetrySubscriptionChargeResponse::OUTCOME_RETRIED, $after)
                : $this->respond($base, RetrySubscriptionChargeResponse::OUTCOME_UNRESOLVED, $after, $before);
        }

        $changed = self::state($charge) !== PaymentState::FAILED
            || ListSubscriptionChargesResponse::attributeOf($charge, 'updated_at')
                !== ListSubscriptionChargesResponse::attributeOf($before, 'updated_at');

        // Unchanged after a 2xx contradicts Edge's answer, so that stays unresolved too.
        $outcome = $changed
            ? RetrySubscriptionChargeResponse::OUTCOME_RETRIED
            : RetrySubscriptionChargeResponse::OUTCOME_UNRESOLVED;

        return $this->respond($base, $outcome, $after, $charge);
    }

    /**
     * Why the subscription itself can't have a charge retried, or null when it can.
     */
    private static function subscriptionRefusal(FetchSubscriptionResponse $subscription): ?string
    {
        if ($subscription->isSuccessful()) {
            return null;
        }

        if ($subscription->isPending()) {
            return RetrySubscriptionChargeResponse::MESSAGE_FIRST_CHARGE;
        }

        return sprintf(
            RetrySubscriptionChargeResponse::MESSAGE_NOT_ACTIVE,
            $subscription->isSubscription() ? (string) $subscription->getStatus() : 'unconfirmed'
        );
    }

    /**
     * @param array<string, mixed> $charge
     */
    private static function state(array $charge): ?string
    {
        $state = ListSubscriptionChargesResponse::attributeOf($charge, 'processor_state');

        return is_string($state) ? $state : null;
    }

    /**
     * @param array<string, mixed>|null $charge the charge the outcome is about
     */
    private function respond(
        FetchSubscriptionResponse $response,
        string $outcome,
        ?ListSubscriptionChargesResponse $charges = null,
        ?array $charge = null,
        ?string $reason = null
    ): RetrySubscriptionChargeResponse {
        return $this->response = new RetrySubscriptionChargeResponse(
            $this,
            $response->getHttpResult(),
            $outcome,
            $this->attempts,
            $charges,
            $charge,
            $reason
        );
    }
}
