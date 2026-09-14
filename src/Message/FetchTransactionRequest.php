<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * GET /payment_demands/{transactionReference}, for polling a demand.
 *
 * Webhooks are the source of truth. Edge returns the confirmed demand once one
 * exists, otherwise the unconfirmed intent; both have the same id.
 */
class FetchTransactionRequest extends AbstractRequest
{
    /**
     * Also return the payment method, through `include=payment_method`.
     *
     * @throws InvalidFieldException when the value isn't a boolean or a boolean string
     */
    public function getIncludePaymentMethod(): bool
    {
        $value = $this->getParameter('includePaymentMethod');

        if ($value === null || is_bool($value)) {
            return (bool) $value;
        }

        // Config often arrives as strings, and (bool) "false" is true.
        $parsed = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        if ($parsed === null) {
            throw new InvalidFieldException(
                'includePaymentMethod',
                'The includePaymentMethod parameter must be a boolean.'
            );
        }

        return $parsed;
    }

    public function setIncludePaymentMethod(bool|string|null $value): static
    {
        return $this->setParameter('includePaymentMethod', $value);
    }

    protected function getRequestData(): ?array
    {
        $this->requireString('transactionReference');
        $this->getIncludePaymentMethod();

        return null;
    }

    public function send(): FetchTransactionResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): FetchTransactionResponse
    {
        $id = trim((string) $this->getTransactionReference());
        $query = $this->getIncludePaymentMethod() ? ['include' => 'payment_method'] : [];

        return $this->response = new FetchTransactionResponse(
            $this,
            $this->sendGet(self::path(AbstractPaymentDemandResponse::TYPE, $id), $query)
        );
    }
}
