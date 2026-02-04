<?php

declare(strict_types=1);

namespace AITracer;

use AITracer\Http\HttpClient;
use AITracer\Wrappers\OpenAIWrapper;
use AITracer\Wrappers\AnthropicWrapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AITracer main client class.
 *
 * Usage:
 * ```php
 * $tracer = new AITracer([
 *     'api_key' => 'at-xxxxx',
 *     'project' => 'my-project',
 * ]);
 *
 * // Wrap OpenAI client
 * $openai = $tracer->wrapOpenAI($openai);
 *
 * // Or manual logging
 * $tracer->log([
 *     'model' => 'gpt-4',
 *     'input' => ['messages' => [...]],
 *     'output' => ['content' => '...'],
 *     'input_tokens' => 100,
 *     'output_tokens' => 50,
 *     'latency_ms' => 1234,
 * ]);
 * ```
 */
class AITracer
{
    private static ?AITracer $instance = null;

    private Config $config;
    private HttpClient $httpClient;
    private LogQueue $queue;
    private ?Trace $currentTrace = null;
    private ?Session $currentSession = null;
    private LoggerInterface $logger;
    private ?PiiDetector $piiDetector = null;

    /**
     * Create a new AITracer instance.
     *
     * @param array{
     *     api_key?: string,
     *     project?: string,
     *     base_url?: string,
     *     sync?: bool,
     *     enabled?: bool,
     *     batch_size?: int,
     *     flush_interval?: float,
     *     pii_detection?: bool,
     *     pii_action?: string,
     *     pii_types?: array<string>,
     *     logger?: LoggerInterface,
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->config = new Config($options);
        $this->logger = $options['logger'] ?? new NullLogger();
        $this->httpClient = new HttpClient($this->config, $this->logger);
        $this->queue = new LogQueue($this->config, $this->httpClient, $this->logger);

        if ($this->config->piiDetection) {
            $this->piiDetector = new PiiDetector(
                $this->config->piiTypes,
                $this->config->piiAction
            );
        }

        // Register shutdown handler
        if ($this->config->flushOnExit) {
            register_shutdown_function([$this, 'shutdown']);
        }

        self::$instance = $this;
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): ?AITracer
    {
        return self::$instance;
    }

    /**
     * Wrap an OpenAI client for automatic logging.
     *
     * @param object $client OpenAI client instance
     * @return OpenAIWrapper
     */
    public function wrapOpenAI(object $client): OpenAIWrapper
    {
        return new OpenAIWrapper($client, $this);
    }

    /**
     * Wrap an Anthropic client for automatic logging.
     *
     * @param object $client Anthropic client instance
     * @return AnthropicWrapper
     */
    public function wrapAnthropic(object $client): AnthropicWrapper
    {
        return new AnthropicWrapper($client, $this);
    }

    /**
     * Create a trace context for grouping related API calls.
     *
     * @param string|null $traceId Custom trace ID (auto-generated if null)
     * @param string|null $name Trace name
     * @return Trace
     */
    public function trace(?string $traceId = null, ?string $name = null): Trace
    {
        $this->currentTrace = new Trace($traceId, $name);
        return $this->currentTrace;
    }

    /**
     * End the current trace.
     */
    public function endTrace(): void
    {
        $this->currentTrace = null;
    }

    /**
     * Get the current trace.
     */
    public function getCurrentTrace(): ?Trace
    {
        return $this->currentTrace;
    }

    /**
     * Start a new session context for tracking user interactions.
     *
     * @param array{
     *     session_id?: string,
     *     user_id?: string,
     *     name?: string,
     *     metadata?: array,
     * } $options
     * @return Session
     */
    public function startSession(array $options = []): Session
    {
        $this->currentSession = new Session($options, $this);
        $this->sendSessionStart($this->currentSession);
        $this->logger->info('Session started: ' . $this->currentSession->getSessionId());
        return $this->currentSession;
    }

    /**
     * End the current session.
     */
    public function endSession(): void
    {
        if ($this->currentSession !== null) {
            $this->sendSessionEnd($this->currentSession);
            $this->logger->info('Session ended: ' . $this->currentSession->getSessionId());
            $this->currentSession = null;
        }
    }

    /**
     * Get the current active session.
     */
    public function getCurrentSession(): ?Session
    {
        return $this->currentSession;
    }

    /**
     * Run a callback within a session context.
     *
     * @template T
     * @param array{
     *     session_id?: string,
     *     user_id?: string,
     *     name?: string,
     *     metadata?: array,
     * } $options
     * @param callable(Session): T $callback
     * @return T
     */
    public function withSession(array $options, callable $callback): mixed
    {
        $session = $this->startSession($options);
        try {
            return $callback($session);
        } finally {
            $this->endSession();
        }
    }

