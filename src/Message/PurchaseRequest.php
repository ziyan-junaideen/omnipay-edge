<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Exception\InvalidFieldException;
use Omnipay\Edge\Gateway;
use Omnipay\Edge\Keys;

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

    /**
     * The consumer address id for billing, as returned by createAddress().
     */
    public function getBillingAddressReference(): ?string
    {
        return $this->getParameter('billingAddressReference');
    }

    public function setBillingAddressReference(?string $value): static
    {
        return $this->setParameter('billingAddressReference', $value);
    }

    /**
     * The consumer address id for shipping. Only sent when it differs from billing.
     */
    public function getShippingAddressReference(): ?string
    {
        return $this->getParameter('shippingAddressReference');
    }

    public function setShippingAddressReference(?string $value): static
    {
        return $this->setParameter('shippingAddressReference', $value);
    }

    public function getFieldForAttribute(string $attribute): string
    {
        return self::FIELDS[$attribute] ?? $attribute;
    }

    /**
     * The values handed to the browser. Checked before anything is sent, so a
     * demand is never created that the browser can't mount.
     *
     * @return array{publishableKey: string, dashboardHost: string, browserSdkUrl: string, mode: string}
     *
     * @throws InvalidRequestException
     */
    public function getClientConfig(): array
    {
        $publishableKey = (string) $this->getPublishableKey();

        if ($publishableKey === '') {
            throw new InvalidFieldException(
                'publishableKey',
                'The publishableKey parameter is required: the browser needs it to mount the payment form.'
            );
        }

        // edge.js appends /pay/<id> to the host, so a query string would break the
        // iframe URL and a trailing slash would double up.
        $dashboardHost = self::httpsUrl(
            $this->getDashboardHost(),
            Gateway::DEFAULT_DASHBOARD_HOST,
            'dashboardHost',
            false
        );
        $browserSdkUrl = self::httpsUrl(
            $this->getBrowserSdkUrl(),
            Gateway::DEFAULT_BROWSER_SDK_URL,
            'browserSdkUrl',
            true
        );

        return [
            'publishableKey' => $publishableKey,
            'dashboardHost' => rtrim($dashboardHost, '/'),
            'browserSdkUrl' => $browserSdkUrl,
            'mode' => Keys::mode($publishableKey),
        ];
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

        // buyer and receiver default to the payer. payer_timezone is set by the
        // hosted payment form from the shopper's browser.
        $relationships = [
            'payer' => [CustomerResponse::TYPE, $customer],
            'billing_address' => [AddressResponse::TYPE, $billingAddress],
        ];

        // Never send `"data": null`: Edge answers it with a 500. Ids are UUIDs, which
        // Edge matches case-insensitively.
        $shippingAddress = trim((string) $this->getShippingAddressReference());

        if ($shippingAddress !== '' && strcasecmp($shippingAddress, $billingAddress) !== 0) {
            $relationships['shipping_address'] = [AddressResponse::TYPE, $shippingAddress];
        }

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

    /**
     * @throws InvalidFieldException
     */
    private static function httpsUrl(?string $value, string $default, string $parameter, bool $allowQuery): string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return $default;
        }

        $parts = parse_url($url);

        $valid = is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && ($allowQuery || !isset($parts['query']));

        if (!$valid) {
            throw new InvalidFieldException($parameter, sprintf(
                'The %s parameter must be an https URL without %s.',
                $parameter,
                $allowQuery ? 'credentials or a fragment' : 'credentials, a fragment or a query string'
            ));
        }

        return $url;
    }
}
