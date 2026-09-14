<?php

declare(strict_types=1);

namespace Omnipay\Edge;

use Omnipay\Common\Message\NotificationInterface;

/**
 * What an Edge `processor_state` means for Omnipay. Pure: no I/O.
 *
 * Edge returns unconfirmed intents (`Core.Transactions.PaymentIntent`) and demands
 * (`Core.Transactions.PaymentDemand`) as `payment_demands`, with the same id. Only
 * `succeeded` is paid. A `pending` demand was accepted by Edge but has not reached
 * the card network yet, and declines arrive later as `failed`.
 *
 * An unknown state is never treated as paid: it is pending for notifications and
 * flagged by isUnrecognised().
 */
final class PaymentState
{
    public const INCOMPLETE = 'incomplete';

    /** Declared but never set by the backend. */
    public const READY = 'ready';

    /** The intent became a demand with the same id. GET returns the demand instead. */
    public const CONFIRMED = 'confirmed';

    /** Declared but never set by the backend. */
    public const CANCELED = 'canceled';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Declared but never set by the backend. */
    public const DISPUTED = 'disputed';

    /** Declared but never set by the backend. */
    public const REVERSED = 'reversed';

    public const KIND_INTENT = 'intent';

    public const KIND_DEMAND = 'demand';

    public const MESSAGE_CVC_MISMATCH = "The security code (CVC) didn't match. Check it or try another card.";

    public const MESSAGE_ADDRESS_MISMATCH = "The billing address didn't match the card.";

    public const MESSAGE_DECLINED = 'The payment was declined. Try another card or contact your card issuer.';

    /**
     * state => [kind, successful, pending, cancelled, notification status, needs reconciliation]
     */
    private const STATES = [
        self::INCOMPLETE => [self::KIND_INTENT, false, false, false, NotificationInterface::STATUS_PENDING, false],
        self::READY => [self::KIND_INTENT, false, false, false, NotificationInterface::STATUS_PENDING, false],
        self::CONFIRMED => [self::KIND_INTENT, false, true, false, NotificationInterface::STATUS_PENDING, false],
        self::CANCELED => [self::KIND_INTENT, false, false, true, NotificationInterface::STATUS_FAILED, false],
        self::PENDING => [self::KIND_DEMAND, false, true, false, NotificationInterface::STATUS_PENDING, false],
        self::PROCESSING => [self::KIND_DEMAND, false, true, false, NotificationInterface::STATUS_PENDING, false],
        self::SUCCEEDED => [self::KIND_DEMAND, true, false, false, NotificationInterface::STATUS_COMPLETED, false],
        self::FAILED => [self::KIND_DEMAND, false, false, false, NotificationInterface::STATUS_FAILED, false],
        self::DISPUTED => [self::KIND_DEMAND, false, false, false, NotificationInterface::STATUS_COMPLETED, true],
        self::REVERSED => [self::KIND_DEMAND, false, false, false, NotificationInterface::STATUS_COMPLETED, true],
    ];

    /**
     * `cvc2_check` values that mean the code was checked and is wrong. `unprocessed`
     * is the default and is not a failure: the sandbox's Incorrect-CVC card reports it.
     */
    private const CVC_FAILURES = ['mismatch', 'missing'];

    private function __construct(private readonly ?string $state)
    {
    }

    public static function fromProcessorState(?string $state): self
    {
        return new self($state);
    }

    /**
     * The raw `processor_state`, as Edge sent it.
     */
    public function getProcessorState(): ?string
    {
        return $this->state;
    }

    /**
     * KIND_INTENT for an unconfirmed payment, KIND_DEMAND once confirmed, or null
     * when the state is unrecognised.
     */
    public function getKind(): ?string
    {
        return $this->row()[0] ?? null;
    }

    /**
     * Paid. Only `succeeded`.
     */
    public function isSuccessful(): bool
    {
        return $this->row()[1] ?? false;
    }

    /**
     * Confirmed and waiting for the card network: `confirmed`, `pending` or
     * `processing`. Not paid, and it may still fail.
     */
    public function isPending(): bool
    {
        return $this->row()[2] ?? false;
    }

    public function isCancelled(): bool
    {
        return $this->row()[3] ?? false;
    }

    /**
     * Declined or failed in processing. Edge accepts a new card and a new confirm on
     * the same demand.
     */
    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }

    /**
     * One of NotificationInterface's `completed`, `pending` or `failed`.
     */
    public function getNotificationStatus(): string
    {
        return $this->row()[4] ?? NotificationInterface::STATUS_PENDING;
    }

    /**
     * The payment settled but then changed hands again (`disputed`, `reversed`).
     * Neither counts as paid: check the order by hand.
     */
    public function needsReconciliation(): bool
    {
        return $this->row()[5] ?? false;
    }

    /**
     * A state this version doesn't know, or none at all. Never treat it as paid.
     */
    public function isUnrecognised(): bool
    {
        return $this->row() === null;
    }

    /**
     * A message for the shopper after a failed payment. Edge has no decline reason,
     * so this reads the card verification results: a CVC failure first, then an
     * address mismatch, otherwise a generic decline.
     */
    public static function declineMessage(
        ?string $cvc2Check,
        ?string $addressLine1Verification,
        ?string $postalCodeVerification
    ): string {
        if (in_array($cvc2Check, self::CVC_FAILURES, true)) {
            return self::MESSAGE_CVC_MISMATCH;
        }

        if ($addressLine1Verification === 'mismatch' || $postalCodeVerification === 'mismatch') {
            return self::MESSAGE_ADDRESS_MISMATCH;
        }

        return self::MESSAGE_DECLINED;
    }

    /**
     * @return array{string, bool, bool, bool, string, bool}|null
     */
    private function row(): ?array
    {
        return $this->state === null ? null : (self::STATES[$this->state] ?? null);
    }
}
