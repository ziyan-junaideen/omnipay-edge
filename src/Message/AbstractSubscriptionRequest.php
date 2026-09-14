<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Omnipay\Edge\Exception\InvalidFieldException;

/**
 * Parameters and reads shared by the payment subscription requests.
 *
 * Enum values are `Core.Transactions.Payment.subscription_module_attributes/0`. The
 * `weekly`/`monthly` list in the view's moduledoc is out of date.
 */
abstract class AbstractSubscriptionRequest extends AbstractRequest
{
    public const BILLING_PERIODS = [
        'one_day',
        'seven_days',
        'fourteen_days',
        'thirty_days',
        'one_month',
        'six_months',
        'twelve_months',
    ];

    public const PRORATION_NONE = 'none';

    public const PRORATION_CREATE_PRORATIONS = 'create_prorations';

    public const PRORATION_BEHAVIORS = [self::PRORATION_NONE, self::PRORATION_CREATE_PRORATIONS];

    /**
     * Lowercase letters, digits and underscores, starting with a letter: the format
     * Edge's message describes. Edge's own pattern is unanchored and accepts more.
     */
    private const SLUG_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * ISO 8601 with a time zone, to the microsecond at most.
     */
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    /**
     * The subscription id, as returned by CreateSubscriptionResponse::getSubscriptionReference().
     * It stays the same through confirm.
     */
    public function getSubscriptionReference(): ?string
    {
        return $this->getParameter('subscriptionReference');
    }

    public function setSubscriptionReference(?string $value): static
    {
        return $this->setParameter('subscriptionReference', $value);
    }

    /**
     * A name to group subscriptions by, such as `gold_monthly`.
     */
    public function getSlug(): ?string
    {
        return $this->getParameter('slug');
    }

    public function setSlug(?string $value): static
    {
        return $this->setParameter('slug', $value);
    }

    /**
     * One of BILLING_PERIODS.
     */
    public function getBillingPeriod(): ?string
    {
        return $this->getParameter('billingPeriod');
    }

    public function setBillingPeriod(?string $value): static
    {
        return $this->setParameter('billingPeriod', $value);
    }

    /**
     * One of PRORATION_BEHAVIORS.
     */
    public function getProrationBehavior(): ?string
    {
        return $this->getParameter('prorationBehavior');
    }

    public function setProrationBehavior(?string $value): static
    {
        return $this->setParameter('prorationBehavior', $value);
    }

    /**
     * When the first full billing period starts: a DateTimeInterface or an ISO 8601
     * string with a time zone. Sent in UTC.
     *
     * @return DateTimeInterface|string|null
     */
    public function getBillingCycleAnchorAt()
    {
        return $this->getParameter('billingCycleAnchorAt');
    }

    public function setBillingCycleAnchorAt(DateTimeInterface|string|null $value): static
    {
        return $this->setParameter('billingCycleAnchorAt', $value);
    }

    /**
     * The slug, trimmed, or null when none was given.
     *
     * @throws InvalidFieldException when the slug isn't in slug format
     */
    protected function resolveSlug(): ?string
    {
        $slug = $this->optionalString('slug');

        if ($slug !== null && preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidFieldException(
                'slug',
                'The slug parameter must start with a lowercase letter and contain only lowercase letters, '
                . 'digits and underscores.'
            );
        }

        return $slug;
    }

    /**
     * A parameter that must be one of the given values, or null when none was given.
     *
     * @param list<string> $values
     *
     * @throws InvalidFieldException
     */
    protected function resolveEnum(string $parameter, array $values): ?string
    {
        $value = $this->optionalString($parameter);

        if ($value !== null && !in_array($value, $values, true)) {
            throw new InvalidFieldException(
                $parameter,
                sprintf('The %s parameter must be one of: %s.', $parameter, implode(', ', $values))
            );
        }

        return $value;
    }

    /**
     * The billing cycle anchor as Edge formats timestamps (UTC, microseconds), or null
     * when none was given.
     *
     * @throws InvalidFieldException
     */
    protected function resolveBillingCycleAnchorAt(): ?string
    {
        $value = $this->getBillingCycleAnchorAt();

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $parsed = $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : null;

        if (is_string($value)) {
            $value = trim($value);

            try {
                $candidate = preg_match(self::TIMESTAMP_PATTERN, $value) === 1 ? new DateTimeImmutable($value) : null;
            } catch (Exception) {
                $candidate = null;
            }

            // DateTimeImmutable rolls an impossible date such as February 30 over.
            if ($candidate !== null && $candidate->format('Y-m-d\TH:i:s') === substr($value, 0, 19)) {
                $parsed = $candidate;
            }
        }

        if ($parsed === null) {
            throw new InvalidFieldException(
                'billingCycleAnchorAt',
                'The billingCycleAnchorAt parameter must be a DateTimeInterface or an ISO 8601 timestamp '
                . 'with a time zone, such as 2026-10-01T00:00:00Z.'
            );
        }

        return self::formatTimestamp($parsed);
    }

    /**
     * Edge's timestamp format: `2026-10-01T00:00:00.000000Z`.
     */
    public static function formatTimestamp(DateTimeInterface $timestamp): string
    {
        return DateTimeImmutable::createFromInterface($timestamp)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * GET /payment_subscriptions/{id}. Edge looks in subscriptions, then in unconfirmed
     * subscription intents.
     */
    protected function readSubscription(string $id, bool $includePaymentMethod): FetchSubscriptionResponse
    {
        return new FetchSubscriptionResponse(
            $this,
            $this->sendGet(
                self::path(AbstractSubscriptionResponse::TYPE, $id),
                $includePaymentMethod ? ['include' => 'payment_method'] : []
            )
        );
    }

    /**
     * GET /payment_demands?filter[payment_subscription]={id}.
     */
    protected function listCharges(string $id): ListSubscriptionChargesResponse
    {
        return new ListSubscriptionChargesResponse(
            $this,
            $this->sendGet(
                AbstractPaymentDemandResponse::TYPE,
                ['filter' => ['payment_subscription' => $id]]
            ),
            $id
        );
    }

    private function optionalString(string $parameter): ?string
    {
        $value = $this->getParameter($parameter);
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
