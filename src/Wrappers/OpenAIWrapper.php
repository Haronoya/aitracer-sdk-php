<?php

declare(strict_types=1);

namespace AITracer\Wrappers;

use AITracer\AITracer;
use Throwable;

/**
 * Wrapper for OpenAI PHP client to automatically log API calls.
 *
 * Supports:
 * - openai-php/client (official community client)
 * - Any client with compatible interface
 *
 * Usage:
 * ```php
 * $openai = OpenAI::client('sk-...');
 * $traced = $tracer->wrapOpenAI($openai);
 * $response = $traced->chat()->completions()->create([...]);
 * ```
 */
class OpenAIWrapper
{
    private object $client;
    private AITracer $tracer;

    public function __construct(object $client, AITracer $tracer)
    {
        $this->client = $client;
        $this->tracer = $tracer;
    }

    /**
     * Get the chat completions wrapper.
     */
    public function chat(): ChatWrapper
    {
        return new ChatWrapper($this->client->chat(), $this->tracer);
    }

    /**
     * Get the completions wrapper (legacy).
     */
    public function completions(): CompletionsWrapper
    {
        return new CompletionsWrapper($this->client->completions(), $this->tracer);
    }

    /**
     * Get the embeddings wrapper.
     */
    public function embeddings(): EmbeddingsWrapper
    {
        return new EmbeddingsWrapper($this->client->embeddings(), $this->tracer);
    }

    /**
     * Get the audio wrapper (Whisper API).
     */
    public function audio(): AudioWrapper
    {
        return new AudioWrapper($this->client->audio(), $this->tracer);
    }

    /**
     * Forward other method calls to the original client.
     *
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->client->$name(...$arguments);
    }
}

/**
 * Wrapper for chat completions.
 */
class ChatWrapper
{
    private object $chat;
    private AITracer $tracer;

    public function __construct(object $chat, AITracer $tracer)
    {
        $this->chat = $chat;
        $this->tracer = $tracer;
    }

    /**
     * Get completions interface.
     */
    public function completions(): ChatCompletionsWrapper
    {
        return new ChatCompletionsWrapper($this->chat->completions(), $this->tracer);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->chat->$name(...$arguments);
    }
}

/**
 * Wrapper for chat.completions.create().
 */
class ChatCompletionsWrapper
{
    private object $completions;
    private AITracer $tracer;

    public function __construct(object $completions, AITracer $tracer)
    {
        $this->completions = $completions;
        $this->tracer = $tracer;
    }

