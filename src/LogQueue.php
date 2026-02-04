<?php

declare(strict_types=1);

namespace AITracer;

use AITracer\Http\HttpClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queue for batching and sending log entries.
 */
class LogQueue
{
    private Config $config;
    private HttpClient $httpClient;
    private LoggerInterface $logger;

    /** @var array<array> */
    private array $queue = [];

    private float $lastFlushTime;
    private bool $isShutdown = false;

    private const MAX_QUEUE_SIZE = 10000;

    public function __construct(Config $config, HttpClient $httpClient, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Add a log entry to the queue.
     *
     * @param array $logEntry
     */
    public function push(array $logEntry): void
    {
        if ($this->isShutdown) {
            return;
        }

        // Protect against unbounded queue growth
        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            $this->logger->warning('AITracer queue full, dropping oldest entry');
            array_shift($this->queue);
        }

        $this->queue[] = $logEntry;

        // In sync mode, send immediately
        if ($this->config->sync) {
            $this->flush();
            return;
        }

        // Check if we should flush
        $this->maybeFlush();
    }

    /**
     * Check if we should flush based on batch size or time.
     */
    private function maybeFlush(): void
    {
        $shouldFlush = false;

        // Batch size reached
        if (count($this->queue) >= $this->config->batchSize) {
            $shouldFlush = true;
        }

        // Flush interval exceeded
        $elapsed = microtime(true) - $this->lastFlushTime;
        if ($elapsed >= $this->config->flushInterval && !empty($this->queue)) {
            $shouldFlush = true;
        }

        if ($shouldFlush) {
            $this->flush();
        }
    }

    /**
     * Flush all pending logs immediately.
     */
    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $entries = $this->queue;
        $this->queue = [];
        $this->lastFlushTime = microtime(true);

        try {
            if (count($entries) === 1) {
                $this->httpClient->sendLog($entries[0]);
            } else {
                $this->httpClient->sendBatch($entries);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to send logs to AITracer', [
                'error' => $e->getMessage(),
                'count' => count($entries),
            ]);

            // Put entries back in queue for retry (unless shutting down)
            if (!$this->isShutdown) {
                $this->queue = array_merge($entries, $this->queue);

                // Trim if over max size
                while (count($this->queue) > self::MAX_QUEUE_SIZE) {
                    array_shift($this->queue);
                }
            }
        }
    }

    /**
     * Shutdown the queue and flush remaining entries.
     */
    public function shutdown(): void
    {
        $this->isShutdown = true;
        $this->flush();
    }

    /**
     * Get the current queue size.
     */
    public function size(): int
    {
        return count($this->queue);
    }

    /**
     * Check if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->queue);
    }
}
