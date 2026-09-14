<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\AbstractGateway;
use Omnipay\Edge\Message\AbstractRequest;
use Omnipay\Edge\Message\CompletePurchaseRequest;
use Omnipay\Edge\Message\CreateAddressRequest;
use Omnipay\Edge\Message\CreateCustomerRequest;
use Omnipay\Edge\Message\FetchAddressRequest;
use Omnipay\Edge\Message\FetchCardRequest;
use Omnipay\Edge\Message\FetchCustomerRequest;
use Omnipay\Edge\Message\FetchRefundRequest;
use Omnipay\Edge\Message\FetchTransactionRequest;
use Omnipay\Edge\Message\ListRefundsRequest;
use Omnipay\Edge\Message\PurchaseRequest;
use Omnipay\Edge\Message\RefundRequest;
use Omnipay\Edge\Message\UpdateCustomerRequest;

/**
 * Edge Payment Technologies gateway.
 *
 * Talks to the Edge v2 JSON:API through Omnipay's injected HTTP client. The
 * secret key authenticates server requests; the publishable key, dashboard host
 * and browser SDK URL are handed to the browser, which mounts Edge's hosted
 * payment form against a payment demand.
 *
 * The gateway stores nothing. Customers and addresses have no idempotency on Edge,
 * so they are explicit calls and the caller persists the ids they return.
 *
 * Work in progress: request messages are tracked in the repository's issues.
 */
class Gateway extends AbstractGateway
{
    /**
     * Sent in the User-Agent header. Bumped with each release.
     */
    public const VERSION = '0.1.0-dev';

    public const DEFAULT_API_BASE_URL = 'https://api.tryedge.io/v2/';

    public const DEFAULT_DASHBOARD_HOST = 'https://dashboard.tryedge.io';

    /**
     * The undigested SDK path. Edge's developer page hands out a content-hashed
     * edge-<digest>.js that changes on every deploy, so never pin that one.
     */
    public const DEFAULT_BROWSER_SDK_URL = 'https://assets.tryedge.io/assets/js/edge.js';

