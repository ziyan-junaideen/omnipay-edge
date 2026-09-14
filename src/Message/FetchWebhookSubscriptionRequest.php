<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /webhook_subscriptions/{webhookSubscriptionReference}.
 *
 * Returns the subscription in any status, archived included, with its secret key. A
 * missing id, or another merchant's, is a plain-text 404
 * (WebhookSubscriptionResponse::isNotFound()).
 */
class FetchWebhookSubscriptionRequest extends AbstractWebhookSubscriptionRequest
{
    protected function getRequestData(): ?array
    {
        $this->requireString('webhookSubscriptionReference');

        return null;
    }

    public function send(): WebhookSubscriptionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): WebhookSubscriptionResponse
    {
        $id = $this->requireString('webhookSubscriptionReference');

        return $this->response = new WebhookSubscriptionResponse(
            $this,
            $this->sendGet(self::path(WebhookSubscriptionResponse::TYPE, $id))
        );
    }
}
