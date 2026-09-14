<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * GET /payment_subscriptions/{subscriptionReference}. Edge returns the subscription
 * once one exists, otherwise the unconfirmed intent; both have the same id.
 */
class FetchSubscriptionRequest extends AbstractSubscriptionRequest
{
    /**
     * Also return the payment method, through `include=payment_method`.
     *
     * @throws InvalidFieldException when the value isn't a boolean or a boolean string
     */
    public function getIncludePaymentMethod(): bool
    {
        return $this->booleanParameter('includePaymentMethod');
    }

    public function setIncludePaymentMethod(bool|string|null $value): static
    {
        return $this->setParameter('includePaymentMethod', $value);
    }

    protected function getRequestData(): ?array
    {
        $this->requireString('subscriptionReference');
        $this->getIncludePaymentMethod();

        return null;
    }

    public function send(): FetchSubscriptionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): FetchSubscriptionResponse
    {
        $id = $this->requireString('subscriptionReference');

        return $this->response = $this->readSubscription($id, $this->getIncludePaymentMethod());
    }
}
