<?php

declare(strict_types=1);

namespace AITracer\Integrations;

use AITracer\AITracer;
use Throwable;

/**
 * LLPhant integration for AITracer.
 *
 * This handler wraps LLPhant chat operations to automatically log all LLM calls.
 *
 * LLPhant is a comprehensive PHP library for building LLM applications,
 * similar to LangChain for Python.
 *
 * @see https://github.com/theodo-group/LLPhant
 *
 * Usage:
 * ```php
 * use LLPhant\Chat\OpenAIChat;
 * use AITracer\AITracer;
 * use AITracer\Integrations\LLPhantHandler;
 *
 * $tracer = new AITracer(['api_key' => 'at-xxx', 'project' => 'my-project']);
 * $handler = new LLPhantHandler($tracer);
 *
 * $chat = new OpenAIChat();
 * $wrappedChat = $handler->wrapChat($chat);
 *
 * $response = $wrappedChat->generateText('Hello!');
 * ```
 */
class LLPhantHandler
{
    private AITracer $tracer;
    private ?string $sessionId = null;
    private ?string $userId = null;
    private array $defaultMetadata = [];

    public function __construct(
        AITracer $tracer,
        ?string $sessionId = null,
        ?string $userId = null,
        array $metadata = []
    ) {
        $this->tracer = $tracer;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->defaultMetadata = $metadata;
    }

    /**
     * Wrap an LLPhant Chat instance for automatic logging.
     *
     * @param object $chat LLPhant Chat instance (OpenAIChat, AnthropicChat, etc.)
     * @return LLPhantChatWrapper Wrapped chat instance
     */
    public function wrapChat(object $chat): LLPhantChatWrapper
    {
        return new LLPhantChatWrapper($chat, $this->tracer, $this);
    }

    /**
     * Wrap an LLPhant Embedding instance for automatic logging.
     *
     * @param object $embedding LLPhant Embedding instance
     * @return LLPhantEmbeddingWrapper Wrapped embedding instance
     */
    public function wrapEmbedding(object $embedding): LLPhantEmbeddingWrapper
    {
        return new LLPhantEmbeddingWrapper($embedding, $this->tracer, $this);
    }

    /**
     * Get the session ID.
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Set the session ID.
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Set the user ID.
     */
    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * Get the default metadata.
     */
    public function getDefaultMetadata(): array
    {
        return $this->defaultMetadata;
    }

    /**
     * Add metadata to be included with all logs.
     */
    public function addMetadata(string $key, mixed $value): void
    {
        $this->defaultMetadata[$key] = $value;
    }

    /**
     * Flush pending logs.
     */
    public function flush(): void
    {
        $this->tracer->flush();
    }
}

/**
 * Wrapper for LLPhant Chat classes.
 */
class LLPhantChatWrapper
{
    private object $chat;
    private AITracer $tracer;
    private LLPhantHandler $handler;

    public function __construct(object $chat, AITracer $tracer, LLPhantHandler $handler)
    {
        $this->chat = $chat;
        $this->tracer = $tracer;
        $this->handler = $handler;
    }

