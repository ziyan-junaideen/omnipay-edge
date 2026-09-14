<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\IdempotencyConflictException;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * POST /payment_subscriptions with `confirmed: false`: an unconfirmed subscription
 * intent for the browser to mount Edge's hosted payment form against. Nothing is
 * charged until completeSubscription() confirms it.
 *
 * Confirming needs a `purchase_reference`, a `purchase_kind` and at least one line item
 * (`PaymentSubscription.create_changeset/3`). Unlike a payment demand, a subscription
 * gets no default line item from Edge, so one line item for the full amount is sent.
 *
 * The caller supplies and stores the idempotency key. Edge returns the subscription or
 * intent a key was first used for without comparing the request, so the response is
 * checked against what was sent and a mismatch throws IdempotencyConflictException.
 */
class CreateSubscriptionRequest extends AbstractSubscriptionRequest
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
        'slug' => 'slug',
        'billing_period' => 'billingPeriod',
        'proration_behavior' => 'prorationBehavior',
        'billing_cycle_anchor_at' => 'billingCycleAnchorAt',
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
        $this->requireString('slug');
        $this->requireString('billingPeriod');

        $slug = (string) $this->resolveSlug();
        $billingPeriod = (string) $this->resolveEnum('billingPeriod', self::BILLING_PERIODS);
        $prorationBehavior = $this->resolveEnum('prorationBehavior', self::PRORATION_BEHAVIORS)
            ?? self::PRORATION_NONE;
        $billingCycleAnchorAt = $this->resolveBillingCycleAnchorAt();

        $this->validate('amount', 'currency');

        $amountCents = (int) $this->getAmountInteger();
        $currency = (string) $this->getCurrency();
        $description = trim((string) $this->getDescription());

        $attributes = [
            // A JSON boolean. true would skip the intent and create the subscription at once.
            'confirmed' => false,
            'amount_cents' => $amountCents,
            'amount_currency' => $currency,
            'purchase_kind' => 'order',
            'purchase_reference' => $transactionId,
            'idempotency_key' => $idempotencyKey,
            'slug' => $slug,
            'billing_period' => $billingPeriod,
            'proration_behavior' => $prorationBehavior,
        ];

        // Left out, Edge anchors the subscription at the moment the intent is created.
        if ($billingCycleAnchorAt !== null) {
            $attributes['billing_cycle_anchor_at'] = $billingCycleAnchorAt;
        }

        if ($description !== '') {
            $attributes['description'] = $description;
        }

        $attributes['line_items'] = [[
            'name' => $description !== '' ? $description : $slug,
            'amount_cents' => $amountCents,
            'amount_currency' => $currency,
            'quantity' => 1,
        ]];

        return $this->resourceDocument(
            AbstractSubscriptionResponse::TYPE,
            $attributes,
            $this->payerRelationships($customer, $billingAddress)
        );
    }

    /**
     * @throws IdempotencyConflictException when Edge returns a subscription made for other facts
     */
    public function send(): CreateSubscriptionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws IdempotencyConflictException when Edge returns a subscription made for other facts
     */
    public function sendData($data): CreateSubscriptionResponse
    {
        $response = new CreateSubscriptionResponse(
            $this,
            $this->sendPost(AbstractSubscriptionResponse::TYPE, $data),
            $data
        );
        $this->response = $response;

        $mismatches = $response->getMismatches();

        if ($mismatches !== []) {
            throw new IdempotencyConflictException($response, $mismatches);
        }

        return $response;
    }
}
