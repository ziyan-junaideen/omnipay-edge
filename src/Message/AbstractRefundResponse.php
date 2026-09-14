<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * A refund demand (`CoreHTTP.Views.RefundDemands`).
 *
 * A refund is `pending` when created, then `processing`, then `succeeded` or `failed`
 * (`Core.Transactions.RefundDemand`). Only `succeeded` means the money went back. A
 * `failed` refund releases its amount, so the payment can be refunded again.
 */
abstract class AbstractRefundResponse extends AbstractResponse
{
    public const TYPE = 'refund_demands';

    public const STATE_PENDING = 'pending';

    public const STATE_PROCESSING = 'processing';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    /**
     * Refunded: only a `succeeded` refund.
     */
    public function isSuccessful(): bool
    {
        return $this->getState() === self::STATE_SUCCEEDED;
    }

    /**
     * Edge accepted the refund but hasn't settled it: `pending` or `processing`. It
     * may still fail.
     */
    public function isPending(): bool
    {
        return in_array($this->getState(), [self::STATE_PENDING, self::STATE_PROCESSING], true);
    }

    /**
     * The refund failed and its amount was released.
     */
    public function isFailed(): bool
    {
        return $this->getState() === self::STATE_FAILED;
    }

    /**
     * The refund demand id.
     */
    public function getTransactionReference(): ?string
    {
        return $this->getResourceId();
    }

    /**
     * `pending`, `processing`, `succeeded` or `failed`, or null unless Edge returned a
     * refund demand.
     */
    public function getState(): ?string
    {
        return $this->stringAttribute('state');
    }

    /**
     * One of RefundRequest::REASONS.
     */
    public function getReason(): ?string
    {
        return $this->stringAttribute('reason');
    }

    public function getReasonNote(): ?string
    {
        return $this->stringAttribute('reason_note');
    }

    public function getAmountCents(): ?int
    {
        $amount = $this->getAttribute('amount_cents');

        return is_int($amount) ? $amount : null;
    }

    /**
     * The payment's currency: Edge copies it onto the refund.
     */
    public function getCurrency(): ?string
    {
        return $this->stringAttribute('amount_currency');
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->stringAttribute('idempotency_key');
    }

    /**
     * The id of the refunded payment demand.
     */
    public function getPaymentDemandReference(): ?string
    {
        return $this->getRelationshipId('payment_demand');
    }

    /**
     * When the refund was created, as Edge's ISO 8601 UTC timestamp.
     */
    public function getCreatedAt(): ?string
    {
        return $this->stringAttribute('created_at');
    }

    /**
     * When the refund last changed, as Edge's ISO 8601 UTC timestamp.
     */
    public function getUpdatedAt(): ?string
    {
        return $this->stringAttribute('updated_at');
    }
}
