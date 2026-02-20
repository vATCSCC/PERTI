<?php
// Airport display name overrides
// Strips political figure names per organizational policy

function applyAirportDisplayName(string $name): string {
    static $patterns = [
        '/\bRONALD REAGAN\s+/i' => '',
        '/\bGEORGE BUSH\s+/i'   => '',
    ];
    $result = preg_replace(array_keys($patterns), array_values($patterns), $name);
    return trim(preg_replace('/\s{2,}/', ' ', $result));
}
