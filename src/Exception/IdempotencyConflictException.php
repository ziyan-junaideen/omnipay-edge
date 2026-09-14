<?php

declare(strict_types=1);

namespace Omnipay\Edge\Exception;

use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Edge\Message\AbstractResponse;

/**
 * Edge returned an existing resource for an idempotency key that was sent with
 * different facts, such as another amount.
 *
 * For payment demands, Edge matches the key on its value alone and hands back the
 * original resource without an error, so continuing would collect the wrong payment.
 * Derive a new key from the current facts (see IdempotencyKey::fingerprint()) and try
 * again.
 *
 * For refund demands, Edge itself answers a reused key with other facts with a 422, so
 * this is only thrown when a refund it returns or lists under the key still differs
 * from the request. Check the refunds already made before refunding with a new key.
 */
class IdempotencyConflictException extends InvalidResponseException
{
    private AbstractResponse $response;

    /** @var array<string, array{sent: mixed, edge: mixed}> */
    private array $mismatches;

    /**
     * @param array<string, array{sent: mixed, edge: mixed}> $mismatches
     */
    public function __construct(AbstractResponse $response, array $mismatches)
    {
        $details = [];

        foreach ($mismatches as $name => ['sent' => $sent, 'edge' => $edge]) {
            $details[] = sprintf('%s (sent %s, Edge has %s)', $name, self::describe($sent), self::describe($edge));
        }

        parent::__construct(sprintf(
            'The idempotency key was already used for resource %s with different facts: %s. '
            . 'Use a new key for the new facts.',
            $response->getResourceId() ?? '(unknown)',
            implode('; ', $details)
        ));

        $this->response = $response;
        $this->mismatches = $mismatches;
    }

    /**
     * The response carrying the existing resource.
     */
    public function getResponse(): AbstractResponse
    {
        return $this->response;
    }

    /**
     * @return array<string, array{sent: mixed, edge: mixed}>
     */
    public function getMismatches(): array
    {
        return $this->mismatches;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : 'nothing';
    }
}
