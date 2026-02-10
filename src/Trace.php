<?php

declare(strict_types=1);

namespace AITracer;

/**
 * Trace context for grouping related API calls.
 */
class Trace
{
    private string $id;
    private ?string $name;
    private array $metadata = [];
    private float $startTime;

    public function __construct(?string $id = null, ?string $name = null)
    {
        $this->id = $id ?? $this->generateTraceId();
        $this->name = $name;
        $this->startTime = microtime(true);
    }

    /**
     * Generate a unique trace ID.
     */
    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get the trace ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the trace name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set trace name.
     *
     * @return $this
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set metadata for the trace.
     *
     * @param array $metadata
     * @return $this
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    /**
     * Get trace metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get elapsed time in milliseconds.
     */
    public function getElapsedMs(): int
    {
        return (int) ((microtime(true) - $this->startTime) * 1000);
    }
}
