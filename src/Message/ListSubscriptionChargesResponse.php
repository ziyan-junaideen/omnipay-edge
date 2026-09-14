<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use DateTimeImmutable;
use Exception;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Edge\PaymentState;

/**
 * The payment demands of one subscription, read by listSubscriptionCharges().
 *
 * isSuccessful() means the listing was read. Edge silently drops a filter it can't
 * apply and then lists every demand, so getCharges() only returns the demands whose
 * `payment_subscription` is the one asked for. Each charge is a payment demand: map its
 * `processor_state` through PaymentState.
 */
class ListSubscriptionChargesResponse extends AbstractResponse
{
    private string $subscriptionReference;

    /**
     * @param string $subscriptionReference the subscription the charges were listed for
     */
    public function __construct(RequestInterface $request, HttpResult $result, string $subscriptionReference)
    {
        parent::__construct($request, $result, AbstractPaymentDemandResponse::TYPE);

        $this->subscriptionReference = $subscriptionReference;
    }

    /**
     * The `payment_demands` resources for the requested subscription, as Edge sent them.
     * Empty unless isSuccessful().
     *
     * @return list<array<string, mixed>>
     */
    public function getCharges(): array
    {
        if (!$this->isSuccessful() || $this->subscriptionReference === '') {
            return [];
        }

        $charges = [];

        /** @var array<string, mixed> $resource */
        foreach ($this->data['data'] as $resource) {
            $related = self::relationshipIdOf($resource, 'payment_subscription');

            // Ids are UUIDs, which Edge matches case-insensitively.
            if ($related !== null && strcasecmp($related, $this->subscriptionReference) === 0) {
                $charges[] = $resource;
            }
        }

        return $charges;
    }

    /**
     * The charge with this id, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getCharge(string $id): ?array
    {
        foreach ($this->getCharges() as $charge) {
            if (strcasecmp((string) $charge['id'], $id) === 0) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * The most recently created charge, or null when there is none or a charge's
     * `created_at` can't be read (so the latest can't be told).
     *
     * Edge's own "last charge" is ordered by `billing_due_at`, which the demand view
     * doesn't expose. Creation order matches it in practice, and Edge only retries a
     * charge that is `failed` either way.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestCharge(): ?array
    {
        $latest = null;
        $latestAt = null;

        foreach ($this->getCharges() as $charge) {
            $createdAt = self::timestamp(self::attributeOf($charge, 'created_at'));

            if ($createdAt === null) {
                return null;
            }

            if ($latestAt === null || $createdAt > $latestAt) {
                $latest = $charge;
                $latestAt = $createdAt;
            }
        }

        return $latest;
    }

    /**
     * Whether a charge is still `pending` or `processing` at Edge.
     */
    public function hasChargeInProgress(): bool
    {
        foreach ($this->getCharges() as $charge) {
            $state = self::attributeOf($charge, 'processor_state');

            if (is_string($state) && PaymentState::fromProcessorState($state)->isPending()) {
                return true;
            }
        }

        return false;
    }

    /**
     * An attribute of a resource object, such as a charge's `processor_state`.
     *
     * @param array<string, mixed> $resource
     */
    public static function attributeOf(array $resource, string $name): mixed
    {
        $attributes = $resource['attributes'] ?? null;

        return is_array($attributes) ? ($attributes[$name] ?? null) : null;
    }

    protected function expectsCollection(): bool
    {
        return true;
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
