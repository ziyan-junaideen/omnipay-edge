<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * An Edge consumer address (`CoreHTTP.Views.ConsumerAddresses`).
 *
 * Edge stores the country as alpha-3 and the state as its code, whichever form was
 * sent.
 */
class AddressResponse extends AbstractResponse
{
    public const TYPE = 'consumer_addresses';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    /**
     * The address id. Persist it: addresses have no idempotency.
     */
    public function getAddressReference(): ?string
    {
        return $this->getResourceId();
    }

    public function getCustomerReference(): ?string
    {
        return $this->getRelationshipId('customer');
    }

    public function getLine1(): ?string
    {
        return $this->stringAttribute('line_1');
    }

    public function getLine2(): ?string
    {
        return $this->stringAttribute('line_2');
    }

    public function getCity(): ?string
    {
        return $this->stringAttribute('city');
    }

    public function getState(): ?string
    {
        return $this->stringAttribute('state');
    }

    public function getZip(): ?string
    {
        return $this->stringAttribute('zip');
    }

    /**
     * ISO 3166-1 alpha-3.
     */
    public function getCountry(): ?string
    {
        return $this->stringAttribute('country');
    }

    public function isDiscarded(): bool
    {
        return $this->stringAttribute('discarded_at') !== null;
    }
}