    public function getName(): string
    {
        return 'Edge';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultParameters(): array
    {
        return [
            'secretKey' => '',
            'publishableKey' => '',
            'webhookSecret' => '',
            'apiBaseUrl' => self::DEFAULT_API_BASE_URL,
            'dashboardHost' => self::DEFAULT_DASHBOARD_HOST,
            'browserSdkUrl' => self::DEFAULT_BROWSER_SDK_URL,
        ];
    }

    public function getSecretKey(): ?string
    {
        return $this->getParameter('secretKey');
    }

    public function setSecretKey(?string $value): static
    {
        return $this->setParameter('secretKey', $value);
    }

    public function getPublishableKey(): ?string
    {
        return $this->getParameter('publishableKey');
    }

    public function setPublishableKey(?string $value): static
    {
        return $this->setParameter('publishableKey', $value);
    }

    public function getWebhookSecret(): ?string
    {
        return $this->getParameter('webhookSecret');
    }

    public function setWebhookSecret(?string $value): static
    {
        return $this->setParameter('webhookSecret', $value);
    }

    public function getApiBaseUrl(): ?string
    {
        return $this->getParameter('apiBaseUrl');
    }

    public function setApiBaseUrl(?string $value): static
    {
        return $this->setParameter('apiBaseUrl', $value);
    }

    public function getDashboardHost(): ?string
    {
        return $this->getParameter('dashboardHost');
    }

    public function setDashboardHost(?string $value): static
    {
        return $this->setParameter('dashboardHost', $value);
    }

    public function getBrowserSdkUrl(): ?string
    {
        return $this->getParameter('browserSdkUrl');
    }

    public function setBrowserSdkUrl(?string $value): static
    {
        return $this->setParameter('browserSdkUrl', $value);
    }

    /**
     * Creates a customer from `email`, `name`, `phoneNumber` and `description`, or the
     * card's email, billing name and billing phone.
     *
     * @param array<string, mixed> $parameters
     */
    public function createCustomer(array $parameters = []): CreateCustomerRequest
    {
        return $this->message(CreateCustomerRequest::class, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters `customerReference`
     */
    public function fetchCustomer(array $parameters = []): FetchCustomerRequest
    {
        return $this->message(FetchCustomerRequest::class, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters `customerReference`, plus the createCustomer() fields
     */
    public function updateCustomer(array $parameters = []): UpdateCustomerRequest
    {
        return $this->message(UpdateCustomerRequest::class, $parameters);
    }

    /**
     * Creates a consumer address from the card's billing fields, or its shipping
     * fields with `addressType` set to `shipping`, linked to `customerReference`.
     *
     * @param array<string, mixed> $parameters
     */
    public function createAddress(array $parameters = []): CreateAddressRequest
    {
        return $this->message(CreateAddressRequest::class, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters `addressReference`
     */
    public function fetchAddress(array $parameters = []): FetchAddressRequest
    {
        return $this->message(FetchAddressRequest::class, $parameters);
    }

    /**
     * Reads a payment method. Edge only creates them in its hosted payment form.
     *
     * @param array<string, mixed> $parameters `cardReference`
     */
    public function fetchCard(array $parameters = []): FetchCardRequest
    {
        return $this->message(FetchCardRequest::class, $parameters);
    }

    /**
     * Creates an unconfirmed payment demand for the browser to mount Edge's hosted
     * payment form against. Nothing is charged until the demand is confirmed.
     *
     * @param array<string, mixed> $parameters `customerReference`, `billingAddressReference`,
     *                                         `transactionId`, `idempotencyKey`, `amount`,
     *                                         `currency`, and optionally
     *                                         `shippingAddressReference` and `description`
     */
    public function purchase(array $parameters = []): PurchaseRequest
    {
        return $this->message(PurchaseRequest::class, $parameters);
    }

    /**
     * Confirms a payment demand once the browser reports `payment_method_verified`.
     * The demand is read first and only confirmed when it matches the amount, currency
     * and idempotency key and has a verified card. Safe to call twice. A confirmed
     * demand is `pending`, not paid.
     *
     * @param array<string, mixed> $parameters `transactionReference`, `amount`, `currency`,
     *                                         `idempotencyKey`, and `previousCardReference`
     *                                         to retry a failed demand
     */
    public function completePurchase(array $parameters = []): CompletePurchaseRequest
    {
        return $this->message(CompletePurchaseRequest::class, $parameters);
    }

    /**
     * Reads a payment demand, for polling. Only a `succeeded` demand is successful:
     * `pending` and `processing` are not paid yet. Prefer webhooks.
     *
     * @param array<string, mixed> $parameters `transactionReference`, and optionally
     *                                         `includePaymentMethod`
     */
    public function fetchTransaction(array $parameters = []): FetchTransactionRequest
    {
        return $this->message(FetchTransactionRequest::class, $parameters);
    }

    /**
     * Refunds all or part of a `succeeded` payment demand. The amount is always sent, so
     * a partial refund never becomes a full one. An unclear answer is resolved by sending
     * the same request once more, then by listing the payment's refunds. A new refund is
     * `pending`, not refunded.
     *
     * @param array<string, mixed> $parameters `transactionReference` (the payment demand),
     *                                         `amount`, `currency`, `idempotencyKey`, and
     *                                         optionally `reason` and `reasonNote`
     */
    public function refund(array $parameters = []): RefundRequest
    {
        return $this->message(RefundRequest::class, $parameters);
    }

    /**
     * Reads a refund demand, for polling. Only a `succeeded` refund is successful.
     *
     * @param array<string, mixed> $parameters `refundReference`
     */
    public function fetchRefund(array $parameters = []): FetchRefundRequest
    {
        return $this->message(FetchRefundRequest::class, $parameters);
    }

    /**
     * Lists the refund demands of one payment demand.
     *
     * @param array<string, mixed> $parameters `transactionReference` (the payment demand)
     */
    public function listRefunds(array $parameters = []): ListRefundsRequest
    {
        return $this->message(ListRefundsRequest::class, $parameters);
    }

    /**
     * @template T of AbstractRequest
     *
     * @param class-string<T> $class
     * @param array<string, mixed> $parameters
     *
     * @return T
     */
    private function message(string $class, array $parameters): AbstractRequest
    {
        /** @var T $request */
        $request = $this->createRequest($class, $parameters);

        return $request;
    }
}
