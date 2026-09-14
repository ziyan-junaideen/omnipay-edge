<?php

declare(strict_types=1);

namespace Omnipay\Edge\Exception;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * A webhook delivery was refused: the signature is missing, legacy, stale or wrong, or
 * the verified body isn't an Edge event.
 *
 * Nothing in the delivery can be trusted. A configuration problem, such as a missing
 * webhookSecret, throws a plain InvalidRequestException instead, so a receiver can ask
 * Edge to retry those.
 */
class InvalidWebhookException extends InvalidRequestException
{
}
