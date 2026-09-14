<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /refund_demands/{refundReference}, for polling a refund. Webhooks are the source
 * of truth.
 */
class FetchRefundRequest extends AbstractRequest
{
    /**
     * The refund demand id, as returned by RefundResponse::getTransactionReference().
     */
    public function getRefundReference(): ?string
    {
        return $this->getParameter('refundReference');
    }

    public function setRefundReference(?string $value): static
    {
        return $this->setParameter('refundReference', $value);
    }

    protected function getRequestData(): ?array
    {
        $this->requireString('refundReference');

        return null;
    }

    public function send(): FetchRefundResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): FetchRefundResponse
    {
        $id = $this->requireString('refundReference');

        return $this->response = new FetchRefundResponse(
            $this,
            $this->sendGet(self::path(AbstractRefundResponse::TYPE, $id))
        );
    }
}
