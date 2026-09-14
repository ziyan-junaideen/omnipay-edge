<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\PaymentState;

/**
 * A payment demand (`CoreHTTP.Views.PaymentDemands`). Edge returns unconfirmed
 * intents and confirmed demands through the same view, with the same id.
 */
abstract class AbstractPaymentDemandResponse extends AbstractResponse
{
    public const TYPE = 'payment_demands';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    /**
     * The demand id. It stays the same through confirm.
     */
    public function getTransactionReference(): ?string
    {
        return $this->getResourceId();
    }

    /**
     * The `purchase_reference`, sent from `transactionId`.
     */
    public function getTransactionId(): ?string
    {
        return $this->stringAttribute('purchase_reference');
    }

    public function getProcessorState(): ?string
    {
        return $this->stringAttribute('processor_state');
    }

    /**
     * What the `processor_state` means, or null unless Edge returned a demand.
     */
    public function getPaymentState(): ?PaymentState
    {
        return parent::isSuccessful() ? PaymentState::fromProcessorState($this->getProcessorState()) : null;
    }

    /**
     * When the demand last changed, as Edge's ISO 8601 UTC timestamp with microseconds.
     */
    public function getUpdatedAt(): ?string
    {
        return $this->stringAttribute('updated_at');
    }

    public function getAmountCents(): ?int
    {
        $amount = $this->getAttribute('amount_cents');

        return is_int($amount) ? $amount : null;
    }

    public function getCurrency(): ?string
    {
        return $this->stringAttribute('amount_currency');
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->stringAttribute('idempotency_key');
    }

    public function getCustomerReference(): ?string
    {
        return $this->getRelationshipId('payer');
    }

    public function getBillingAddressReference(): ?string
    {
        return $this->getRelationshipId('billing_address');
    }

    public function getShippingAddressReference(): ?string
    {
        return $this->getRelationshipId('shipping_address');
    }

    /**
     * The subscription id when the demand is a subscription charge (a renewal or a
     * prorated first charge), otherwise null.
     */
    public function getSubscriptionReference(): ?string
    {
        return $this->getRelationshipId('payment_subscription');
    }

    /**
     * The payment method id, once the hosted payment form has collected a card.
     */
    public function getCardReference(): ?string
    {
        return $this->getRelationshipId('payment_method');
    }
}
