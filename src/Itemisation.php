<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\ItemBag;
use Omnipay\Common\ItemInterface;

/**
 * The itemised breakdown sent with a payment demand: `line_items`, `tax_detail`,
 * `shipping_detail` and `discount_cents`. Pure: no I/O.
 *
 * The breakdown is informational. Edge charges the demand's `amount_cents` and never
 * compares the two, so a breakdown that doesn't add up is still sent, and
 * getDifferenceCents() reports by how much it is off.
 *
 * It is all or nothing. A non-empty `line_items` replaces the single "Payment" line
 * Edge adds when an intent is confirmed, so a basket missing one line would look
 * complete to the payer. When any line or amount can't be represented, nothing is
 * sent and getProblems() says why. The tax, shipping and discount describe the
 * lines, so they are only sent with them.
 *
 * - A line's `amount_cents` is the price of one unit before discount, and its
 *   `discount_cents` the discount on one unit (`Core.Transactions.line_item_amount/1`
 *   multiplies the price by the quantity).
 * - Tax is only ever sent in `tax_detail`, never on a line, so it is counted once.
 * - Amounts are decimals like "12.50", rounded half up to whole cents with integer
 *   maths. An integer is whole dollars. A float is read as PHP prints it, as Omnipay
 *   reads an amount.
 */
final class Itemisation
{
    /**
     * The largest whole-dollar part accepted, so every total fits in an integer.
     */
    private const MAX_DOLLAR_DIGITS = 12;

    /**
     * The largest quantity accepted, for the same reason.
     */
    private const MAX_QUANTITY = 1000000;

    /** @var array<string, mixed> */
    private array $attributes;

    /** @var list<string> */
    private array $problems;

    private ?int $itemisedCents;

    private int $amountCents;

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $problems
     */
    private function __construct(array $attributes, array $problems, ?int $itemisedCents, int $amountCents)
    {
        $this->attributes = $attributes;
        $this->problems = $problems;
        $this->itemisedCents = $itemisedCents;
        $this->amountCents = $amountCents;
    }

    /**
     * @param ItemBag|null $items the cart lines, each priced per unit
     * @param mixed $taxAmount all the tax on the purchase, or null
     * @param mixed $shippingAmount the shipping charge, before tax, or null
     * @param mixed $discountAmount a discount on the whole purchase that isn't already on a line, or null
     * @param int $amountCents the demand's `amount_cents`
     * @param string $currency the demand's currency
     */
    public static function build(
        ?ItemBag $items,
        mixed $taxAmount,
        mixed $shippingAmount,
        mixed $discountAmount,
        int $amountCents,
        string $currency
    ): self {
        $summary = array_filter(
            ['taxAmount' => $taxAmount, 'shippingAmount' => $shippingAmount, 'discountAmount' => $discountAmount],
            static fn (mixed $value): bool => $value !== null && (!is_string($value) || trim($value) !== '')
        );

        if ($items === null || count($items) === 0) {
            $problems = $summary === [] ? [] : [sprintf(
                '%s %s only sent with items, and no items were given.',
                implode(' and ', array_keys($summary)),
                count($summary) === 1 ? 'is' : 'are'
            )];

            return new self([], $problems, null, $amountCents);
        }

        $problems = [];
        $lines = [];
        $itemisedCents = 0;

        foreach (array_values($items->all()) as $index => $item) {
            $line = self::line($item, 'items[' . $index . ']', $currency, $problems);

            if ($line !== null) {
                [$lines[], $netCents] = $line;
                $itemisedCents += $netCents;
            }
        }

        $cents = [];

        foreach ($summary as $parameter => $value) {
            $parsed = self::cents($value);

            if (is_string($parsed)) {
                $problems[] = $parameter . ' ' . $parsed;
            } else {
                $cents[$parameter] = $parsed;
            }
        }

        if ($problems !== []) {
            return new self([], $problems, null, $amountCents);
        }

        $attributes = ['line_items' => $lines];

        if (isset($cents['taxAmount'])) {
            $attributes['tax_detail'] = ['tax_cents' => $cents['taxAmount'], 'tax_currency' => $currency];
            $itemisedCents += $cents['taxAmount'];
        }

        if (isset($cents['shippingAmount'])) {
            $attributes['shipping_detail'] = [
                'shipping_cents' => $cents['shippingAmount'],
                'shipping_currency' => $currency,
            ];
            $itemisedCents += $cents['shippingAmount'];
        }

        if (($cents['discountAmount'] ?? 0) > 0) {
            $attributes['discount_cents'] = $cents['discountAmount'];
            $itemisedCents -= $cents['discountAmount'];
        }

        // Integer arithmetic that overflows carries on as a float.
        if (!is_int($itemisedCents)) {
            return new self([], ['The items add up to more than can be counted in whole cents.'], null, $amountCents);
        }

        return new self($attributes, [], $itemisedCents, $amountCents);
    }

