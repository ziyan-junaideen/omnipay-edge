<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use JsonException;
use Omnipay\Common\Message\AbstractResponse as OmnipayAbstractResponse;
use Omnipay\Common\Message\RequestInterface;

/**
 * Reads an Edge JSON:API response, or the lack of one.
 *
 * Three outcomes:
 * - well formed: a 2xx whose primary data has the expected type and an id;
 * - failed: a JSON:API error document (422) or a plain-text error (401, 403, 404,
 *   405, 500), or a malformed 2xx to a read;
 * - ambiguous: a transport failure, or a malformed 2xx to a POST or PATCH. The
 *   change may or may not have happened, so callers read the resource back
 *   instead of assuming either way.
 *
 * getData() returns the decoded document, or null when the body was not JSON.
 */
abstract class AbstractResponse extends OmnipayAbstractResponse
{
    private const MAX_PLAIN_TEXT_MESSAGE_LENGTH = 200;

    protected HttpResult $result;

    protected ?string $expectedType;

    private bool $wellFormed = false;

    private bool $ambiguous = false;

    private ?string $message = null;

    /** @var list<array<string, mixed>> */
    private array $errors = [];

    /**
     * @param string|null $expectedType the JSON:API `type` the primary data must have
     */
    public function __construct(RequestInterface $request, HttpResult $result, ?string $expectedType = null)
    {
        parent::__construct($request, null);

        $this->result = $result;
        $this->expectedType = $expectedType;

        $this->parse();
    }

    /**
     * True for a well-formed 2xx. Subclasses narrow this with resource state: a
     * well-formed payment demand is not necessarily paid.
     */
    public function isSuccessful(): bool
    {
        return $this->wellFormed;
    }

    /**
     * The outcome is unknown: a transport failure, or a POST or PATCH answered with
     * a 2xx that isn't the expected resource. Read the resource back before acting.
     */
    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    /**
     * The HTTP status, or null when no response arrived.
     */
    public function getHttpStatus(): ?int
    {
        return $this->result->status;
    }

