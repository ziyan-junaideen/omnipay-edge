<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\ItemBag;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Exception\InvalidFieldException;
use Omnipay\Edge\Itemisation;

/**
 * POST /payment_demands with `confirmed: false`: an unconfirmed payment demand for
 * the browser to mount Edge's hosted payment form against. Nothing is charged.
 *
 * Edge stores it as a payment intent (`Core.Transactions.PaymentIntent`) but
 * returns it as `payment_demands`, and the id stays the same through confirm.
 *
 * The caller supplies and stores the idempotency key. Edge returns the demand a key
 * was first used for without comparing the request, so the response is checked
 * against what was sent and a mismatch throws IdempotencyConflictException.
 *
 * `items`, `taxAmount`, `shippingAmount` and `discountAmount` add an itemised
 * breakdown for the payer's receipt. It is informational: see Itemisation.
 */
class PurchaseRequest extends AbstractRequest
{
    use ClientConfigTrait;

    /**
     * Edge attribute or relationship => request parameter.
     */
    private const FIELDS = [
        'amount_cents' => 'amount',
        'amount_currency' => 'currency',
        'purchase_reference' => 'transactionId',
        'idempotency_key' => 'idempotencyKey',
        'description' => 'description',
        'payer' => 'customerReference',
        'billing_address' => 'billingAddressReference',
        'shipping_address' => 'shippingAddressReference',
        'line_items' => 'items',
        'tax_detail' => 'taxAmount',
        'shipping_detail' => 'shippingAmount',
        'discount_cents' => 'discountAmount',
    ];

    public function getFieldForAttribute(string $attribute): string
    {
        return self::FIELDS[$attribute] ?? $attribute;
    }

    /**
     * All the tax on the purchase, as a decimal amount such as "2.10". Sent as
     * `tax_detail` with the items.
     *
     * @return int|float|string|null
     */
    public function getTaxAmount()
    {
        return $this->getParameter('taxAmount');
    }

    public function setTaxAmount(int|float|string|null $value): static
    {
        return $this->setParameter('taxAmount', $value);
    }

    /**
     * The shipping charge before tax, as a decimal amount. Sent as `shipping_detail`
     * with the items.
     *
     * @return int|float|string|null
     */
    public function getShippingAmount()
    {
        return $this->getParameter('shippingAmount');
    }

    public function setShippingAmount(int|float|string|null $value): static
    {
        return $this->setParameter('shippingAmount', $value);
    }

    /**
     * A discount on the whole purchase that isn't already on an item, as a decimal
     * amount. Sent as `discount_cents` with the items when more than zero.
     *
     * @return int|float|string|null
     */
    public function getDiscountAmount()
    {
        return $this->getParameter('discountAmount');
    }

    public function setDiscountAmount(int|float|string|null $value): static
    {
        return $this->setParameter('discountAmount', $value);
    }

    /**
     * The itemised breakdown this request sends: check isSent(), getProblems() for why
     * a breakdown given wasn't sent, and getDifferenceCents() for how far it is from the
     * amount. Neither stops the purchase; log them.
     *
     * @throws InvalidRequestException when the amount or currency is invalid
     */
    public function getItemisation(): Itemisation
    {
        $this->validate('amount', 'currency');

        // Omnipay's setItems() only wraps a non-empty array in an ItemBag, so an empty
        // cart arrives as [].
        $items = $this->getItems();

        return Itemisation::build(
            $items instanceof ItemBag ? $items : null,
            $this->getTaxAmount(),
            $this->getShippingAmount(),
            $this->getDiscountAmount(),
            (int) $this->getAmountInteger(),
            (string) $this->getCurrency()
        );
    }

    /**
     * @return array{data: array<string, mixed>}
     *
     * @throws InvalidRequestException
     */
    protected function getRequestData(): array
    {
        $this->getClientConfig();

        $customer = $this->requireString('customerReference');
        $billingAddress = $this->requireString('billingAddressReference');
        $transactionId = $this->requireString('transactionId');
        $idempotencyKey = $this->requireString('idempotencyKey');

        $this->validate('amount', 'currency');

        $attributes = [
            // A JSON boolean: the controller only matches true or false.
            'confirmed' => false,
            'amount_cents' => (int) $this->getAmountInteger(),
            'amount_currency' => (string) $this->getCurrency(),
            // Edge also accepts manual, but has no capture endpoint.
            'capture_method' => 'automatic',
            'purchase_kind' => 'order',
            'purchase_reference' => $transactionId,
            'idempotency_key' => $idempotencyKey,
        ];

        $description = trim((string) $this->getDescription());

        if ($description !== '') {
            $attributes['description'] = $description;
        }

        $attributes += $this->getItemisation()->getAttributes();

        // payer_timezone is set by the hosted payment form from the shopper's browser.
        $relationships = $this->payerRelationships($customer, $billingAddress);

        return $this->resourceDocument(PurchaseResponse::TYPE, $attributes, $relationships);
    }

    /**
     * @throws IdempotencyConflictException when Edge returns a demand made for other facts
     */
    public function send(): PurchaseResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws IdempotencyConflictException when Edge returns a demand made for other facts
     */
    public function sendData($data): PurchaseResponse
    {
        $response = new PurchaseResponse($this, $this->sendPost('payment_demands', $data), $data);
        $this->response = $response;

        $mismatches = $response->getMismatches();

        if ($mismatches !== []) {
            throw new IdempotencyConflictException($response, $mismatches);
        }

        return $response;
    }
}
