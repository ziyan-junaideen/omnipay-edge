<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Common\Message\RequestInterface;

/**
 * The refund demands of one payment demand, read by listRefunds().
 *
 * isSuccessful() means the listing was read. Edge silently drops a filter it can't
 * apply and then lists every refund, so getRefunds() only returns the refunds whose
 * `payment_demand` is the one asked for.
 */
class ListRefundsResponse extends AbstractResponse
{
    private string $paymentDemandReference;

    /**
     * @param string $paymentDemandReference the payment demand the refunds were listed for
     */
    public function __construct(RequestInterface $request, HttpResult $result, string $paymentDemandReference)
    {
        parent::__construct($request, $result, AbstractRefundResponse::TYPE);

        $this->paymentDemandReference = $paymentDemandReference;
    }

    /**
     * The `refund_demands` resources for the requested payment demand, as Edge sent
     * them. Empty unless isSuccessful().
     *
     * @return list<array<string, mixed>>
     */
    public function getRefunds(): array
    {
        if (!$this->isSuccessful() || $this->paymentDemandReference === '') {
            return [];
        }

        $refunds = [];

        /** @var array<string, mixed> $resource */
        foreach ($this->data['data'] as $resource) {
            $related = self::relationshipIdOf($resource, 'payment_demand');

            // Ids are UUIDs, which Edge matches case-insensitively.
            if ($related !== null && strcasecmp($related, $this->paymentDemandReference) === 0) {
                $refunds[] = $resource;
            }
        }

        return $refunds;
    }

    /**
     * The refund made with this idempotency key, compared with hash_equals, or null
     * when there is none. Keys are unique within a merchant, so there is at most one.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        if ($idempotencyKey === '') {
            return null;
        }

        foreach ($this->getRefunds() as $resource) {
            $attributes = $resource['attributes'] ?? null;
            $key = is_array($attributes) ? ($attributes['idempotency_key'] ?? null) : null;

            if (is_string($key) && hash_equals($key, $idempotencyKey)) {
                return $resource;
            }
        }

        return null;
    }

    protected function expectsCollection(): bool
    {
        return true;
    }
}
