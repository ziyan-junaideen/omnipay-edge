<?php

declare(strict_types=1);

namespace Omnipay\Edge\Tests\Message;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Http\Client\Exception\NetworkException;
use Http\Message\RequestMatcher\CallbackRequestMatcher;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers every request from a queue, in order, so one test can script a read, a
 * write and a read back. Use from a MessageTestCase and call setUpQueue() in setUp().
 */
trait QueuedResponsesTrait
{
    /** @var list<ResponseInterface|NetworkException> */
    private array $queue = [];

    private function setUpQueue(): void
    {
        $matchAll = new CallbackRequestMatcher(static fn (): bool => true);

        $this->getMockClient()->on($matchAll, function (): ResponseInterface {
            $next = array_shift($this->queue);

            if ($next === null) {
                $this->fail('No mock response queued for this request');
            }

            if ($next instanceof NetworkException) {
                throw $next;
            }

            return $next;
        });
    }

    private function queueMock(string $file): void
    {
        $this->queue[] = $this->getMockHttpResponse($file);
    }

    /**
     * Queues a mock file's response with its JSON document changed by $change.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function queueChangedMock(string $file, callable $change, ?string $status = null): void
    {
        $mock = (string) file_get_contents(__DIR__ . '/../Mock/' . $file);
        [$head, $body] = explode("\n\n", $mock, 2);

        if ($status !== null) {
            $head = (string) preg_replace('~^HTTP/1\.1 \d{3} [^\n]*~', 'HTTP/1.1 ' . $status, $head);
        }

        /** @var array<string, mixed> $document */
        $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->queue[] = Message::parseResponse($head . "\n\n" . json_encode($change($document)));
    }

    private function queueNetworkFailure(): void
    {
        $this->queue[] = new NetworkException('Connection timed out', new PsrRequest('GET', 'https://api.tryedge.io/'));
    }

    /**
     * Asserts the requests sent, in order.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $expected [method, url, body]
     */
    private function assertRequests(array $expected): void
    {
        $requests = $this->getMockedRequests();
        $this->assertCount(count($expected), $requests);

        foreach ($expected as $index => $request) {
            $this->assertSame($request[0], $requests[$index]->getMethod());
            $this->assertSame($request[1], (string) $requests[$index]->getUri());
            $this->assertSame($request[2] ?? '', (string) $requests[$index]->getBody());
            $this->assertSame('Bearer ept_sandbox_s_test', $requests[$index]->getHeaderLine('Authorization'));
            $this->assertSame('application/vnd.api+json', $requests[$index]->getHeaderLine('Content-Type'));
            $this->assertSame('application/vnd.api+json', $requests[$index]->getHeaderLine('Accept'));
        }
    }
}
