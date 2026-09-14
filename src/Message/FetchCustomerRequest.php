<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /customers/{customerReference}.
 */
class FetchCustomerRequest extends AbstractRequest
{
    protected function getRequestData(): ?array
    {
        $this->requireString('customerReference');

        return null;
    }

    public function send(): CustomerResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): CustomerResponse
    {
        $id = trim((string) $this->getCustomerReference());

        return $this->response = new CustomerResponse($this, $this->sendGet(self::path('customers', $id)));
    }
}
