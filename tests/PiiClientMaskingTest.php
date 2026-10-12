<?php

declare(strict_types=1);

namespace AITracer\Tests;

use AITracer\AITracer;
use AITracer\Http\HttpClient;
use AITracer\LogQueue;
use PHPUnit\Framework\TestCase;

/**
 * PII masking must cover every content field the client sends, and only those.
 */
class PiiClientMaskingTest extends TestCase
{
    private const EMAIL = 'john.doe@example.com';

    protected function setUp(): void
    {
        $property = (new \ReflectionClass(AITracer::class))->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function makeTracer(): AITracer
    {
        return new AITracer([
            'api_key' => 'at-test-key',
            'project' => 'test-project',
            'pii_detection' => true,
        ]);
    }

    private function setPrivate(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * Replace the log queue and collect the entries pushed to it.
     */
    private function captureQueue(AITracer $tracer): \ArrayObject
    {
        $queued = new \ArrayObject();
        $queue = $this->createMock(LogQueue::class);
        $queue->method('push')->willReturnCallback(function (array $entry) use ($queued): void {
            $queued[] = $entry;
        });
        $this->setPrivate($tracer, 'queue', $queue);
        return $queued;
    }

    /**
     * Replace the HTTP client and collect [method, endpoint, data] of each request.
     */
    private function captureHttp(AITracer $tracer): \ArrayObject
    {
        $sent = new \ArrayObject();
        $http = $this->createMock(HttpClient::class);
        $http->method('post')->willReturnCallback(function (string $endpoint, array $data) use ($sent): void {
            $sent[] = ['post', $endpoint, $data];
        });
        $http->method('patch')->willReturnCallback(function (string $endpoint, array $data) use ($sent): void {
            $sent[] = ['patch', $endpoint, $data];
        });
        $this->setPrivate($tracer, 'httpClient', $http);
        return $sent;
    }

    public function testLogMasksContentFields(): void
    {
        $tracer = $this->makeTracer();
        $queued = $this->captureQueue($tracer);

        $tracer->log([
            'model' => 'gpt-4',
            'provider' => 'openai',
            'input' => ['messages' => [['role' => 'user', 'content' => 'Mail ' . self::EMAIL]]],
            'output' => ['content' => 'ok'],
            'status' => 'error',
            'error_message' => 'Invalid recipient ' . self::EMAIL,
            'metadata' => ['customer' => ['email' => self::EMAIL]],
        ]);

        $entry = $queued[0];
        $this->assertSame('Mail [email]', $entry['input']['messages'][0]['content']);
        $this->assertSame('Invalid recipient [email]', $entry['error_message']);
        $this->assertSame('[email]', $entry['metadata']['customer']['email']);
    }

    public function testLogDoesNotMaskIdentifiers(): void
    {
        // Masking IDs would break the link between logs, traces and spans
        $tracer = $this->makeTracer();
        $queued = $this->captureQueue($tracer);
        $traceId = '00000000-0000-4000-8000-123456789012';

        $tracer->log([
            'model' => 'gpt-4',
            'provider' => 'openai',
            'input' => ['messages' => []],
            'trace_id' => $traceId,
        ]);

        $this->assertSame($traceId, $queued[0]['trace_id']);
    }

    public function testSessionStartAndEndMaskNameAndMetadata(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $tracer->startSession(['name' => 'Support for ' . self::EMAIL, 'metadata' => ['contact' => self::EMAIL]]);
        $tracer->endSession();

        [, , $started] = $sent[0];
        [$method, , $ended] = $sent[1];
        $this->assertSame('Support for [email]', $started['name']);
        $this->assertSame(['contact' => '[email]'], $started['metadata']);
        $this->assertSame('patch', $method);
        $this->assertSame(['contact' => '[email]'], $ended['metadata']);
    }

    public function testSessionEventMasksNameAndData(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $session = $tracer->startSession();
        $session->event('message', ['name' => 'From ' . self::EMAIL, 'data' => ['content' => 'Reply to ' . self::EMAIL]]);

        [, $endpoint, $event] = $sent[1];
        $this->assertStringEndsWith('/events/', $endpoint);
        $this->assertSame('From [email]', $event['name']);
        $this->assertSame(['content' => 'Reply to [email]'], $event['data']);
    }

    public function testFeedbackMasksComment(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $session = $tracer->startSession();
        $session->feedback('text', ['log_id' => 'log-1', 'comment' => 'Email me at ' . self::EMAIL]);

        [, $endpoint, $feedback] = $sent[1];
        $this->assertStringEndsWith('/feedback/', $endpoint);
        $this->assertSame('Email me at [email]', $feedback['comment']);
    }
}
