<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests;

use Omnipay\Common\ItemBag;
use Omnipay\Edge\Item;
use Omnipay\Edge\Itemisation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ItemisationTest extends TestCase
{
    private const QUANTITY_PROBLEM = 'items[1].quantity must be a whole number from 1 to 1000000.';

    private const PRICE_PROBLEM = 'items[1].price must be a decimal amount, such as "12.50".';

    /**
     * Two shirts at $20.00 with $2.50 off each, a free sticker, $5.00 shipping, $3.10
     * tax and a $1.00 voucher: 35.00 + 0 + 5.00 + 3.10 - 1.00 = 42.10.
     */
    private function cart(): ItemBag
    {
        return new ItemBag([
            new Item([
                'name' => 'T-shirt',
                'description' => 'Blue, large',
                'sku' => 'TS-BLU-L',
                'quantity' => 2,
                'price' => '20.00',
                'discount' => '2.50',
            ]),
            ['name' => 'Sticker', 'quantity' => 1, 'price' => '0.00'],
        ]);
    }

    public function testBuildsTheBreakdownForACart(): void
    {
        $itemisation = Itemisation::build($this->cart(), '3.10', '5.00', '1.00', 4210, 'USD');

        $this->assertTrue($itemisation->isSent());
        $this->assertSame([], $itemisation->getProblems());
        $this->assertSame(
            '{"line_items":[{"name":"T-shirt","description":"Blue, large","sku":"TS-BLU-L","amount_cents":2000,'
            . '"amount_currency":"USD","quantity":2,"discount_cents":250,"discount_currency":"USD"},'
            . '{"name":"Sticker","description":"Sticker","amount_cents":0,"amount_currency":"USD","quantity":1}],'
            . '"tax_detail":{"tax_cents":310,"tax_currency":"USD"},'
            . '"shipping_detail":{"shipping_cents":500,"shipping_currency":"USD"},"discount_cents":100}',
            json_encode($itemisation->getAttributes())
        );
        $this->assertSame(4210, $itemisation->getItemisedCents());
        $this->assertSame(0, $itemisation->getDifferenceCents());
    }

    public function testSendsLinesAloneWhenNoSummaryIsGiven(): void
    {
        $itemisation = Itemisation::build($this->cart(), null, '', ' ', 3500, 'USD');

        $this->assertSame(['line_items'], array_keys($itemisation->getAttributes()));
        $this->assertSame([], $itemisation->getProblems());
    }

    public function testSendsAZeroTaxAndShippingButNoZeroDiscount(): void
    {
        $attributes = Itemisation::build($this->cart(), '0', '0.00', '0.00', 3500, 'USD')->getAttributes();

        $this->assertSame(['tax_cents' => 0, 'tax_currency' => 'USD'], $attributes['tax_detail']);
        $this->assertSame(['shipping_cents' => 0, 'shipping_currency' => 'USD'], $attributes['shipping_detail']);
        $this->assertArrayNotHasKey('discount_cents', $attributes);
    }

    public function testAnItemWithOnlyADescriptionSendsNoName(): void
    {
        $items = new ItemBag([['description' => 'Gift wrap', 'quantity' => '1', 'price' => '2.00']]);

        $this->assertSame(
            [['description' => 'Gift wrap', 'amount_cents' => 200, 'amount_currency' => 'USD', 'quantity' => 1]],
            Itemisation::build($items, null, null, null, 200, 'USD')->getAttributes()['line_items']
        );
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function wholeQuantities(): array
    {
        return [
            'an integer' => [3],
            'a digit string' => [' 3 '],
            'a whole float' => [3.0],
            'a whole decimal string' => ['3.00'],
            'leading zeros' => ['03'],
        ];
    }

    #[DataProvider('wholeQuantities')]
    public function testAcceptsAWholeQuantityInAnyForm(int|float|string $quantity): void
    {
        $items = new ItemBag([['name' => 'Rice', 'quantity' => $quantity, 'price' => '1.00']]);

        $attributes = Itemisation::build($items, null, null, null, 300, 'USD')->getAttributes();

        $this->assertSame(3, $attributes['line_items'][0]['quantity']);
    }

    /**
     * @return array<string, array{int|float|string, int}>
     */
    public static function roundedPrices(): array
    {
        return [
            'whole cents' => ['12.34', 1234],
            'one decimal' => ['0.5', 50],
            'no decimals' => ['12', 1200],
            'an integer is whole dollars' => [12, 1200],
            'half a cent rounds up' => ['1.005', 101],
            'just under half a cent rounds down' => ['1.0049', 100],
            'half a cent up to the next dollar' => ['0.995', 100],
            'many decimals' => ['3.33333333', 333],
            'leading zeros and spaces' => [' 007.10 ', 710],
            'negative zero' => ['-0.00', 0],
            'a float as PHP prints it' => [19.99, 1999],
            'a float on half a cent' => [10000.005, 1000001],
            'the largest accepted' => ['999999999999.99', 99999999999999],
        ];
    }

    #[DataProvider('roundedPrices')]
    public function testRoundsHalfUpToWholeCents(int|float|string $price, int $cents): void
    {
        $items = new ItemBag([['name' => 'Thing', 'quantity' => 1, 'price' => $price]]);

        $itemisation = Itemisation::build($items, null, null, null, 10, 'USD');

        $this->assertSame([], $itemisation->getProblems());
        $this->assertSame($cents, $itemisation->getAttributes()['line_items'][0]['amount_cents']);
    }

    public function testRoundsDiscountsAndSummaryAmountsHalfUp(): void
    {
        $items = new ItemBag([
            new Item(['name' => 'Thing', 'quantity' => 3, 'price' => '10.00', 'discount' => '0.3333']),
        ]);

        $itemisation = Itemisation::build($items, '0.125', '4.995', '0.0049', 2900, 'USD');
        $attributes = $itemisation->getAttributes();

        $this->assertSame(33, $attributes['line_items'][0]['discount_cents']);
        $this->assertSame(13, $attributes['tax_detail']['tax_cents']);
        $this->assertSame(500, $attributes['shipping_detail']['shipping_cents']);
        $this->assertArrayNotHasKey('discount_cents', $attributes);
        $this->assertSame((1000 - 33) * 3 + 13 + 500, $itemisation->getItemisedCents());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unrepresentableLines(): array
    {
        $line = ['name' => 'Thing', 'quantity' => 1, 'price' => '5.00'];

        return [
            'a fractional quantity' => [['quantity' => 1.5] + $line, self::QUANTITY_PROBLEM],
            'a zero quantity' => [['quantity' => '0'] + $line, self::QUANTITY_PROBLEM],
            'no quantity' => [['quantity' => null] + $line, self::QUANTITY_PROBLEM],
            'a boolean quantity' => [['quantity' => true] + $line, self::QUANTITY_PROBLEM],
            'too many' => [['quantity' => 1000001] + $line, self::QUANTITY_PROBLEM],
            'a fractional decimal string' => [['quantity' => '2.50'] + $line, self::QUANTITY_PROBLEM],
            'a negative quantity' => [['quantity' => -2] + $line, self::QUANTITY_PROBLEM],
            'no price' => [['price' => null] + $line, self::PRICE_PROBLEM],
            'a price that is not a number' => [['price' => '5,00'] + $line, self::PRICE_PROBLEM],
            'a float PHP prints with an exponent' => [['price' => 1.0E-7] + $line, self::PRICE_PROBLEM],
            'a negative price' => [['price' => '-5.00'] + $line, 'items[1].price must not be negative.'],
            'a price too large' => [['price' => '1000000000000'] + $line, 'items[1].price is too large.'],
            'a float price too large' => [['price' => 1.0E15] + $line, 'items[1].price is too large.'],
            'no name or description' => [
                ['name' => ' ', 'description' => ''] + $line,
                'items[1] needs a name or a description.',
            ],
            'a negative discount' => [['discount' => '-1'] + $line, 'items[1].discount must not be negative.'],
            'a discount above the price' => [
                ['discount' => '5.01'] + $line,
                'items[1].discount must not be more than the price.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $line
     */
    #[DataProvider('unrepresentableLines')]
    public function testALineThatCannotBeRepresentedDropsTheWholeBreakdown(array $line, string $problem): void
    {
        $items = new ItemBag([
            new Item(['name' => 'Good', 'quantity' => 1, 'price' => '10.00']),
            new Item($line),
            new Item(['name' => 'Also good', 'quantity' => 2, 'price' => '1.00']),
        ]);

        $itemisation = Itemisation::build($items, '1.00', '2.00', '0.50', 1500, 'USD');

        $this->assertFalse($itemisation->isSent());
        $this->assertSame([], $itemisation->getAttributes());
        $this->assertSame([$problem], $itemisation->getProblems());
        $this->assertNull($itemisation->getItemisedCents());
        $this->assertNull($itemisation->getDifferenceCents());
    }

    public function testASummaryAmountThatCannotBeRepresentedDropsTheWholeBreakdown(): void
    {
        $itemisation = Itemisation::build($this->cart(), '-3.10', 'free', '1.00', 4210, 'USD');

        $this->assertSame([], $itemisation->getAttributes());
        $this->assertSame(
            ['taxAmount must not be negative.', 'shippingAmount must be a decimal amount, such as "12.50".'],
            $itemisation->getProblems()
        );
    }

    public function testListsEveryProblem(): void
    {
        $items = new ItemBag([['quantity' => 0, 'price' => 'x']]);

        $this->assertSame([
            'items[0] needs a name or a description.',
            'items[0].quantity must be a whole number from 1 to 1000000.',
            'items[0].price must be a decimal amount, such as "12.50".',
        ], Itemisation::build($items, null, null, null, 100, 'USD')->getProblems());
    }

    public function testATotalTooLargeToCountSendsNothing(): void
    {
        $line = ['name' => 'Yacht', 'quantity' => 1000000, 'price' => '999999999999.99'];
        $items = new ItemBag([$line, $line]);

        $itemisation = Itemisation::build($items, null, null, null, 100, 'USD');

        $this->assertSame([], $itemisation->getAttributes());
        $this->assertSame(
            ['The items add up to more than can be counted in whole cents.'],
            $itemisation->getProblems()
        );
    }

    /**
     * @return array<string, array{mixed, mixed, mixed, string}>
     */
    public static function summaryWithoutItems(): array
    {
        return [
            'tax' => ['1.00', null, null, 'taxAmount is only sent with items, and no items were given.'],
            'all three' => [
                '1.00',
                '0',
                '2.00',
                'taxAmount and shippingAmount and discountAmount are only sent with items, and no items were given.',
            ],
        ];
    }

    #[DataProvider('summaryWithoutItems')]
    public function testSendsNoSummaryWithoutItems(mixed $tax, mixed $shipping, mixed $discount, string $problem): void
    {
        foreach ([null, new ItemBag()] as $items) {
            $itemisation = Itemisation::build($items, $tax, $shipping, $discount, 1000, 'USD');

            $this->assertSame([], $itemisation->getAttributes());
            $this->assertSame([$problem], $itemisation->getProblems());
        }
    }

    public function testNothingGivenIsNotAProblem(): void
    {
        $itemisation = Itemisation::build(null, null, '', '  ', 1000, 'USD');

        $this->assertFalse($itemisation->isSent());
        $this->assertSame([], $itemisation->getProblems());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function mismatchedAmounts(): array
    {
        return [
            'the breakdown is short' => [4300, -90],
            'the breakdown is over' => [4200, 10],
        ];
    }

    #[DataProvider('mismatchedAmounts')]
    public function testABreakdownThatDoesNotAddUpIsStillSent(int $amountCents, int $difference): void
    {
        $itemisation = Itemisation::build($this->cart(), '3.10', '5.00', '1.00', $amountCents, 'USD');

        $this->assertTrue($itemisation->isSent());
        $this->assertSame($difference, $itemisation->getDifferenceCents());
    }

    public function testTheEdgeItemKeepsItsSkuAndDiscount(): void
    {
        $item = (new Item())->setSku('SKU-1')->setDiscount('1.25');

        $this->assertSame('SKU-1', $item->getSku());
        $this->assertSame('1.25', $item->getDiscount());
    }
}