    /**
     * The attributes to add to the demand, or an empty array when nothing is sent.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isSent(): bool
    {
        return $this->attributes !== [];
    }

    /**
     * Why the breakdown given wasn't sent, one message per problem. Empty when it was
     * sent, or when none was given.
     *
     * @return list<string>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    /**
     * What the breakdown adds up to: each line's discounted unit price times its
     * quantity, plus tax and shipping, less the purchase discount. Null when nothing
     * is sent.
     */
    public function getItemisedCents(): ?int
    {
        return $this->itemisedCents;
    }

    /**
     * getItemisedCents() less the demand's amount: positive when the breakdown adds up
     * to more than is charged. Null when nothing is sent. Edge doesn't check it, so a
     * non-zero difference is worth logging, not refusing.
     */
    public function getDifferenceCents(): ?int
    {
        return $this->itemisedCents === null ? null : $this->itemisedCents - $this->amountCents;
    }

    /**
     * One Edge line item and what it adds up to after discount, or null after adding
     * the problems to $problems.
     *
     * @param list<string> $problems
     *
     * @return array{array<string, mixed>, int|float}|null
     */
    private static function line(mixed $item, string $path, string $currency, array &$problems): ?array
    {
        if (!$item instanceof ItemInterface) {
            $problems[] = $path . ' must be an Omnipay item.';

            return null;
        }

        $count = count($problems);

        $name = self::text($item->getName());
        $description = self::text($item->getDescription());
        $sku = $item instanceof Item ? self::text($item->getSku()) : '';

        if ($name === '' && $description === '') {
            $problems[] = $path . ' needs a name or a description.';
        }

        $quantity = self::quantity($item->getQuantity());

        if ($quantity === null) {
            $problems[] = sprintf('%s.quantity must be a whole number from 1 to %d.', $path, self::MAX_QUANTITY);
        }

        $price = self::cents($item->getPrice());

        if (is_string($price)) {
            $problems[] = $path . '.price ' . $price;
        }

        $discount = 0;

        if ($item instanceof Item && $item->getDiscount() !== null && $item->getDiscount() !== '') {
            $discount = self::cents($item->getDiscount());

            if (is_string($discount)) {
                $problems[] = $path . '.discount ' . $discount;
            } elseif (is_int($price) && $discount > $price) {
                $problems[] = $path . '.discount must not be more than the price.';
            }
        }

        if (count($problems) > $count || !is_int($price) || !is_int($discount) || $quantity === null) {
            return null;
        }

        $line = [];

        if ($name !== '') {
            $line['name'] = $name;
        }

        // Edge's invoice shows a line's description and its dashboard the name, so
        // each falls back to the other.
        $line['description'] = $description !== '' ? $description : $name;

        if ($sku !== '') {
            $line['sku'] = $sku;
        }

        $line['amount_cents'] = $price;
        $line['amount_currency'] = $currency;
        $line['quantity'] = $quantity;

        if ($discount > 0) {
            $line['discount_cents'] = $discount;
            $line['discount_currency'] = $currency;
        }

        return [$line, ($price - $discount) * $quantity];
    }

    /**
     * Whole cents, rounded half up, or why the value can't be read.
     */
    private static function cents(mixed $value): int|string
    {
        if (is_float($value) && is_finite($value) && abs($value) >= 10 ** self::MAX_DOLLAR_DIGITS) {
            // PHP prints a float this large with an exponent.
            return $value < 0 ? 'must not be negative.' : 'is too large.';
        }

        $decimal = match (true) {
            is_int($value), is_float($value) && is_finite($value) => (string) $value,
            is_string($value) => trim($value),
            default => null,
        };

        if ($decimal === null || preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $match) !== 1) {
            return 'must be a decimal amount, such as "12.50".';
        }

        [, $sign, $dollars, $fraction] = $match + [3 => ''];
        $dollars = ltrim($dollars, '0');

        if ($sign === '-' && ($dollars !== '' || trim($fraction, '0') !== '')) {
            return 'must not be negative.';
        }

        if (strlen($dollars) > self::MAX_DOLLAR_DIGITS) {
            return 'is too large.';
        }

        $fraction = str_pad($fraction, 3, '0');

        // Half up: a third decimal of 5 or more is at least half a cent.
        return (int) $dollars * 100 + (int) substr($fraction, 0, 2) + ($fraction[2] >= '5' ? 1 : 0);
    }

    private static function quantity(mixed $value): ?int
    {
        // Some carts, such as Aimeos, keep quantities as floats, or as decimal strings
        // read from the database, so a whole "3.0" is 3.
        $digits = match (true) {
            is_int($value), is_float($value) && is_finite($value) => (string) $value,
            is_string($value) => trim($value),
            default => '',
        };

        if (preg_match('/^0*(\d{1,7})(?:\.0*)?$/', $digits, $match) !== 1) {
            return null;
        }

        $quantity = (int) $match[1];

        return $quantity >= 1 && $quantity <= self::MAX_QUANTITY ? $quantity : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
