<?php
/**
 * VATSWIM CPDLC Message Parser
 *
 * Parses CPDLC messages to extract clearance data.
 *
 * @package VATSWIM
 * @subpackage Hoppie CPDLC Integration
 * @version 1.0.0
 */

namespace VatSwim\Hoppie;

/**
 * CPDLC Message Parser
 *
 * Parses DCL, PDC, and CPDLC uplinks to extract clearance information.
 */
class CPDLCParser
{
    /**
     * CPDLC message types of interest
     */
    private const DCL_TYPES = ['DCL', 'PDC'];
    private const CLEARANCE_UPLINKS = [
        'CLIMB TO',
        'DESCEND TO',
        'MAINTAIN',
        'PROCEED DIRECT',
        'EXPECT',
        'CLEARED',
        'SQUAWK',
        'CONTACT'
    ];

    /**
     * Parse a CPDLC message
     *
     * @param array $message Message from HoppieClient
     * @return array|null Extracted clearance data or null
     */
    public function parse(array $message): ?array
    {
        $type = strtoupper($message['type'] ?? '');
        $packet = $message['packet'] ?? '';
        $from = $message['from'] ?? '';

        if (in_array($type, self::DCL_TYPES)) {
            return $this->parseDCL($packet, $from, $message['timestamp'] ?? null);
        }

        if ($type === 'CPDLC') {
            return $this->parseCPDLC($packet, $from, $message['timestamp'] ?? null);
        }

        return null;
    }

    /**
     * Parse DCL (Departure Clearance) message
     *
     * Format: /DATA2/{min}/{mrn}/{callsign}[/{field}=value]...
     */
    private function parseDCL(string $packet, string $from, ?string $timestamp): ?array
    {
        $data = [
            'type' => 'dcl',
            'source_station' => $from,
            'timestamp' => $timestamp ?? gmdate('c')
        ];

        // Extract callsign
        if (preg_match('/^\/DATA2\/\d+\/\d+\/(\w+)/', $packet, $match)) {
            $data['callsign'] = $match[1];
        }

        // Extract destination
        if (preg_match('/\/DEST\/(\w{4})/', $packet, $match)) {
            $data['destination'] = $match[1];
        }

        // Extract cleared altitude
        if (preg_match('/\/RVEC\/FL(\d+)/', $packet, $match)) {
            $data['cleared_altitude_fl'] = (int) $match[1];
        }

        // Extract SID
        if (preg_match('/\/DEP\/([A-Z0-9]+)/', $packet, $match)) {
            $data['sid'] = $match[1];
        }

        // Extract initial heading
        if (preg_match('/\/INIT\/H(\d+)/', $packet, $match)) {
            $data['initial_heading'] = (int) $match[1];
        }

        // Extract squawk
        if (preg_match('/\/SQUAWK\/(\d{4})/', $packet, $match)) {
            $data['squawk'] = $match[1];
        }

        // Extract departure runway
        if (preg_match('/\/DRWY\/(\d{2}[LCR]?)/', $packet, $match)) {
            $data['departure_runway'] = $match[1];
        }

        // Extract departure frequency
        if (preg_match('/\/DFREQ\/([\d.]+)/', $packet, $match)) {
            $data['departure_frequency'] = $match[1];
        }

        return $data;
    }

    /**
     * Parse CPDLC uplink message
     *
     * Format: /data2/{min}/{mrn}/{response}/{text}
     */
    private function parseCPDLC(string $packet, string $from, ?string $timestamp): ?array
    {
        $data = [
            'type' => 'cpdlc',
            'source_station' => $from,
            'timestamp' => $timestamp ?? gmdate('c'),
            'raw_text' => $packet
        ];

        // Extract message text (after /data2/x/x/x/)
        if (preg_match('/\/data2\/\d+\/\d+\/\w+\/(.+)$/i', $packet, $match)) {
            $text = $match[1];
            $data['message_text'] = $text;

            // Parse specific clearance types
            $this->parseClearanceText($text, $data);
        }

        return $data;
    }

    /**
     * Parse clearance text for specific instructions
     */
    private function parseClearanceText(string $text, array &$data): void
    {
        $text = strtoupper($text);

        // Altitude clearances
        if (preg_match('/CLIMB (?:TO|AND MAINTAIN) (?:FL)?(\d+)/', $text, $match)) {
            $data['cleared_altitude_fl'] = (int) $match[1];
            $data['clearance_type'] = 'climb';
        } elseif (preg_match('/DESCEND (?:TO|AND MAINTAIN) (?:FL)?(\d+)/', $text, $match)) {
            $data['cleared_altitude_fl'] = (int) $match[1];
            $data['clearance_type'] = 'descend';
        } elseif (preg_match('/MAINTAIN (?:FL)?(\d+)/', $text, $match)) {
            $data['cleared_altitude_fl'] = (int) $match[1];
            $data['clearance_type'] = 'maintain';
        }

        // Direct to waypoint
        if (preg_match('/PROCEED DIRECT (?:TO )?(\w+)/', $text, $match)) {
            $data['direct_to'] = $match[1];
            $data['clearance_type'] = 'direct';
        }

        // Expect clearance
        if (preg_match('/EXPECT (?:FL)?(\d+)/', $text, $match)) {
            $data['expect_altitude_fl'] = (int) $match[1];
        }

        // Runway clearance
        if (preg_match('/CLEARED (?:ILS |VISUAL )?(?:APPROACH )?(?:RWY |RUNWAY )?(\d{2}[LCR]?)/', $text, $match)) {
            $data['cleared_runway'] = $match[1];
            $data['clearance_type'] = 'approach';
        }

        // Squawk
        if (preg_match('/SQUAWK (\d{4})/', $text, $match)) {
            $data['squawk'] = $match[1];
        }

        // Contact frequency
        if (preg_match('/CONTACT .+ (?:ON )?([\d.]+)/', $text, $match)) {
            $data['contact_frequency'] = $match[1];
        }
    }

    /**
     * Check if message is a pilot response (WILCO, UNABLE, etc.)
     */
    public function isResponse(array $message): bool
    {
        $packet = strtoupper($message['packet'] ?? '');
        return preg_match('/\b(WILCO|UNABLE|ROGER|STANDBY|AFFIRM|NEGATIVE)\b/', $packet) === 1;
    }

    /**
     * Extract response type from pilot message
     */
    public function getResponseType(array $message): ?string
    {
        $packet = strtoupper($message['packet'] ?? '');

        if (str_contains($packet, 'WILCO')) return 'wilco';
        if (str_contains($packet, 'ROGER')) return 'roger';
        if (str_contains($packet, 'UNABLE')) return 'unable';
        if (str_contains($packet, 'STANDBY')) return 'standby';
        if (str_contains($packet, 'AFFIRM')) return 'affirm';
        if (str_contains($packet, 'NEGATIVE')) return 'negative';

        return null;
    }
}
