<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /payment_demands?filter[payment_subscription]={subscriptionReference}: the
 * charges of one subscription, including a prorated first charge. Edge has no
 * pagination, so every charge comes back at once.
 */
class ListSubscriptionChargesRequest extends AbstractSubscriptionRequest
{
    protected function getRequestData(): ?array
    {
        $this->requireString('subscriptionReference');

        return null;
    }

    public function send(): ListSubscriptionChargesResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): ListSubscriptionChargesResponse
    {
        return $this->response = $this->listCharges($this->requireString('subscriptionReference'));
    }
}