    /**
     * Generate text response (non-streaming).
     *
     * @param string $prompt User prompt
     * @return string Generated text
     */
    public function generateText(string $prompt): string
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->chat->generateText($prompt);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall('generateText', $prompt, $response, $startTime, $error);
        }
    }

    /**
     * Generate text response with system prompt.
     *
     * @param string $prompt User prompt
     * @param string $systemPrompt System prompt
     * @return string Generated text
     */
    public function generateTextWithSystemPrompt(string $prompt, string $systemPrompt): string
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->chat->generateTextWithSystemPrompt($prompt, $systemPrompt);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logCall('generateTextWithSystemPrompt', $prompt, $response, $startTime, $error, $systemPrompt);
        }
    }

    /**
     * Generate streaming text response.
     *
     * @param string $prompt User prompt
     * @return iterable Stream of text chunks
     */
    public function generateStreamOfText(string $prompt): iterable
    {
        $startTime = microtime(true);

        try {
            $stream = $this->chat->generateStreamOfText($prompt);

            return new LLPhantStreamWrapper($stream, function (string $fullText) use ($prompt, $startTime) {
                $this->logCall('generateStreamOfText', $prompt, $fullText, $startTime, null, null, true);
            });
        } catch (Throwable $e) {
            $this->logCall('generateStreamOfText', $prompt, null, $startTime, $e);
            throw $e;
        }
    }

    /**
     * Generate chat response.
     *
     * @param array $messages Array of messages [['role' => 'user', 'content' => '...'], ...]
     * @return string Generated response
     */
    public function generateChat(array $messages): string
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->chat->generateChat($messages);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logChatCall($messages, $response, $startTime, $error);
        }
    }

    /**
     * Set the system message.
     */
    public function setSystemMessage(string $message): self
    {
        $this->chat->setSystemMessage($message);
        return $this;
    }

    /**
     * Add a function (tool) to the chat.
     */
    public function addFunction(object $function): self
    {
        $this->chat->addFunction($function);
        return $this;
    }

    /**
     * Log a text generation call.
     */
    private function logCall(
        string $method,
        string $prompt,
        ?string $response,
        float $startTime,
        ?Throwable $error,
        ?string $systemPrompt = null,
        bool $isStreaming = false
    ): void {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $modelInfo = $this->extractModelInfo();

        $input = ['prompt' => $prompt];
        if ($systemPrompt !== null) {
            $input['system_prompt'] = $systemPrompt;
        }
        if ($isStreaming) {
            $input['stream'] = true;
        }

        $logData = [
            'model' => $modelInfo['model'],
            'provider' => $modelInfo['provider'],
            'input' => $input,
            'latency_ms' => $latencyMs,
            'metadata' => array_merge(
                $this->handler->getDefaultMetadata(),
                [
                    'method' => $method,
                    'session_id' => $this->handler->getSessionId(),
                    'user_id' => $this->handler->getUserId(),
                    'source' => 'llphant',
                ]
            ),
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['output'] = [];
        } else {
            $logData['status'] = 'success';
            $logData['output'] = ['content' => $response];
        }

        $this->tracer->log($logData);
    }

    /**
     * Log a chat completion call.
     */
    private function logChatCall(
        array $messages,
        ?string $response,
        float $startTime,
        ?Throwable $error
    ): void {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $modelInfo = $this->extractModelInfo();

        $logData = [
            'model' => $modelInfo['model'],
            'provider' => $modelInfo['provider'],
            'input' => ['messages' => $messages],
            'latency_ms' => $latencyMs,
            'metadata' => array_merge(
                $this->handler->getDefaultMetadata(),
                [
                    'method' => 'generateChat',
                    'session_id' => $this->handler->getSessionId(),
                    'user_id' => $this->handler->getUserId(),
                    'source' => 'llphant',
                ]
            ),
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['output'] = [];
        } else {
            $logData['status'] = 'success';
            $logData['output'] = ['content' => $response];
        }

        $this->tracer->log($logData);
    }

    /**
     * Extract model and provider info from the chat instance.
     */
    private function extractModelInfo(): array
    {
        $provider = 'unknown';
        $model = 'unknown';

        $className = get_class($this->chat);

        // Detect provider from class name
        $classNameLower = strtolower($className);
        if (str_contains($classNameLower, 'openai')) {
            $provider = 'openai';
        } elseif (str_contains($classNameLower, 'anthropic') || str_contains($classNameLower, 'claude')) {
            $provider = 'anthropic';
        } elseif (str_contains($classNameLower, 'mistral')) {
            $provider = 'mistral';
        } elseif (str_contains($classNameLower, 'ollama')) {
            $provider = 'ollama';
        } elseif (str_contains($classNameLower, 'gemini') || str_contains($classNameLower, 'google')) {
            $provider = 'google';
        }

        // Try to get model name
        if (method_exists($this->chat, 'getModel')) {
            $model = (string) $this->chat->getModel();
        } elseif (property_exists($this->chat, 'model')) {
            $model = (string) $this->chat->model;
        } elseif (method_exists($this->chat, 'getModelName')) {
            $model = (string) $this->chat->getModelName();
        }

        return ['provider' => $provider, 'model' => $model];
    }

    /**
     * Forward other method calls to the original chat instance.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->chat->$name(...$arguments);
    }
}

/**
 * Wrapper for LLPhant Embedding classes.
 */
class LLPhantEmbeddingWrapper
{
    private object $embedding;
    private AITracer $tracer;
    private LLPhantHandler $handler;

    public function __construct(object $embedding, AITracer $tracer, LLPhantHandler $handler)
    {
        $this->embedding = $embedding;
        $this->tracer = $tracer;
        $this->handler = $handler;
    }

