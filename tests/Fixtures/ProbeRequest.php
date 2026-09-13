<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Fixtures;

use Omnipay\Edge\Message\AbstractRequest;

/**
 * A minimal concrete request that exposes the foundation helpers to tests.
 */
final class ProbeRequest extends AbstractRequest
{
    public function getMethod(): string
    {
        return $this->getParameter('method') ?? 'GET';
    }

    public function setMethod(string $value): self
    {
        return $this->setParameter('method', $value);
    }

    public function getEndpoint(): string
    {
        return $this->getParameter('endpoint') ?? 'payment_demands';
    }

    public function setEndpoint(string $value): self
    {
        return $this->setParameter('endpoint', $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return $this->getParameter('query') ?? [];
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setQuery(array $value): self
    {
        return $this->setParameter('query', $value);
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setDocument(?array $value): self
    {
        return $this->setParameter('document', $value);
    }

    public function setExpectedType(?string $value): self
    {
        return $this->setParameter('expectedType', $value);
    }

    public function setMinimumAmountCents(int $value): self
    {
        $this->minimumAmountCents = $value;

        return $this;
    }

    public function send(): ProbeResponse
    {
        return $this->sendData($this->getData());
    }

    /**
     * @param mixed $data
     */
    public function sendData($data): ProbeResponse
    {
        $result = match ($this->getMethod()) {
            'POST' => $this->sendPost($this->getEndpoint(), $data ?? []),
            'PATCH' => $this->sendPatch($this->getEndpoint(), $data ?? []),
            default => $this->sendGet($this->getEndpoint(), $this->getQuery()),
        };

        return $this->response = new ProbeResponse($this, $result, $this->getParameter('expectedType'));
    }

    /**
     * @param array<string, mixed> $query
     */
    public function exposeBuildUrl(string $endpoint, array $query = []): string
    {
        return $this->buildUrl($endpoint, $query);
    }

    public function exposePath(string ...$segments): string
    {
        return self::path(...$segments);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, array{0: string, 1: string}> $relationships
     *
     * @return array{data: array<string, mixed>}
     */
    public function exposeResourceDocument(
        string $type,
        array $attributes = [],
        array $relationships = [],
        ?string $id = null
    ): array {
        return $this->resourceDocument($type, $attributes, $relationships, $id);
    }

    protected function getRequestData(): ?array
    {
        return $this->getParameter('document');
    }
}
