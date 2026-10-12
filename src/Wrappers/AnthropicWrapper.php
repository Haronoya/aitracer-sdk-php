<?php

declare(strict_types=1);

namespace AITracer\Wrappers;

use AITracer\AITracer;
use Throwable;

/**
 * Wrapper for Anthropic PHP client to automatically log API calls.
 *
 * Usage:
 * ```php
 * $anthropic = new Anthropic\Client('sk-ant-...');
 * $traced = $tracer->wrapAnthropic($anthropic);
 * $response = $traced->messages()->create([...]);
 * ```
 */
class AnthropicWrapper
{
    private object $client;
    private AITracer $tracer;

    public function __construct(object $client, AITracer $tracer)
    {
        $this->client = $client;
        $this->tracer = $tracer;
    }

    /**
     * Get the messages wrapper.
     */
    public function messages(): MessagesWrapper
    {
        return new MessagesWrapper($this->client->messages(), $this->tracer);
    }

    /**
     * Forward other method calls to the original client.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->client->$name(...$arguments);
    }
}

/**
 * Wrapper for Anthropic messages API.
 */
class MessagesWrapper
{
    private object $messages;
    private AITracer $tracer;

    public function __construct(object $messages, AITracer $tracer)
    {
        $this->messages = $messages;
        $this->tracer = $tracer;
    }

