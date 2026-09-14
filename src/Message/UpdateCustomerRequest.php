<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * PATCH /customers/{customerReference}.
 *
 * Only the attributes given are sent; the rest keep their stored values. Edge
 * checks the stored name and email too (`Core.Consumer.Customer.update_changeset/2`),
 * so updating a customer that was created without a name fails with a 422 on `name`
 * unless one is sent.
 */
class UpdateCustomerRequest extends AbstractCustomerRequest
{
    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidFieldException when the customer reference is missing
     */
    protected function getRequestData(): array
    {
        $id = $this->requireString('customerReference');

        return $this->resourceDocument(CustomerResponse::TYPE, $this->customerAttributes(), [], $id);
    }

    public function send(): CustomerResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendData($data): CustomerResponse
    {
        $id = (string) $this->getCustomerReference();

        return $this->response = new CustomerResponse(
            $this,
            $this->sendPatch(self::path('customers', trim($id)), $data)
        );
    }
}
