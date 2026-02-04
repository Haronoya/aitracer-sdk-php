<?php

declare(strict_types=1);

namespace AITracer\Wrappers;

use AITracer\AITracer;
use Throwable;

/**
 * Wrapper for Google Gemini PHP client to automatically log API calls.
 *
 * Supports:
 * - google-gemini-php/client
 * - Any client with compatible interface
 *
 * Usage:
 * ```php
 * use Google\GenerativeAI\Client;
 *
 * $client = new Client('your-api-key');
 * $model = $client->generativeModel('gemini-1.5-flash');
 * $traced = $tracer->wrapGemini($model);
 * $response = $traced->generateContent('Hello!');
 * ```
 */
class GeminiWrapper
{
    private object $model;
    private AITracer $tracer;
    private string $modelName;

    public function __construct(object $model, AITracer $tracer, ?string $modelName = null)
    {
        $this->model = $model;
        $this->tracer = $tracer;
        $this->modelName = $modelName ?? $this->extractModelName($model);
    }

    /**
     * Try to extract model name from the model object.
     */
    private function extractModelName(object $model): string
    {
        // Try common property/method names
        if (property_exists($model, 'model')) {
            return (string) $model->model;
        }
        if (property_exists($model, 'modelName')) {
            return (string) $model->modelName;
        }
        if (method_exists($model, 'getModel')) {
            return (string) $model->getModel();
        }
        if (method_exists($model, 'getModelName')) {
            return (string) $model->getModelName();
        }

        return 'gemini-unknown';
    }

