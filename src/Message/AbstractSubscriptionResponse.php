<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * A payment subscription (`CoreHTTP.Views.PaymentSubscriptions`).
 *
 * Edge returns unconfirmed subscription intents (`Core.Transactions.PaymentIntent`)
 * and subscriptions through the same view, with the same id. The view has no
 * `processor_state`, and an intent's virtual `status` is always `pending`, so the
 * kind comes from fields only a subscription has: `next_billing_at` is required on a
 * subscription and never set on an intent, and `last_processed_at` is only rendered
 * for a subscription (see getKind()).
 *
 * A confirmed subscription is `pending` until its first charge succeeds, then `active`.
 * Pausing and cancelling happen only in Edge's dashboard.
 */
abstract class AbstractSubscriptionResponse extends AbstractResponse
{
    public const TYPE = 'payment_subscriptions';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    /** Spelled with a double L, unlike a payment intent's `canceled`. */
    public const STATUS_CANCELLED = 'cancelled';

    /** An unconfirmed subscription intent: the payment form can collect a card for it. */
    public const KIND_INTENT = 'intent';

    /** A confirmed subscription. */
    public const KIND_SUBSCRIPTION = 'subscription';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    /**
     * An active subscription: its first charge succeeded. A failed renewal leaves it
     * active, so this says nothing about the latest charge.
     */
    public function isSuccessful(): bool
    {
        return $this->getKind() === self::KIND_SUBSCRIPTION && $this->getStatus() === self::STATUS_ACTIVE;
    }

    /**
     * A confirmed subscription whose first charge hasn't succeeded yet. An intent is
     * not pending: nothing has been confirmed.
     */
    public function isPending(): bool
    {
        return $this->getKind() === self::KIND_SUBSCRIPTION && $this->getStatus() === self::STATUS_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->getKind() === self::KIND_SUBSCRIPTION && $this->getStatus() === self::STATUS_CANCELLED;
    }

    public function isPaused(): bool
    {
        return $this->getKind() === self::KIND_SUBSCRIPTION && $this->getStatus() === self::STATUS_PAUSED;
    }

    /**
     * KIND_INTENT or KIND_SUBSCRIPTION, or null when Edge returned neither
     * recognisably (or no subscription at all).
     *
     * `CoreHTTP.Views.PaymentSubscriptions` renders whichever struct it is given and
     * leaves out the fields a struct doesn't have. A subscription always has a
     * `next_billing_at` (`PaymentSubscription.create_from_intent_changeset/3` requires
     * it) and a `last_processed_at` key; an intent's `next_billing_at` is a virtual
     * field that is never set, and it has no `last_processed_at` at all.
     */
    public function getKind(): ?string
    {
        $attributes = $this->getResource()['attributes'] ?? null;

        if (!is_array($attributes) || !array_key_exists('next_billing_at', $attributes)) {
            return null;
        }

        $nextBillingAt = $attributes['next_billing_at'];
        $hasLastProcessedAt = array_key_exists('last_processed_at', $attributes);

        if (is_string($nextBillingAt) && $nextBillingAt !== '' && $hasLastProcessedAt) {
            return self::KIND_SUBSCRIPTION;
        }

        if ($nextBillingAt === null && !$hasLastProcessedAt && $this->getStatus() === self::STATUS_PENDING) {
            return self::KIND_INTENT;
        }

        return null;
    }

    public function isIntent(): bool
    {
        return $this->getKind() === self::KIND_INTENT;
    }

    public function isSubscription(): bool
    {
        return $this->getKind() === self::KIND_SUBSCRIPTION;
    }

    /**
     * `pending`, `active`, `paused` or `cancelled`. Always `pending` for an intent.
     */
    public function getStatus(): ?string
    {
        return $this->stringAttribute('status');
    }

    /**
     * The subscription id. It stays the same through confirm.
     */
    public function getSubscriptionReference(): ?string
    {
        return $this->getResourceId();
    }

    /**
     * The subscription id, as for getSubscriptionReference().
     */
    public function getTransactionReference(): ?string
    {
        return $this->getSubscriptionReference();
    }

    /**
     * The `purchase_reference`, sent from `transactionId`. Every charge copies it.
     */
    public function getTransactionId(): ?string
    {
        return $this->stringAttribute('purchase_reference');
    }

    /**
     * The amount of each full billing period.
     */
    public function getAmountCents(): ?int
    {
        $amount = $this->getAttribute('amount_cents');

        return is_int($amount) ? $amount : null;
    }

    public function getCurrency(): ?string
    {
        return $this->stringAttribute('amount_currency');
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->stringAttribute('idempotency_key');
    }

    public function getSlug(): ?string
    {
        return $this->stringAttribute('slug');
    }

    /**
     * One of AbstractSubscriptionRequest::BILLING_PERIODS.
     */
    public function getBillingPeriod(): ?string
    {
        return $this->stringAttribute('billing_period');
    }

    /**
     * `none` or `create_prorations`.
     */
    public function getProrationBehavior(): ?string
    {
        return $this->stringAttribute('proration_behavior');
    }

    /**
     * Timestamps are Edge's ISO 8601 UTC strings with microseconds.
     */
    public function getBillingCycleAnchorAt(): ?string
    {
        return $this->stringAttribute('billing_cycle_anchor_at');
    }

    /**
     * When the next charge is due. Null for an intent.
     */
    public function getNextBillingAt(): ?string
    {
        return $this->stringAttribute('next_billing_at');
    }

    public function getLastProcessedAt(): ?string
    {
        return $this->stringAttribute('last_processed_at');
    }

    public function getCanceledAt(): ?string
    {
        return $this->stringAttribute('canceled_at');
    }

    public function getCreatedAt(): ?string
    {
        return $this->stringAttribute('created_at');
    }

    public function getUpdatedAt(): ?string
    {
        return $this->stringAttribute('updated_at');
    }

    public function getCustomerReference(): ?string
    {
        return $this->getRelationshipId('payer');
    }

    public function getBillingAddressReference(): ?string
    {
        return $this->getRelationshipId('billing_address');
    }

    public function getShippingAddressReference(): ?string
    {
        return $this->getRelationshipId('shipping_address');
    }

    /**
     * The payment method id, once the hosted payment form has collected a card.
     * Renewals charge this card.
     */
    public function getCardReference(): ?string
    {
        return $this->getRelationshipId('payment_method');
    }

    /**
     * The included `payment_methods` resource, when the request asked for it and a card
     * has been collected.
     *
     * @return array<string, mixed>|null
     */
    public function getPaymentMethod(): ?array
    {
        $id = $this->getCardReference();

        return $id === null ? null : $this->getIncluded(CardResponse::TYPE, $id);
    }
}
