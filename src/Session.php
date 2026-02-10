<?php

declare(strict_types=1);

namespace AITracer;

/**
 * Session for tracking user interactions over time.
 *
 * A session groups multiple logs/traces that belong to the same user interaction flow.
 *
 * Usage:
 * ```php
 * $session = $tracer->startSession([
 *     'user_id' => 'user-123',
 *     'metadata' => ['platform' => 'web'],
 * ]);
 *
 * // All API calls within this session will be associated
 * $response = $client->chat()->create([...]);
 *
 * // Collect user feedback
 * $session->thumbsUp();
 *
 * // End session when done
 * $tracer->endSession();
 * ```
 */
class Session
{
    private string $sessionId;
    private ?string $userId;
    private ?string $name;
    private array $metadata;
    private ?AITracer $tracer;
    private string $startedAt;
    private ?string $lastLogId = null;

    /**
     * Create a new Session instance.
     *
     * @param array{
     *     session_id?: string,
     *     user_id?: string,
     *     name?: string,
     *     metadata?: array,
     * } $options
     * @param AITracer|null $tracer
     */
    public function __construct(array $options = [], ?AITracer $tracer = null)
    {
        $this->sessionId = $options['session_id'] ?? $this->generateSessionId();
        $this->userId = $options['user_id'] ?? null;
        $this->name = $options['name'] ?? null;
        $this->metadata = $options['metadata'] ?? [];
        $this->tracer = $tracer;
        $this->startedAt = date('c');
    }

    /**
     * Get the session ID.
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Get the session name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Set a metadata value.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Record a custom event in the session.
     *
     * @param string $eventType Type of event (e.g., 'page_view', 'action')
     * @param array{
     *     name?: string,
     *     data?: array,
     *     log_id?: string,
     * } $options
     */
    public function event(string $eventType, array $options = []): void
    {
        $event = [
            'event_type' => $eventType,
            'name' => $options['name'] ?? null,
            'data' => $options['data'] ?? [],
            'log_id' => $options['log_id'] ?? $this->lastLogId,
            'timestamp' => date('c'),
        ];

        if ($this->tracer !== null && $this->tracer->isEnabled()) {
            $this->tracer->sendSessionEvent($this->sessionId, $event);
        }
    }

    /**
     * Record user feedback for an AI response.
     *
     * @param string $feedbackType One of: 'thumbs_up', 'thumbs_down', 'rating', 'text'
     * @param array{
     *     log_id?: string,
     *     score?: int|float,
     *     comment?: string,
     * } $options
     */
    public function feedback(string $feedbackType, array $options = []): void
    {
        $score = $options['score'] ?? null;

        // Auto-set score for thumbs feedback
        if ($score === null) {
            if ($feedbackType === 'thumbs_up') {
                $score = 1;
            } elseif ($feedbackType === 'thumbs_down') {
                $score = -1;
            }
        }

        $feedback = [
            'feedback_type' => $feedbackType,
            'log_id' => $options['log_id'] ?? $this->lastLogId,
            'score' => $score,
            'comment' => $options['comment'] ?? null,
            'user_id' => $this->userId,
            'timestamp' => date('c'),
        ];

        if ($this->tracer !== null && $this->tracer->isEnabled()) {
            $this->tracer->sendFeedback($this->sessionId, $feedback);
        }
    }

    /**
     * Record a thumbs up feedback.
     *
     * @param string|null $logId Log ID to associate with (uses last log if null)
     * @param string|null $comment Optional comment
     */
    public function thumbsUp(?string $logId = null, ?string $comment = null): void
    {
        $this->feedback('thumbs_up', [
            'log_id' => $logId,
            'comment' => $comment,
        ]);
    }

    /**
     * Record a thumbs down feedback.
     *
     * @param string|null $logId Log ID to associate with (uses last log if null)
     * @param string|null $comment Optional comment
     */
    public function thumbsDown(?string $logId = null, ?string $comment = null): void
    {
        $this->feedback('thumbs_down', [
            'log_id' => $logId,
            'comment' => $comment,
        ]);
    }

    /**
     * Record a rating feedback (1-5).
     *
     * @param int $score Rating score (1-5)
     * @param string|null $logId Log ID to associate with (uses last log if null)
     * @param string|null $comment Optional comment
     * @throws \InvalidArgumentException If score is not between 1 and 5
     */
    public function rate(int $score, ?string $logId = null, ?string $comment = null): void
    {
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException('Score must be between 1 and 5');
        }

        $this->feedback('rating', [
            'log_id' => $logId,
            'score' => $score,
            'comment' => $comment,
        ]);
    }

    /**
     * Internal: Set the last log ID for feedback association.
     */
    public function setLastLogId(string $logId): void
    {
        $this->lastLogId = $logId;
    }

    /**
     * Generate a unique session ID.
     */
    private function generateSessionId(): string
    {
        return 'ses_' . bin2hex(random_bytes(12));
    }
}
