<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * PATCH /webhook_subscriptions/{webhookSubscriptionReference} with `status: archived`.
 *
 * Edge has no DELETE: archiving stops deliveries and sets `archived_at`, and the API
 * can't undo it. Only an `active` or `paused` subscription can be archived; archiving
 * one that already is answers 422 on `/data/attributes/status`, so fetch it to check.
 */
class ArchiveWebhookSubscriptionRequest extends AbstractWebhookSubscriptionRequest
{
    /**
     * @return array{data: array<string, mixed>}
     */
    protected function getRequestData(): array
    {
        $id = $this->requireString('webhookSubscriptionReference');

        // Edge ignores every other attribute sent with this status.
        return $this->resourceDocument(
            WebhookSubscriptionResponse::TYPE,
            ['status' => WebhookSubscriptionResponse::STATUS_ARCHIVED],
            [],
            $id
        );
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
