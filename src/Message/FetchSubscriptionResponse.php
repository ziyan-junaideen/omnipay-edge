<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * A payment subscription or subscription intent read by fetchSubscription().
 *
 * isSuccessful() means an active subscription. See AbstractSubscriptionResponse for the
 * kinds and statuses.
 */
class FetchSubscriptionResponse extends AbstractSubscriptionResponse
{
}
