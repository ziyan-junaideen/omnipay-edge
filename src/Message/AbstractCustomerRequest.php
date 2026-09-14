<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\CardMapper;

/**
 * Builds customer attributes from explicit parameters, falling back to the card's
 * billing name, email and billing phone. Empty values are left out.
 */
abstract class AbstractCustomerRequest extends AbstractRequest
{
    /**
     * Customer attribute => explicit request parameter.
     */
    private const PARAMETERS = [
        'name' => 'name',
        'email' => 'email',
        'phone_number' => 'phoneNumber',
        'description' => 'description',
    ];

    public function getName(): ?string
    {
        return $this->getParameter('name');
    }

    public function setName(?string $value): static
    {
        return $this->setParameter('name', $value);
    }

    public function getEmail(): ?string
    {
        return $this->getParameter('email');
    }

    public function setEmail(?string $value): static
    {
        return $this->setParameter('email', $value);
    }

    public function getPhoneNumber(): ?string
    {
        return $this->getParameter('phoneNumber');
    }

    public function setPhoneNumber(?string $value): static
    {
        return $this->setParameter('phoneNumber', $value);
    }

    public function getFieldForAttribute(string $attribute): string
    {
        $parameter = self::PARAMETERS[$attribute] ?? null;

        if ($parameter === null) {
            return $attribute;
        }

        if ($this->explicitValue($parameter) !== null || $this->findCard() === null) {
            return $parameter;
        }

        return CardMapper::customerField($attribute) ?? $parameter;
    }

    /**
     * @return array<string, string>
     */
    protected function customerAttributes(): array
    {
        $card = $this->findCard();
        $fromCard = $card === null ? [] : CardMapper::customerAttributes($card);
        $attributes = [];

        foreach (self::PARAMETERS as $attribute => $parameter) {
            $value = $this->explicitValue($parameter) ?? $fromCard[$attribute] ?? null;

            if ($value !== null) {
                $attributes[$attribute] = $value;
            }
        }

        return $attributes;
    }

    private function explicitValue(string $parameter): ?string
    {
        $value = $this->getParameter($parameter);
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
