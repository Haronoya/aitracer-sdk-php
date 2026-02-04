<?php

declare(strict_types=1);

namespace AITracer;

/**
 * PII (Personally Identifiable Information) detector and masker.
 */
class PiiDetector
{
    private array $patterns = [];
    private string $action;

    private const DEFAULT_PATTERNS = [
        'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i',
        'phone' => '/(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)?\d{3}[-.\s]?\d{4}|0\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{4}/i',
        'credit_card' => '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})\b/',
        'ssn' => '/\b\d{3}[-.\s]?\d{2}[-.\s]?\d{4}\b/',
        'ip_address' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        'japanese_phone' => '/0\d{1,4}[-\s]?\d{1,4}[-\s]?\d{4}/',
        'my_number' => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
    ];

    /**
     * @param array<string> $types PII types to detect
     * @param string $action Action to take: mask, redact, hash, none
     */
    public function __construct(array $types = [], string $action = 'mask')
    {
        $this->action = $action;

        // Build patterns based on requested types
        foreach ($types as $type) {
            if (isset(self::DEFAULT_PATTERNS[$type])) {
                $this->patterns[$type] = self::DEFAULT_PATTERNS[$type];
            }
        }

        // If no types specified, use all defaults
        if (empty($this->patterns)) {
            $this->patterns = self::DEFAULT_PATTERNS;
        }
    }

    /**
     * Add a custom pattern.
     *
     * @param string $name Pattern name
     * @param string $pattern Regex pattern
     * @return $this
     */
    public function addPattern(string $name, string $pattern): self
    {
        $this->patterns[$name] = $pattern;
        return $this;
    }

    /**
     * Process data and mask/redact PII.
     *
     * @param mixed $data
     * @return mixed
     */
    public function process(mixed $data): mixed
    {
        if ($this->action === 'none') {
            return $data;
        }

        return $this->processRecursive($data);
    }

    /**
     * Recursively process data structure.
     *
     * @param mixed $data
     * @return mixed
     */
    private function processRecursive(mixed $data): mixed
    {
        if (is_string($data)) {
            return $this->maskString($data);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->processRecursive($value);
            }
            return $result;
        }

        if (is_object($data)) {
            $result = new \stdClass();
            foreach (get_object_vars($data) as $key => $value) {
                $result->$key = $this->processRecursive($value);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Mask PII in a string.
     *
     * @param string $text
     * @return string
     */
    private function maskString(string $text): string
    {
        foreach ($this->patterns as $type => $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) use ($type) {
                return $this->replaceMatch($matches[0], $type);
            }, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Replace a matched PII value.
     *
     * @param string $match
     * @param string $type
     * @return string
     */
    private function replaceMatch(string $match, string $type): string
    {
        return match ($this->action) {
            'mask' => "[{$type}]",
            'redact' => str_repeat('*', strlen($match)),
            'hash' => substr(hash('sha256', $match), 0, 16),
            default => $match,
        };
    }

    /**
     * Detect PII in data without masking.
     *
     * @param mixed $data
     * @return array<array{type: string, value: string, path: string}>
     */
    public function detect(mixed $data): array
    {
        $detections = [];
        $this->detectRecursive($data, '', $detections);
        return $detections;
    }

    /**
     * Recursively detect PII.
     *
     * @param mixed $data
     * @param string $path
     * @param array $detections
     */
    private function detectRecursive(mixed $data, string $path, array &$detections): void
    {
        if (is_string($data)) {
            foreach ($this->patterns as $type => $pattern) {
                if (preg_match_all($pattern, $data, $matches)) {
                    foreach ($matches[0] as $match) {
                        $detections[] = [
                            'type' => $type,
                            'value' => $match,
                            'path' => $path,
                        ];
                    }
                }
            }
            return;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $newPath = $path ? "{$path}.{$key}" : (string) $key;
                $this->detectRecursive($value, $newPath, $detections);
            }
        }

        if (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                $newPath = $path ? "{$path}.{$key}" : $key;
                $this->detectRecursive($value, $newPath, $detections);
            }
        }
    }
}
