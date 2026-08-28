<?php

declare(strict_types=1);

namespace AITracer\Tests;

use AITracer\AITracer;
use AITracer\Wrappers\OpenAIWrapper;
use AITracer\Wrappers\ChatCompletionsWrapper;
use AITracer\Wrappers\AnthropicWrapper;
use AITracer\Wrappers\MessagesWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for prompt cache token extraction and normalization.
 */
class CacheTokensTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $capturedLog = null;

    protected function setUp(): void
    {
        // Force-load wrapper files (each defines multiple classes)
        class_exists(OpenAIWrapper::class);
        class_exists(AnthropicWrapper::class);
        $this->capturedLog = null;
    }

    protected function tearDown(): void
    {
        // Clear singleton instance
        $reflection = new \ReflectionClass(AITracer::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /**
     * Create a mocked tracer that captures log() calls.
     */
    private function createCapturingTracer(): AITracer
    {
        $tracer = $this->createMock(AITracer::class);
        $tracer->method('log')->willReturnCallback(function (array $data): void {
            $this->capturedLog = $data;
        });

        return $tracer;
    }

    // --- AITracer::buildLogEntry ---

    public function testLogEntryIncludesCacheTokens(): void
    {
        $tracer = new AITracer([
            'api_key' => 'at-test-key',
            'project' => 'test-project',
            'enabled' => false,
        ]);

        $method = new \ReflectionMethod(AITracer::class, 'buildLogEntry');
        $method->setAccessible(true);
        $entry = $method->invoke($tracer, [
            'model' => 'claude-sonnet-4',
            'provider' => 'anthropic',
            'input' => [],
            'output' => [],
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cache_read_input_tokens' => 100,
            'cache_creation_input_tokens' => 50,
        ]);

        $this->assertSame(10, $entry['input_tokens']);
        $this->assertSame(100, $entry['cache_read_input_tokens']);
        $this->assertSame(50, $entry['cache_creation_input_tokens']);
    }

    public function testLogEntryOmitsZeroCacheTokens(): void
    {
        $tracer = new AITracer([
            'api_key' => 'at-test-key',
            'project' => 'test-project',
            'enabled' => false,
        ]);

        $method = new \ReflectionMethod(AITracer::class, 'buildLogEntry');
        $method->setAccessible(true);
        $entry = $method->invoke($tracer, [
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'input' => [],
            'output' => [],
            'input_tokens' => 10,
            'output_tokens' => 5,
        ]);

        $this->assertArrayNotHasKey('cache_read_input_tokens', $entry);
        $this->assertArrayNotHasKey('cache_creation_input_tokens', $entry);
    }

    // --- OpenAI: cached_tokens deduction ---

    public function testOpenAIDeductsCachedTokensFromInput(): void
    {
        $tracer = $this->createCapturingTracer();

        $completions = new class {
            public function create(array $parameters): array
            {
                return [
                    'choices' => [
                        ['message' => ['content' => 'Hello'], 'finish_reason' => 'stop'],
                    ],
                    'usage' => [
                        // prompt_tokens INCLUDES cached tokens
                        'prompt_tokens' => 110,
                        'completion_tokens' => 20,
                        'prompt_tokens_details' => ['cached_tokens' => 100],
                    ],
                ];
            }
        };

        $wrapper = new ChatCompletionsWrapper($completions, $tracer);
        $wrapper->create(['model' => 'gpt-4o', 'messages' => []]);

        $this->assertSame(10, $this->capturedLog['input_tokens']);
        $this->assertSame(20, $this->capturedLog['output_tokens']);
        $this->assertSame(100, $this->capturedLog['cache_read_input_tokens']);
    }

    public function testOpenAIWithoutDetailsIsUnchanged(): void
    {
        $tracer = $this->createCapturingTracer();

        $completions = new class {
            public function create(array $parameters): array
            {
                return [
                    'choices' => [
                        ['message' => ['content' => 'Hello'], 'finish_reason' => 'stop'],
                    ],
                    'usage' => [
                        'prompt_tokens' => 110,
                        'completion_tokens' => 20,
                    ],
                ];
            }
        };

        $wrapper = new ChatCompletionsWrapper($completions, $tracer);
        $wrapper->create(['model' => 'gpt-4o', 'messages' => []]);

        $this->assertSame(110, $this->capturedLog['input_tokens']);
        $this->assertArrayNotHasKey('cache_read_input_tokens', $this->capturedLog);
    }

    public function testOpenAIStreamedCachedTokens(): void
    {
        $tracer = $this->createCapturingTracer();

        $completions = new class {
            public function createStreamed(array $parameters): array
            {
                return [
                    ['choices' => [['delta' => ['content' => 'Hello']]]],
                    [
                        'choices' => [],
                        'usage' => [
                            'prompt_tokens' => 110,
                            'completion_tokens' => 20,
                            'prompt_tokens_details' => ['cached_tokens' => 100],
                        ],
                    ],
                ];
            }
        };

        $wrapper = new ChatCompletionsWrapper($completions, $tracer);
        $stream = $wrapper->createStreamed(['model' => 'gpt-4o', 'messages' => []]);

        // Consume the stream to trigger the completion log
        foreach ($stream as $chunk) {
            // no-op
        }

        $this->assertSame(10, $this->capturedLog['input_tokens']);
        $this->assertSame(20, $this->capturedLog['output_tokens']);
        $this->assertSame(100, $this->capturedLog['cache_read_input_tokens']);
    }

    // --- Anthropic: cache tokens reported separately (no deduction) ---

    public function testAnthropicReportsCacheTokensSeparately(): void
    {
        $tracer = $this->createCapturingTracer();

        $messages = new class {
            public function create(array $parameters): array
            {
                return [
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                    'stop_reason' => 'end_turn',
                    'usage' => [
                        // input_tokens already excludes cache tokens
                        'input_tokens' => 10,
                        'output_tokens' => 20,
                        'cache_read_input_tokens' => 100,
                        'cache_creation_input_tokens' => 50,
                    ],
                ];
            }
        };

        $wrapper = new MessagesWrapper($messages, $tracer);
        $wrapper->create(['model' => 'claude-sonnet-4', 'messages' => []]);

        // input_tokens passed through as-is (no deduction)
        $this->assertSame(10, $this->capturedLog['input_tokens']);
        $this->assertSame(20, $this->capturedLog['output_tokens']);
        $this->assertSame(100, $this->capturedLog['cache_read_input_tokens']);
        $this->assertSame(50, $this->capturedLog['cache_creation_input_tokens']);
    }

    public function testAnthropicWithoutCacheFieldsIsUnchanged(): void
    {
        $tracer = $this->createCapturingTracer();

        $messages = new class {
            public function create(array $parameters): array
            {
                return [
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                    'stop_reason' => 'end_turn',
                    'usage' => [
                        'input_tokens' => 10,
                        'output_tokens' => 20,
                    ],
                ];
            }
        };

        $wrapper = new MessagesWrapper($messages, $tracer);
        $wrapper->create(['model' => 'claude-sonnet-4', 'messages' => []]);

        $this->assertSame(10, $this->capturedLog['input_tokens']);
        $this->assertArrayNotHasKey('cache_read_input_tokens', $this->capturedLog);
        $this->assertArrayNotHasKey('cache_creation_input_tokens', $this->capturedLog);
    }

    public function testAnthropicStreamedCacheTokens(): void
    {
        $tracer = $this->createCapturingTracer();

        $messages = new class {
            public function createStreamed(array $parameters): array
            {
                return [
                    [
                        'type' => 'message_start',
                        'message' => [
                            'model' => 'claude-sonnet-4',
                            'usage' => [
                                'input_tokens' => 10,
                                'cache_read_input_tokens' => 100,
                                'cache_creation_input_tokens' => 50,
                            ],
                        ],
                    ],
                    ['type' => 'content_block_delta', 'delta' => ['text' => 'Hello']],
                    [
                        'type' => 'message_delta',
                        'delta' => ['stop_reason' => 'end_turn'],
                        'usage' => ['output_tokens' => 20],
                    ],
                ];
            }
        };

        $wrapper = new MessagesWrapper($messages, $tracer);
        $stream = $wrapper->createStreamed(['model' => 'claude-sonnet-4', 'messages' => []]);

        // Consume the stream to trigger the completion log
        foreach ($stream as $event) {
            // no-op
        }

        $this->assertSame(10, $this->capturedLog['input_tokens']);
        $this->assertSame(20, $this->capturedLog['output_tokens']);
        $this->assertSame(100, $this->capturedLog['cache_read_input_tokens']);
        $this->assertSame(50, $this->capturedLog['cache_creation_input_tokens']);
    }
}
