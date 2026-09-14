<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * A webhook subscription (`CoreHTTP.Views.WebhookSubscriptions`).
 *
 * A new subscription is `active`. Archiving sets `archived` and `archived_at`; Edge
 * delivers events only to `active` subscriptions (`Core.Developers`), and the API can't
 * make an archived one active again. `paused` is set from the dashboard.
 */
class WebhookSubscriptionResponse extends AbstractResponse
{
    public const TYPE = 'webhook_subscriptions';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ARCHIVED = 'archived';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    /**
     * Edge has no such subscription for this merchant: a missing or another merchant's
     * id. The only error that means "create a new one"; any other failure, such as a 401,
     * 403 or 5xx, says nothing about whether the subscription exists.
     */
    public function isNotFound(): bool
    {
        return $this->getHttpStatus() === 404;
    }

    /**
     * The webhook subscription id. Persist it, per mode, with the secret key.
     */
    public function getWebhookSubscriptionReference(): ?string
    {
        return $this->getResourceId();
    }

    /**
     * The signing secret for this subscription's deliveries: the gateway's
     * `webhookSecret`. Edge returns it on every read and can't rotate it through the API.
     */
    public function getSecretKey(): ?string
    {
        return $this->stringAttribute('secret_key');
    }

    /**
     * `active`, `paused` or `archived`.
     */
    public function getStatus(): ?string
    {
        return $this->stringAttribute('status');
    }

    /**
     * Edge delivers events to this subscription.
     */
    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->getStatus() === self::STATUS_ARCHIVED;
    }

    public function getArchivedAt(): ?string
    {
        return $this->stringAttribute('archived_at');
    }

    /**
     * `live` or `sandbox`: the mode whose events the subscription receives.
     */
    public function getMode(): ?string
    {
        return $this->stringAttribute('mode');
    }

    public function getUrl(): ?string
    {
        return $this->stringAttribute('url');
    }

    public function getDescription(): ?string
    {
        return $this->stringAttribute('description');
    }

    /**
     * The subscribed event codes.
     *
     * @return list<string>
     */
    public function getEvents(): array
    {
        $events = $this->getAttribute('events');

        return is_array($events) ? array_values(array_filter($events, 'is_string')) : [];
    }

    public function getConcurrencyLimit(): ?int
    {
        $limit = $this->getAttribute('concurrency_limit');

        return is_int($limit) ? $limit : null;
    }
}
