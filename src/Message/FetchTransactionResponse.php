<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\PaymentState;

/**
 * A payment demand read by fetchTransaction().
 *
 * isSuccessful() means paid: only a `succeeded` demand. A confirmed demand is
 * `pending` until Edge sends it to the card network, and a decline arrives later as
 * `failed`. See PaymentState for the full mapping, and getPaymentState() for whether
 * the demand was read at all.
 */
class FetchTransactionResponse extends AbstractPaymentDemandResponse
{
    public function isSuccessful(): bool
    {
        return $this->getPaymentState()?->isSuccessful() ?? false;
    }

    public function isPending(): bool
    {
        return $this->getPaymentState()?->isPending() ?? false;
    }

    public function isCancelled(): bool
    {
        return $this->getPaymentState()?->isCancelled() ?? false;
    }

    /**
     * The demand was declined or failed in processing. The shopper can verify a card
     * again in the payment form and the same demand can be confirmed again.
     */
    public function isFailed(): bool
    {
        return $this->getPaymentState()?->isFailed() ?? false;
    }

    /**
     * For a failed demand, a message for the shopper built from the card verification
     * results (Edge has no decline reason). Otherwise the error, if any.
     */
    public function getMessage(): ?string
    {
        if (!$this->isFailed()) {
            return parent::getMessage();
        }

        return PaymentState::declineMessage(
            $this->getCvc2Check(),
            $this->getAddressLine1Verification(),
            $this->getPostalCodeVerification()
        );
    }

    /**
     * When the demand succeeded, as Edge's ISO 8601 UTC timestamp. Null otherwise.
     */
    public function getSucceededAt(): ?string
    {
        return $this->stringAttribute('succeeded_at');
    }

    /**
     * `match`, `mismatch`, `unprocessed` (the default, not a failure), `missing`,
     * `unavailable` or `unresponsive`.
     */
    public function getCvc2Check(): ?string
    {
        return $this->stringAttribute('cvc2_check');
    }

    /**
     * `match`, `mismatch`, `retry`, `unavailable` or `unverified` (the default).
     */
    public function getAddressLine1Verification(): ?string
    {
        return $this->stringAttribute('address_line1_verification');
    }

    /**
     * `match`, `mismatch`, `retry`, `unavailable` or `unverified` (the default).
     */
    public function getPostalCodeVerification(): ?string
    {
        return $this->stringAttribute('postal_code_verification');
    }

    /**
     * The included `payment_methods` resource, when the request set
     * `includePaymentMethod` and a card has been collected.
     *
     * @return array<string, mixed>|null
     */
    public function getPaymentMethod(): ?array
    {
        $id = $this->getCardReference();

        return $id === null ? null : $this->getIncluded(CardResponse::TYPE, $id);
    }
}
