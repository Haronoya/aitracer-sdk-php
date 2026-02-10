<?php

declare(strict_types=1);

namespace AITracer;

use InvalidArgumentException;

/**
 * Configuration class for AITracer.
 */
class Config
{
    public const DEFAULT_BASE_URL = 'https://api.aitracer.co';
    public const DEFAULT_BATCH_SIZE = 10;
    public const DEFAULT_FLUSH_INTERVAL = 5.0;
    public const DEFAULT_TIMEOUT = 30;

    public readonly string $apiKey;
    public readonly string $project;
    public readonly string $baseUrl;
    public readonly bool $sync;
    public bool $enabled;
    public readonly bool $flushOnExit;
    public readonly int $batchSize;
    public readonly float $flushInterval;
    public readonly int $timeout;
    public readonly bool $piiDetection;
    public readonly string $piiAction;
    public readonly array $piiTypes;

    /**
     * @param array{
     *     api_key?: string,
     *     project?: string,
     *     base_url?: string,
     *     sync?: bool,
     *     enabled?: bool,
     *     flush_on_exit?: bool,
     *     batch_size?: int,
     *     flush_interval?: float,
     *     timeout?: int,
     *     pii_detection?: bool,
     *     pii_action?: string,
     *     pii_types?: array<string>,
     * } $options
     */
    public function __construct(array $options = [])
    {
        // API Key (required)
        $this->apiKey = $options['api_key']
            ?? getenv('AITRACER_API_KEY')
            ?: throw new InvalidArgumentException(
                'API key is required. Set via options or AITRACER_API_KEY environment variable.'
            );

        // Project (required)
        $this->project = $options['project']
            ?? getenv('AITRACER_PROJECT')
            ?: throw new InvalidArgumentException(
                'Project is required. Set via options or AITRACER_PROJECT environment variable.'
            );

        // Validate API key format
        if (!str_starts_with($this->apiKey, 'at-')) {
            throw new InvalidArgumentException(
                'Invalid API key format. API key should start with "at-".'
            );
        }

        // Optional settings
        $this->baseUrl = rtrim(
            $options['base_url'] ?? getenv('AITRACER_BASE_URL') ?: self::DEFAULT_BASE_URL,
            '/'
        );
        $this->sync = $options['sync'] ?? false;
        $this->enabled = $options['enabled'] ?? true;
        $this->flushOnExit = $options['flush_on_exit'] ?? true;
        $this->batchSize = $options['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
        $this->flushInterval = $options['flush_interval'] ?? self::DEFAULT_FLUSH_INTERVAL;
        $this->timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;

        // PII settings
        $this->piiDetection = $options['pii_detection'] ?? false;
        $this->piiAction = $options['pii_action'] ?? 'mask';
        $this->piiTypes = $options['pii_types'] ?? ['email', 'phone', 'credit_card', 'ssn'];

        // Validate PII action
        if (!in_array($this->piiAction, ['mask', 'redact', 'hash', 'none'], true)) {
            throw new InvalidArgumentException(
                'Invalid PII action. Must be one of: mask, redact, hash, none.'
            );
        }
    }

    /**
     * Get the full API endpoint URL.
     */
    public function getEndpoint(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /**
     * Get authorization header value.
     */
    public function getAuthorizationHeader(): string
    {
        return 'Bearer ' . $this->apiKey;
    }
}