    /**
     * Log an LLM API call.
     *
     * @param array{
     *     model: string,
     *     provider?: string,
     *     input: array,
     *     output: array,
     *     input_tokens?: int,
     *     output_tokens?: int,
     *     latency_ms?: int,
     *     status?: string,
     *     error_message?: string,
     *     metadata?: array,
     *     tags?: array<string>,
     *     trace_id?: string,
     *     span_id?: string,
     *     parent_span_id?: string,
     *     session_id?: string,
     *     user_id?: string,
     * } $data
     */
    public function log(array $data): void
    {
        if (!$this->config->enabled) {
            return;
        }

        $logEntry = $this->buildLogEntry($data);

        // Apply PII detection if enabled
        if ($this->piiDetector !== null) {
            $logEntry = $this->piiDetector->process($logEntry);
        }

        $this->queue->push($logEntry);
    }

    /**
     * Build a complete log entry with defaults.
     */
    private function buildLogEntry(array $data): array
    {
        $entry = [
            'project' => $this->config->project,
            'model' => $data['model'] ?? 'unknown',
            'provider' => $data['provider'] ?? $this->detectProvider($data['model'] ?? ''),
            'input' => $data['input'] ?? [],
            'output' => $data['output'] ?? [],
            'input_tokens' => $data['input_tokens'] ?? 0,
            'output_tokens' => $data['output_tokens'] ?? 0,
            'latency_ms' => $data['latency_ms'] ?? 0,
            'status' => $data['status'] ?? 'success',
            'error_message' => $data['error_message'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'tags' => $data['tags'] ?? [],
            'span_id' => $data['span_id'] ?? $this->generateSpanId(),
            'parent_span_id' => $data['parent_span_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'timestamp' => date('c'),
        ];

        // Add trace context if available
        if ($this->currentTrace !== null) {
            $entry['trace_id'] = $this->currentTrace->getId();
            $entry['metadata'] = array_merge(
                $entry['metadata'],
                $this->currentTrace->getMetadata()
            );
            $entry['tags'] = array_merge(
                $entry['tags'],
                $this->currentTrace->getTags()
            );
        } elseif (isset($data['trace_id'])) {
            $entry['trace_id'] = $data['trace_id'];
        }

        // Add session context if available
        if ($this->currentSession !== null) {
            $entry['session_id'] = $entry['session_id'] ?? $this->currentSession->getSessionId();
            $entry['user_id'] = $entry['user_id'] ?? $this->currentSession->getUserId();

            // Update last log ID for feedback association
            $this->currentSession->setLastLogId($entry['span_id']);
        }

        return $entry;
    }

    /**
     * Detect provider from model name.
     */
    private function detectProvider(string $model): string
    {
        $model = strtolower($model);

        if (str_contains($model, 'gpt') || str_contains($model, 'o1') || str_contains($model, 'davinci')) {
            return 'openai';
        }
        if (str_contains($model, 'claude')) {
            return 'anthropic';
        }
        if (str_contains($model, 'gemini') || str_contains($model, 'palm')) {
            return 'google';
        }
        if (str_contains($model, 'mistral') || str_contains($model, 'mixtral')) {
            return 'mistral';
        }
        if (str_contains($model, 'command') || str_contains($model, 'cohere')) {
            return 'cohere';
        }

        return 'unknown';
    }

    /**
     * Generate a unique span ID.
     */
    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Flush all pending logs immediately.
     */
    public function flush(): void
    {
        $this->queue->flush();
    }

    /**
     * Shutdown the tracer gracefully.
     */
    public function shutdown(): void
    {
        $this->queue->shutdown();
    }

    /**
     * Get the configuration.
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Check if tracing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    /**
     * Enable tracing.
     */
    public function enable(): void
    {
        $this->config->enabled = true;
    }

    /**
     * Disable tracing.
     */
    public function disable(): void
    {
        $this->config->enabled = false;
    }

    // Session API methods (internal)

    /**
     * Send session start event to the server.
     */
    private function sendSessionStart(Session $session): void
    {
        try {
            $this->httpClient->post('/api/v1/sessions/', [
                'session_id' => $session->getSessionId(),
                'user_id' => $session->getUserId(),
                'name' => $session->getName(),
                'metadata' => $session->getMetadata(),
                'project' => $this->config->project,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to start session: ' . $e->getMessage());
        }
    }

    /**
     * Send session end event to the server.
     */
    private function sendSessionEnd(Session $session): void
    {
        try {
            $this->httpClient->patch('/api/v1/sessions/' . $session->getSessionId() . '/', [
                'ended_at' => date('c'),
                'metadata' => $session->getMetadata(),
                'tags' => $session->getTags(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to end session: ' . $e->getMessage());
        }
    }

    /**
     * Send a session event to the server.
     *
     * @internal
     * @param string $sessionId
     * @param array $event
     */
    public function sendSessionEvent(string $sessionId, array $event): void
    {
        try {
            $this->httpClient->post('/api/v1/sessions/' . $sessionId . '/events/', $event);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send session event: ' . $e->getMessage());
        }
    }

    /**
     * Send feedback to the server.
     *
     * @internal
     * @param string $sessionId
     * @param array $feedback
     */
    public function sendFeedback(string $sessionId, array $feedback): void
    {
        try {
            $this->httpClient->post('/api/v1/sessions/' . $sessionId . '/feedback/', array_merge(
                $feedback,
                ['session_id' => $sessionId]
            ));
        } catch (\Exception $e) {
            $this->logger->error('Failed to send feedback: ' . $e->getMessage());
        }
    }
}
