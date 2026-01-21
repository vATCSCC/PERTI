<?php

declare(strict_types=1);

namespace VatSim\Swim;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use VatSim\Swim\Exceptions\SwimApiException;
use VatSim\Swim\Exceptions\SwimAuthException;
use VatSim\Swim\Exceptions\SwimRateLimitException;

/**
 * Low-level REST client for SWIM API
 */
class RestClient
{
    private const DEFAULT_BASE_URL = 'https://perti.vatcscc.org/api/swim/v1';
    private const DEFAULT_TIMEOUT = 30;

    private Client $http;
    private string $apiKey;
    private string $baseUrl;
    private string $sourceId;

    /**
     * Create REST client
     *
     * @param string $apiKey API key
     * @param array{
     *     base_url?: string,
     *     source_id?: string,
     *     timeout?: int,
     *     verify_ssl?: bool
     * } $options Options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->sourceId = $options['source_id'] ?? 'php_client';

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $options['timeout'] ?? self::DEFAULT_TIMEOUT,
            'verify' => $options['verify_ssl'] ?? true,
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-SWIM-Source' => $this->sourceId,
                'User-Agent' => 'VATSWIM-PHP-SDK/1.0.0'
            ]
        ]);
    }

    /**
     * Perform GET request
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array Response data
     * @throws SwimApiException
     */
    public function get(string $endpoint, array $params = []): array
    {
        try {
            $response = $this->http->get($endpoint, [
                'query' => $params
            ]);

            return $this->parseResponse($response);
        } catch (RequestException $e) {
            throw $this->handleException($e);
        }
    }

    /**
     * Perform POST request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @return array Response data
     * @throws SwimApiException
     */
    public function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->http->post($endpoint, [
                'json' => $data
            ]);

            return $this->parseResponse($response);
        } catch (RequestException $e) {
            throw $this->handleException($e);
        }
    }

    /**
     * Perform PUT request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @return array Response data
     * @throws SwimApiException
     */
    public function put(string $endpoint, array $data): array
    {
        try {
            $response = $this->http->put($endpoint, [
                'json' => $data
            ]);

            return $this->parseResponse($response);
        } catch (RequestException $e) {
            throw $this->handleException($e);
        }
    }

    /**
     * Perform DELETE request
     *
     * @param string $endpoint API endpoint
     * @return array Response data
     * @throws SwimApiException
     */
    public function delete(string $endpoint): array
    {
        try {
            $response = $this->http->delete($endpoint);
            return $this->parseResponse($response);
        } catch (RequestException $e) {
            throw $this->handleException($e);
        }
    }

    /**
     * Parse response body
     */
    private function parseResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SwimApiException('Invalid JSON response: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Handle request exception
     */
    private function handleException(RequestException $e): SwimApiException
    {
        $response = $e->getResponse();
        $statusCode = $response?->getStatusCode() ?? 0;
        $body = $response ? (string) $response->getBody() : '';
        $data = json_decode($body, true) ?? [];

        $message = $data['message'] ?? $e->getMessage();

        switch ($statusCode) {
            case 401:
            case 403:
                return new SwimAuthException($message, $statusCode);

            case 429:
                $retryAfter = $response?->getHeader('Retry-After')[0] ?? null;
                return new SwimRateLimitException(
                    $message,
                    $statusCode,
                    $retryAfter ? (int) $retryAfter : null
                );

            default:
                return new SwimApiException($message, $statusCode);
        }
    }

    /**
     * Get base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get source ID
     */
    public function getSourceId(): string
    {
        return $this->sourceId;
    }
}
