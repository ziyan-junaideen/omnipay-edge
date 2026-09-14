<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * An Edge payment method (`CoreHTTP.Views.PaymentMethods`).
 *
 * isSuccessful() means the payment method was read, not that it is usable. Check
 * isConfirmed() before confirming a payment demand with it.
 */
class CardResponse extends AbstractResponse
{
    public const TYPE = 'payment_methods';

    /**
     * `external_state` values (`Core.Consumer.PaymentMethod`).
     */
    public const STATE_PENDING = 'pending';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_FAILED = 'failed';

    public const STATE_ERRORED = 'errored';

    public function __construct(RequestInterface $request, HttpResult $result)
    {
        parent::__construct($request, $result, self::TYPE);
    }

    public function getCardReference(): ?string
    {
        return $this->getResourceId();
    }

    /**
     * `pending`, `confirmed`, `failed` or `errored`.
     */
    public function getExternalState(): ?string
    {
        return $this->stringAttribute('external_state');
    }

    /**
     * The card was verified in the hosted payment form.
     */
    public function isConfirmed(): bool
    {
        return $this->getExternalState() === self::STATE_CONFIRMED;
    }

    public function isDiscarded(): bool
    {
        return $this->stringAttribute('discarded_at') !== null;
    }

    public function getLastFour(): ?string
    {
        return $this->stringAttribute('last_four');
    }

    public function getCardBin(): ?string
    {
        return $this->stringAttribute('card_bin');
    }

    /**
     * The card brand or account kind, such as `visa`, `amex` or `checking`.
     */
    public function getKind(): ?string
    {
        return $this->stringAttribute('kind');
    }

    public function getNickname(): ?string
    {
        return $this->stringAttribute('nickname');
    }

    public function getCustomerReference(): ?string
    {
        return $this->getRelationshipId('customer');
    }

    public function getAddressReference(): ?string
    {
        return $this->getRelationshipId('address');
    }
}
