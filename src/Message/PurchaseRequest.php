<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Exception\InvalidFieldException;

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
    ];

    public function getFieldForAttribute(string $attribute): string
    {
        return self::FIELDS[$attribute] ?? $attribute;
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
