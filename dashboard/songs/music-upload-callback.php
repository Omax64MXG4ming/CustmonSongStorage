<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);

require_once $root . '/database/config/misc.php';
require_once $root . '/database/incl/lib/connection.php';
require_once $root . '/database/incl/lib/mainLib.php';
require_once $root . '/database/config/music-storage.php';

function neoCallbackResponse(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function neoCallbackVerifyToken(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    [$payload, $signature] = $parts;
    $expected = hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $json = base64_decode(
        strtr($payload, '-_', '+/') .
        str_repeat('=', (4 - strlen($payload) % 4) % 4),
        true
    );

    if ($json === false) {
        return null;
    }

    $claims = json_decode($json, true);
    if (!is_array($claims)) {
        return null;
    }

    $now = time();

    if (
        !is_int($claims['id'] ?? null) ||
        $claims['id'] < 1 ||
        !is_int($claims['accountID'] ?? null) ||
        $claims['accountID'] < 1 ||
        !in_array($claims['ext'] ?? null, ['mp3', 'ogg'], true) ||
        !is_int($claims['exp'] ?? null) ||
        $claims['exp'] > $now + 600 ||
        $claims['exp'] < $now - 3600 ||
        !is_int($claims['expectedSize'] ?? null) ||
        $claims['expectedSize'] < 1 ||
        $claims['expectedSize'] > 10 * 1024 * 1024 ||
        !is_string($claims['name'] ?? null) ||
        !is_string($claims['authorName'] ?? null)
    ) {
        return null;
    }

    return $claims;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    neoCallbackResponse(405, ['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}

if (
    !defined('NEO_MUSIC_CALLBACK_SECRET') ||
    NEO_MUSIC_CALLBACK_SECRET === '' ||
    !defined('NEO_MUSIC_API_SECRET') ||
    NEO_MUSIC_API_SECRET === ''
) {
    neoCallbackResponse(500, ['success' => false, 'error' => 'SERVER_CONFIG_ERROR']);
}

$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$expectedAuthorization = 'Bearer ' . NEO_MUSIC_CALLBACK_SECRET;

if (!hash_equals($expectedAuthorization, $authorization)) {
    neoCallbackResponse(401, ['success' => false, 'error' => 'UNAUTHORIZED']);
}

$body = file_get_contents('php://input');
$data = json_decode($body === false ? '' : $body, true);

if (!is_array($data) || !is_string($data['token'] ?? null)) {
    neoCallbackResponse(400, ['success' => false, 'error' => 'INVALID_REQUEST']);
}

$claims = neoCallbackVerifyToken($data['token'], NEO_MUSIC_API_SECRET);

if ($claims === null) {
    neoCallbackResponse(403, ['success' => false, 'error' => 'INVALID_UPLOAD_TOKEN']);
}

$id = $claims['id'];
$extension = $claims['ext'];
$expectedPath = "songs/{$id}.{$extension}";

if (
    (int)($data['id'] ?? 0) !== $id ||
    (int)($data['accountID'] ?? 0) !== $claims['accountID'] ||
    ($data['pathname'] ?? '') !== $expectedPath ||
    !is_string($data['url'] ?? null) ||
    !is_string($data['contentType'] ?? null) ||
    !is_numeric($data['size'] ?? null)
) {
    neoCallbackResponse(400, ['success' => false, 'error' => 'INVALID_BLOB_METADATA']);
}

$url = $data['url'];
$blobUrl = parse_url($url);
$allowedHost = is_array($blobUrl)
    && isset($blobUrl['scheme'], $blobUrl['host'])
    && $blobUrl['scheme'] === 'https'
    && str_ends_with(strtolower($blobUrl['host']), '.public.blob.vercel-storage.com');

if (!$allowedHost) {
    neoCallbackResponse(400, ['success' => false, 'error' => 'INVALID_BLOB_URL']);
}

$expectedContentType = $extension === 'mp3' ? 'audio/mpeg' : 'audio/ogg';
$size = (int)$data['size'];

if (
    $data['contentType'] !== $expectedContentType ||
    $size < 1 ||
    $size > 10 * 1024 * 1024 ||
    $size !== $claims['expectedSize']
) {
    neoCallbackResponse(400, ['success' => false, 'error' => 'INVALID_BLOB_FILE']);
}

if (!isset($db) || !($db instanceof PDO)) {
    neoCallbackResponse(500, ['success' => false, 'error' => 'DATABASE_NOT_AVAILABLE']);
}

// Make callbacks idempotent: Vercel may retry a completion callback.
try {
    $existing = $db->prepare('SELECT download FROM songs WHERE ID = ? LIMIT 1');
    $existing->execute([$id]);
    $existingUrl = $existing->fetchColumn();

    if ($existingUrl !== false) {
        if (is_string($existingUrl) && hash_equals($existingUrl, $url)) {
            neoCallbackResponse(200, ['success' => true, 'alreadyRegistered' => true]);
        }
        neoCallbackResponse(409, ['success' => false, 'error' => 'SONG_ID_CONFLICT']);
    }
} catch (Throwable $e) {
    error_log('Neo song duplicate check failed: ' . $e->getMessage());
    neoCallbackResponse(500, ['success' => false, 'error' => 'DATABASE_ERROR']);
}

// Download the audio to a temporary file to calculate its duration.
// Redirects are disabled and the hostname was checked above.
$tempFile = tempnam(sys_get_temp_dir(), 'neo_song_');
if ($tempFile === false) {
    neoCallbackResponse(500, ['success' => false, 'error' => 'TEMP_FILE_ERROR']);
}

$duration = 0;
$handle = fopen($tempFile, 'wb');

if ($handle === false) {
    @unlink($tempFile);
    neoCallbackResponse(500, ['success' => false, 'error' => 'TEMP_FILE_ERROR']);
}

try {
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('CURL_INIT_FAILED');
    }

    curl_setopt_array($curl, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FAILONERROR => true
    ]);

    $downloaded = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($handle);
    $handle = false;

    clearstatcache(true, $tempFile);
    $actualSize = filesize($tempFile);

    if (
        $downloaded !== true ||
        $httpCode !== 200 ||
        $actualSize === false ||
        $actualSize !== $size ||
        $actualSize > 10 * 1024 * 1024
    ) {
        throw new RuntimeException('BLOB_DOWNLOAD_FAILED: ' . $curlError);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actualMime = $finfo->file($tempFile);

    $mimeOkay = $extension === 'mp3'
        ? in_array($actualMime, ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], true)
        : in_array($actualMime, ['audio/ogg', 'application/ogg', 'application/octet-stream'], true);

    if (!$mimeOkay) {
        throw new RuntimeException('AUDIO_MIME_MISMATCH');
    }

    $gs = new mainLib();
    $duration = (int)$gs->getAudioDuration($tempFile);

    if ($duration < 0) {
        $duration = 0;
    }

    $songName = $claims['name'];
    $authorName = $claims['authorName'];
    $accountID = $claims['accountID'];
    $sizeMB = round($size / (1024 * 1024), 2);
    $isDisabled = !empty($preenableSongs) ? 0 : 1;

    $db->beginTransaction();

    $insert = $db->prepare(
        'INSERT INTO songs
        (ID, name, authorID, authorName, size, duration, download, hash,
         reuploadTime, reuploadID, isDisabled)
        VALUES (?, ?, 9, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insert->execute([
        $id,
        $songName,
        $authorName,
        $sizeMB,
        $duration,
        $url,
        '',
        time(),
        $accountID,
        $isDisabled
    ]);

    $db->commit();

    neoCallbackResponse(200, [
        'success' => true,
        'id' => $id,
        'duration' => $duration
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Neo song callback failed: ' . $e->getMessage());

    neoCallbackResponse(500, ['success' => false, 'error' => 'SONG_REGISTRATION_FAILED']);
} finally {
    if (is_resource($handle)) {
        fclose($handle);
    }
    @unlink($tempFile);
}
