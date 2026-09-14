<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\CardMapper;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * POST /consumer_addresses, from the card's billing or shipping fields.
 *
 * The required fields and the country are checked before sending. Edge has no
 * idempotency for addresses, so persist the returned id and reuse it.
 */
class CreateAddressRequest extends AbstractRequest
{
    /**
     * `billing` (the default) or `shipping`: which of the card's addresses to send.
     */
    public function getAddressType(): string
    {
        return $this->getParameter('addressType') ?? CardMapper::BILLING;
    }

    public function setAddressType(?string $value): static
    {
        return $this->setParameter('addressType', $value);
    }

    public function getFieldForAttribute(string $attribute): string
    {
        if ($attribute === 'customer') {
            return 'customerReference';
        }

        $type = $this->getAddressType();

        if ($type !== CardMapper::BILLING && $type !== CardMapper::SHIPPING) {
            return $attribute;
        }

        return CardMapper::addressField($attribute, $type) ?? $attribute;
    }

    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidRequestException
     */
    protected function getRequestData(): array
    {
        $type = $this->getAddressType();

        if ($type !== CardMapper::BILLING && $type !== CardMapper::SHIPPING) {
            throw new InvalidFieldException('addressType', 'The addressType parameter must be billing or shipping.');
        }

        $card = $this->findCard();

        if ($card === null) {
            throw new InvalidFieldException('card', 'The card parameter is required');
        }

        $attributes = CardMapper::addressAttributes($card, $type);

        // Never send `"data": null` for a relationship: Edge answers it with a 500.
        $customer = trim((string) $this->getCustomerReference());
        $relationships = $customer === '' ? [] : ['customer' => [CustomerResponse::TYPE, $customer]];

        return $this->resourceDocument(AddressResponse::TYPE, $attributes, $relationships);
    }

    public function send(): AddressResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendData($data): AddressResponse
    {
        return $this->response = new AddressResponse($this, $this->sendPost('consumer_addresses', $data));
    }
}
