<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\PaymentState;

/**
 * The payment demand created by purchase() (`CoreHTTP.Views.PaymentDemands`).
 *
 * Nothing has been charged, so isSuccessful(), isPending() and isRedirect() are
 * always false. Check isAwaitingPaymentMethod(), then hand getClientData() to the
 * browser to mount Edge's hosted payment form.
 *
 * getProcessorState() is `incomplete` for a new demand. When the idempotency key was
 * used before, Edge returns that demand in whatever state it has reached. A demand
 * already `pending`, `processing` or `succeeded` has been confirmed: poll it with
 * fetchTransaction() rather than mounting the form again.
 */
class PurchaseResponse extends AbstractPaymentDemandResponse
{
    /**
     * `processor_state` values the hosted payment form can still collect a card for:
     * a new intent (`incomplete`, or the declared but unused `ready`) and a `failed`
     * demand, which is retried on the same id.
     */
    private const AWAITING_PAYMENT_METHOD_STATES = [
        PaymentState::INCOMPLETE,
        PaymentState::READY,
        PaymentState::FAILED,
    ];

    /**
     * Attributes that must come back as sent.
     */
    private const MATCHED_ATTRIBUTES = [
        'amount_cents',
        'amount_currency',
        'capture_method',
        'purchase_kind',
        'purchase_reference',
        'idempotency_key',
    ];

    /**
     * Relationships that must come back as sent, or absent when not sent. Ids compare
     * case-insensitively: Edge casts them as UUIDs and returns them in lower case.
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
     * Always false: an unconfirmed demand has charged nothing.
     */
    public function isSuccessful(): bool
    {
        return false;
    }

    /**
     * Edge created the demand, or returned the one this key made, with the facts that
     * were sent, and its payment form can collect a card.
     */
    public function isAwaitingPaymentMethod(): bool
    {
        return parent::isSuccessful()
            && $this->getMismatches() === []
            && in_array($this->getProcessorState(), self::AWAITING_PAYMENT_METHOD_STATES, true);
    }

    /**
     * What the browser needs to mount the payment form: `demandId`, `publishableKey`,
     * `dashboardHost`, `browserSdkUrl` and `mode`. Never the secret key. Null unless
     * isAwaitingPaymentMethod().
     *
     * @return array{
     *     demandId: string,
     *     publishableKey: string,
     *     dashboardHost: string,
     *     browserSdkUrl: string,
     *     mode: string
     * }|null
     */
    public function getClientData(): ?array
    {
        $demandId = $this->getTransactionReference();

        if ($demandId === null || !$this->isAwaitingPaymentMethod() || !$this->request instanceof PurchaseRequest) {
            return null;
        }

        return ['demandId' => $demandId] + $this->request->getClientConfig();
    }

    /**
     * The sent facts Edge's demand disagrees with, keyed by attribute or relationship
     * name. Non-empty when an idempotency key was reused for a different purchase:
     * Edge returns the original demand without comparing. Empty unless the response
     * is a well-formed demand.
     *
     * @return array<string, array{sent: mixed, edge: mixed}>
     */
    public function getMismatches(): array
    {
        if (!parent::isSuccessful()) {
            return [];
        }

        $data = $this->sent['data'] ?? null;
        $attributes = is_array($data) && is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $relationships = is_array($data) && is_array($data['relationships'] ?? null) ? $data['relationships'] : [];
        $mismatches = [];

        foreach (self::MATCHED_ATTRIBUTES as $name) {
            $sent = $attributes[$name] ?? null;
            $edge = $this->getAttribute($name);

            if ($sent !== $edge) {
                $mismatches[$name] = ['sent' => $sent, 'edge' => $edge];
            }
        }

        foreach (self::MATCHED_RELATIONSHIPS as $name) {
            $linkage = $relationships[$name]['data'] ?? null;
            $sent = is_array($linkage) ? ($linkage['id'] ?? null) : null;
            $edge = $this->getRelationshipId($name);

            $same = is_string($sent) && is_string($edge) ? strcasecmp($sent, $edge) === 0 : $sent === $edge;

            if (!$same) {
                $mismatches[$name] = ['sent' => $sent, 'edge' => $edge];
            }
        }

        return $mismatches;
    }
}
