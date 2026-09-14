<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * POST /webhook_subscriptions.
 *
 * Edge sets the subscription `active` and generates its secret key. There is no
 * idempotency, so persist the returned id and secret key and look the subscription up by
 * that id: retrying a create after a lost response registers a second subscription,
 * and every event is then delivered twice.
 */
class CreateWebhookSubscriptionRequest extends AbstractWebhookSubscriptionRequest
{
    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidRequestException
     */
    protected function getRequestData(): array
    {
        $attributes = [
            'url' => $this->resolveUrl(),
            'mode' => $this->resolveMode(),
            'description' => $this->resolveDescription(),
            'events' => $this->resolveEvents(),
        ];

        $concurrencyLimit = $this->resolveConcurrencyLimit();

        if ($concurrencyLimit !== null) {
            $attributes['concurrency_limit'] = $concurrencyLimit;
        }

        return $this->resourceDocument(WebhookSubscriptionResponse::TYPE, $attributes);
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
        return $this->response = new WebhookSubscriptionResponse(
            $this,
            $this->sendPost(WebhookSubscriptionResponse::TYPE, $data)
        );
    }
}
