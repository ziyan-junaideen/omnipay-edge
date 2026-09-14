<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * An Edge customer (`CoreHTTP.Views.Customers`).
 */
class CustomerResponse extends AbstractResponse
{
    public const TYPE = 'customers';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    /**
     * The customer id. Persist it: customers have no idempotency.
     */
    public function getCustomerReference(): ?string
    {
        return $this->getResourceId();
    }

    public function getName(): ?string
    {
        return $this->stringAttribute('name');
    }

    public function getEmail(): ?string
    {
        return $this->stringAttribute('email');
    }

    /**
     * Edge normalises a valid phone number to E.164.
     */
    public function getPhoneNumber(): ?string
    {
        return $this->stringAttribute('phone_number');
    }

    public function getDescription(): ?string
    {
        return $this->stringAttribute('description');
    }

    public function isBlocked(): bool
    {
        return $this->stringAttribute('blocked_at') !== null;
    }
}
