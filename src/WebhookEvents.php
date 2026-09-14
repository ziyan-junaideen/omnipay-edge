<?php

declare(strict_types=1);

namespace Omnipay\Edge;

/**
 * Webhook event codes, `<resource_type>.<slug>`, for a webhook subscription's `events`.
 *
 * Edge stores whatever codes a subscription lists without checking them, and delivers an
 * event only to subscriptions whose list contains its exact code, so a misspelt code
 * silently receives nothing.
 */
final class WebhookEvents
{
    public const PAYMENT_DEMAND_CREATED = 'transaction.payment_demands.created';

    public const PAYMENT_DEMAND_UPDATED = 'transaction.payment_demands.updated';

    public const PAYMENT_DEMAND_SUCCEEDED = 'transaction.payment_demands.succeeded';

    public const PAYMENT_DEMAND_FAILED = 'transaction.payment_demands.failed';

    /**
     * Left out of RECOMMENDED: it can arrive before the refund request's response does.
     */
    public const REFUND_DEMAND_CREATED = 'transaction.refund_demands.created';

    /**
     * Also sent when a refund succeeds: Edge emits no `succeeded` refund event.
     */
    public const REFUND_DEMAND_UPDATED = 'transaction.refund_demands.updated';

    public const REFUND_DEMAND_FAILED = 'transaction.refund_demands.failed';

    public const PAYMENT_SUBSCRIPTION_CREATED = 'transaction.payment_subscriptions.created';

    public const PAYMENT_SUBSCRIPTION_UPDATED = 'transaction.payment_subscriptions.updated';

    /**
     * The events the gateway's flows rely on, and the only codes createWebhookSubscription()
     * and updateWebhookSubscription() accept.
     */
    public const RECOMMENDED = [
        self::PAYMENT_DEMAND_CREATED,
        self::PAYMENT_DEMAND_UPDATED,
        self::PAYMENT_DEMAND_SUCCEEDED,
        self::PAYMENT_DEMAND_FAILED,
        self::REFUND_DEMAND_UPDATED,
        self::REFUND_DEMAND_FAILED,
        self::PAYMENT_SUBSCRIPTION_CREATED,
        self::PAYMENT_SUBSCRIPTION_UPDATED,
    ];
}
