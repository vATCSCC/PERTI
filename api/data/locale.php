<?php
/**
 * Locale Session Sync
 * POST: Set session locale (called by JS locale switcher)
 * GET:  Return current session locale
 */
include("../../load/config.php");
define('PERTI_MYSQL_ONLY', true);
include("../../load/connect.php");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$supported = ['en-US', 'en-CA', 'fr-CA'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $locale = $input['locale'] ?? '';

    if (!in_array($locale, $supported)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported locale', 'supported' => $supported]);
        exit;
    }

    $_SESSION['PERTI_LOCALE'] = $locale;
    echo json_encode(['success' => true, 'locale' => $locale]);
} else {
    echo json_encode(['locale' => $_SESSION['PERTI_LOCALE'] ?? 'en-US']);
}
