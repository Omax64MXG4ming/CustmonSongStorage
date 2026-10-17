<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = dirname(__DIR__, 2);

require_once $root . '/database/config/misc.php';
require_once $root . '/database/incl/lib/connection.php';

function neoStatusResponse(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    neoStatusResponse(405, [
        'success' => false,
        'error' => 'METHOD_NOT_ALLOWED'
    ]);
}

$accountID = (int)($_SESSION['accountID'] ?? 0);

if ($accountID < 1) {
    neoStatusResponse(401, [
        'success' => false,
        'error' => 'LOGIN_REQUIRED'
    ]);
}

$songID = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$songID || $songID < 1) {
    neoStatusResponse(400, [
        'success' => false,
        'error' => 'INVALID_SONG_ID'
    ]);
}

try {
    if (!isset($db) || !($db instanceof PDO)) {
        throw new RuntimeException('DATABASE_NOT_AVAILABLE');
    }

    $stmt = $db->prepare(
        'SELECT ID, name, authorName, isDisabled
         FROM songs
         WHERE ID = ? AND reuploadID = ?
         LIMIT 1'
    );

    $stmt->execute([$songID, $accountID]);
    $song = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$song) {
        // The callback may still be processing the upload.
        neoStatusResponse(200, [
            'success' => true,
            'registered' => false
        ]);
    }

    $disabled = (int)$song['isDisabled'] === 1;

    neoStatusResponse(200, [
        'success' => true,
        'registered' => true,
        'song' => [
            'id' => (int)$song['ID'],
            'name' => (string)$song['name'],
            'authorName' => (string)$song['authorName'],
            'isDisabled' => $disabled,
            'published' => !$disabled
        ]
    ]);
} catch (Throwable $e) {
    error_log('Neo song status error: ' . $e->getMessage());

    neoStatusResponse(500, [
        'success' => false,
        'error' => 'STATUS_CHECK_FAILED'
    ]);
}
