<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Common\CreditCard;
use Omnipay\Edge\CardMapper;
use Omnipay\Edge\Exception\InvalidFieldException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CardMapperTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     */
    private function card(array $overrides = []): CreditCard
    {
        return new CreditCard($overrides + [
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => ' ada@example.com ',
            'billingPhone' => '800-305-7664',
            'billingAddress1' => '12  åNALYTICAL Way',
            'billingCity' => 'Springfield',
            'billingState' => 'IL',
            'billingPostcode' => '62701',
            'billingCountry' => 'US',
        ]);
    }

    public function testCustomerAttributesComeFromTheBillingHolder(): void
    {
        $this->assertSame(
            ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'phone_number' => '800-305-7664'],
            CardMapper::customerAttributes($this->card())
        );
    }

    public function testCustomerAttributesLeaveOutBlanks(): void
    {
        $this->assertSame(
            ['email' => 'ada@example.com'],
            CardMapper::customerAttributes(new CreditCard(['email' => 'ada@example.com', 'billingPhone' => '']))
        );
    }

    public function testKeepsNonAsciiLettersIntact(): void
    {
        $card = new CreditCard([
            'firstName' => 'Åsa',
            'lastName' => "Михаил\u{85}Ņ",
            'email' => 'asa@example.com',
            'billingAddress1' => "Åkervägen \t 1",
            'billingCity' => 'Åre',
            'billingState' => 'Z',
            'billingPostcode' => '837 52',
            'billingCountry' => 'SE',
        ]);

        $this->assertSame('Åsa Михаил' . "\u{85}" . 'Ņ', CardMapper::customerAttributes($card)['name']);
        $this->assertSame(
            ['line_1' => 'Åkervägen 1', 'city' => 'Åre', 'state' => 'Z', 'zip' => '837 52', 'country' => 'SWE'],
            CardMapper::addressAttributes($card, CardMapper::BILLING)
        );
    }

    public function testBillingAddressConvertsTheCountryAndLeavesOutAnEmptyLine2(): void
    {
        $this->assertSame(
            ['line_1' => '12 åNALYTICAL Way', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701',
                'country' => 'USA'],
            CardMapper::addressAttributes($this->card(), CardMapper::BILLING)
        );
    }

    public function testShippingAddressReadsTheShippingFields(): void
    {
        $card = $this->card([
            'shippingAddress1' => '1 Engine Row',
            'shippingAddress2' => 'Flat 2',
            'shippingCity' => 'London',
            'shippingState' => 'LND',
            'shippingPostcode' => 'EC1A 1BB',
            'shippingCountry' => 'gb',
        ]);

        $this->assertSame(
            ['line_1' => '1 Engine Row', 'line_2' => 'Flat 2', 'city' => 'London', 'state' => 'LND',
                'zip' => 'EC1A 1BB', 'country' => 'GBR'],
            CardMapper::addressAttributes($card, CardMapper::SHIPPING)
        );
    }

    public function testAMissingStateNamesTheCardField(): void
    {
        try {
            CardMapper::addressAttributes($this->card(['billingState' => '']), CardMapper::BILLING);
            $this->fail('Expected an exception');
        } catch (InvalidFieldException $exception) {
            $this->assertSame('billingState', $exception->getField());
            $this->assertSame('The billing state is required.', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function fields(): array
    {
        return [
            'billing zip' => ['zip', CardMapper::BILLING, 'billingPostcode'],
            'shipping line 1' => ['line_1', CardMapper::SHIPPING, 'shippingAddress1'],
            'not an address attribute' => ['customer', CardMapper::BILLING, null],
        ];
    }

    #[DataProvider('fields')]
    public function testMapsAddressAttributesToCardFields(string $attribute, string $type, ?string $field): void
    {
        /** @var CardMapper::BILLING|CardMapper::SHIPPING $type */
        $this->assertSame($field, CardMapper::addressField($attribute, $type));
    }

    public function testMapsCustomerAttributesToCardFields(): void
    {
        $this->assertSame('billingName', CardMapper::customerField('name'));
        $this->assertSame('billingPhone', CardMapper::customerField('phone_number'));
        $this->assertNull(CardMapper::customerField('description'));
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function shippingAddresses(): array
    {
        $same = [
            'shippingAddress1' => '12 Ånalytical way ',
            'shippingCity' => 'SPRINGFIELD',
            'shippingState' => 'il',
            'shippingPostcode' => '62701',
            'shippingCountry' => 'USA',
        ];

        return [
            'blank' => [[], false],
            'the same, in another case, spacing and country form' => [$same, false],
            'another street' => [['shippingAddress1' => '13 Ånalytical Way'] + $same, true],
            'the same street with non-ASCII letters in another case' => [
                ['shippingAddress1' => '12 ÅNALYTICAL WAY'] + $same,
                false,
            ],
            'an extra line 2' => [$same + ['shippingAddress2' => 'Suite 4'], true],
            'only part filled in' => [['shippingCity' => 'Springfield'], true],
        ];
    }

    /**
     * @param array<string, string> $shipping
     */
    #[DataProvider('shippingAddresses')]
    public function testDecidesWhetherShippingNeedsItsOwnAddress(array $shipping, bool $distinct): void
    {
        $this->assertSame($distinct, CardMapper::hasDistinctShippingAddress($this->card($shipping)));
    }
}
