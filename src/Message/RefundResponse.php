<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * The outcome of refund(), carrying the refund or the answer that explains its absence.
 *
 * isSuccessful() means refunded, which a new refund never is: Edge creates it `pending`
 * and settles it later. isPending() is true for a `pending` or `processing` refund, and
 * when the outcome couldn't be resolved (isUnresolved()). An unresolved refund may
 * exist: never send another one with a new key.
 */
class RefundResponse extends AbstractRefundResponse
{
    /** Edge created the refund, replayed it, or listed it under this key. */
    public const OUTCOME_REFUND = 'refund';

    /** Edge refused the refund, for example with a 422. Nothing was created. */
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * Both requests were unclear, but both got an answer from Edge itself and the
     * payment's refunds don't include the key.
     */
    public const OUTCOME_NOT_CREATED = 'not_created';

    /**
     * Both requests were unclear, and the payment's refunds couldn't be read or a
     * request may still be running on Edge.
     */
    public const OUTCOME_UNRESOLVED = 'unresolved';

    public const MESSAGE_NOT_CREATED = 'Edge did not create the refund. Retry with the same idempotency key.';

    public const MESSAGE_UNRESOLVED = 'Edge did not confirm whether the refund was created. '
        . 'Do not refund with a new key: retry with the same idempotency key, check listRefunds() '
        . 'or wait for the webhook.';

    private string $outcome;

    private int $attempts;

    private ?ListRefundsResponse $listing;

    /** @var array<string, mixed>|null */
    private ?array $listed;

    /**
     * @param array<string, mixed>|null $listed the listing entry that is the refund, when
     *                                          it was found by listing
     */
    public function __construct(
        RequestInterface $request,
        HttpResult $result,
        string $outcome,
        int $attempts,
        ?ListRefundsResponse $listing = null,
        ?array $listed = null
    ) {
        parent::__construct($request, $result);

        $this->outcome = $outcome;
        $this->attempts = $attempts;
        $this->listing = $listing;
        $this->listed = $listed;
    }

    /**
     * One of the OUTCOME_ constants.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * How many creates were sent: 1, or 2 when the first answer was unclear.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * The payment's refunds, when they were listed to resolve an unclear answer.
     */
    public function getListResponse(): ?ListRefundsResponse
    {
        return $this->listing;
    }

    public function isSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_REFUND && parent::isSuccessful();
    }

    public function isPending(): bool
    {
        return $this->outcome === self::OUTCOME_UNRESOLVED
            || ($this->outcome === self::OUTCOME_REFUND && parent::isPending());
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_REFUND && parent::isFailed();
    }

    /**
     * The refund may exist, and listing the payment's refunds didn't settle it.
     */
    public function isUnresolved(): bool
    {
        return $this->outcome === self::OUTCOME_UNRESOLVED;
    }

    /**
     * Only an unresolved outcome is ambiguous: every other one was settled, if need be
     * by listing the payment's refunds.
     */
    public function isAmbiguous(): bool
    {
        return $this->isUnresolved();
    }

    public function getMessage(): ?string
    {
        return match ($this->outcome) {
            self::OUTCOME_REFUND => null,
            self::OUTCOME_NOT_CREATED => self::MESSAGE_NOT_CREATED,
            self::OUTCOME_UNRESOLVED => self::MESSAGE_UNRESOLVED,
            default => parent::getMessage(),
        };
    }

    /**
     * The refund demand, or null unless the outcome is OUTCOME_REFUND. When the refund was
     * found by listing, this is its listing entry and getData() is the whole listing.
     *
     * @return array<string, mixed>|null
     */
    public function getResource(): ?array
    {
        if ($this->outcome !== self::OUTCOME_REFUND) {
            return null;
        }

        return $this->listed ?? parent::getResource();
    }
}
