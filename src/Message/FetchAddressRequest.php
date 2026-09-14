<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

/**
 * GET /consumer_addresses/{addressReference}.
 */
class FetchAddressRequest extends AbstractRequest
{
    public function getAddressReference(): ?string
    {
        return $this->getParameter('addressReference');
    }

    public function setAddressReference(?string $value): static
    {
        return $this->setParameter('addressReference', $value);
    }

    protected function getRequestData(): ?array
    {
        $this->requireString('addressReference');

        return null;
    }

    public function send(): AddressResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): AddressResponse
    {
        $id = trim((string) $this->getAddressReference());

        return $this->response = new AddressResponse($this, $this->sendGet(self::path('consumer_addresses', $id)));
    }
}
