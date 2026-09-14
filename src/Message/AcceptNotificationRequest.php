<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use JsonException;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Message\AbstractRequest as OmnipayAbstractRequest;
use Omnipay\Edge\Exception\InvalidWebhookException;
use Omnipay\Edge\WebhookSignature;

/**
 * Verifies an incoming Edge webhook delivery and reads its event. Sends nothing.
 *
 * The raw body and headers come from the Symfony `httpRequest`, or from the `rawBody`
 * and `headers` parameters. The signature is checked with `webhookSecret` before the
 * body is decoded.
 */
class AcceptNotificationRequest extends OmnipayAbstractRequest
{
    public function getWebhookSecret(): ?string
    {
        return $this->getParameter('webhookSecret');
    }

    public function setWebhookSecret(?string $value): static
    {
        return $this->setParameter('webhookSecret', $value);
    }

    /**
     * The largest accepted distance between the signature's `t` and now, in seconds.
     *
     * @return int|string|null
     */
    public function getWebhookTolerance()
    {
        return $this->getParameter('webhookTolerance');
    }

    public function setWebhookTolerance(int|string|null $value): static
    {
        return $this->setParameter('webhookTolerance', $value);
    }

    /**
     * The request body exactly as received. Defaults to the httpRequest's content.
     * Independent of `headers`: give both when the delivery didn't arrive through the
     * httpRequest.
     */
    public function getRawBody(): ?string
    {
        return $this->getParameter('rawBody');
    }

    public function setRawBody(?string $value): static
    {
        return $this->setParameter('rawBody', $value);
    }

    /**
     * The request headers, name => value or list of values, as from PSR-7's
     * getHeaders(). Names are matched case-insensitively. Defaults to the
     * httpRequest's headers.
     *
     * @return array<string, string|list<string>>|null
     */
    public function getHeaders(): ?array
    {
        return $this->getParameter('headers');
    }

    /**
     * @param array<string, string|list<string>>|null $value
     */
    public function setHeaders(?array $value): static
    {
        return $this->setParameter('headers', $value);
    }

    /**
     * Verifies the delivery, then decodes the event document.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidWebhookException when the signature or the event is refused
     * @throws InvalidRequestException when webhookSecret or webhookTolerance is unusable
     */
    public function getData(): array
    {
        $secret = (string) $this->getWebhookSecret();

        if ($secret === '') {
            throw new InvalidRequestException('The webhookSecret parameter is required');
        }

        $tolerance = $this->tolerance();
        $rawBody = $this->rawBody();
        $header = $this->signatureHeader();

        if (!WebhookSignature::verify($rawBody, $header, $secret, $tolerance)) {
            throw new InvalidWebhookException(
                'The edge-signature header is invalid, or its timestamp is outside the tolerance.'
            );
        }

        return $this->decodeEvent($rawBody);
    }

    public function send(): Notification
    {
        return $this->sendData(null);
    }

    /**
     * Builds the Notification. The argument is ignored: the delivery is always verified
     * again here, so data that didn't come from getData() can't skip the check.
     *
     * @param mixed $data
     *
     * @throws InvalidRequestException
     */
    public function sendData($data): Notification
    {
        return $this->response = new Notification($this, $this->getData());
    }

    /**
     * @throws InvalidRequestException
     */
    private function tolerance(): int
    {
        $tolerance = $this->getWebhookTolerance() ?? WebhookSignature::DEFAULT_TOLERANCE;

        if (is_string($tolerance) && preg_match('/^[0-9]{1,9}$/D', trim($tolerance)) === 1) {
            $tolerance = (int) trim($tolerance);
        }

        if (!is_int($tolerance) || $tolerance < 0) {
            throw new InvalidRequestException('The webhookTolerance parameter must be a whole number of seconds.');
        }

        return $tolerance;
    }

    private function rawBody(): string
    {
        $rawBody = $this->getRawBody();

        return $rawBody ?? (string) $this->httpRequest->getContent();
    }

    /**
     * The one `edge-signature` header value.
     *
     * @throws InvalidWebhookException
     */
    private function signatureHeader(): string
    {
        $values = $this->headerValues(WebhookSignature::HEADER);

        if (count($values) > 1) {
            throw new InvalidWebhookException('The webhook has more than one edge-signature header.');
        }

        if ($values === []) {
            if ($this->headerValues(WebhookSignature::LEGACY_HEADER) !== []) {
                throw new InvalidWebhookException(
                    'The webhook is signed with the legacy x-hub-signature header, which does not cover the body. '
                    . 'Only edge-signature (webhook delivery version v3) is accepted.'
                );
            }

            throw new InvalidWebhookException('The webhook has no edge-signature header.');
        }

        return $values[0];
    }

    /**
     * @return list<string>
     */
    private function headerValues(string $name): array
    {
        $headers = $this->getHeaders() ?? [$name => $this->httpRequest->headers->all($name)];

        $values = [];

        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            foreach ((array) $value as $item) {
                $values[] = (string) $item;
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidWebhookException
     */
    private function decodeEvent(string $rawBody): array
    {
        try {
            $document = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidWebhookException('The webhook body is not JSON.', 0, $exception);
        }

        $event = is_array($document) ? ($document['data'] ?? null) : null;
        $attributes = is_array($event) ? ($event['attributes'] ?? null) : null;

        $valid = is_array($event) && is_array($attributes)
            && ($event['type'] ?? null) === 'events'
            && self::isNonEmptyString($event['id'] ?? null)
            && self::isNonEmptyString($attributes['resource_type'] ?? null)
            && self::isNonEmptyString($attributes['resource_id'] ?? null)
            && self::isNonEmptyString($attributes['slug'] ?? null);

        if (!$valid) {
            throw new InvalidWebhookException(
                'The webhook body is not an Edge event: expected data.type "events" with an id, '
                . 'resource_type, resource_id and slug.'
            );
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
