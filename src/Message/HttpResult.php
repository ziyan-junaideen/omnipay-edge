<?php

declare(strict_types=1);

namespace Omnipay\Edge\Message;

use Throwable;

/**
 * What came back from one HTTP exchange with Edge: a status and body, or the
 * transport failure that left the outcome unknown.
 *
 * Deliberately free of Omnipay semantics. A response class reads it and decides
 * whether the call succeeded, failed or is ambiguous.
 *
 * Don't log or serialise the transport error whole: Omnipay's exceptions carry the
 * PSR-7 request, including its Authorization header.
 */
final class HttpResult
{
    private function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $status,
        public readonly string $body,
        public readonly ?Throwable $transportError,
    ) {
    }

    public static function received(string $method, string $url, int $status, string $body): self
    {
        return new self(strtoupper($method), $url, $status, $body, null);
    }

    /**
     * The request may or may not have reached Edge. A PSR-18 client never reports
     * a status for this, so there is none.
     */
    public static function transportFailed(string $method, string $url, Throwable $error): self
    {
        return new self(strtoupper($method), $url, null, '', $error);
    }

    /**
     * POST and PATCH change state on Edge, so an unreadable answer to one of them
     * cannot be treated as "nothing happened".
     */
    public function isMutating(): bool
    {
        return $this->method !== 'GET';
    }
}
