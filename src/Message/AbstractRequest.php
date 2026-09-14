<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use JsonException;
use Omnipay\Common\CreditCard;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\Exception as OmnipayHttpException;
use Omnipay\Common\Message\AbstractRequest as OmnipayAbstractRequest;
use Omnipay\Edge\Exception\InvalidFieldException;
use Omnipay\Edge\Gateway;
use Omnipay\Edge\Keys;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use stdClass;

/**
 * Shared plumbing for every Edge API request: key checks, URLs, headers, JSON:API
 * encoding and transport failures.
 *
 * A concrete request builds its document in getRequestData(), sends it with one of
 * the send helpers from sendData(), and wraps the HttpResult in its response class.
 */
abstract class AbstractRequest extends OmnipayAbstractRequest
{
    public const CONTENT_TYPE = 'application/vnd.api+json';

    public const CURRENCY = 'USD';

    /**
     * Edge's minimum charge (`Core.Transactions.minimum_charge_cents/0`). Requests
     * that move a different kind of amount, such as refunds, may lower it.
     */
    protected int $minimumAmountCents = 10;

    /**
     * Validates the keys, then builds the request document.
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidRequestException
     */
    public function getData()
    {
        $this->validateKeys();

        return $this->getRequestData();
    }

    /**
     * The JSON:API document to send, or null when the request has no body.
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidRequestException
     */
    abstract protected function getRequestData(): ?array;

    public function getSecretKey(): ?string
    {
        return $this->getParameter('secretKey');
    }

    public function setSecretKey(?string $value): static
    {
        return $this->setParameter('secretKey', $value);
    }

    public function getPublishableKey(): ?string
    {
        return $this->getParameter('publishableKey');
    }

    public function setPublishableKey(?string $value): static
    {
        return $this->setParameter('publishableKey', $value);
    }

    public function getApiBaseUrl(): ?string
    {
        return $this->getParameter('apiBaseUrl');
    }

    public function setApiBaseUrl(?string $value): static
    {
        return $this->setParameter('apiBaseUrl', $value);
    }

    public function getDashboardHost(): ?string
    {
        return $this->getParameter('dashboardHost');
    }

    public function setDashboardHost(?string $value): static
    {
        return $this->setParameter('dashboardHost', $value);
    }

    public function getBrowserSdkUrl(): ?string
    {
        return $this->getParameter('browserSdkUrl');
    }

    public function setBrowserSdkUrl(?string $value): static
    {
        return $this->setParameter('browserSdkUrl', $value);
    }

    /**
     * Accepted so every gateway parameter reaches the request. Never sent to Edge.
     */
    public function getWebhookSecret(): ?string
    {
        return $this->getParameter('webhookSecret');
    }

    public function setWebhookSecret(?string $value): static
    {
        return $this->setParameter('webhookSecret', $value);
    }

    /**
     * The Edge customer id, as returned by createCustomer().
     */
    public function getCustomerReference(): ?string
    {
        return $this->getParameter('customerReference');
    }

    public function setCustomerReference(?string $value): static
    {
        return $this->setParameter('customerReference', $value);
    }

    /**
     * The caller's idempotency key. Store it before sending, and send the same key
     * when retrying after an unclear outcome. See IdempotencyKey::fingerprint() for a
     * derived one.
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->getParameter('idempotencyKey');
    }

    public function setIdempotencyKey(?string $value): static
    {
        return $this->setParameter('idempotencyKey', $value);
    }

    /**
     * The request parameter an Edge attribute or relationship was built from, so a
     * 422 can be reported against the caller's input. Defaults to the Edge name.
     */
    public function getFieldForAttribute(string $attribute): string
    {
        return $attribute;
    }

