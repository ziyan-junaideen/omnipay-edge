<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\AbstractResponse as OmnipayAbstractResponse;
use Omnipay\Common\Message\NotificationInterface;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\PaymentState;

/**
 * A verified Edge webhook event (v2 and v3 payloads).
 *
 * The event names a resource and carries a small snapshot of it, taken when the event
 * was recorded. Deliveries can arrive late, twice or out of order, so re-read the
 * resource from the API before acting, and deduplicate on getEventId().
 *
 * getTransactionReference() is the resource id, never the event id. The status and
 * isSuccessful() describe the snapshot only.
 */
class Notification extends OmnipayAbstractResponse implements NotificationInterface
{
    public const RESOURCE_PAYMENT_DEMANDS = 'transaction.payment_demands';

    public const RESOURCE_REFUND_DEMANDS = 'transaction.refund_demands';

    public const RESOURCE_PAYMENT_SUBSCRIPTIONS = 'transaction.payment_subscriptions';

    /**
     * @param array<string, mixed> $data the verified event document
     */
    public function __construct(RequestInterface $request, array $data)
    {
        parent::__construct($request, $data);
    }

    /**
     * The event id (`data.id`). Deduplicate on it: Edge sends no event-id header.
     */
    public function getEventId(): string
    {
        return (string) ($this->event()['id'] ?? '');
    }

    /**
     * Such as `transaction.payment_demands`. See the RESOURCE_ constants.
     */
    public function getResourceType(): string
    {
        return (string) ($this->attributes()['resource_type'] ?? '');
    }

    public function getResourceId(): string
    {
        return (string) ($this->attributes()['resource_id'] ?? '');
    }

    /**
     * Such as `created`, `updated`, `succeeded` or `failed`.
     */
    public function getSlug(): string
    {
        return (string) ($this->attributes()['slug'] ?? '');
    }

    /**
     * `<resource_type>.<slug>`, the name webhook subscriptions list, such as
     * `transaction.payment_demands.failed`.
     */
    public function getEventCode(): string
    {
        return $this->getResourceType() . '.' . $this->getSlug();
    }

    /**
     * `live` or `sandbox`, as the payload claims. Don't rely on it: the mode is the one
     * whose webhook secret verified the signature.
     */
    public function getMode(): ?string
    {
        $mode = $this->attributes()['mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    /**
     * The snapshot's attributes, such as `processor_state` and `amount_cents` for a
     * payment demand, or an empty array when the event has none.
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        $snapshot = $this->attributes()['data'] ?? null;
        $attributes = is_array($snapshot) ? ($snapshot['attributes'] ?? null) : null;

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * The resource id: a payment demand, refund demand or subscription id. Never the
     * event id.
     */
    public function getTransactionReference(): string
    {
        return $this->getResourceId();
    }

    /**
     * The snapshot's status, as one of NotificationInterface's `completed`, `pending` or
     * `failed`:
     *
     * - payment demands: `processor_state` through PaymentState;
     * - refund demands: `state`, `succeeded` completed, `failed` failed, otherwise pending;
     * - subscriptions: `status`, `active` completed, `cancelled` failed, otherwise pending;
     * - any other resource: pending.
     */
    public function getTransactionStatus(): string
    {
        switch ($this->getResourceType()) {
            case self::RESOURCE_PAYMENT_DEMANDS:
                return PaymentState::fromProcessorState($this->snapshotString('processor_state'))
                    ->getNotificationStatus();
            case self::RESOURCE_REFUND_DEMANDS:
                return self::status($this->snapshotString('state'), 'succeeded', 'failed');
            case self::RESOURCE_PAYMENT_SUBSCRIPTIONS:
                return self::status($this->snapshotString('status'), 'active', 'cancelled');
            default:
                return NotificationInterface::STATUS_PENDING;
        }
    }

    /**
     * The payment demand's state from the snapshot, or null for another resource.
     */
    public function getPaymentState(): ?PaymentState
    {
        if ($this->getResourceType() !== self::RESOURCE_PAYMENT_DEMANDS) {
            return null;
        }

        return PaymentState::fromProcessorState($this->snapshotString('processor_state'));
    }

    /**
     * The snapshot shows a paid payment demand, a succeeded refund or an active
     * subscription. A `disputed` or `reversed` demand is not successful.
     */
    public function isSuccessful(): bool
    {
        $paymentState = $this->getPaymentState();

        if ($paymentState !== null) {
            return $paymentState->isSuccessful();
        }

        $settles = [self::RESOURCE_REFUND_DEMANDS, self::RESOURCE_PAYMENT_SUBSCRIPTIONS];

        return in_array($this->getResourceType(), $settles, true)
            && $this->getTransactionStatus() === NotificationInterface::STATUS_COMPLETED;
    }

    /**
     * The snapshot shows a `cancelled` subscription, or a `canceled` payment intent.
     */
    public function isCancelled(): bool
    {
        $paymentState = $this->getPaymentState();

        if ($paymentState !== null) {
            return $paymentState->isCancelled();
        }

        return $this->getResourceType() === self::RESOURCE_PAYMENT_SUBSCRIPTIONS
            && $this->snapshotString('status') === 'cancelled';
    }

    public function isPending(): bool
    {
        return $this->getTransactionStatus() === NotificationInterface::STATUS_PENDING;
    }

    /**
     * The event code, such as `transaction.payment_demands.failed`. Edge sends no
     * decline reason: re-read the demand for one.
     */
    public function getMessage(): string
    {
        return $this->getEventCode();
    }

    private static function status(?string $value, string $completed, string $failed): string
    {
        return match ($value) {
            $completed => NotificationInterface::STATUS_COMPLETED,
            $failed => NotificationInterface::STATUS_FAILED,
            default => NotificationInterface::STATUS_PENDING,
        };
    }

    private function snapshotString(string $field): ?string
    {
        $value = $this->getSnapshot()[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(): array
    {
        $event = is_array($this->data) ? ($this->data['data'] ?? null) : null;

        return is_array($event) ? $event : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        $attributes = $this->event()['attributes'] ?? null;

        return is_array($attributes) ? $attributes : [];
    }
}
