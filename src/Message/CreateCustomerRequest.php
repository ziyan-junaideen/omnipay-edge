<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * POST /customers.
 *
 * Edge has no idempotency for customers, so persist the returned id and reuse it:
 * retrying after a lost response creates a duplicate.
 */
class CreateCustomerRequest extends AbstractCustomerRequest
{
    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidFieldException when there is no email
     */
    protected function getRequestData(): array
    {
        $attributes = $this->customerAttributes();

        // Required on create (`Core.Consumer.Customer.changeset/2`).
        if (!isset($attributes['email'])) {
            throw new InvalidFieldException($this->getFieldForAttribute('email'), 'The email is required.');
        }

        return $this->resourceDocument(CustomerResponse::TYPE, $attributes);
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
        return $this->response = new CustomerResponse($this, $this->sendPost('customers', $data));
    }
}
