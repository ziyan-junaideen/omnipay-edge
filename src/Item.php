<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\Item as OmnipayItem;

/**
 * An Omnipay cart item with the extra line item fields Edge stores: a SKU and a
 * per-unit discount.
 *
 * Plain Omnipay items work too. Pass this class when a line has either, because an
 * item given as an array is built as Omnipay\Common\Item, which drops unknown keys.
 */
class Item extends OmnipayItem
{
    public function getSku(): ?string
    {
        return $this->getParameter('sku');
    }

    public function setSku(?string $value): static
    {
        return $this->setParameter('sku', $value);
    }

    /**
     * The discount on one unit, as a decimal amount like the price (such as "1.50").
     *
     * @return int|float|string|null
     */
    public function getDiscount()
    {
        return $this->getParameter('discount');
    }

    public function setDiscount(int|float|string|null $value): static
    {
        return $this->setParameter('discount', $value);
    }
}
