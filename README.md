### GDPS Music Upload System — How It Works

GDPS uses a direct-to-cloud music upload system that allows authenticated users to upload MP3 and OGG files, store them in Vercel Blob, and register them in the Geometry Dash private server database.

### 1\. System Architecture

1\. User / Web Browser

Signs in, enters song details, selects an audio file, and completes CAPTCHA.

2\. PHP Upload Preparation

Validates the session, CSRF token, CAPTCHA, file metadata, and upload settings. Reserves a song ID and creates a signed authorization token.

`music-upload-prepare.php`

3\. Vercel Blob

The browser uploads the audio directly to cloud storage using Vercel's client upload protocol.

MP3 / OGG · Maximum 10 MiB

4\. Vercel API

Validates the signed authorization, restricts the path and file type, and processes the upload completion callback.

`/api/upload.js`

5\. PHP Callback + MySQL

Verifies the callback, validates the stored file, calculates its duration, and registers the song in the database.

`music-upload-callback.php`

6\. Geometry Dash Client

Uses the registered song ID and download URL to access the song through the existing GDPS song system.

### 2\. How Each Component Works

### A. Frontend — `index.php`

The upload page is responsible for the user experience.

* Requires the user to be logged into GDPS.

* Displays the song name, artist name, audio file selector, and CAPTCHA.

* Accepts MP3 and OGG files up to 10 MiB.

* Requests upload authorization from the PHP preparation endpoint.

* Uploads the file directly to Vercel Blob.

* Displays upload progress and checks whether the database registration has finished.

The audio file does not pass through PHP during the initial upload.

### B. PHP preparation — `music-upload-prepare.php`

Before allowing the upload, PHP verifies that the request is authorized.

It checks the user's session, CSRF token, CAPTCHA, upload configuration, and file metadata. It then reserves a free song ID and creates a signed token containing information such as:

* Song ID and account ID.

* File extension.

* Expiration time.

* Expected file size.

* Song name and artist name.

The HMAC-SHA256 signature allows the Vercel API to verify that the authorization was created by the trusted PHP server and has not been altered.

### C. Cloud storage — Vercel Blob

The browser sends the actual audio file to Vercel Blob rather than uploading it to the GDPS web server first.

This reduces the initial workload on the PHP hosting environment. Vercel's upload protocol handles authorization and the upload process, including progress reporting in the browser.

The API restricts uploads to the authorized song path, expected content type, and maximum permitted size.

### D. Upload completion — `/api/upload.js`

Once the file has been uploaded, Vercel processes the completion event.

The API verifies the signed token and checks the stored file's metadata and URL. It then sends a server-to-server POST request to the PHP callback, authenticated with a separate callback secret.

This is important because the browser itself is not trusted to declare that a song has been successfully registered.

### E. Database registration — `music-upload-callback.php`

The PHP callback verifies the authorization and callback secret, checks the Blob metadata, and validates the stored audio file.

It calculates the audio duration and inserts a record into the existing `songs` table, including fields such as:

| Field        | Purpose                        |
| ------------ | ------------------------------ |
| ID           | Song ID used by the game       |
| name         | Song title                     |
| authorName   | Artist name                    |
| size         | Audio file size                |
| duration     | Audio duration                 |
| download     | Vercel Blob URL                |
| reuploadID   | Account that uploaded the song |
| reuploadTime | Upload registration timestamp  |
| isDisabled   | Whether the song is disabled   |

The callback also checks for an existing record with the same ID and URL, helping prevent duplicate registrations when a completion event is repeated.

### F. Registration status — `music-upload-status.php`

The browser periodically checks this endpoint to determine whether the song exists in the database and belongs to the uploading account.

Once the record appears, the page reports the song ID, name, artist, and whether the song is enabled.

Upload completion and database registration are separate stages. A file can already exist in Blob while the PHP callback is still validating it or registering it.

### 3\. Security Measures

The current architecture uses several layers of protection:

* Session authentication: only signed-in users can initiate uploads.

* CSRF protection and CAPTCHA: help protect the preparation form against unauthorized or automated submissions.

* Signed authorization tokens: bind an upload to its assigned song ID, account, extension, size, and expiration.

* Restricted upload paths: prevent the client from choosing an arbitrary storage path.

* Separate callback authentication: protects the server-to-server registration request.

* Server-side file validation: checks metadata and the actual downloaded audio file.

* Database checks: prevent conflicting song IDs and help make repeated callbacks safe.

The upload API's CORS policy restricts browser requests to the GDPS website, but CORS is not authentication by itself. The signed token and callback secret remain essential.

### 4\. Why This Architecture Is Faster

The main optimization is the separation of file transfer from database registration.

Direct upload

The browser sends the file to Vercel Blob instead of routing the entire upload through PHP.

Asynchronous registration from the user's perspective

The browser can show progress and wait for the database record without performing the registration itself.

Existing game compatibility

The song is stored in GDPS's existing `songs` table, so the established song delivery system can continue using its song ID and download URL.

One remaining cost is that PHP downloads the stored audio to validate it and calculate its duration. That happens after the direct upload, so it does not add the same workload to the initial file transfer, but it can delay registration.

### 5\. Current Upload Lifecycle

* Authorize: PHP validates the user and issues a signed upload token.

* Transfer: the browser uploads the file directly to Vercel Blob.

* Verify: Vercel processes the completion event and sends authenticated metadata to PHP.

* Register: PHP validates the audio, calculates duration, and inserts the song into MySQL.

* Confirm: the browser polls the status endpoint and displays the registered song details.

* Play: Geometry Dash accesses the song through GDPS's existing song delivery mechanism.

In summary, GDPS uses a browser-to-Blob upload, a signed Vercel authorization layer, and a PHP/MySQL registration pipeline. This keeps the initial file transfer separate from database work while preserving the song IDs and database structure expected by the existing Geometry Dash client.
