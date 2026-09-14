<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * PATCH /webhook_subscriptions/{webhookSubscriptionReference}: changes the url, events,
 * description or concurrency limit.
 *
 * Only the parameters given are sent. Each is checked as on create, so `events` replaces
 * the whole list and can't be emptied. Edge updates an archived subscription too but
 * leaves it archived; use archiveWebhookSubscription() to archive.
 */
class UpdateWebhookSubscriptionRequest extends AbstractWebhookSubscriptionRequest
{
    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidRequestException
     */
    protected function getRequestData(): array
    {
        $id = $this->requireString('webhookSubscriptionReference');

        // Edge would move the subscription to the other mode's events, and a secret key
        // only ever creates resources in its own mode.
        if ($this->hasParameter('mode')) {
            throw new InvalidFieldException(
                'mode',
                'A webhook subscription\'s mode is not changed here. Create a subscription with a key of that mode.'
            );
        }

        $attributes = [];

        if ($this->hasParameter('url')) {
            $attributes['url'] = $this->resolveUrl();
        }

        if ($this->getParameter('events') !== null) {
            $attributes['events'] = $this->resolveEvents();
        }

        if ($this->hasParameter('description')) {
            $attributes['description'] = $this->resolveDescription();
        }

        $concurrencyLimit = $this->resolveConcurrencyLimit();

        if ($concurrencyLimit !== null) {
            $attributes['concurrency_limit'] = $concurrencyLimit;
        }

        if ($attributes === []) {
            throw new InvalidRequestException('Nothing to update: pass url, events, description or concurrencyLimit.');
        }

        return $this->resourceDocument(WebhookSubscriptionResponse::TYPE, $attributes, [], $id);
    }

    public function send(): WebhookSubscriptionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendData($data): WebhookSubscriptionResponse
    {
        $id = $this->requireString('webhookSubscriptionReference');

        return $this->response = new WebhookSubscriptionResponse(
            $this,
            $this->sendPatch(self::path(WebhookSubscriptionResponse::TYPE, $id), $data)
        );
    }
}
