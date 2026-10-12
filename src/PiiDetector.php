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

    /**
     * Patterns shared with the Python SDK (sdk/aitracer/pii.py).
     *
     * Without the u modifier, \b treats multibyte characters as non-word, so
     * values written right after Japanese text are still detected.
     */
    private const DEFAULT_PATTERNS = [
        'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/i',
        'phone' => '/\b(?:\+?[0-9]{1,4}[-.\s]?)?(?:\(?[0-9]{2,4}\)?[-.\s]?)?[0-9]{2,4}[-.\s]?[0-9]{3,4}[-.\s]?[0-9]{3,4}\b/',
        'credit_card' => '/\b(?:4[0-9]{3}[-\s]?[0-9]{4}[-\s]?[0-9]{4}[-\s]?[0-9]{4}|5[1-5][0-9]{2}[-\s]?[0-9]{4}[-\s]?[0-9]{4}[-\s]?[0-9]{4}|3[47][0-9]{2}[-\s]?[0-9]{6}[-\s]?[0-9]{5}|6(?:011|5[0-9]{2})[-\s]?[0-9]{4}[-\s]?[0-9]{4}[-\s]?[0-9]{4})\b/',
        'ssn' => '/\b(?:[0-9]{3}[-\s]?[0-9]{2}[-\s]?[0-9]{4}|[0-9]{4}[-\s]?[0-9]{4}[-\s]?[0-9]{4})\b/',
        'ip_address' => '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b|(?:[A-Fa-f0-9]{1,4}:){7}[A-Fa-f0-9]{1,4}\b/',
        'japanese_phone' => '/\b0\d{1,4}[-\s]?\d{1,4}[-\s]?\d{4}\b/',
        'my_number' => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
    ];

    /**
     * When matches overlap, the one starting first wins; at the same start,
     * the higher confidence wins (same rule as the Python SDK).
     */
    private const CONFIDENCE = [
        'email' => 0.95,
        'phone' => 0.85,
        'credit_card' => 0.95,
        'ssn' => 0.90,
        'ip_address' => 0.95,
        'japanese_phone' => 0.85,
        'my_number' => 0.90,
    ];
    private const CUSTOM_CONFIDENCE = 0.8;

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
        // Replace from the end so earlier byte offsets stay valid
        foreach (array_reverse($this->findMatches($text)) as $match) {
            $text = substr_replace(
                $text,
                $this->replaceMatch($match['value'], $match['type']),
                $match['offset'],
                strlen($match['value'])
            );
        }

        return $text;
    }

    /**
     * Find PII in a string with every pattern, then drop overlapping matches.
     *
     * Applying the patterns one after another let an earlier pattern take part
     * of a value (e.g. phone matching the first digits of a card number).
     *
     * @return array<array{type: string, value: string, offset: int}> Matches in order of position
     */
    private function findMatches(string $text): array
    {
        $matches = [];
        foreach ($this->patterns as $type => $pattern) {
            if (!preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($found[0] as [$value, $offset]) {
                if ($value === '') {
                    continue;
                }
                $matches[] = [
                    'type' => $type,
                    'value' => $value,
                    'offset' => $offset,
                    'confidence' => self::CONFIDENCE[$type] ?? self::CUSTOM_CONFIDENCE,
                ];
            }
        }

        usort($matches, fn ($a, $b) => [$a['offset'], $b['confidence']] <=> [$b['offset'], $a['confidence']]);

        $result = [];
        $lastEnd = -1;
        foreach ($matches as $match) {
            if ($match['offset'] >= $lastEnd) {
                $result[] = ['type' => $match['type'], 'value' => $match['value'], 'offset' => $match['offset']];
                $lastEnd = $match['offset'] + strlen($match['value']);
            }
        }

        return $result;
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
            foreach ($this->findMatches($data) as $match) {
                $detections[] = [
                    'type' => $match['type'],
                    'value' => $match['value'],
                    'path' => $path,
                ];
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
