<?php

declare(strict_types=1);

namespace AITracer\Tests;

use AITracer\AITracer;
use AITracer\Http\HttpClient;
use AITracer\Wrappers\ChatCompletionsWrapper;
use AITracer\Wrappers\OpenAIWrapper;
use PHPUnit\Framework\TestCase;

/**
 * capture_content => false must send metadata only, never the LLM content.
 */
class CaptureContentTest extends TestCase
{
    private const SECRET = '議事録: 山田太郎さんの住所は東京都千代田区1-1-1';
    private const EMAIL = 'john.doe@example.com';
    private const REDACTED = ['redacted' => true];

    protected function setUp(): void
    {
        // Force-load wrapper files (each defines multiple classes)
        class_exists(OpenAIWrapper::class);
    }

    protected function tearDown(): void
    {
        $property = (new \ReflectionClass(AITracer::class))->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function makeTracer(array $options = []): AITracer
    {
        return new AITracer(array_merge([
            'api_key' => 'at-test-key',
            'project' => 'test-project',
            'sync' => true,
            'flush_on_exit' => false,
            'capture_content' => false,
        ], $options));
    }

    private function setPrivate(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function getPrivate(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    /**
     * Replace the HTTP client (used by both the tracer and its log queue) and collect
     * [endpoint, data] of each request: what would go over the network.
     */
    private function captureHttp(AITracer $tracer): \ArrayObject
    {
        $sent = new \ArrayObject();
        $http = $this->createMock(HttpClient::class);
        $http->method('sendLog')->willReturnCallback(function (array $entry) use ($sent): void {
            $sent[] = ['/api/v1/logs/', $entry];
        });
        $http->method('sendBatch')->willReturnCallback(function (array $entries) use ($sent): void {
            $sent[] = ['/api/v1/logs/batch/', ['logs' => $entries]];
        });
        $http->method('post')->willReturnCallback(function (string $endpoint, array $data) use ($sent): void {
            $sent[] = [$endpoint, $data];
        });
        $http->method('patch')->willReturnCallback(function (string $endpoint, array $data) use ($sent): void {
            $sent[] = [$endpoint, $data];
        });
        $this->setPrivate($tracer, 'httpClient', $http);
        $this->setPrivate($this->getPrivate($tracer, 'queue'), 'httpClient', $http);
        return $sent;
    }

    private function assertNoSecret(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('山田', $json);
    }

    private function logWithContent(): array
    {
        return [
            'model' => 'claude-sonnet-4',
            'provider' => 'anthropic',
            'input' => ['messages' => [['role' => 'user', 'content' => self::SECRET]]],
            'output' => ['content' => self::SECRET],
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 200,
            'cache_creation_input_tokens' => 10,
            'latency_ms' => 1234,
            'status' => 'success',
            'metadata' => ['request_kind' => 'summary'],
            'span_id' => 'span-1',
            'parent_span_id' => 'parent-1',
            'trace_id' => 'trace-1',
        ];
    }

    public function testDefaultSendsContent(): void
    {
        $tracer = new AITracer([
            'api_key' => 'at-test-key',
            'project' => 'test-project',
            'sync' => true,
            'flush_on_exit' => false,
        ]);
        $this->assertTrue($tracer->getConfig()->captureContent);
        $sent = $this->captureHttp($tracer);

        $tracer->log(array_merge($this->logWithContent(), [
            'status' => 'error',
            'error_message' => 'failed: ' . self::SECRET,
            'error_type' => 'RateLimitError',
        ]));

        [, $entry] = $sent[0];
        $this->assertSame(['messages' => [['role' => 'user', 'content' => self::SECRET]]], $entry['input']);
        $this->assertSame(['content' => self::SECRET], $entry['output']);
        $this->assertSame('failed: ' . self::SECRET, $entry['error_message']);
        $this->assertArrayNotHasKey('error_type', $entry);
    }

    public function testDropsContentAndKeepsMetadata(): void
    {
        $tracer = $this->makeTracer(['user_id' => 'app-user-1']);
        $sent = $this->captureHttp($tracer);
        $tracer->trace('trace-1')->setMetadata(['feature' => 'minutes']);

        $tracer->log($this->logWithContent());

        [$endpoint, $entry] = $sent[0];
        $this->assertSame('/api/v1/logs/', $endpoint);
        $this->assertNoSecret($entry);
        $this->assertSame(self::REDACTED, $entry['input']);
        $this->assertSame(self::REDACTED, $entry['output']);
        $this->assertNull($entry['error_message']);
        $this->assertSame('test-project', $entry['project']);
        $this->assertSame('claude-sonnet-4', $entry['model']);
        $this->assertSame('anthropic', $entry['provider']);
        $this->assertSame(100, $entry['input_tokens']);
        $this->assertSame(50, $entry['output_tokens']);
        $this->assertSame(200, $entry['cache_read_input_tokens']);
        $this->assertSame(10, $entry['cache_creation_input_tokens']);
        $this->assertSame(1234, $entry['latency_ms']);
        $this->assertSame('success', $entry['status']);
        $this->assertSame('trace-1', $entry['trace_id']);
        $this->assertSame('span-1', $entry['span_id']);
        $this->assertSame('parent-1', $entry['parent_span_id']);
        $this->assertSame('app-user-1', $entry['app_user_id']);
        $this->assertSame(['request_kind' => 'summary', 'feature' => 'minutes'], $entry['metadata']);
    }

    public function testErrorMessageBecomesExceptionClassName(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $tracer->log(array_merge($this->logWithContent(), [
            'status' => 'error',
            'error_message' => 'bad request: ' . self::SECRET,
            'error_type' => 'OpenAI\\Exceptions\\ErrorException',
        ]));

        [, $entry] = $sent[0];
        $this->assertNoSecret($entry);
        $this->assertSame('error', $entry['status']);
        $this->assertSame('OpenAI\\Exceptions\\ErrorException', $entry['error_message']);
        $this->assertArrayNotHasKey('error_type', $entry);
    }

    public function testErrorMessageWithoutTypeIsNotSent(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $tracer->log(array_merge($this->logWithContent(), [
            'status' => 'error',
            'error_message' => self::SECRET,
        ]));

        $this->assertNull($sent[0][1]['error_message']);
    }

    public function testSessionIdIsSent(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $tracer->startSession(['session_id' => 'sess-1', 'user_id' => 'u-1']);
        $tracer->log($this->logWithContent());

        [$endpoint, $entry] = $sent[1];
        $this->assertSame('/api/v1/logs/', $endpoint);
        $this->assertSame('sess-1', $entry['session_id']);
        $this->assertSame('u-1', $entry['app_user_id']);
        $this->assertSame(self::REDACTED, $entry['input']);
    }

    public function testWithPiiDetection(): void
    {
        $tracer = $this->makeTracer(['pii_detection' => true]);
        $sent = $this->captureHttp($tracer);

        $tracer->log(array_merge($this->logWithContent(), [
            'input' => ['text' => self::SECRET . ' ' . self::EMAIL],
            'status' => 'error',
            'error_message' => 'invalid ' . self::EMAIL,
            'error_type' => 'InvalidArgumentException',
            'metadata' => ['owner' => self::EMAIL],
        ]));

        [, $entry] = $sent[0];
        $this->assertNoSecret($entry);
        $this->assertStringNotContainsString(self::EMAIL, json_encode($entry, JSON_THROW_ON_ERROR));
        $this->assertSame(self::REDACTED, $entry['input']);
        $this->assertSame(self::REDACTED, $entry['output']);
        $this->assertSame('InvalidArgumentException', $entry['error_message']);
        $this->assertSame(['owner' => '[email]'], $entry['metadata']);
    }

    public function testBatchedLogsDropContent(): void
    {
        $tracer = $this->makeTracer(['sync' => false, 'batch_size' => 10]);
        $sent = $this->captureHttp($tracer);

        $tracer->log($this->logWithContent());
        $tracer->log($this->logWithContent());
        $tracer->flush();

        [$endpoint, $batch] = $sent[0];
        $this->assertSame('/api/v1/logs/batch/', $endpoint);
        $this->assertNoSecret($batch);
        $this->assertCount(2, $batch['logs']);
        $this->assertSame(self::REDACTED, $batch['logs'][0]['input']);
        $this->assertSame(100, $batch['logs'][0]['input_tokens']);
    }

    // --- Wrappers compute token counts before the content is dropped ---

    public function testOpenAIWrapperKeepsTokenCounts(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);
        $completions = new class {
            public function create(array $parameters): array
            {
                return [
                    'choices' => [['message' => ['content' => '議事録: 山田太郎さんの住所は東京都千代田区1-1-1'], 'finish_reason' => 'stop']],
                    'usage' => [
                        'prompt_tokens' => 120,
                        'completion_tokens' => 30,
                        'prompt_tokens_details' => ['cached_tokens' => 20],
                    ],
                ];
            }
        };

        $response = (new ChatCompletionsWrapper($completions, $tracer))->create([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => self::SECRET]],
        ]);

        $this->assertSame(self::SECRET, $response['choices'][0]['message']['content']);
        [, $entry] = $sent[0];
        $this->assertNoSecret($entry);
        $this->assertSame(self::REDACTED, $entry['input']);
        $this->assertSame(self::REDACTED, $entry['output']);
        $this->assertSame('gpt-4o', $entry['model']);
        $this->assertSame(100, $entry['input_tokens']);
        $this->assertSame(30, $entry['output_tokens']);
        $this->assertSame(20, $entry['cache_read_input_tokens']);
    }