    /**
     * Create a message with automatic logging.
     */
    public function create(array $parameters): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->messages->create($parameters);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall($parameters, $response, $startTime, $error);
        }
    }

    /**
     * Create a streaming message with automatic logging.
     */
    public function createStreamed(array $parameters): mixed
    {
        $parameters['stream'] = true;
        $startTime = microtime(true);

        try {
            $stream = $this->messages->createStreamed($parameters);

            return new AnthropicStreamWrapper($stream, function ($events) use ($parameters, $startTime) {
                $this->logStreamedCall($parameters, $events, $startTime);
            });
        } catch (Throwable $e) {
            $this->logCall($parameters, null, $startTime, $e);
            throw $e;
        }
    }

    /**
     * Log a message creation call.
     */
    private function logCall(array $parameters, mixed $response, float $startTime, ?Throwable $error): void
    {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $logData = [
            'model' => $parameters['model'] ?? 'unknown',
            'provider' => 'anthropic',
            'input' => [
                'messages' => $parameters['messages'] ?? [],
                'system' => $parameters['system'] ?? null,
                'max_tokens' => $parameters['max_tokens'] ?? null,
                'temperature' => $parameters['temperature'] ?? null,
                'tools' => $parameters['tools'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['error_type'] = $error::class;
            $logData['output'] = [];
            $logData['input_tokens'] = 0;
            $logData['output_tokens'] = 0;
        } else {
            $logData['status'] = 'success';
            $logData['output'] = $this->extractOutput($response);
            // Anthropic's input_tokens already excludes cache tokens,
            // so it is passed through as-is (no deduction).
            $logData['input_tokens'] = $this->extractInputTokens($response);
            $logData['output_tokens'] = $this->extractOutputTokens($response);

            $cacheReadTokens = $this->extractCacheTokens($response, 'cache_read_input_tokens');
            if ($cacheReadTokens > 0) {
                $logData['cache_read_input_tokens'] = $cacheReadTokens;
            }
            $cacheCreationTokens = $this->extractCacheTokens($response, 'cache_creation_input_tokens');
            if ($cacheCreationTokens > 0) {
                $logData['cache_creation_input_tokens'] = $cacheCreationTokens;
            }
        }

        $this->tracer->log($logData);
    }

    /**
     * Log a streamed message call.
     */
    private function logStreamedCall(array $parameters, array $events, float $startTime): void
    {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Extract content from stream events
        $content = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheReadTokens = 0;
        $cacheCreationTokens = 0;
        $stopReason = null;
        $model = $parameters['model'] ?? 'unknown';

        foreach ($events as $event) {
            $type = $event['type'] ?? '';

            if ($type === 'content_block_delta') {
                $delta = $event['delta'] ?? [];
                if (isset($delta['text'])) {
                    $content .= $delta['text'];
                }
            }

            if ($type === 'message_delta') {
                $delta = $event['delta'] ?? [];
                $stopReason = $delta['stop_reason'] ?? $stopReason;
            }

            if ($type === 'message_start') {
                $message = $event['message'] ?? [];
                $model = $message['model'] ?? $model;
                $usage = $message['usage'] ?? [];
                // Anthropic's input_tokens already excludes cache tokens
                $inputTokens = $usage['input_tokens'] ?? $inputTokens;
                $cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? $cacheReadTokens);
                $cacheCreationTokens = (int) ($usage['cache_creation_input_tokens'] ?? $cacheCreationTokens);
            }

            if ($type === 'message_delta') {
                $usage = $event['usage'] ?? [];
                $outputTokens = $usage['output_tokens'] ?? $outputTokens;
            }
        }

        $logData = [
            'model' => $model,
            'provider' => 'anthropic',
            'input' => [
                'messages' => $parameters['messages'] ?? [],
                'system' => $parameters['system'] ?? null,
                'max_tokens' => $parameters['max_tokens'] ?? null,
                'stream' => true,
            ],
            'output' => [
                'content' => $content,
                'stop_reason' => $stopReason,
            ],
            'latency_ms' => $latencyMs,
            'status' => 'success',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];

        if ($cacheReadTokens > 0) {
            $logData['cache_read_input_tokens'] = $cacheReadTokens;
        }
        if ($cacheCreationTokens > 0) {
            $logData['cache_creation_input_tokens'] = $cacheCreationTokens;
        }

        $this->tracer->log($logData);
    }

    /**
     * Extract output from response.
     */
    private function extractOutput(mixed $response): array
    {
        if ($response === null) {
            return [];
        }

        // Handle object response
        if (is_object($response)) {
            $content = [];
            foreach ($response->content ?? [] as $block) {
                if (isset($block->text)) {
                    $content[] = ['type' => 'text', 'text' => $block->text];
                } elseif (isset($block->type) && $block->type === 'tool_use') {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $block->id ?? null,
                        'name' => $block->name ?? null,
                        'input' => $block->input ?? null,
                    ];
                }
            }

            return [
                'content' => $content,
                'stop_reason' => $response->stopReason ?? $response->stop_reason ?? null,
            ];
        }

        // Handle array response
        if (is_array($response)) {
            return [
                'content' => $response['content'] ?? [],
                'stop_reason' => $response['stop_reason'] ?? null,
            ];
        }

        return [];
    }

    /**
     * Extract input token count.
     */
    private function extractInputTokens(mixed $response): int
    {
        if (is_object($response) && isset($response->usage->inputTokens)) {
            return $response->usage->inputTokens;
        }
        if (is_object($response) && isset($response->usage->input_tokens)) {
            return $response->usage->input_tokens;
        }
        if (is_array($response) && isset($response['usage']['input_tokens'])) {
            return $response['usage']['input_tokens'];
        }
        return 0;
    }

    /**
     * Extract a prompt cache token count from response usage.
     *
     * Returns 0 when the field is absent (older API/client versions).
     *
     * @param string $field Snake_case field name, e.g. 'cache_read_input_tokens'
     */
    private function extractCacheTokens(mixed $response, string $field): int
    {
        // Object response: try camelCase then snake_case property
        if (is_object($response) && isset($response->usage)) {
            $camelField = lcfirst(str_replace('_', '', ucwords($field, '_')));
            $usage = $response->usage;
            if (is_object($usage)) {
                return (int) ($usage->$camelField ?? $usage->$field ?? 0);
            }
        }
        // Array response
        if (is_array($response) && isset($response['usage'][$field])) {
            return (int) $response['usage'][$field];
        }
        return 0;
    }

    /**
     * Extract output token count.
     */
    private function extractOutputTokens(mixed $response): int
    {
        if (is_object($response) && isset($response->usage->outputTokens)) {
            return $response->usage->outputTokens;
        }
        if (is_object($response) && isset($response->usage->output_tokens)) {
            return $response->usage->output_tokens;
        }
        if (is_array($response) && isset($response['usage']['output_tokens'])) {
            return $response['usage']['output_tokens'];
        }
        return 0;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->messages->$name(...$arguments);
    }
}

/**
 * Wrapper for Anthropic streaming responses.
 */
class AnthropicStreamWrapper implements \IteratorAggregate
{
    private iterable $stream;
    private \Closure $onComplete;
    private array $events = [];

    public function __construct(iterable $stream, \Closure $onComplete)
    {
        $this->stream = $stream;
        $this->onComplete = $onComplete;
    }

    public function getIterator(): \Generator
    {
        try {
            foreach ($this->stream as $event) {
                // Convert to array for storage
                if (is_object($event)) {
                    $this->events[] = json_decode(json_encode($event), true);
                } else {
                    $this->events[] = $event;
                }
                yield $event;
            }
        } finally {
            ($this->onComplete)($this->events);
        }
    }
}
