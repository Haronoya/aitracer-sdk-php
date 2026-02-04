<?php

declare(strict_types=1);

namespace AITracer\Http;

use AITracer\Config;
use AITracer\Exceptions\AITracerException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for communicating with AITracer API.
 */
class HttpClient
{
    private Client $client;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->client = new Client([
            'base_uri' => $config->baseUrl,
            'timeout' => $config->timeout,
            'headers' => [
                'Authorization' => $config->getAuthorizationHeader(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'AITracer-PHP/1.0.0',
            ],
        ]);
    }

    /**
     * Send a single log entry.
     *
     * @param array $logEntry
     * @throws AITracerException
     */
    public function sendLog(array $logEntry): void
    {
        $this->post('/api/v1/logs/', $logEntry);
    }

    /**
     * Send a batch of log entries.
     *
     * @param array<array> $logEntries
     * @throws AITracerException
     */
    public function sendBatch(array $logEntries): void
    {
        if (empty($logEntries)) {
            return;
        }

        $this->post('/api/v1/logs/batch/', ['logs' => $logEntries]);
    }

    /**
     * Send a POST request.
     *
     * @param string $endpoint
     * @param array $data
     * @throws AITracerException
     */
    public function post(string $endpoint, array $data): void
    {
        try {
            $response = $this->client->post($endpoint, [
                RequestOptions::JSON => $data,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $body = (string) $response->getBody();
                $this->logger->error('AITracer API error', [
                    'status' => $statusCode,
                    'body' => $body,
                ]);
                throw new AITracerException(
                    "API request failed with status {$statusCode}: {$body}",
                    $statusCode
                );
            }
        } catch (GuzzleException $e) {
            $this->logger->error('AITracer HTTP error', [
                'message' => $e->getMessage(),
            ]);
            throw new AITracerException(
                'Failed to send log to AITracer: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Send a PATCH request.
     *
     * @param string $endpoint
     * @param array $data
     * @throws AITracerException
     */
    public function patch(string $endpoint, array $data): void
    {
        try {
            $response = $this->client->patch($endpoint, [
                RequestOptions::JSON => $data,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $body = (string) $response->getBody();
                $this->logger->error('AITracer API error', [
                    'status' => $statusCode,
                    'body' => $body,
                ]);
                throw new AITracerException(
                    "API request failed with status {$statusCode}: {$body}",
                    $statusCode
                );
            }
        } catch (GuzzleException $e) {
            $this->logger->error('AITracer HTTP error', [
                'message' => $e->getMessage(),
            ]);
            throw new AITracerException(
                'Failed to send request to AITracer: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Send a GET request.
     *
     * @param string $endpoint
     * @param array $query
     * @return array
     * @throws AITracerException
     */
    public function get(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client->get($endpoint, [
                RequestOptions::QUERY => $query,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $body = (string) $response->getBody();
                throw new AITracerException(
                    "API request failed with status {$statusCode}: {$body}",
                    $statusCode
                );
            }

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            throw new AITracerException(
                'Failed to fetch from AITracer: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }
}
