<?php
/**
 * VATSWIM Hoppie ACARS Client
 *
 * Client for Hoppie's ACARS system to poll CPDLC messages.
 *
 * @package VATSWIM
 * @subpackage Hoppie CPDLC Integration
 * @version 1.0.0
 */

namespace VatSwim\Hoppie;

/**
 * Hoppie ACARS API Client
 */
class HoppieClient
{
    private const HOPPIE_URL = 'http://www.hoppie.nl/acars/system/connect.html';

    private string $logon;  // Hoppie logon code
    private string $callsign;  // Station callsign (e.g., VATCSCC)

    public function __construct(string $logon, string $callsign)
    {
        $this->logon = $logon;
        $this->callsign = $callsign;
    }

    /**
     * Poll for new messages
     *
     * @return array Array of messages
     */
    public function poll(): array
    {
        $response = $this->request('poll');

        if (!$response || !str_starts_with($response, 'ok')) {
            return [];
        }

        return $this->parseMessages($response);
    }

    /**
     * Peek at messages without marking as read
     *
     * @return array Array of messages
     */
    public function peek(): array
    {
        $response = $this->request('peek');

        if (!$response || !str_starts_with($response, 'ok')) {
            return [];
        }

        return $this->parseMessages($response);
    }

    /**
     * Send a CPDLC message
     *
     * @param string $to Recipient callsign
     * @param string $type Message type (cpdlc, telex, etc.)
     * @param string $packet Message content
     * @return bool Success
     */
    public function send(string $to, string $type, string $packet): bool
    {
        $response = $this->request($type, $to, $packet);
        return $response === 'ok';
    }

    /**
     * Make request to Hoppie API
     */
    private function request(string $type, ?string $to = null, ?string $packet = null): ?string
    {
        $data = [
            'logon' => $this->logon,
            'from' => $this->callsign,
            'type' => $type
        ];

        if ($to !== null) {
            $data['to'] = $to;
        }

        if ($packet !== null) {
            $data['packet'] = $packet;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::HOPPIE_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'VATSWIM-Hoppie/1.0.0'
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[Hoppie] cURL error: $error");
            return null;
        }

        return $response;
    }

    /**
     * Parse Hoppie response into message array
     */
    private function parseMessages(string $response): array
    {
        $messages = [];

        // Response format: ok {from type {packet}}
        // Remove "ok " prefix
        $content = substr($response, 3);

        // Parse each message block
        preg_match_all('/\{(\w+)\s+(\w+)\s+\{(.+?)\}\}/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $messages[] = [
                'from' => $match[1],
                'type' => $match[2],
                'packet' => $match[3],
                'timestamp' => gmdate('c')
            ];
        }

        return $messages;
    }
}