    /**
     * Embed a single text.
     *
     * @param string $text Text to embed
     * @return array Embedding vector
     */
    public function embedText(string $text): array
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->embedding->embedText($text);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logEmbeddingCall($text, $response, $startTime, $error);
        }
    }

    /**
     * Embed multiple texts.
     *
     * @param array $texts Array of texts to embed
     * @return array Array of embedding vectors
     */
    public function embedTexts(array $texts): array
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->embedding->embedTexts($texts);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->logEmbeddingCall($texts, $response, $startTime, $error, true);
        }
    }

    /**
     * Embed a document.
     *
     * @param object $document Document to embed
     * @return object Document with embedding
     */
    public function embedDocument(object $document): object
    {
        $startTime = microtime(true);
        $error = null;
        $response = null;

        try {
            $response = $this->embedding->embedDocument($document);
            return $response;
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $modelInfo = $this->extractModelInfo();

            $logData = [
                'model' => $modelInfo['model'],
                'provider' => $modelInfo['provider'],
                'input' => ['document' => $document->content ?? '[Document]'],
                'latency_ms' => $latencyMs,
                'metadata' => [
                    'method' => 'embedDocument',
                    'source' => 'llphant',
                ],
            ];

            if ($error !== null) {
                $logData['status'] = 'error';
                $logData['error_message'] = $error->getMessage();
                $logData['output'] = [];
            } else {
                $logData['status'] = 'success';
                $logData['output'] = ['embedding_dimensions' => count($response->embedding ?? [])];
            }

            $this->tracer->log($logData);
        }
    }

    /**
     * Log an embedding call.
     */
    private function logEmbeddingCall(
        string|array $input,
        ?array $response,
        float $startTime,
        ?Throwable $error,
        bool $isBatch = false
    ): void {
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
        $modelInfo = $this->extractModelInfo();

        $logData = [
            'model' => $modelInfo['model'],
            'provider' => $modelInfo['provider'],
            'input' => $isBatch ? ['texts' => $input, 'count' => count($input)] : ['text' => $input],
            'latency_ms' => $latencyMs,
            'metadata' => [
                'method' => $isBatch ? 'embedTexts' : 'embedText',
                'session_id' => $this->handler->getSessionId(),
                'user_id' => $this->handler->getUserId(),
                'source' => 'llphant',
            ],
        ];

        if ($error !== null) {
            $logData['status'] = 'error';
            $logData['error_message'] = $error->getMessage();
            $logData['output'] = [];
        } else {
            $embeddingCount = $isBatch ? count($response ?? []) : 1;
            $dimensions = !empty($response) ? count($isBatch ? ($response[0] ?? []) : $response) : 0;
            $logData['status'] = 'success';
            $logData['output'] = [
                'embedding_count' => $embeddingCount,
                'dimensions' => $dimensions,
            ];
        }

        $this->tracer->log($logData);
    }

    /**
     * Extract model and provider info.
     */
    private function extractModelInfo(): array
    {
        $provider = 'unknown';
        $model = 'text-embedding-ada-002';

        $className = get_class($this->embedding);
        $classNameLower = strtolower($className);

        if (str_contains($classNameLower, 'openai')) {
            $provider = 'openai';
        } elseif (str_contains($classNameLower, 'ollama')) {
            $provider = 'ollama';
        } elseif (str_contains($classNameLower, 'mistral')) {
            $provider = 'mistral';
        }

        if (method_exists($this->embedding, 'getModel')) {
            $model = (string) $this->embedding->getModel();
        } elseif (property_exists($this->embedding, 'model')) {
            $model = (string) $this->embedding->model;
        }

        return ['provider' => $provider, 'model' => $model];
    }

    /**
     * Forward other method calls to the original embedding instance.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->embedding->$name(...$arguments);
    }
}

/**
 * Wrapper for LLPhant streaming responses.
 */
class LLPhantStreamWrapper implements \IteratorAggregate
{
    private iterable $stream;
    private \Closure $onComplete;
    private string $fullText = '';

    public function __construct(iterable $stream, \Closure $onComplete)
    {
        $this->stream = $stream;
        $this->onComplete = $onComplete;
    }

    public function getIterator(): \Generator
    {
        try {
            foreach ($this->stream as $chunk) {
                $this->fullText .= $chunk;
                yield $chunk;
            }
        } finally {
            ($this->onComplete)($this->fullText);
        }
    }
}
