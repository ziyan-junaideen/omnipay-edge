<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\PaymentState;

/**
 * The outcome of completePurchase(), carrying the last demand read or confirm.
 *
 * isSuccessful() means paid, and is only true when Edge already shows the demand
 * `succeeded`. A confirm that lands leaves the demand `pending`: isPending() is true
 * and a decline may still arrive as `failed`. isPending() is also true when the
 * confirm's outcome couldn't be resolved (isUnresolved()); poll fetchTransaction()
 * or wait for the webhook, and never charge again.
 */
class CompletePurchaseResponse extends FetchTransactionResponse
{
    /** The response is the demand; its processor_state decides the outcome. */
    public const OUTCOME_DEMAND = 'demand';

    /** The demand couldn't be read, so nothing was confirmed. */
    public const OUTCOME_NOT_READ = 'not_read';

    /**
     * No card is verified for this attempt, so nothing was confirmed: none has been
     * verified yet, or a `failed` demand still has the card that was declined.
     */
    public const OUTCOME_PAYMENT_METHOD_UNVERIFIED = 'payment_method_unverified';

    /** The demand is in a state that can't be confirmed, so nothing was confirmed. */
    public const OUTCOME_NOT_CONFIRMABLE = 'not_confirmable';

    /** Edge refused the confirm, for example with a 422. */
    public const OUTCOME_REJECTED = 'rejected';

    /** The confirm may have landed, and reading the demand again didn't settle it. */
    public const OUTCOME_UNRESOLVED = 'unresolved';

    public const MESSAGE_PAYMENT_METHOD_UNVERIFIED = 'The card has not been verified yet. '
        . 'Verify it in the payment form, then try again.';

    public const MESSAGE_RETRY_NEEDS_NEW_CARD = 'The payment was declined. '
        . 'Verify a card again in the payment form, then try again.';

    public const MESSAGE_UNRESOLVED = 'Edge did not confirm whether the payment was submitted. '
        . 'Do not charge again: poll fetchTransaction() or wait for the webhook.';

    public const MESSAGE_NEEDS_RECONCILIATION = 'The payment was disputed or reversed. Check the order with Edge.';

    private string $outcome;

    private int $confirmAttempts;

    private ?string $attemptedCardReference;

    public function __construct(
        RequestInterface $request,
        HttpResult $result,
        string $outcome,
        int $confirmAttempts,
        ?string $attemptedCardReference = null
    ) {
        parent::__construct($request, $result);

        $this->outcome = $outcome;
        $this->confirmAttempts = $confirmAttempts;
        $this->attemptedCardReference = $attemptedCardReference;
    }

    /**
     * One of the OUTCOME_ constants.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * How many confirms were sent: 0, 1, or 2 when an unclear first answer was
     * followed by a demand that provably hadn't changed.
     */
    public function getConfirmAttempts(): int
    {
        return $this->confirmAttempts;
    }

    /**
     * The payment method id the confirm was sent for, or null when none was sent.
     * Store it with the order whenever it isn't null, and pass it as
     * `previousCardReference` next time: a `failed` demand is only retried with
     * another card.
     */
    public function getAttemptedCardReference(): ?string
    {
        return $this->attemptedCardReference;
    }

    /**
     * Paid: the demand is already `succeeded`.
     */
    public function isSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_DEMAND && parent::isSuccessful();
    }

    /**
     * Not paid yet: the demand is `pending` or `processing`, or the outcome is
     * unresolved. Keep the order pending and let the webhook settle it.
     */
    public function isPending(): bool
    {
        return $this->outcome === self::OUTCOME_UNRESOLVED
            || ($this->outcome === self::OUTCOME_DEMAND && parent::isPending());
    }

    public function isCancelled(): bool
    {
        return false;
    }

    /**
     * The card network declined the payment. The shopper can verify a card again and
     * the same demand can be completed again.
     */
    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_DEMAND && parent::isFailed();
    }

    /**
     * The confirm may have landed but reading the demand back didn't show it. Don't
     * charge again: poll fetchTransaction() or wait for the webhook.
     */
    public function isUnresolved(): bool
    {
        return $this->outcome === self::OUTCOME_UNRESOLVED;
    }

    public function isAmbiguous(): bool
    {
        return $this->isUnresolved() || parent::isAmbiguous();
    }

    /**
     * The demand needs a verified card before it can be completed: none was verified
     * yet, or the last one was declined.
     */
    public function isAwaitingPaymentMethod(): bool
    {
        return $this->outcome === self::OUTCOME_PAYMENT_METHOD_UNVERIFIED || $this->isFailed();
    }

    /**
     * The demand is `disputed` or `reversed`. Neither is paid: check the order by hand.
     */
    public function needsReconciliation(): bool
    {
        return $this->outcome === self::OUTCOME_DEMAND && ($this->getPaymentState()?->needsReconciliation() ?? false);
    }

    public function getMessage(): ?string
    {
        $unverified = $this->outcome === self::OUTCOME_PAYMENT_METHOD_UNVERIFIED;

        return match (true) {
            $unverified && $this->getProcessorState() === PaymentState::FAILED => self::MESSAGE_RETRY_NEEDS_NEW_CARD,
            $unverified => self::MESSAGE_PAYMENT_METHOD_UNVERIFIED,
            $this->outcome === self::OUTCOME_UNRESOLVED => self::MESSAGE_UNRESOLVED,
            $this->outcome === self::OUTCOME_NOT_CONFIRMABLE => sprintf(
                'The payment demand cannot be completed from the %s state.',
                $this->getProcessorState() ?? 'unknown'
            ),
            $this->needsReconciliation() => self::MESSAGE_NEEDS_RECONCILIATION,
            default => parent::getMessage(),
        };
    }

    /**
     * The demand id, or the one the request named when Edge returned no demand.
     */
    public function getTransactionReference(): ?string
    {
        $id = parent::getTransactionReference();

        if ($id !== null || !$this->request instanceof CompletePurchaseRequest) {
            return $id;
        }

        $reference = trim((string) $this->request->getTransactionReference());

        return $reference === '' ? null : $reference;
    }
}
