<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * The outcome of completeSubscription(), carrying the last subscription read or confirm.
 *
 * isSuccessful() means an active subscription: its first charge has already succeeded.
 * A confirm that lands leaves the subscription `pending`, so isPending() is true, and
 * the first charge may still fail. isPending() is also true when the outcome couldn't be
 * resolved (isUnresolved()); never set up another subscription for it.
 */
class CompleteSubscriptionResponse extends FetchSubscriptionResponse
{
    /** The response is the subscription; its status decides the outcome. */
    public const OUTCOME_SUBSCRIPTION = 'subscription';

    /** The subscription couldn't be read, so nothing was confirmed. */
    public const OUTCOME_NOT_READ = 'not_read';

    /** No card has been verified in the payment form, so nothing was confirmed. */
    public const OUTCOME_PAYMENT_METHOD_UNVERIFIED = 'payment_method_unverified';

    /** Edge returned neither an intent nor a subscription, so nothing was confirmed. */
    public const OUTCOME_NOT_CONFIRMABLE = 'not_confirmable';

    /** Edge refused the confirm, for example with a 422. */
    public const OUTCOME_REJECTED = 'rejected';

    /** The confirm may have landed, and reading the subscription again didn't settle it. */
    public const OUTCOME_UNRESOLVED = 'unresolved';

    public const MESSAGE_PAYMENT_METHOD_UNVERIFIED = 'The card has not been verified yet. '
        . 'Verify it in the payment form, then try again.';

    public const MESSAGE_NOT_CONFIRMABLE = 'Edge did not return a subscription intent or a subscription, '
        . 'so nothing was confirmed.';

    public const MESSAGE_UNRESOLVED = 'Edge did not confirm whether the subscription was set up. '
        . 'Do not set up another one: call completeSubscription() again, or check fetchSubscription().';

    private string $outcome;

    private int $confirmAttempts;

    private ?ListSubscriptionChargesResponse $charges;

    public function __construct(
        RequestInterface $request,
        HttpResult $result,
        string $outcome,
        int $confirmAttempts,
        ?ListSubscriptionChargesResponse $charges = null
    ) {
        parent::__construct($request, $result);

        $this->outcome = $outcome;
        $this->confirmAttempts = $confirmAttempts;
        $this->charges = $charges;
    }

    /**
     * One of the OUTCOME_ constants.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * How many confirms were sent: 0, 1, or 2 when an unclear first answer was followed
     * by a read that still showed the intent.
     */
    public function getConfirmAttempts(): int
    {
        return $this->confirmAttempts;
    }

    /**
     * An active subscription.
     */
    public function isSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_SUBSCRIPTION && parent::isSuccessful();
    }

    /**
     * A subscription waiting for its first charge to succeed, or an unresolved outcome.
     */
    public function isPending(): bool
    {
        return $this->outcome === self::OUTCOME_UNRESOLVED
            || ($this->outcome === self::OUTCOME_SUBSCRIPTION && parent::isPending());
    }

    public function isCancelled(): bool
    {
        return $this->outcome === self::OUTCOME_SUBSCRIPTION && parent::isCancelled();
    }

    public function isPaused(): bool
    {
        return $this->outcome === self::OUTCOME_SUBSCRIPTION && parent::isPaused();
    }

    /**
     * The confirm may have landed but reading the subscription back didn't show it.
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
     * The intent needs a card verified in the payment form before it can be completed.
     */
    public function isAwaitingPaymentMethod(): bool
    {
        return $this->outcome === self::OUTCOME_PAYMENT_METHOD_UNVERIFIED;
    }

    public function getMessage(): ?string
    {
        return match ($this->outcome) {
            self::OUTCOME_SUBSCRIPTION => null,
            self::OUTCOME_PAYMENT_METHOD_UNVERIFIED => self::MESSAGE_PAYMENT_METHOD_UNVERIFIED,
            self::OUTCOME_NOT_CONFIRMABLE => self::MESSAGE_NOT_CONFIRMABLE,
            self::OUTCOME_UNRESOLVED => self::MESSAGE_UNRESOLVED,
            default => parent::getMessage(),
        };
    }

    /**
     * The subscription's charges, listed once it was confirmed: the payment demands
     * Edge had created by then, such as a prorated first charge. A first charge made by
     * Edge's background job may not be listed yet. Empty when the listing couldn't be
     * read; see getChargesResponse().
     *
     * @return list<array<string, mixed>>
     */
    public function getCharges(): array
    {
        return $this->charges?->getCharges() ?? [];
    }

    /**
     * The charge listing, or null unless the outcome is OUTCOME_SUBSCRIPTION.
     */
    public function getChargesResponse(): ?ListSubscriptionChargesResponse
    {
        return $this->charges;
    }

    /**
     * The subscription id, or the one the request named when Edge returned none.
     */
    public function getSubscriptionReference(): ?string
    {
        $id = parent::getSubscriptionReference();

        if ($id !== null || !$this->request instanceof CompleteSubscriptionRequest) {
            return $id;
        }

        $reference = trim((string) $this->request->getSubscriptionReference());

        return $reference === '' ? null : $reference;
    }
}