    public function getHttpResult(): HttpResult
    {
        return $this->result;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * The first JSON:API error's `code`, when Edge sent one.
     */
    public function getCode(): ?string
    {
        $code = $this->errors[0]['code'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /**
     * The JSON:API error objects, as sent. Empty for plain-text errors.
     *
     * @return list<array<string, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The first error's `source.pointer`, such as `/data/attributes/zip`.
     */
    public function getErrorPointer(): ?string
    {
        $source = $this->errors[0]['source'] ?? null;
        $pointer = is_array($source) ? ($source['pointer'] ?? null) : null;

        return is_string($pointer) ? $pointer : null;
    }

    /**
     * Error messages keyed by the Edge attribute or relationship each `source.pointer`
     * names: `/data/attributes/zip` gives `zip`, `/data/relationships/customer` gives
     * `customer`. Errors that point elsewhere, or nowhere, are left out.
     *
     * @return array<string, list<string>>
     */
    public function getAttributeErrors(): array
    {
        $errors = [];

        foreach ($this->errors as $error) {
            $source = $error['source'] ?? null;
            $pointer = is_array($source) ? ($source['pointer'] ?? null) : null;

            $matched = is_string($pointer)
                && preg_match('~^/data/(?:attributes|relationships)/([^/]+)~', $pointer, $match) === 1;

            if (!$matched) {
                continue;
            }

            // JSON Pointer escapes: ~1 is "/", ~0 is "~".
            $name = strtr($match[1], ['~1' => '/', '~0' => '~']);
            $errors[$name][] = $this->errorMessage($error, (int) $this->result->status);
        }

        return $errors;
    }

    /**
     * Like getAttributeErrors(), but keyed by the request parameter each value came
     * from, such as `billingPostcode` for a billing address `zip`. Show each message
     * next to the matching input.
     *
     * @return array<string, list<string>>
     */
    public function getFieldErrors(): array
    {
        $errors = [];

        foreach ($this->getAttributeErrors() as $attribute => $messages) {
            $field = $this->request instanceof AbstractRequest
                ? $this->request->getFieldForAttribute($attribute)
                : $attribute;

            $errors[$field] = array_merge($errors[$field] ?? [], $messages);
        }

        return $errors;
    }

    /**
     * The primary resource of a well-formed single-resource response.
     *
     * @return array<string, mixed>|null
     */
    public function getResource(): ?array
    {
        if (!$this->wellFormed || $this->expectsCollection()) {
            return null;
        }

        return $this->data['data'];
    }

    public function getResourceId(): ?string
    {
        $resource = $this->getResource();

        return $resource === null ? null : $resource['id'];
    }

    public function getAttribute(string $name): mixed
    {
        $attributes = $this->getResource()['attributes'] ?? null;

        return is_array($attributes) ? ($attributes[$name] ?? null) : null;
    }

    /**
     * A string attribute, or null when it is absent, empty or not a string.
     */
    public function stringAttribute(string $name): ?string
    {
        $value = $this->getAttribute($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The id of a to-one relationship, or null when it is absent or empty.
     */
    public function getRelationshipId(string $name): ?string
    {
        $resource = $this->getResource();

        return $resource === null ? null : self::relationshipIdOf($resource, $name);
    }

    /**
     * A resource from the compound document's `included` member.
     *
     * @return array<string, mixed>|null
     */
    public function getIncluded(string $type, string $id): ?array
    {
        $included = $this->wellFormed ? ($this->data['included'] ?? null) : null;

        if (!is_array($included)) {
            return null;
        }

        foreach ($included as $resource) {
            if (is_array($resource) && ($resource['type'] ?? null) === $type && ($resource['id'] ?? null) === $id) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLinks(): array
    {
        $links = $this->data['links'] ?? null;

        return is_array($links) ? $links : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        $meta = $this->data['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * The id of a to-one relationship of any resource object, such as a collection
     * entry, or null when it is absent or empty.
     *
     * @param array<string, mixed> $resource
     */
    public static function relationshipIdOf(array $resource, string $name): ?string
    {
        $relationships = $resource['relationships'] ?? null;
        $relationship = is_array($relationships) ? ($relationships[$name] ?? null) : null;
        $linkage = is_array($relationship) ? ($relationship['data'] ?? null) : null;
        $id = is_array($linkage) ? ($linkage['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The facts a created resource disagrees with the posted document on, keyed by
     * attribute or relationship name. Relationship ids compare case-insensitively: Edge
     * casts them as UUIDs and returns them in lower case. A relationship that wasn't
     * sent must come back absent.
     *
     * @param array<string, mixed> $sent the posted document
     * @param list<string> $attributes
     * @param list<string> $relationships
     *
     * @return array<string, array{sent: mixed, edge: mixed}>
     */
    protected function compareWithSent(array $sent, array $attributes, array $relationships): array
    {
        $data = $sent['data'] ?? null;
        $sentAttributes = is_array($data) && is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $sentRelationships = is_array($data) && is_array($data['relationships'] ?? null) ? $data['relationships'] : [];
        $mismatches = [];

        foreach ($attributes as $name) {
            $expected = $sentAttributes[$name] ?? null;
            $actual = $this->getAttribute($name);

            if ($expected !== $actual) {
                $mismatches[$name] = ['sent' => $expected, 'edge' => $actual];
            }
        }

        foreach ($relationships as $name) {
            $linkage = $sentRelationships[$name]['data'] ?? null;
            $expected = is_array($linkage) ? ($linkage['id'] ?? null) : null;
            $actual = $this->getRelationshipId($name);

            $same = is_string($expected) && is_string($actual)
                ? strcasecmp($expected, $actual) === 0
                : $expected === $actual;

            if (!$same) {
                $mismatches[$name] = ['sent' => $expected, 'edge' => $actual];
            }
        }

        return $mismatches;
    }

    /**
     * Whether the primary data is a list of resources rather than one resource.
     */
    protected function expectsCollection(): bool
    {
        return false;
    }

    private function parse(): void
    {
        if ($this->result->transportError !== null) {
            $this->ambiguous = true;
            $this->message = 'No response from Edge, so the outcome is unknown: '
                . $this->result->transportError->getMessage();

            return;
        }

        $status = (int) $this->result->status;
        $this->data = $this->decode($this->result->body);

        if ($status >= 200 && $status < 300) {
            if (is_array($this->data) && $this->hasExpectedPrimaryData($this->data)) {
                $this->wellFormed = true;

                return;
            }

            $this->ambiguous = $this->result->isMutating();
            $this->message = sprintf('Edge returned an unexpected response (HTTP %d).', $status);

            return;
        }

        $errors = $this->data['errors'] ?? null;

        if (is_array($errors) && array_is_list($errors)) {
            $this->errors = array_values(array_filter($errors, 'is_array'));
        }

        $this->message = $this->errors === []
            ? $this->plainTextMessage($status)
            : $this->errorMessage($this->errors[0], $status);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $document
     */
    private function hasExpectedPrimaryData(array $document): bool
    {
        $data = $document['data'] ?? null;

        if (!is_array($data)) {
            return false;
        }

        if (!$this->expectsCollection()) {
            return !array_is_list($data) && $this->isExpectedResource($data);
        }

        if (!array_is_list($data)) {
            return false;
        }

        foreach ($data as $resource) {
            if (!is_array($resource) || !$this->isExpectedResource($resource)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $resource
     */
    private function isExpectedResource(array $resource): bool
    {
        $type = $resource['type'] ?? null;
        $id = $resource['id'] ?? null;

        return is_string($type)
            && ($this->expectedType === null || $type === $this->expectedType)
            && is_string($id)
            && $id !== '';
    }

    /**
     * @param array<string, mixed> $error
     */
    private function errorMessage(array $error, int $status): string
    {
        foreach (['detail', 'title', 'code'] as $member) {
            $value = $error[$member] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $this->genericMessage($status);
    }

    /**
     * Edge answers 401, 403, 404, 405 and 500 with a plain-text reason phrase. Use it
     * when it is short and plain; anything else (an HTML proxy page, a stack dump)
     * gets a generic message.
     */
    private function plainTextMessage(int $status): string
    {
        $text = trim($this->result->body);

        $usable = $this->data === null
            && $text !== ''
            && strlen($text) <= self::MAX_PLAIN_TEXT_MESSAGE_LENGTH
            && preg_match('/[\x00-\x1F\x7F<]/', $text) !== 1;

        return $usable ? $text : $this->genericMessage($status);
    }

    private function genericMessage(int $status): string
    {
        return sprintf('Edge API error (HTTP %d)', $status);
    }
}