    /**
     * Generate content with automatic logging.
     *
     * @param string|array $contents Text prompt or structured contents
     * @param array $options Optional generation options
     * @return mixed Response from Gemini
     */
    public function generateContent(string|array $contents, array $options = []): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->model->generateContent($contents, $options);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall($contents, $options, $response, $startTime, $error);
        }
    }

    /**
     * Generate content with streaming.
     *
     * @param string|array $contents Text prompt or structured contents
     * @param array $options Optional generation options
     * @return iterable Streaming response
     */
    public function streamGenerateContent(string|array $contents, array $options = []): iterable
    {
        $startTime = microtime(true);

        try {
            $stream = $this->model->streamGenerateContent($contents, $options);

            return new GeminiStreamWrapper($stream, function (array $chunks) use ($contents, $options, $startTime) {
                $this->logStreamedCall($contents, $options, $chunks, $startTime);
            });
        } catch (Throwable $e) {
            $this->logCall($contents, $options, null, $startTime, $e);
            throw $e;
        }
    }

    /**
     * Generate content (alias for generateContent).
     */
    public function generate(string|array $contents, array $options = []): mixed
    {
        return $this->generateContent($contents, $options);
    }

    /**
     * Count tokens in content.
     *
     * @param string|array $contents Text or structured contents
     * @return mixed Token count response
     */
    public function countTokens(string|array $contents): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->model->countTokens($contents);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $logData = [
                'model' => $this->modelName,
                'provider' => 'google',
                'input' => [
                    'contents' => $this->serializeContents($contents),
                    'operation' => 'countTokens',
                ],
                'latency_ms' => $latencyMs,
            ];

            if ($error !== null) {
                $logData['status'] = 'error';
                $logData['error_message'] = $error->getMessage();
                $logData['output'] = [];
            } else {
                $logData['status'] = 'success';
                $logData['output'] = [
                    'totalTokens' => $this->extractTokenCount($response),
                ];
            }

            $this->tracer->log($logData);
        }
    }

    /**
     * Log a generation call.
     */
    private function logCall(
        string|array $contents,
        array $options,
        mixed $response,
        float $startTime,
        ?Throwable $error
    ): void {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $logData = [
            'model' => $this->modelName,
            'provider' => 'google',
            'input' => [
                'contents' => $this->serializeContents($contents),
                'generationConfig' => $options['generationConfig'] ?? null,
                'safetySettings' => $options['safetySettings'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['output'] = [];
            $logData['input_tokens'] = 0;
            $logData['output_tokens'] = 0;
        } else {
            $logData['status'] = 'success';
            $logData['output'] = $this->extractOutput($response);
            $logData['input_tokens'] = $this->extractInputTokens($response);
            $logData['output_tokens'] = $this->extractOutputTokens($response);
        }

        $this->tracer->log($logData);
    }

    /**
     * Log a streamed generation call.
     */
    private function logStreamedCall(
        string|array $contents,
        array $options,
        array $chunks,
        float $startTime
    ): void {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Combine chunks
        $combinedText = '';
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($chunks as $chunk) {
            if (is_object($chunk)) {
                $text = method_exists($chunk, 'text') ? $chunk->text() : null;
                if ($text !== null) {
                    $combinedText .= $text;
                }

                // Extract usage metadata if available
                if (isset($chunk->usageMetadata)) {
                    $inputTokens = $chunk->usageMetadata->promptTokenCount ?? $inputTokens;
                    $outputTokens = $chunk->usageMetadata->candidatesTokenCount ?? $outputTokens;
                }
            } elseif (is_array($chunk)) {
                $combinedText .= $chunk['text'] ?? '';
                $inputTokens = $chunk['usageMetadata']['promptTokenCount'] ?? $inputTokens;
                $outputTokens = $chunk['usageMetadata']['candidatesTokenCount'] ?? $outputTokens;
            }
        }

        $logData = [
            'model' => $this->modelName,
            'provider' => 'google',
            'input' => [
                'contents' => $this->serializeContents($contents),
                'generationConfig' => $options['generationConfig'] ?? null,
                'stream' => true,
            ],
            'output' => [
                'content' => $combinedText,
            ],
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'status' => 'success',
        ];

        $this->tracer->log($logData);
    }

    /**
     * Serialize contents for logging.
     */
    private function serializeContents(string|array $contents): mixed
    {
        if (is_string($contents)) {
            return $contents;
        }

        // Handle structured contents
        return array_map(function ($item) {
            if (is_string($item)) {
                return $item;
            }
            if (is_object($item)) {
                return json_decode(json_encode($item), true);
            }
            if (is_array($item)) {
                // Mask binary data
                if (isset($item['inlineData']['data'])) {
                    $item['inlineData']['data'] = '[BASE64_DATA]';
                }
            }
            return $item;
        }, $contents);
    }

    /**
     * Extract output content from response.
     */
    private function extractOutput(mixed $response): array
    {
        if ($response === null) {
            return [];
        }

        // Try text() method
        if (is_object($response) && method_exists($response, 'text')) {
            try {
                return ['content' => $response->text()];
            } catch (Throwable $e) {
                // text() may throw if blocked
            }
        }

        // Try candidates
        $candidates = null;
        if (is_object($response) && isset($response->candidates)) {
            $candidates = $response->candidates;
        } elseif (is_array($response) && isset($response['candidates'])) {
            $candidates = $response['candidates'];
        }

        if ($candidates && count($candidates) > 0) {
            $parts = [];
            foreach ($candidates as $candidate) {
                $content = is_object($candidate) ? ($candidate->content ?? null) : ($candidate['content'] ?? null);
                if ($content) {
                    $contentParts = is_object($content) ? ($content->parts ?? []) : ($content['parts'] ?? []);
                    foreach ($contentParts as $part) {
                        $text = is_object($part) ? ($part->text ?? null) : ($part['text'] ?? null);
                        if ($text) {
                            $parts[] = $text;
                        }
                    }
                }
            }
            return ['content' => implode('', $parts)];
        }

        return [];
    }

    /**
     * Extract input token count from response.
     */
    private function extractInputTokens(mixed $response): int
    {
        if (is_object($response) && isset($response->usageMetadata->promptTokenCount)) {
            return $response->usageMetadata->promptTokenCount;
        }
        if (is_array($response) && isset($response['usageMetadata']['promptTokenCount'])) {
            return $response['usageMetadata']['promptTokenCount'];
        }
        return 0;
    }

    /**
     * Extract output token count from response.
     */
    private function extractOutputTokens(mixed $response): int
    {
        if (is_object($response) && isset($response->usageMetadata->candidatesTokenCount)) {
            return $response->usageMetadata->candidatesTokenCount;
        }
        if (is_array($response) && isset($response['usageMetadata']['candidatesTokenCount'])) {
            return $response['usageMetadata']['candidatesTokenCount'];
        }
        return 0;
    }

    /**
     * Extract token count from countTokens response.
     */
    private function extractTokenCount(mixed $response): int
    {
        if (is_object($response) && isset($response->totalTokens)) {
            return $response->totalTokens;
        }
        if (is_array($response) && isset($response['totalTokens'])) {
            return $response['totalTokens'];
        }
        return 0;
    }

    /**
     * Forward other method calls to the original model.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->model->$name(...$arguments);
    }
}

/**
 * Wrapper for Gemini streaming responses.
 */
class GeminiStreamWrapper implements \IteratorAggregate
{
    private iterable $stream;
    private \Closure $onComplete;
    private array $chunks = [];

    public function __construct(iterable $stream, \Closure $onComplete)
    {
        $this->stream = $stream;
        $this->onComplete = $onComplete;
    }

    public function getIterator(): \Generator
    {
        try {
            foreach ($this->stream as $chunk) {
                $this->chunks[] = $chunk;
                yield $chunk;
            }
        } finally {
            ($this->onComplete)($this->chunks);
        }
    }
}
