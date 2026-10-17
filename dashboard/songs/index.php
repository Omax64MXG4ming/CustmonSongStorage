<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = dirname(__DIR__, 2);

require_once $root . '/database/config/misc.php';
require_once $root . '/database/incl/lib/connection.php';
require_once $root . '/database/incl/lib/Captcha.php';

if (empty($_SESSION['neo_song_csrf'])) {
    $_SESSION['neo_song_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['neo_song_csrf'];
$accountID = (int)($_SESSION['accountID'] ?? 0);
$songUploadsEnabled = isset($songEnabled) && (bool)$songEnabled;

function neoSongEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102b63">

    <title>Upload Music</title>

    <style>
        :root {
            color-scheme: dark;
            --bg: #081326;
            --panel: #101f3c;
            --border: #2d4773;
            --blue: #3478f6;
            --text: #edf4ff;
            --muted: #aabbd7;
            --green: #45d6a0;
            --red: #ff7272;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 28px 16px;
            color: var(--text);
            background:
                radial-gradient(ellipse at top, #173b78 0%, transparent 55%),
                var(--bg);
            font-family: system-ui, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
        }

        .container {
            width: 100%;
            max-width: 680px;
            margin: 0 auto;
        }

        .panel {
            padding: 26px;
            background: rgba(16, 31, 60, .96);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 18px 55px rgba(0, 0, 0, .25);
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(25px, 5vw, 34px);
        }

        .description {
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .field {
            margin-bottom: 19px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 650;
        }

        input[type="text"],
        input[type="file"] {
            width: 100%;
            min-height: 46px;
            padding: 12px;
            color: var(--text);
            background: #09162d;
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        input:focus-visible,
        button:focus-visible,
        a:focus-visible {
            outline: 2px solid #80b1ff;
            outline-offset: 3px;
        }

        .hint {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: 13px;
        }

        button,
        .link-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 45px;
            padding: 11px 17px;
            border: 0;
            border-radius: 10px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .primary {
            color: white;
            background: var(--blue);
        }

        .secondary {
            color: var(--text);
            background: #243b60;
        }

        button:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        .progress-wrap {
            display: none;
            margin-top: 24px;
        }

        .progress-track {
            width: 100%;
            height: 12px;
            overflow: hidden;
            background: #071126;
            border: 1px solid var(--border);
            border-radius: 99px;
        }

        .progress-bar {
            width: 0;
            height: 100%;
            background: linear-gradient(90deg, #3478f6, #45d6a0);
            transition: width .15s ease;
        }

        .status {
            margin-top: 16px;
            padding: 13px 15px;
            border: 1px solid var(--border);
            border-radius: 10px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .status:empty {
            display: none;
        }

        .status.success {
            color: var(--green);
            border-color: #277e66;
        }

        .status.error {
            color: var(--red);
            border-color: #854343;
        }

        .status.info {
            color: var(--text);
        }

        .song-details {
            margin-top: 10px;
            color: var(--text);
        }

        .footer {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 20px;
        }

        .footer a {
            color: #a9c9ff;
        }

        .captcha-area {
            margin: 18px 0;
        }

        @media (max-width: 480px) {
            .panel {
                padding: 19px;
            }

            .actions > * {
                width: 100%;
            }
        }
    </style>
</head>

<body>
<main class="container">
    <section class="panel">
        <h1>Upload Music</h1>

        <p class="description">
            Upload an MP3 or OGG audio file to .
            Files can be up to 10 MiB. Your submission will be registered
            after the server verifies the uploaded file.
        </p>

        <?php if ($accountID < 1): ?>
            <div class="status error">
                Please sign in to your  account before uploading music.
            </div>

            <div class="footer">
                <a href="/dashboard/">Return to Dashboard</a>
            </div>

        <?php elseif (!$songUploadsEnabled): ?>
            <div class="status error">
                Music uploads are currently disabled.
            </div>

            <div class="footer">
                <a href="/dashboard/">Return to Dashboard</a>
            </div>

        <?php else: ?>
            <form id="songUploadForm" enctype="multipart/form-data">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= neoSongEscape($csrfToken) ?>"
                >

                <div class="field">
                    <label for="song_name">Song name</label>
                    <input
                        type="text"
                        id="song_name"
                        name="song_name"
                        maxlength="40"
                        required
                    >
                </div>

                <div class="field">
                    <label for="author_name">Artist name</label>
                    <input
                        type="text"
                        id="author_name"
                        name="author_name"
                        maxlength="40"
                        required
                    >
                </div>

                <div class="field">
                    <label for="song">Audio file</label>
                    <input
                        type="file"
                        id="song"
                        name="song"
                        accept=".mp3,.ogg,audio/mpeg,audio/ogg"
                        required
                    >
                    <span class="hint">
                        Supported formats: MP3 and OGG. Maximum size: 10 MiB.
                    </span>
                </div>

                <div class="captcha-area">
                    <?php Captcha::displayCaptcha(); ?>
                </div>

                <div class="actions">
                    <button
                        class="primary"
                        id="uploadButton"
                        type="submit"
                    >
                        Upload Music
                    </button>

                    <button
                        class="secondary"
                        id="checkButton"
                        type="button"
                        hidden
                    >
                        Check Registration
                    </button>

                    <button
                        class="secondary"
                        id="anotherButton"
                        type="button"
                        hidden
                    >
                        Upload Another Song
                    </button>
                </div>
            </form>

            <div class="progress-wrap" id="progressWrap">
                <div
                    class="progress-track"
                    role="progressbar"
                    aria-label="Upload progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="0"
                    id="progressTrack"
                >
                    <div class="progress-bar" id="progressBar"></div>
                </div>
                <p id="progressText">Preparing upload...</p>
            </div>

            <div
                class="status"
                id="statusMessage"
                role="status"
                aria-live="polite"
            ></div>

            <div class="footer">
                <a href="/dashboard/">Dashboard</a>
                <a href="/"> Home</a>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php if ($accountID > 0 && $songUploadsEnabled): ?>
<script type="module">
import { upload } from "https://esm.sh/@vercel/blob/client";

const form = document.getElementById("songUploadForm");
const uploadButton = document.getElementById("uploadButton");
const checkButton = document.getElementById("checkButton");
const anotherButton = document.getElementById("anotherButton");

const progressWrap = document.getElementById("progressWrap");
const progressBar = document.getElementById("progressBar");
const progressText = document.getElementById("progressText");
const progressTrack = document.getElementById("progressTrack");
const statusMessage = document.getElementById("statusMessage");

const maxSize = 10 * 1024 * 1024;
let currentSongID = null;
let uploading = false;

function showStatus(message, type = "info") {
    statusMessage.className = `status ${type}`;
    statusMessage.textContent = message;
}

function setProgress(percentage, message) {
    const value = Math.max(0, Math.min(100, Math.round(percentage)));

    progressWrap.style.display = "block";
    progressBar.style.width = `${value}%`;
    progressTrack.setAttribute("aria-valuenow", String(value));
    progressText.textContent = message;
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function getJsonResponse(response) {
    const data = await response.json().catch(() => null);

    if (!response.ok) {
        const error = data?.error || `HTTP_${response.status}`;
        throw new Error(error);
    }

    if (!data) {
        throw new Error("INVALID_SERVER_RESPONSE");
    }

    return data;
}

async function checkRegistrationOnce(songID) {
    const response = await fetch(
        `/dashboard/songs/music-upload-status.php?id=${encodeURIComponent(songID)}`,
        {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store"
        }
    );

    return getJsonResponse(response);
}

function showRegisteredSong(song) {
    const state = song.published
        ? "The song is registered and enabled."
        : "The song is registered, but it is awaiting approval or activation.";

    showStatus(
        `${state} Song ID: ${song.id}. Name: ${song.name}. Artist: ${song.authorName}.`,
        "success"
    );

    progressText.textContent = "Upload and database registration completed.";
    setProgress(100, progressText.textContent);

    uploadButton.hidden = true;
    checkButton.hidden = true;
    anotherButton.hidden = false;
}

async function waitForRegistration(songID, timeoutMs = 90000) {
    const deadline = Date.now() + timeoutMs;

    showStatus(
        "The audio file was uploaded. Waiting for  to register it in the database...",
        "info"
    );

    while (Date.now() < deadline) {
        try {
    const data = await checkRegistrationOnce(songID);

    if (data.registered && data.song) {
        showRegisteredSong(data.song);
        return true;
    }
} catch (error) {
    // A temporary status request failure should not cancel the upload.
    console.warn("Temporary registration check failure.");
}

await delay(2500);
    }

    showStatus(
        "The upload completed, but database registration has not been confirmed yet. You can check again shortly.",
        "info"
    );

    checkButton.hidden = false;
    checkButton.disabled = false;
    checkButton.textContent = "Check Registration";
    return false;
}

async function checkRegistration() {
    if (!currentSongID || uploading) return;

    checkButton.disabled = true;
    checkButton.textContent = "Checking...";

    try {
        const registered = await waitForRegistration(currentSongID);

        if (!registered) {
            checkButton.disabled = false;
            checkButton.textContent = "Check Again";
        }
    } catch (error) {
        console.error("Registration status check failed:", error);

        showStatus(
            "Unable to check the database status. Please try again.",
            "error"
        );

        checkButton.disabled = false;
        checkButton.textContent = "Check Again";
    }
}

form.addEventListener("submit", async event => {
    event.preventDefault();

    if (uploading) return;

    const fileInput = document.getElementById("song");
    const file = fileInput.files?.[0];

    if (!file) {
        showStatus("Please select an audio file.", "error");
        return;
    }

    if (file.size < 1 || file.size > maxSize) {
        showStatus("The file must be between 1 byte and 10 MiB.", "error");
        return;
    }

    const extension = file.name.split(".").pop().toLowerCase();

    if (!["mp3", "ogg"].includes(extension)) {
        showStatus("Only MP3 and OGG files are supported.", "error");
        return;
    }

// Android browsers can report an empty or inconsistent file.type.
// Validate the extension here; the server validates the actual upload.
const contentType = extension === "mp3"
    ? "audio/mpeg"
    : "audio/ogg";

    uploading = true;
    currentSongID = null;

    uploadButton.disabled = true;
    checkButton.hidden = true;
    anotherButton.hidden = true;
    showStatus("");
    setProgress(0, "Preparing upload...");

    try {
        const formData = new FormData(form);

        // The audio file goes directly to Blob, not through PHP.
        formData.delete("song");

        formData.set("file_name", file.name);
        formData.set("file_size", String(file.size));
        formData.set("file_type", file.type);

        const prepareResponse = await fetch(
            "/dashboard/songs/music-upload-prepare.php",
            {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                cache: "no-store"
            }
        );

        const prepared = await getJsonResponse(prepareResponse);

        if (!prepared.success || !prepared.token || !prepared.id) {
            throw new Error(prepared.error || "UPLOAD_PREPARATION_FAILED");
        }

        currentSongID = prepared.id;

        setProgress(0, "Uploading audio to secure storage...");

        await upload(
            prepared.pathname,
            file,
            {
                access: "public",
                handleUploadUrl: "https://neops-api-saver.vercel.app/api/upload",
                clientPayload: prepared.token,
                contentType: contentType,
                multipart: true,

                onUploadProgress(progress) {
                    const percentage = Number(progress.percentage || 0);

                    setProgress(
                        percentage,
                        `Uploading audio... ${Math.round(percentage)}%`
                    );
                }
            }
        );

        setProgress(100, "File uploaded. Confirming database registration...");

        // The callback inserts the row; this page only verifies it.
        await waitForRegistration(currentSongID);

    } catch (error) {
        console.error(" music upload failed:", error);

        showStatus(
            `Upload or registration failed: ${error.message || "UNKNOWN_ERROR"}. Check the Vercel and PHP logs for details.`,
            "error"
        );

        if (currentSongID) {
            checkButton.hidden = false;
            checkButton.disabled = false;
            checkButton.textContent = "Check Registration";
        }
    } finally {
        uploading = false;
        uploadButton.disabled = false;

        if (!anotherButton.hidden) {
            uploadButton.hidden = true;
        }
    }
});

checkButton.addEventListener("click", checkRegistration);

anotherButton.addEventListener("click", () => {
    form.reset();

    currentSongID = null;
    progressWrap.style.display = "none";
    progressBar.style.width = "0%";

    statusMessage.textContent = "";
    statusMessage.className = "status";

    uploadButton.hidden = false;
    uploadButton.disabled = false;
    checkButton.hidden = true;
    anotherButton.hidden = true;
});
</script>
<?php endif; ?>
</body>
</html>