    /**
     * Adds Edge's money rules to Omnipay's required-parameter check: whenever the
     * amount or currency is validated, the currency must be USD and the amount at
     * least the minimum, in integer cents.
     *
     * @param string ...$args
     *
     * @throws InvalidRequestException
     */
    public function validate(...$args): void
    {
        parent::validate(...$args);

        $checksAmount = in_array('amount', $args, true);

        if (!$checksAmount && !in_array('currency', $args, true)) {
            return;
        }

        // Omnipay treats an unset currency as USD when it parses the amount.
        $currency = (string) $this->getCurrency();

        if ($currency !== '' && $currency !== self::CURRENCY) {
            throw new InvalidRequestException(sprintf('Edge only accepts USD, not "%s".', $currency));
        }

        if ($checksAmount && (int) $this->getAmountInteger() < $this->minimumAmountCents) {
            throw new InvalidRequestException(sprintf(
                'The amount must be at least %d %s.',
                $this->minimumAmountCents,
                $this->minimumAmountCents === 1 ? 'cent' : 'cents'
            ));
        }
    }

    /**
     * The card, or null when none was given. Omnipay's getCard() is documented as
     * never returning null, but does.
     */
    protected function findCard(): ?CreditCard
    {
        $card = $this->getParameter('card');

        return $card instanceof CreditCard ? $card : null;
    }

    /**
     * A required string parameter, trimmed.
     *
     * @throws InvalidFieldException
     */
    protected function requireString(string $parameter): string
    {
        $value = $this->getParameter($parameter);
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            throw new InvalidFieldException($parameter, sprintf('The %s parameter is required', $parameter));
        }

