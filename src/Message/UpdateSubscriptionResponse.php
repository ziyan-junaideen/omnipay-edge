<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * The outcome of updateSubscription().
 *
 * isSuccessful() means Edge updated the intent. A 405 means the intent was already
 * confirmed into a subscription, which Edge doesn't let the API change.
 */
class UpdateSubscriptionResponse extends AbstractSubscriptionResponse
{
    public const MESSAGE_ALREADY_CONFIRMED = 'The subscription has already been confirmed and can no longer be '
        . 'updated. Change it in the Edge dashboard, or set up a new subscription.';

    /**
     * Edge returned the updated intent.
     */
    public function isSuccessful(): bool
    {
        return $this->isIntent();
    }

    public function isPending(): bool
    {
        return false;
    }

    public function isCancelled(): bool
    {
        return false;
    }

    /**
     * Edge refused the update because the subscription is no longer an intent (405).
     */
    public function isAlreadyConfirmed(): bool
    {
        return $this->getHttpStatus() === 405;
    }

    public function getMessage(): ?string
    {
        return $this->isAlreadyConfirmed() ? self::MESSAGE_ALREADY_CONFIRMED : parent::getMessage();
    }
}
