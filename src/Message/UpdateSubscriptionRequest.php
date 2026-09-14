<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * PATCH /payment_subscriptions/{subscriptionReference}: changes a subscription intent
 * before it is confirmed.
 *
 * Edge only updates an unconfirmed intent, and answers 405 once it is a subscription
 * (see UpdateSubscriptionResponse::isAlreadyConfirmed()). Only the parameters given are
 * sent. For an intent, Edge casts the billing attributes and the payer and address
 * relationships only (`PaymentIntent.json_api_update_changeset/2`), so an amount,
 * currency or description, which it would silently ignore, is refused before sending.
 */
class UpdateSubscriptionRequest extends AbstractSubscriptionRequest
{
    /**
     * Edge attribute or relationship => request parameter.
     */
    private const FIELDS = [
        'slug' => 'slug',
        'billing_period' => 'billingPeriod',
        'proration_behavior' => 'prorationBehavior',
        'billing_cycle_anchor_at' => 'billingCycleAnchorAt',
        'payer' => 'customerReference',
        'billing_address' => 'billingAddressReference',
        'shipping_address' => 'shippingAddressReference',
    ];

    /**
     * Parameters Edge doesn't change on a subscription intent.
     */
    private const UNCHANGEABLE = ['amount', 'currency', 'description'];

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
        $id = $this->requireString('subscriptionReference');

        foreach (self::UNCHANGEABLE as $parameter) {
            $value = $this->getParameter($parameter);

            if ($value !== null && $value !== '') {
                throw new InvalidFieldException($parameter, sprintf(
                    'Edge does not change the %s of a subscription. Create a new subscription instead.',
                    $parameter
                ));
            }
        }

        $attributes = array_filter([
            'slug' => $this->resolveSlug(),
            'billing_period' => $this->resolveEnum('billingPeriod', self::BILLING_PERIODS),
            'proration_behavior' => $this->resolveEnum('prorationBehavior', self::PRORATION_BEHAVIORS),
            'billing_cycle_anchor_at' => $this->resolveBillingCycleAnchorAt(),
        ], static fn (?string $value): bool => $value !== null);

        $relationships = [];
        $references = [
            'payer' => [CustomerResponse::TYPE, 'customerReference'],
            'billing_address' => [AddressResponse::TYPE, 'billingAddressReference'],
            'shipping_address' => [AddressResponse::TYPE, 'shippingAddressReference'],
        ];

        foreach ($references as $name => [$type, $parameter]) {
            $value = $this->getParameter($parameter);
            $value = is_scalar($value) ? trim((string) $value) : '';

            // Never `"data": null`: Edge answers it with a 500.
            if ($value !== '') {
                $relationships[$name] = [$type, $value];
            }
        }

        if ($attributes === [] && $relationships === []) {
            throw new InvalidRequestException(
                'Nothing to update: pass slug, billingPeriod, prorationBehavior, billingCycleAnchorAt, '
                . 'customerReference, billingAddressReference or shippingAddressReference.'
            );
        }

        return $this->resourceDocument(AbstractSubscriptionResponse::TYPE, $attributes, $relationships, $id);
    }

    public function send(): UpdateSubscriptionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendData($data): UpdateSubscriptionResponse
    {
        $id = $this->requireString('subscriptionReference');

        return $this->response = new UpdateSubscriptionResponse(
            $this,
            $this->sendPatch(self::path(AbstractSubscriptionResponse::TYPE, $id), $data)
        );
    }
}