        return $value;
    }

    /**
     * Runs before any request is built or sent.
     *
     * @throws InvalidRequestException
     */
    protected function validateKeys(): void
    {
        $secretKey = (string) $this->getSecretKey();

        if ($secretKey === '') {
            throw new InvalidRequestException('The secretKey parameter is required');
        }

        Keys::assertSecret($secretKey);

        $publishableKey = (string) $this->getPublishableKey();

        if ($publishableKey !== '') {
            Keys::validatePair($secretKey, $publishableKey);
        }

        // Omnipay leaves testMode unset unless the caller sets it; Aimeos always does.
        $testMode = $this->getParameter('testMode');

        if ($testMode === null) {
            return;
        }

        // Config often arrives as strings, and (bool) "false" is true.
        $testMode = is_bool($testMode)
            ? $testMode
            : filter_var($testMode, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($testMode === null) {
            throw new InvalidRequestException('The testMode parameter must be a boolean.');
        }

        Keys::assertTestMode($secretKey, $testMode);
    }

    /**
     * The API root, always ending in a slash. A bare host gets `/v2/`.
     *
     * @throws InvalidRequestException
     */
    protected function getBaseUrl(): string
    {
        $configured = trim((string) $this->getApiBaseUrl());

        if ($configured === '') {
            $configured = Gateway::DEFAULT_API_BASE_URL;
        }

        if (!str_contains($configured, '://')) {
            $configured = 'https://' . $configured;
        }

        $parts = parse_url($configured);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new InvalidRequestException(
                'The apiBaseUrl parameter must be an https URL, such as https://api.tryedge.io/v2/.'
            );
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidRequestException(
                'The apiBaseUrl parameter must not contain credentials, a query string or a fragment.'
            );
        }

        $path = $parts['path'] ?? '';

        if ($path === '' || $path === '/') {
            $path = '/v2/';
        } elseif (!str_ends_with($path, '/')) {
            $path .= '/';
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
    }

    /**
     * Resolves an endpoint against the API root.
     *
     * Relative endpoints are joined by hand: RFC 3986 resolution would drop `/v2`
     * from the base when the endpoint starts with a slash. Absolute URLs, such as a
     * `links.self`, must share the API root's origin because every request carries
     * the secret key.
     *
     * @param array<string, mixed> $query
     *
     * @throws InvalidRequestException
     */
    protected function buildUrl(string $endpoint, array $query = []): string
    {
        $base = $this->getBaseUrl();

        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $endpoint) === 1 || str_starts_with($endpoint, '//')) {
            $url = $this->assertSameOrigin($endpoint, $base);
        } elseif (strpbrk($endpoint, '?#') !== false) {
            throw new InvalidRequestException('Pass query parameters separately, not inside the endpoint.');
        } else {
            $url = $base . ltrim($endpoint, '/');
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        if ($queryString !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        return $url;
    }

    /**
     * Joins path segments, percent-encoding each one, so an id can never add a
     * segment or a query string to the endpoint.
     *
     * @throws InvalidRequestException when a segment is empty, `.` or `..`
     */
    protected static function path(string ...$segments): string
    {
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidRequestException('An Edge endpoint segment or resource id is missing or invalid.');
            }
        }

        return implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * Builds a single-resource JSON:API document. Empty attributes encode as `{}`,
     * which Edge requires (for example on confirm).
     *
     * @param array<string, mixed> $attributes
     * @param array<string, array{0: string, 1: string}> $relationships name => [type, id]
     *
     * @return array{data: array<string, mixed>}
     */
    protected function resourceDocument(
        string $type,
        array $attributes = [],
        array $relationships = [],
        ?string $id = null
    ): array {
        $data = ['type' => $type];

        if ($id !== null) {
            $data['id'] = $id;
        }

        $data['attributes'] = $attributes === [] ? new stdClass() : $attributes;

        foreach ($relationships as $name => [$relatedType, $relatedId]) {
            $data['relationships'][$name] = ['data' => ['type' => $relatedType, 'id' => $relatedId]];
        }

        return ['data' => $data];
    }

    /**
     * @return array<string, string>
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getSecretKey(),
            'Content-Type' => self::CONTENT_TYPE,
            'Accept' => self::CONTENT_TYPE,
            'User-Agent' => 'OmnipayEdge/' . Gateway::VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @throws InvalidRequestException
     */
    protected function sendGet(string $endpoint, array $query = []): HttpResult
    {
        return $this->sendRequest('GET', $this->buildUrl($endpoint, $query), null);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws InvalidRequestException
     */
    protected function sendPost(string $endpoint, array $document): HttpResult
    {
        return $this->sendRequest('POST', $this->buildUrl($endpoint), $document);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws InvalidRequestException
     */
    protected function sendPatch(string $endpoint, array $document): HttpResult
    {
        return $this->sendRequest('PATCH', $this->buildUrl($endpoint), $document);
    }

    /**
     * @param array<string, mixed>|null $document
     *
     * @throws InvalidRequestException
     */
    private function sendRequest(string $method, string $url, ?array $document): HttpResult
    {
        // Also checked here so a subclass that overrides getData() can't skip it.
        $this->validateKeys();

        $body = $document === null ? null : $this->encode($document);

        try {
            $response = $this->httpClient->request($method, $url, $this->getHeaders(), $body);
        } catch (ClientExceptionInterface | OmnipayHttpException $exception) {
            return HttpResult::transportFailed($method, $url, $exception);
        }

        try {
            $responseBody = (string) $response->getBody();
        } catch (RuntimeException $exception) {
            // The status arrived but the body stream broke: still an unknown outcome.
            return HttpResult::transportFailed($method, $url, $exception);
        }

        return HttpResult::received($method, $url, $response->getStatusCode(), $responseBody);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws InvalidRequestException
     */
    private function encode(array $document): string
    {
        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidRequestException(
                'The request document could not be encoded as JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * @throws InvalidRequestException
     */
    private function assertSameOrigin(string $url, string $base): string
    {
        $target = parse_url($url);
        $root = parse_url($base);

        $sameOrigin = is_array($target) && is_array($root)
            && !isset($target['user']) && !isset($target['pass'])
            && strtolower($target['scheme'] ?? '') === 'https'
            && strtolower($target['host'] ?? '') === ($root['host'] ?? null)
            && ($target['port'] ?? 443) === ($root['port'] ?? 443);

        if (!$sameOrigin) {
            throw new InvalidRequestException(
                'Refusing to send the secret key to a URL outside the configured apiBaseUrl origin.'
            );
        }

        // A fragment is never sent on the wire.
        return explode('#', $url, 2)[0];
    }
}
