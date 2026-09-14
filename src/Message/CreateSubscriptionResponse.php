<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use DateTimeImmutable;
use Exception;
use Omnipay\Common\Message\RequestInterface;

/**
 * The subscription intent created by createSubscription().
 *
 * Nothing has been charged, so isSuccessful(), isPending() and isRedirect() are always
 * false. Check isAwaitingPaymentMethod(), then hand getClientData() to the browser to
 * mount Edge's hosted payment form.
 *
 * When the idempotency key was used before, Edge returns what the key made: the intent,
 * or the subscription it became. getKind() tells them apart. A subscription has been
 * confirmed already: call completeSubscription() or fetchSubscription() rather than
 * mounting the form again.
 */
class CreateSubscriptionResponse extends AbstractSubscriptionResponse
{
    /**
     * Attributes that must come back as sent.
     */
    private const MATCHED_ATTRIBUTES = [
        'amount_cents',
        'amount_currency',
        'purchase_kind',
        'purchase_reference',
        'idempotency_key',
        'slug',
        'billing_period',
        'proration_behavior',
    ];

    /**
     * Relationships that must come back as sent, or absent when not sent.
     */
    private const MATCHED_RELATIONSHIPS = ['payer', 'billing_address', 'shipping_address'];

    /** @var array<string, mixed> */
    private array $sent;

    /**
     * @param array<string, mixed> $sent the document that was posted
     */
    public function __construct(RequestInterface $request, HttpResult $result, array $sent)
    {
        parent::__construct($request, $result);

        $this->sent = $sent;
    }

    /**
     * Always false: an unconfirmed subscription has charged nothing.
     */
    public function isSuccessful(): bool
    {
        return false;
    }

    /**
     * Always false: check isAwaitingPaymentMethod() and getKind().
     */
    public function isPending(): bool
    {
        return false;
    }

    public function isCancelled(): bool
    {
        return false;
    }

    /**
     * Edge created the intent, or returned the one this key made, with the facts that
     * were sent, and its payment form can collect a card.
     */
    public function isAwaitingPaymentMethod(): bool
    {
        return $this->isIntent() && $this->getMismatches() === [];
    }

    /**
     * What the browser needs to mount the payment form: `subscriptionId`,
     * `publishableKey`, `dashboardHost`, `browserSdkUrl` and `mode`. Never the secret
     * key. Null unless isAwaitingPaymentMethod().
     *
     * @return array{
     *     subscriptionId: string,
     *     publishableKey: string,
     *     dashboardHost: string,
     *     browserSdkUrl: string,
     *     mode: string
     * }|null
     */
    public function getClientData(): ?array
    {
        $id = $this->getSubscriptionReference();

        if ($id === null || !$this->isAwaitingPaymentMethod() || !$this->request instanceof CreateSubscriptionRequest) {
            return null;
        }

        return ['subscriptionId' => $id] + $this->request->getClientConfig();
    }

    /**
     * The sent facts Edge's intent or subscription disagrees with, keyed by attribute or
     * relationship name. Non-empty when an idempotency key was reused for a different
     * subscription. A billing cycle anchor compares as a point in time, and only when
     * one was sent and Edge returned the intent. Empty unless the response is a
     * well-formed subscription resource.
     *
     * @return array<string, array{sent: mixed, edge: mixed}>
     */
    public function getMismatches(): array
    {
        if ($this->getResource() === null) {
            return [];
        }

        $mismatches = $this->compareWithSent($this->sent, self::MATCHED_ATTRIBUTES, self::MATCHED_RELATIONSHIPS);

        $data = $this->sent['data'] ?? null;
        $attributes = is_array($data) && is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $sentAnchor = $attributes['billing_cycle_anchor_at'] ?? null;

        // Every successful charge moves a subscription's anchor to when the charge
        // completed (`Transactions.advance_payment_subscription_billing/2`), so only an
        // intent still holds the anchor that was sent.
        if (is_string($sentAnchor) && $this->isIntent()) {
            $edgeAnchor = $this->getAttribute('billing_cycle_anchor_at');

            if (!self::sameInstant($sentAnchor, $edgeAnchor)) {
                $mismatches['billing_cycle_anchor_at'] = ['sent' => $sentAnchor, 'edge' => $edgeAnchor];
            }
        }

        return $mismatches;
    }

    private static function sameInstant(string $sent, mixed $edge): bool
    {
        if (!is_string($edge) || $edge === '') {
            return false;
        }

        try {
            return (new DateTimeImmutable($sent))->format('U.u') === (new DateTimeImmutable($edge))->format('U.u');
        } catch (Exception) {
            return false;
        }
    }
}
