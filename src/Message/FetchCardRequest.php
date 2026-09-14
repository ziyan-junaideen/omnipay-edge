<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /payment_methods/{cardReference}.
 *
 * Read-only: payment methods can only be created in Edge's hosted payment form.
 */
class FetchCardRequest extends AbstractRequest
{
    protected function getRequestData(): ?array
    {
        $this->requireString('cardReference');

        return null;
    }

    public function send(): CardResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): CardResponse
    {
        $id = trim((string) $this->getCardReference());

        return $this->response = new CardResponse($this, $this->sendGet(self::path('payment_methods', $id)));
    }
}
