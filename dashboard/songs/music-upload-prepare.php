<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);

require_once $root . '/database/config/misc.php';
require_once $root . '/database/incl/lib/connection.php';
require_once $root . '/database/incl/lib/Captcha.php';
require_once $root . '/database/incl/lib/exploitPatch.php';
require_once $root . '/database/config/music-storage.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function neoPrepareResponse(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function neoPrepareSign(array $claims, string $secret): string {
    $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON_ENCODING_FAILED');
    }

    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, $secret);

    return $payload . '.' . $signature;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    neoPrepareResponse(405, ['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}

if (
    !defined('NEO_MUSIC_API_SECRET') ||
    NEO_MUSIC_API_SECRET === ''
) {
    neoPrepareResponse(500, ['success' => false, 'error' => 'SERVER_CONFIG_ERROR']);
}

$accountID = (int)($_SESSION['accountID'] ?? 0);
if ($accountID < 1) {
    neoPrepareResponse(401, ['success' => false, 'error' => 'LOGIN_REQUIRED']);
}

if (
    empty($_SESSION['neo_song_csrf']) ||
    !is_string($_POST['csrf_token'] ?? null) ||
    !hash_equals(
        (string)$_SESSION['neo_song_csrf'],
        (string)$_POST['csrf_token']
    )
) {
    neoPrepareResponse(403, ['success' => false, 'error' => 'INVALID_CSRF']);
}

if (!isset($songEnabled) || !(bool)$songEnabled) {
    neoPrepareResponse(403, ['success' => false, 'error' => 'UPLOADS_DISABLED']);
}

/*
 * Keep the same CAPTCHA implementation used by the existing form.
 * This assumes validateCaptcha() reads the submitted POST fields.
 */
try {
    if (!Captcha::validateCaptcha()) {
        neoPrepareResponse(400, ['success' => false, 'error' => 'CAPTCHA_FAILED']);
    }
} catch (Throwable $e) {
    error_log('Neo song CAPTCHA error: ' . $e->getMessage());
    neoPrepareResponse(400, ['success' => false, 'error' => 'CAPTCHA_VALIDATION_ERROR']);
}

$fileName = (string)($_POST['file_name'] ?? '');
$fileSize = filter_var($_POST['file_size'] ?? null, FILTER_VALIDATE_INT);
$fileType = strtolower(trim((string)($_POST['file_type'] ?? '')));

$maxUploadSize = 10 * 1024 * 1024;

if ($fileSize === false || $fileSize < 1 || $fileSize > $maxUploadSize) {
    neoPrepareResponse(400, ['success' => false, 'error' => 'INVALID_FILE_SIZE']);
}

$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($extension, ['mp3', 'ogg'], true)) {
    neoPrepareResponse(400, ['success' => false, 'error' => 'INVALID_FILE_EXTENSION']);
}

if ($extension === 'mp3') {
    if (!in_array($fileType, ['audio/mpeg', 'audio/mp3', 'audio/x-mp3'], true)) {
        neoPrepareResponse(400, ['success' => false, 'error' => 'INVALID_FILE_TYPE']);
    }
    $contentType = 'audio/mpeg';
} else {
    if (!in_array($fileType, ['audio/ogg', 'application/ogg'], true)) {
        neoPrepareResponse(400, ['success' => false, 'error' => 'INVALID_FILE_TYPE']);
    }
    $contentType = 'audio/ogg';
}

try {
    $songName = trim((string)($_POST['song_name'] ?? ''));
    $authorName = trim((string)($_POST['author_name'] ?? ''));

    $songName = ExploitPatch::rucharclean($songName);
    $authorName = ExploitPatch::rucharclean($authorName);

    if (
        $songName === '' ||
        $authorName === '' ||
        mb_strlen($songName) > 40 ||
        mb_strlen($authorName) > 40
    ) {
        neoPrepareResponse(400, ['success' => false, 'error' => 'INVALID_SONG_DETAILS']);
    }

    if (!isset($db) || !($db instanceof PDO)) {
        throw new RuntimeException('DATABASE_NOT_AVAILABLE');
    }

    // Reserve an ID not already present in the songs table.
    $check = $db->prepare('SELECT 1 FROM songs WHERE ID = ? LIMIT 1');

    $songID = 0;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $candidate = random_int(99, 7999999);
        $check->execute([$candidate]);

        if (!$check->fetchColumn()) {
            $songID = $candidate;
            break;
        }
    }

    if ($songID < 1) {
        neoPrepareResponse(503, ['success' => false, 'error' => 'NO_SONG_ID_AVAILABLE']);
    }

    $claims = [
        'id' => $songID,
        'accountID' => $accountID,
        'ext' => $extension,
        'exp' => time() + 600,
        'name' => $songName,
        'authorName' => $authorName,
        'expectedSize' => $fileSize
    ];

    $token = neoPrepareSign($claims, NEO_MUSIC_API_SECRET);

    neoPrepareResponse(200, [
        'success' => true,
        'id' => $songID,
        'pathname' => "songs/{$songID}.{$extension}",
        'contentType' => $contentType,
        'token' => $token
    ]);
} catch (Throwable $e) {
    error_log('Neo song preparation failed: ' . $e->getMessage());
    neoPrepareResponse(500, ['success' => false, 'error' => 'PREPARATION_FAILED']);
}
