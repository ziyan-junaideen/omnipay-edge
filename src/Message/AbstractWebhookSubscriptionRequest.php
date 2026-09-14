<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Omnipay\Edge\Exception\InvalidFieldException;
use Omnipay\Edge\Keys;
use Omnipay\Edge\WebhookEvents;

/**
 * Parameters and local checks shared by the webhook subscription requests.
 *
 * Edge checks less than this (`Core.Developers.WebhookSubscription.common_changeset/2`):
 * any URL with a scheme and host, any list of event codes, and a mode that nothing
 * compares with the key. A misspelt event or the other mode is accepted and then silently
 * receives nothing, and an http URL would receive signed events unencrypted, so those are
 * refused here.
 */
abstract class AbstractWebhookSubscriptionRequest extends AbstractRequest
{
    public const MIN_DESCRIPTION_LENGTH = 10;

    public const MIN_CONCURRENCY_LIMIT = 1;

    public const MAX_CONCURRENCY_LIMIT = 100;

    /**
     * Edge attribute => request parameter.
     */
    private const FIELDS = [
        'url' => 'url',
        'mode' => 'mode',
        'description' => 'description',
        'events' => 'events',
        'concurrency_limit' => 'concurrencyLimit',
    ];

    public function getFieldForAttribute(string $attribute): string
    {
        return self::FIELDS[$attribute] ?? $attribute;
    }

    /**
     * The webhook subscription id, as returned by
     * WebhookSubscriptionResponse::getWebhookSubscriptionReference().
     */
    public function getWebhookSubscriptionReference(): ?string
    {
        return $this->getParameter('webhookSubscriptionReference');
    }

    public function setWebhookSubscriptionReference(?string $value): static
    {
        return $this->setParameter('webhookSubscriptionReference', $value);
    }

    /**
     * The https URL Edge posts events to.
     */
    public function getUrl(): ?string
    {
        return $this->getParameter('url');
    }

    public function setUrl(?string $value): static
    {
        return $this->setParameter('url', $value);
    }

    /**
     * `live` or `sandbox`. Defaults to the secret key's mode, and must match it.
     */
    public function getMode(): ?string
    {
        return $this->getParameter('mode');
    }

    public function setMode(?string $value): static
    {
        return $this->setParameter('mode', $value);
    }

    /**
     * Event codes from WebhookEvents::RECOMMENDED.
     *
     * @return mixed
     */
    public function getEvents()
    {
        return $this->getParameter('events');
    }

    /**
     * Untyped so a single code, or anything else, is refused by the events check with an
     * InvalidFieldException rather than a TypeError from Omnipay's initialize().
     *
     * @param list<string>|mixed $value
     */
    public function setEvents(mixed $value): static
    {
        return $this->setParameter('events', $value);
    }

    /**
     * Stored by Edge (1–100, default 50) but not enforced yet.
     *
     * @return int|string|null
     */
    public function getConcurrencyLimit()
    {
        return $this->getParameter('concurrencyLimit');
    }

    public function setConcurrencyLimit(int|string|null $value): static
    {
        return $this->setParameter('concurrencyLimit', $value);
    }

    /**
     * Whether a parameter was given a non-empty value.
     */
    protected function hasParameter(string $parameter): bool
    {
        $value = $this->getParameter($parameter);

        return $value !== null && $value !== '' && !(is_string($value) && trim($value) === '');
    }

    /**
     * An absolute https URL with a host and no credentials, trimmed.
     *
     * @throws InvalidFieldException
     */
    protected function resolveUrl(): string
    {
        $url = $this->requireString('url');
        $parts = preg_match('/\s/', $url) === 1 ? false : parse_url($url);

        $valid = is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);

        if (!$valid) {
            throw new InvalidFieldException(
                'url',
                'The url parameter must be an absolute https URL without credentials, such as '
                . 'https://shop.example.com/edge/webhook.'
            );
        }

        return $url;
    }

    /**
     * The secret key's mode, or the given mode when it matches.
     *
     * @throws InvalidFieldException
     */
    protected function resolveMode(): string
    {
        $keyMode = Keys::mode((string) $this->getSecretKey());

        if (!$this->hasParameter('mode')) {
            return $keyMode;
        }

        $mode = $this->requireString('mode');

        if (!in_array($mode, [Keys::MODE_LIVE, Keys::MODE_SANDBOX], true)) {
            throw new InvalidFieldException('mode', 'The mode parameter must be live or sandbox.');
        }

        if ($mode !== $keyMode) {
            throw new InvalidFieldException('mode', sprintf(
                'The mode is %s but the secret key is a %s key. Edge only delivers a %s key\'s events to a '
                . '%s subscription.',
                $mode,
                $keyMode,
                $keyMode,
                $keyMode
            ));
        }

        return $mode;
    }

    /**
     * A description of at least 10 characters, trimmed.
     *
     * @throws InvalidFieldException
     */
    protected function resolveDescription(): string
    {
        $description = $this->requireString('description');

        // Edge counts graphemes (`Ecto.Changeset.validate_length/3`).
        $length = preg_match_all('/\X/u', $description);

        if ($length === false || $length < self::MIN_DESCRIPTION_LENGTH) {
            throw new InvalidFieldException('description', sprintf(
                'The description must be at least %d characters.',
                self::MIN_DESCRIPTION_LENGTH
            ));
        }

        return $description;
    }

    /**
     * A non-empty list of recommended event codes, without duplicates.
     *
     * @return list<string>
     *
     * @throws InvalidFieldException
     */
    protected function resolveEvents(): array
    {
        $events = $this->getParameter('events');

        if (!is_array($events) || $events === []) {
            throw new InvalidFieldException(
                'events',
                'The events parameter must list at least one event from WebhookEvents::RECOMMENDED.'
            );
        }

        foreach ($events as $event) {
            if (!is_string($event)) {
                throw new InvalidFieldException('events', 'The events parameter must be a list of event codes.');
            }

            if (!in_array($event, WebhookEvents::RECOMMENDED, true)) {
                throw new InvalidFieldException(
                    'events',
                    sprintf('The event "%s" is not in WebhookEvents::RECOMMENDED.', $event)
                );
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * The concurrency limit as an integer from 1 to 100, or null when none was given.
     *
     * @throws InvalidFieldException
     */
    protected function resolveConcurrencyLimit(): ?int
    {
        if (!$this->hasParameter('concurrencyLimit')) {
            return null;
        }

        $value = $this->getParameter('concurrencyLimit');
        $limit = is_int($value) ? $value : null;

        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            $limit = (int) trim($value);
        }

        if ($limit === null || $limit < self::MIN_CONCURRENCY_LIMIT || $limit > self::MAX_CONCURRENCY_LIMIT) {
            throw new InvalidFieldException('concurrencyLimit', sprintf(
                'The concurrencyLimit parameter must be a whole number from %d to %d.',
                self::MIN_CONCURRENCY_LIMIT,
                self::MAX_CONCURRENCY_LIMIT
            ));
        }

        return $limit;
    }
}
