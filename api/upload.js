import { handleUpload } from "@vercel/blob/client";
import { createHmac, timingSafeEqual } from "node:crypto";

const SECRET = process.env.MUSIC_UPLOAD_SECRET;
const MAX_SIZE = 10 * 1024 * 1024;
const ALLOWED_ORIGIN = "https://CHANGE THIS TO YOUR GDPS BASE URL";
const API_CALLBACK_URL = "https://YOUR VERCEL APP URL NAME.vercel.app/api/upload";

function verifyToken(token, allowExpired = false) {
  if (
    typeof token !== "string" ||
    token.length > 4096 ||
    !SECRET
  ) {
    return null;
  }

  const parts = token.split(".");
  if (parts.length !== 2) return null;

  const [payload, signature] = parts;

  if (
    !/^[A-Za-z0-9_-]+$/.test(payload) ||
    !/^[a-f0-9]{64}$/i.test(signature)
  ) {
    return null;
  }

  const expected = createHmac("sha256", SECRET)
    .update(payload, "utf8")
    .digest();

  const received = Buffer.from(signature, "hex");

  if (
    received.length !== expected.length ||
    !timingSafeEqual(received, expected)
  ) {
    return null;
  }

  let claims;

  try {
    claims = JSON.parse(
      Buffer.from(payload, "base64url").toString("utf8")
    );
  } catch {
    return null;
  }

  const now = Math.floor(Date.now() / 1000);

  if (
    !claims ||
    !Number.isSafeInteger(claims.id) ||
    claims.id < 1 ||
    !Number.isSafeInteger(claims.accountID) ||
    claims.accountID < 1 ||
    !["mp3", "ogg"].includes(claims.ext) ||
    !Number.isSafeInteger(claims.exp) ||
    !Number.isSafeInteger(claims.expectedSize) ||
    claims.expectedSize < 1 ||
    claims.expectedSize > MAX_SIZE ||
    typeof claims.name !== "string" ||
    typeof claims.authorName !== "string"
  ) {
    return null;
  }

  if (allowExpired) {
    if (claims.exp < now - 3600 || claims.exp > now + 600) {
      return null;
    }
  } else if (claims.exp <= now || claims.exp > now + 600) {
    return null;
  }

  return claims;
}

function expectedMime(ext) {
  return ext === "mp3" ? "audio/mpeg" : "audio/ogg";
}

function validBlobUrl(value) {
  try {
    const url = new URL(value);

    return (
      url.protocol === "https:" &&
      url.hostname.endsWith(".public.blob.vercel-storage.com")
    );
  } catch {
    return false;
  }
}

export default async function handler(req, res) {
  res.setHeader("Cache-Control", "no-store");

  const origin = req.headers.origin;

  if (origin && origin !== ALLOWED_ORIGIN) {
    return res.status(403).json({ error: "ORIGIN_NOT_ALLOWED" });
  }

  if (origin === ALLOWED_ORIGIN) {
    res.setHeader("Access-Control-Allow-Origin", ALLOWED_ORIGIN);
    res.setHeader("Vary", "Origin");
    res.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
    res.setHeader("Access-Control-Allow-Headers", "Content-Type");
    res.setHeader("Access-Control-Max-Age", "86400");
  }

  if (req.method === "OPTIONS") {
    if (origin !== ALLOWED_ORIGIN) {
      return res.status(403).end();
    }

    return res.status(204).end();
  }

  if (req.method !== "POST") {
    res.setHeader("Allow", "POST, OPTIONS");
    return res.status(405).json({ error: "METHOD_NOT_ALLOWED" });
  }

  const phpCallbackUrl = process.env.MUSIC_UPLOAD_CALLBACK_URL;
  const callbackSecret = process.env.MUSIC_CALLBACK_SECRET;

  if (
    !SECRET ||
    !process.env.BLOB_READ_WRITE_TOKEN ||
    !phpCallbackUrl ||
    !callbackSecret
  ) {
    console.error("Missing required upload environment variables.");
    return res.status(500).json({ error: "SERVER_CONFIG_ERROR" });
  }

  // Vercel sends this body type when notifying us that an upload completed.
  const isCompletion =
    req.body?.type === "blob.upload-completed";

  try {
    const result = await handleUpload({
      body: req.body,
      request: req,

      onBeforeGenerateToken: async (pathname, clientPayload) => {
        const claims = verifyToken(clientPayload);

        if (!claims) {
          throw new Error("INVALID_UPLOAD_AUTHORIZATION");
        }

        const expectedPath = `songs/${claims.id}.${claims.ext}`;

        if (pathname !== expectedPath) {
          throw new Error("INVALID_PATH");
        }

        return {
          allowedContentTypes: [expectedMime(claims.ext)],
          maximumSizeInBytes: claims.expectedSize,
          addRandomSuffix: false,
          allowOverwrite: false,
          validUntil: claims.exp * 1000,
          tokenPayload: clientPayload,
          callbackUrl: API_CALLBACK_URL
        };
      },

      onUploadCompleted: async ({ blob, tokenPayload }) => {
        const claims = verifyToken(tokenPayload, true);

        if (!claims) {
          throw new Error("INVALID_COMPLETION_TOKEN");
        }

        const expectedPath = `songs/${claims.id}.${claims.ext}`;
        const expectedType = expectedMime(claims.ext);

        if (
          blob.pathname !== expectedPath ||
          blob.contentType !== expectedType ||
          (Number.isSafeInteger(blob.size) &&
            blob.size !== claims.expectedSize) ||
          !validBlobUrl(blob.url)
        ) {
          throw new Error("UPLOADED_FILE_VALIDATION_FAILED");
        }

        // If Blob omits its size metadata, PHP still checks the actual
        // downloaded file size before inserting the database row.
        const actualSize = Number.isSafeInteger(blob.size)
          ? blob.size
          : claims.expectedSize;

        const response = await fetch(phpCallbackUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${callbackSecret}`
          },
          body: JSON.stringify({
            token: tokenPayload,
            id: claims.id,
            accountID: claims.accountID,
            ext: claims.ext,
            url: blob.url,
            pathname: blob.pathname,
            contentType: blob.contentType,
            size: actualSize
          }),
          signal: AbortSignal.timeout(15000)
        });

        if (!response.ok) {
          // Do not log the token, URL credentials, or authorization header.
          console.error("GDPS PHP callback returned HTTP", response.status);
          throw new Error("PHP_CALLBACK_FAILED");
        }

        console.log("GDPS song registration completed:", claims.id);
      }
    });

    return res.status(200).json(result);
  } catch (error) {
    console.error("Neo Blob handler error:", {
      name: error?.name,
      message: error?.message
    });

    // A completion callback failure should be retried by the provider;
    // malformed upload requests should not be treated as server failures.
    const status = isCompletion ? 500 : 400;

    return res.status(status).json({
      success: false,
      error: isCompletion
        ? "UPLOAD_COMPLETION_FAILED"
        : (error?.message || "INVALID_UPLOAD_REQUEST")
    });
  }
}
