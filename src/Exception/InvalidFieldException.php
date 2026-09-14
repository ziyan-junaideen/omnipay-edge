<?php

declare(strict_types=1);

namespace Omnipay\Edge\Exception;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * A request parameter failed a local check before anything was sent.
 *
 * getField() names the parameter, using Omnipay's CreditCard names (such as
 * `billingState`) for values read from a card, so the message can sit next to the
 * matching input.
 */
class InvalidFieldException extends InvalidRequestException
{
    private string $field;

    public function __construct(string $field, string $message)
    {
        parent::__construct($message);

        $this->field = $field;
    }

    public function getField(): string
    {
        return $this->field;
    }
}