    /**
     * Create a chat completion with automatic logging.
     *
     * @param array $parameters
     * @return mixed
     */
    public function create(array $parameters): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->completions->create($parameters);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall($parameters, $response, $startTime, $error);
        }
    }

    /**
     * Create a streaming chat completion with automatic logging.
     *
     * @param array $parameters
     * @return mixed
     */
    public function createStreamed(array $parameters): mixed
    {
        $parameters['stream'] = true;
        $startTime = microtime(true);

        try {
            $stream = $this->completions->createStreamed($parameters);

            // Return a wrapper that logs when stream completes
            return new StreamWrapper($stream, function ($chunks) use ($parameters, $startTime) {
                $this->logStreamedCall($parameters, $chunks, $startTime);
            });
        } catch (Throwable $e) {
            $this->logCall($parameters, null, $startTime, $e);
            throw $e;
        }
    }

    /**
     * Log a chat completion call.
     */
    private function logCall(array $parameters, mixed $response, float $startTime, ?Throwable $error): void
    {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $logData = [
            'model' => $parameters['model'] ?? 'unknown',
            'provider' => 'openai',
            'input' => [
                'messages' => $parameters['messages'] ?? [],
                'temperature' => $parameters['temperature'] ?? null,
                'max_tokens' => $parameters['max_tokens'] ?? null,
                'tools' => $parameters['tools'] ?? null,
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
     * Log a streamed chat completion.
     */
    private function logStreamedCall(array $parameters, array $chunks, float $startTime): void
    {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Combine chunks into final response
        $content = '';
        $toolCalls = [];
        $finishReason = null;
        $model = $parameters['model'] ?? 'unknown';

        foreach ($chunks as $chunk) {
            if (isset($chunk['choices'][0]['delta']['content'])) {
                $content .= $chunk['choices'][0]['delta']['content'];
            }
            if (isset($chunk['choices'][0]['delta']['tool_calls'])) {
                $toolCalls = array_merge($toolCalls, $chunk['choices'][0]['delta']['tool_calls']);
            }
            if (isset($chunk['choices'][0]['finish_reason'])) {
                $finishReason = $chunk['choices'][0]['finish_reason'];
            }
            if (isset($chunk['model'])) {
                $model = $chunk['model'];
            }
        }

        $logData = [
            'model' => $model,
            'provider' => 'openai',
            'input' => [
                'messages' => $parameters['messages'] ?? [],
                'temperature' => $parameters['temperature'] ?? null,
                'max_tokens' => $parameters['max_tokens'] ?? null,
                'stream' => true,
            ],
            'output' => [
                'content' => $content,
                'tool_calls' => $toolCalls ?: null,
                'finish_reason' => $finishReason,
            ],
            'latency_ms' => $latencyMs,
            'status' => 'success',
            // Token counts not available in streaming
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];

        $this->tracer->log($logData);
    }

    /**
     * Extract output content from response.
     */
    private function extractOutput(mixed $response): array
    {
        if ($response === null) {
            return [];
        }

        // Handle openai-php/client response object
        if (is_object($response)) {
            $choice = $response->choices[0] ?? null;
            if ($choice) {
                return [
                    'content' => $choice->message->content ?? null,
                    'tool_calls' => $choice->message->toolCalls ?? null,
                    'finish_reason' => $choice->finishReason ?? null,
                ];
            }
        }

        // Handle array response
        if (is_array($response)) {
            $choice = $response['choices'][0] ?? null;
            if ($choice) {
                return [
                    'content' => $choice['message']['content'] ?? null,
                    'tool_calls' => $choice['message']['tool_calls'] ?? null,
                    'finish_reason' => $choice['finish_reason'] ?? null,
                ];
            }
        }

        return [];
    }

    /**
     * Extract input token count from response.
     */
    private function extractInputTokens(mixed $response): int
    {
        if (is_object($response) && isset($response->usage->promptTokens)) {
            return $response->usage->promptTokens;
        }
        if (is_array($response) && isset($response['usage']['prompt_tokens'])) {
            return $response['usage']['prompt_tokens'];
        }
        return 0;
    }

    /**
     * Extract output token count from response.
     */
    private function extractOutputTokens(mixed $response): int
    {
        if (is_object($response) && isset($response->usage->completionTokens)) {
            return $response->usage->completionTokens;
        }
        if (is_array($response) && isset($response['usage']['completion_tokens'])) {
            return $response['usage']['completion_tokens'];
        }
        return 0;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->completions->$name(...$arguments);
    }
}

/**
 * Wrapper for legacy completions API.
 */
class CompletionsWrapper
{
    private object $completions;
    private AITracer $tracer;

    public function __construct(object $completions, AITracer $tracer)
    {
        $this->completions = $completions;
        $this->tracer = $tracer;
    }

    /**
     * Create a completion with automatic logging.
     */
    public function create(array $parameters): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->completions->create($parameters);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $logData = [
                'model' => $parameters['model'] ?? 'unknown',
                'provider' => 'openai',
                'input' => [
                    'prompt' => $parameters['prompt'] ?? '',
                    'max_tokens' => $parameters['max_tokens'] ?? null,
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
                    'text' => $response->choices[0]->text ?? '',
                ];
                $logData['input_tokens'] = $response->usage->promptTokens ?? 0;
                $logData['output_tokens'] = $response->usage->completionTokens ?? 0;
            }

            $this->tracer->log($logData);
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->completions->$name(...$arguments);
    }
}

/**
 * Wrapper for embeddings API.
 */
class EmbeddingsWrapper
{
    private object $embeddings;
    private AITracer $tracer;

    public function __construct(object $embeddings, AITracer $tracer)
    {
        $this->embeddings = $embeddings;
        $this->tracer = $tracer;
    }

    /**
     * Create embeddings with automatic logging.
     */
    public function create(array $parameters): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->embeddings->create($parameters);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $logData = [
                'model' => $parameters['model'] ?? 'text-embedding-ada-002',
                'provider' => 'openai',
                'input' => [
                    'input' => $parameters['input'] ?? '',
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
                    'embedding_count' => count($response->embeddings ?? []),
                ];
                $logData['input_tokens'] = $response->usage->promptTokens ?? 0;
                $logData['output_tokens'] = 0;
            }

            $this->tracer->log($logData);
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->embeddings->$name(...$arguments);
    }
}

/**
 * Wrapper for streaming responses.
 */
class StreamWrapper implements \IteratorAggregate
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
                // Convert to array for storage
                if (is_object($chunk)) {
                    $this->chunks[] = json_decode(json_encode($chunk), true);
                } else {
                    $this->chunks[] = $chunk;
                }
                yield $chunk;
            }
        } finally {
            ($this->onComplete)($this->chunks);
        }
    }
}

/**
 * Wrapper for audio API (Whisper).
 */
class AudioWrapper
{
    private object $audio;
    private AITracer $tracer;

    public function __construct(object $audio, AITracer $tracer)
    {
        $this->audio = $audio;
        $this->tracer = $tracer;
    }

    /**
     * Get transcriptions interface.
     */
    public function transcriptions(): AudioTranscriptionsWrapper
    {
        return new AudioTranscriptionsWrapper($this->audio->transcriptions(), $this->tracer);
    }

    /**
     * Get translations interface.
     */
    public function translations(): AudioTranslationsWrapper
    {
        return new AudioTranslationsWrapper($this->audio->translations(), $this->tracer);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->audio->$name(...$arguments);
    }
}

/**
 * Wrapper for audio.transcriptions.create() (Whisper).
 */
class AudioTranscriptionsWrapper
{
    private object $transcriptions;
    private AITracer $tracer;

    public function __construct(object $transcriptions, AITracer $tracer)
    {
        $this->transcriptions = $transcriptions;
        $this->tracer = $tracer;
    }