    public function testOpenAIWrapperStreamKeepsTokenCounts(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);
        $completions = new class {
            public function createStreamed(array $parameters): array
            {
                return [
                    ['choices' => [['delta' => ['content' => '議事録: 山田']]]],
                    ['choices' => [['delta' => ['content' => '太郎さん']]]],
                    ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12]],
                ];
            }
        };

        $stream = (new ChatCompletionsWrapper($completions, $tracer))->createStreamed([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => self::SECRET]],
        ]);
        foreach ($stream as $chunk) {
            // Consume the stream to trigger the completion log
        }

        [, $entry] = $sent[0];
        $this->assertNoSecret($entry);
        $this->assertSame(self::REDACTED, $entry['output']);
        $this->assertSame(40, $entry['input_tokens']);
        $this->assertSame(12, $entry['output_tokens']);
    }

    public function testOpenAIWrapperErrorSendsClassNameOnly(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);
        $completions = new class {
            public function create(array $parameters): array
            {
                throw new \DomainException('rejected: 議事録: 山田太郎さんの住所は東京都千代田区1-1-1');
            }
        };

        try {
            (new ChatCompletionsWrapper($completions, $tracer))->create([
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => self::SECRET]],
            ]);
            $this->fail('The exception must be rethrown');
        } catch (\DomainException) {
            // expected
        }

        [, $entry] = $sent[0];
        $this->assertNoSecret($entry);
        $this->assertSame('error', $entry['status']);
        $this->assertSame('DomainException', $entry['error_message']);
    }

    public function testOpenAIWrapperErrorSendsMessageByDefault(): void
    {
        $tracer = $this->makeTracer(['capture_content' => true]);
        $sent = $this->captureHttp($tracer);
        $completions = new class {
            public function create(array $parameters): array
            {
                throw new \DomainException('rejected');
            }
        };

        try {
            (new ChatCompletionsWrapper($completions, $tracer))->create(['model' => 'gpt-4o', 'messages' => []]);
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame('rejected', $sent[0][1]['error_message']);
    }

    // --- Sessions ---

    public function testSessionNameAndMetadataAreSent(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $tracer->startSession(['session_id' => 'sess-1', 'name' => 'weekly', 'metadata' => ['plan' => 'pro']]);
        $tracer->endSession();

        [, $started] = $sent[0];
        [$endEndpoint, $ended] = $sent[1];
        $this->assertSame('sess-1', $started['session_id']);
        $this->assertSame('weekly', $started['name']);
        $this->assertSame(['plan' => 'pro'], $started['metadata']);
        $this->assertSame('/api/v1/sessions/sess-1/end/', $endEndpoint);
        $this->assertSame(['plan' => 'pro'], $ended['metadata']);
    }

    public function testSessionEventDataIsRedacted(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $session = $tracer->startSession();
        $session->event('message', ['name' => 'user_message', 'data' => ['content' => self::SECRET], 'log_id' => 'l-1']);

        [$endpoint, $event] = $sent[1];
        $this->assertStringEndsWith('/events/', $endpoint);
        $this->assertNoSecret($event);
        $this->assertSame('message', $event['event_type']);
        $this->assertSame('user_message', $event['name']);
        $this->assertSame(self::REDACTED, $event['data']);
        $this->assertSame('l-1', $event['log_id']);
    }

    public function testFeedbackCommentIsNotSent(): void
    {
        $tracer = $this->makeTracer();
        $sent = $this->captureHttp($tracer);

        $session = $tracer->startSession(['user_id' => 'u-1']);
        $session->rate(4, 'l-1', self::SECRET);

        [$endpoint, $feedback] = $sent[1];
        $this->assertStringEndsWith('/feedback/', $endpoint);
        $this->assertNoSecret($feedback);
        $this->assertNull($feedback['comment']);
        $this->assertSame('rating', $feedback['feedback_type']);
        $this->assertSame(4, $feedback['score']);
        $this->assertSame('l-1', $feedback['log_id']);
        $this->assertSame('u-1', $feedback['user_id']);
    }

    public function testDefaultSendsEventDataAndComment(): void
    {
        $tracer = $this->makeTracer(['capture_content' => true]);
        $sent = $this->captureHttp($tracer);

        $session = $tracer->startSession();
        $session->event('message', ['data' => ['content' => self::SECRET]]);
        $session->thumbsUp('l-1', self::SECRET);

        $this->assertSame(['content' => self::SECRET], $sent[1][1]['data']);
        $this->assertSame(self::SECRET, $sent[2][1]['comment']);
    }
}
