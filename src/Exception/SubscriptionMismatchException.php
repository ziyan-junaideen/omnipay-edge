<?php

declare(strict_types=1);

namespace Omnipay\Edge\Exception;

use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Edge\Message\AbstractResponse;

/**
 * The subscription Edge holds under a subscriptionReference doesn't match what the
 * caller expects to set up: another amount, currency or idempotency key.
 *
 * Thrown before anything is confirmed. Create a new subscription intent with a new key
 * instead.
 */
class SubscriptionMismatchException extends InvalidResponseException
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
            'Subscription %s does not match the expected subscription: %s. Nothing was confirmed.',
            $response->getResourceId() ?? '(unknown)',
            implode('; ', $described)
        ));

        $this->response = $response;
        $this->mismatches = $mismatches;
    }

    /**
     * The response carrying the subscription as Edge has it.
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
