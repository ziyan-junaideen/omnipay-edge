<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\CreditCard;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * Maps an Omnipay CreditCard to Edge customer and consumer address attributes.
 *
 * Only the holder's details are read. Card numbers never reach the server: Edge
 * collects them in its hosted payment form.
 */
final class CardMapper
{
    public const BILLING = 'billing';

    public const SHIPPING = 'shipping';

    /**
     * Edge address attribute => CreditCard field suffix, in the order Edge lists them.
     */
    private const ADDRESS_FIELDS = [
        'line_1' => 'Address1',
        'line_2' => 'Address2',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'Postcode',
        'country' => 'Country',
    ];

    /**
     * Edge validates these (`Core.Consumer.Address.changeset/2`).
     */
    private const REQUIRED_ADDRESS_ATTRIBUTES = ['line_1', 'city', 'state', 'zip', 'country'];

    private const ADDRESS_LABELS = [
        'line_1' => 'address line 1',
        'line_2' => 'address line 2',
        'city' => 'city',
        'state' => 'state',
        'zip' => 'postcode',
        'country' => 'country',
    ];

    /**
     * Byte-safe for UTF-8: no `u` flag (which fails on invalid input) and no `\s` or
     * `\v`, which match 0x85, the second byte of letters such as Å and х.
     */
    private const ASCII_WHITESPACE = '/[ \t\n\r\f\x0B]+/';

    /**
     * Customer attribute => CreditCard field.
     */
    private const CUSTOMER_FIELDS = [
        'name' => 'billingName',
        'email' => 'email',
        'phone_number' => 'billingPhone',
    ];

    /**
     * The billing name, email and phone, leaving out empty values.
     *
     * @return array<string, string>
     */
    public static function customerAttributes(CreditCard $card): array
    {
        return self::withoutBlanks([
            'name' => $card->getBillingName(),
            'email' => $card->getEmail(),
            'phone_number' => $card->getBillingPhone(),
        ]);
    }

    /**
     * The CreditCard field a customer attribute is read from, such as `billingPhone`
     * for `phone_number`.
     */
    public static function customerField(string $attribute): ?string
    {
        return self::CUSTOMER_FIELDS[$attribute] ?? null;
    }

    /**
     * The billing or shipping address as Edge attributes: required fields checked,
     * country converted to alpha-3, and an empty `line_2` left out.
     *
     * @param self::BILLING|self::SHIPPING $type
     *
     * @return array<string, string>
     *
     * @throws InvalidFieldException naming the CreditCard field, such as `billingState`
     */
    public static function addressAttributes(CreditCard $card, string $type): array
    {
        $attributes = self::rawAddress($card, $type);

        foreach (self::REQUIRED_ADDRESS_ATTRIBUTES as $attribute) {
            if (!isset($attributes[$attribute])) {
                throw new InvalidFieldException(
                    self::addressField($attribute, $type),
                    sprintf('The %s %s is required.', $type, self::ADDRESS_LABELS[$attribute])
                );
            }
        }

        try {
            $attributes['country'] = Countries::toAlpha3($attributes['country']);
        } catch (InvalidRequestException $exception) {
            throw new InvalidFieldException(self::addressField('country', $type), $exception->getMessage());
        }

        return $attributes;
    }

    /**
     * The CreditCard field an address attribute is read from, such as `billingPostcode`
     * for `zip`.
     *
     * @param self::BILLING|self::SHIPPING $type
     */
    public static function addressField(string $attribute, string $type): ?string
    {
        $suffix = self::ADDRESS_FIELDS[$attribute] ?? null;

        return $suffix === null ? null : $type . $suffix;
    }

    /**
     * Whether the card holds a shipping address that is worth its own consumer
     * address. False when the shipping fields are blank or match billing, because
     * Edge uses the billing address when a demand has no shipping address.
     */
    public static function hasDistinctShippingAddress(CreditCard $card): bool
    {
        $shipping = self::rawAddress($card, self::SHIPPING);

        if ($shipping === []) {
            return false;
        }

        return self::comparable(self::rawAddress($card, self::BILLING)) !== self::comparable($shipping);
    }

    /**
     * @param self::BILLING|self::SHIPPING $type
     *
     * @return array<string, string>
     */
    private static function rawAddress(CreditCard $card, string $type): array
    {
        $values = [];

        foreach (self::ADDRESS_FIELDS as $attribute => $suffix) {
            $values[$attribute] = $card->{'get' . ucfirst($type) . $suffix}();
        }

        return self::withoutBlanks($values);
    }

    /**
     * @param array<string, string> $address
     *
     * @return array<string, string>
     */
    private static function comparable(array $address): array
    {
        // mbstring arrives with omnipay/common (through symfony/polyfill-mbstring), but
        // isn't a declared dependency of this package.
        $normalised = array_map(
            static fn (string $value): string => function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value),
            $address
        );

        if (isset($normalised['country'])) {
            try {
                $normalised['country'] = Countries::toAlpha3($normalised['country']);
            } catch (InvalidRequestException) {
                // Left as typed; creating the address reports it.
            }
        }

        return $normalised;
    }

    /**
     * Trims scalar values, collapses runs of whitespace (a card's billing name joins
     * first and last name with a space, even when one already ends in one) and drops
     * the empty ones.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private static function withoutBlanks(array $values): array
    {
        $kept = [];

        foreach ($values as $name => $value) {
            $value = is_scalar($value) ? trim((string) preg_replace(self::ASCII_WHITESPACE, ' ', (string) $value)) : '';

            if ($value !== '') {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }
}
