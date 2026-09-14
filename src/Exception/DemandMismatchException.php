<?php

declare(strict_types=1);

namespace Omnipay\Edge\Exception;

use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Edge\Message\AbstractResponse;

/**
 * The payment demand Edge holds under a transactionReference doesn't match what the
 * caller expects to charge: another amount, currency or idempotency key.
 *
 * Thrown before anything is confirmed. The demand was made for other facts (a stale
 * or mixed-up id, or a cart that changed after purchase()), so create a new demand
 * with a new key instead.
 */
class DemandMismatchException extends InvalidResponseException
{
    private AbstractResponse $response;

    /** @var list<string> */
    private array $mismatches;

    /**
     * @param list<string> $mismatches Edge attribute names
     * @param array<string, string> $details attribute => description, for the message
     */
    public function __construct(AbstractResponse $response, array $mismatches, array $details = [])
    {
        $described = array_map(
            static fn (string $name): string => isset($details[$name]) ? $name . ' (' . $details[$name] . ')' : $name,
            $mismatches
        );

        parent::__construct(sprintf(
            'Payment demand %s does not match the expected payment: %s. Nothing was confirmed.',
            $response->getResourceId() ?? '(unknown)',
            implode('; ', $described)
        ));

        $this->response = $response;
        $this->mismatches = $mismatches;
    }

    /**
     * The response carrying the demand as Edge has it.
     */
    public function getResponse(): AbstractResponse
    {
        return $this->response;
    }

    /**
     * The attributes that differ: `amount_cents`, `amount_currency` or
     * `idempotency_key`.
     *
     * @return list<string>
     */
    public function getMismatches(): array
    {
        return $this->mismatches;
    }
}
