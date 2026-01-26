<?php
/**
 * Migration 014 - COMPLETED AND DISABLED
 * 
 * This migration has been completed successfully on 2026-01-26.
 * The script has been disabled for security.
 * 
 * Results:
 * - 228 programs migrated from VATSIM_ADL.dbo.ntml to VATSIM_TMI.dbo.tmi_programs
 * - All GDT API endpoints now use VATSIM_TMI
 */

header('Content-Type: application/json; charset=utf-8');
http_response_code(410); // Gone

echo json_encode([
    'status' => 'disabled',
    'message' => 'Migration 014 completed on 2026-01-26. This endpoint has been disabled.',
    'records_migrated' => 228
], JSON_PRETTY_PRINT);