    /**
     * Create a transcription with automatic logging.
     *
     * @param array $parameters
     * @return mixed
     */
    public function create(array $parameters): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->transcriptions->create($parameters);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall($parameters, $response, $startTime, $error);
        }
    }

    /**
     * Log a transcription call.
     */
    private function logCall(array $parameters, mixed $response, float $startTime, ?Throwable $error): void
    {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Get file info
        $fileInfo = $this->getFileInfo($parameters['file'] ?? null);

        $logData = [
            'model' => $parameters['model'] ?? 'whisper-1',
            'provider' => 'openai',
            'input' => [
                'type' => 'audio_transcription',
                'file_name' => $fileInfo['name'],
                'file_size_bytes' => $fileInfo['size'],
                'language' => $parameters['language'] ?? null,
            ],
            'latency_ms' => $latencyMs,
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['output'] = [];
        } else {
            $logData['status'] = 'success';
            $text = $this->extractText($response);
            $logData['output'] = [
                'text' => $text,
                'text_length' => strlen($text),
            ];
        }

        $this->tracer->log($logData);
    }

    /**
     * Extract text from response.
     */
    private function extractText(mixed $response): string
    {
        if ($response === null) {
            return '';
        }

        if (is_object($response) && isset($response->text)) {
            return $response->text;
        }

        if (is_array($response) && isset($response['text'])) {
            return $response['text'];
        }

        if (is_string($response)) {
            return $response;
        }

        return '';
    }

    /**
     * Get file info from file parameter.
     */
    private function getFileInfo(mixed $file): array
    {
        $info = ['name' => null, 'size' => null];

        if ($file === null) {
            return $info;
        }

        // Handle SplFileInfo
        if ($file instanceof \SplFileInfo) {
            $info['name'] = $file->getFilename();
            $info['size'] = $file->getSize();
            return $info;
        }

        // Handle resource (fopen)
        if (is_resource($file)) {
            $meta = stream_get_meta_data($file);
            if (isset($meta['uri'])) {
                $info['name'] = basename($meta['uri']);
                if (file_exists($meta['uri'])) {
                    $info['size'] = filesize($meta['uri']);
                }
            }
            return $info;
        }

        // Handle string (file path)
        if (is_string($file) && file_exists($file)) {
            $info['name'] = basename($file);
            $info['size'] = filesize($file);
            return $info;
        }

        return $info;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->transcriptions->$name(...$arguments);
    }
}

/**
 * Wrapper for audio.translations.create() (Whisper).
 */
class AudioTranslationsWrapper
{
    private object $translations;
    private AITracer $tracer;

    public function __construct(object $translations, AITracer $tracer)
    {
        $this->translations = $translations;
        $this->tracer = $tracer;
    }

    /**
     * Create a translation with automatic logging.
     *
     * @param array $parameters
     * @return mixed
     */
    public function create(array $parameters): mixed
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->translations->create($parameters);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall($parameters, $response, $startTime, $error);
        }
    }

    /**
     * Log a translation call.
     */
    private function logCall(array $parameters, mixed $response, float $startTime, ?Throwable $error): void
    {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Get file info
        $fileInfo = $this->getFileInfo($parameters['file'] ?? null);

        $logData = [
            'model' => $parameters['model'] ?? 'whisper-1',
            'provider' => 'openai',
            'input' => [
                'type' => 'audio_translation',
                'file_name' => $fileInfo['name'],
                'file_size_bytes' => $fileInfo['size'],
            ],
            'latency_ms' => $latencyMs,
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['output'] = [];
        } else {
            $logData['status'] = 'success';
            $text = $this->extractText($response);
            $logData['output'] = [
                'text' => $text,
                'text_length' => strlen($text),
            ];
        }

        $this->tracer->log($logData);
    }

    /**
     * Extract text from response.
     */
    private function extractText(mixed $response): string
    {
        if ($response === null) {
            return '';
        }

        if (is_object($response) && isset($response->text)) {
            return $response->text;
        }

        if (is_array($response) && isset($response['text'])) {
            return $response['text'];
        }

        if (is_string($response)) {
            return $response;
        }

        return '';
    }

    /**
     * Get file info from file parameter.
     */
    private function getFileInfo(mixed $file): array
    {
        $info = ['name' => null, 'size' => null];

        if ($file === null) {
            return $info;
        }

        // Handle SplFileInfo
        if ($file instanceof \SplFileInfo) {
            $info['name'] = $file->getFilename();
            $info['size'] = $file->getSize();
            return $info;
        }

        // Handle resource (fopen)
        if (is_resource($file)) {
            $meta = stream_get_meta_data($file);
            if (isset($meta['uri'])) {
                $info['name'] = basename($meta['uri']);
                if (file_exists($meta['uri'])) {
                    $info['size'] = filesize($meta['uri']);
                }
            }
            return $info;
        }

        // Handle string (file path)
        if (is_string($file) && file_exists($file)) {
            $info['name'] = basename($file);
            $info['size'] = filesize($file);
            return $info;
        }

        return $info;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->translations->$name(...$arguments);
    }
}
