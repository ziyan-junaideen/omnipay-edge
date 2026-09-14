<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /refund_demands?filter[payment_demand]={transactionReference}: the refunds of
 * one payment demand. Edge has no pagination, so every refund comes back at once.
 */
class ListRefundsRequest extends AbstractRequest
{
    protected function getRequestData(): ?array
    {
        $this->requireString('transactionReference');

        return null;
    }

    public function send(): ListRefundsResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): ListRefundsResponse
    {
        $paymentDemand = $this->requireString('transactionReference');

        return $this->response = new ListRefundsResponse(
            $this,
            $this->sendGet(AbstractRefundResponse::TYPE, ['filter' => ['payment_demand' => $paymentDemand]]),
            $paymentDemand
        );
    }
}
