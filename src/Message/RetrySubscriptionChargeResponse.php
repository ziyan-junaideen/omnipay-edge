<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\PaymentState;

/**
 * The outcome of retrySubscriptionCharge(), carrying the subscription and the charge the
 * outcome is about.
 *
 * isSuccessful() means the retried charge is already `succeeded`. A retry that lands
 * puts the charge back to `pending`, so isPending() is true and the card network may
 * still decline it. isPending() is also true when the outcome couldn't be resolved
 * (isUnresolved()); don't retry again until the charge settles.
 */
class RetrySubscriptionChargeResponse extends FetchSubscriptionResponse
{
    /** Edge retried the charge; getCharge() is the charge as read back. */
    public const OUTCOME_RETRIED = 'retried';

    /** The subscription or its charges couldn't be read, so nothing was sent. */
    public const OUTCOME_NOT_READ = 'not_read';

    /** The subscription has no failed charge Edge can retry, so nothing was sent. */
    public const OUTCOME_NOT_RETRYABLE = 'not_retryable';

    /** Edge refused the retry, for example with a 405. */
    public const OUTCOME_REJECTED = 'rejected';

    /** The retry may have landed, and the charge read back hasn't changed. */
    public const OUTCOME_UNRESOLVED = 'unresolved';

    public const MESSAGE_FIRST_CHARGE = 'The subscription is still pending: its first charge has not succeeded. '
        . 'Edge can only retry a charge of an active subscription.';

    public const MESSAGE_NOT_ACTIVE = 'Edge can only retry a charge of an active subscription, not a %s one.';

    public const MESSAGE_CHARGE_IN_PROGRESS = 'A charge of this subscription is still being processed.';

    public const MESSAGE_NOTHING_TO_RETRY = 'The latest charge of this subscription has not failed, so there is '
        . 'nothing to retry.';

    public const MESSAGE_REJECTED = 'Edge refused to retry the charge: the subscription is not active or its '
        . 'latest charge has not failed.';

    public const MESSAGE_UNRESOLVED = 'Edge did not confirm whether the charge was retried. Do not retry again '
        . 'yet: check listSubscriptionCharges() or wait for the webhook.';

    private string $outcome;

    private int $attempts;

    private ?ListSubscriptionChargesResponse $charges;

    /** @var array<string, mixed>|null */
    private ?array $charge;

    private ?string $reason;

    /**
     * @param array<string, mixed>|null $charge the charge the outcome is about
     * @param string|null $reason why nothing could be retried, for OUTCOME_NOT_RETRYABLE
     */
    public function __construct(
        RequestInterface $request,
        HttpResult $result,
        string $outcome,
        int $attempts,
        ?ListSubscriptionChargesResponse $charges = null,
        ?array $charge = null,
        ?string $reason = null
    ) {
        parent::__construct($request, $result);

        $this->outcome = $outcome;
        $this->attempts = $attempts;
        $this->charges = $charges;
        $this->charge = $charge;
        $this->reason = $reason;
    }

    /**
     * One of the OUTCOME_ constants.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * How many retries were sent: 0 or 1.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * The retried charge is already `succeeded`.
     */
    public function isSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_RETRIED && $this->getChargeState() === PaymentState::SUCCEEDED;
    }

    /**
     * The retried charge is waiting for the card network, or the outcome is unresolved.
     */
    public function isPending(): bool
    {
        if ($this->outcome === self::OUTCOME_UNRESOLVED) {
            return true;
        }

        $state = $this->getChargeState();

        return $this->outcome === self::OUTCOME_RETRIED
            && ($state === null || PaymentState::fromProcessorState($state)->isPending());
    }

    /**
     * The retried charge was declined again.
     */
    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_RETRIED && $this->getChargeState() === PaymentState::FAILED;
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function isPaused(): bool
    {
        return false;
    }

    public function isUnresolved(): bool
    {
        return $this->outcome === self::OUTCOME_UNRESOLVED;
    }

    public function isAmbiguous(): bool
    {
        return $this->isUnresolved() || parent::isAmbiguous();
    }

    public function getMessage(): ?string
    {
        if ($this->outcome === self::OUTCOME_RETRIED) {
            return $this->isFailed() && $this->charge !== null
                ? PaymentState::declineMessage(
                    self::stringOf($this->charge, 'cvc2_check'),
                    self::stringOf($this->charge, 'address_line1_verification'),
                    self::stringOf($this->charge, 'postal_code_verification')
                )
                : null;
        }

        return match (true) {
            $this->outcome === self::OUTCOME_NOT_RETRYABLE => $this->reason,
            $this->outcome === self::OUTCOME_UNRESOLVED => self::MESSAGE_UNRESOLVED,
            $this->outcome === self::OUTCOME_REJECTED && $this->getHttpStatus() === 405 => self::MESSAGE_REJECTED,
            $this->outcome === self::OUTCOME_NOT_READ && $this->charges !== null => $this->charges->getMessage(),
            default => parent::getMessage(),
        };
    }

    /**
     * The charge the outcome is about: the retried charge as read back, or the latest
     * charge that couldn't be retried. Null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function getCharge(): ?array
    {
        return $this->charge;
    }

    /**
     * The payment demand id of getCharge(). Track it with fetchTransaction().
     */
    public function getChargeReference(): ?string
    {
        $id = $this->charge['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The `processor_state` of getCharge().
     */
    public function getChargeState(): ?string
    {
        return $this->charge === null ? null : self::stringOf($this->charge, 'processor_state');
    }

    /**
     * The last charge listing read, or null when the charges weren't listed.
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

        if ($id !== null || !$this->request instanceof RetrySubscriptionChargeRequest) {
            return $id;
        }

        $reference = trim((string) $this->request->getSubscriptionReference());

        return $reference === '' ? null : $reference;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private static function stringOf(array $resource, string $name): ?string
    {
        $value = ListSubscriptionChargesResponse::attributeOf($resource, $name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
