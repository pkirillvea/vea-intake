# Legal form: request and response contract

The contract between the front end (`app/forms/legal/assets/js/legal.js`, Prompt 3)
and the endpoints `/legal/ping.php` and `/legal/submit.php` (Prompt 4). The server
is authoritative: it re-checks everything below and rejects anything else.
Limits marked † come from `content.json` → `limits` (browser) and
`/etc/vea-intake/app.env` (server); keep them equal.

## 1. `GET /legal/ping.php`

Sent before every submission with `fetch(…, { redirect: 'manual' })`, and once at
page load to fill "Signed in as …". Detects an expired Cloudflare Access session
before any upload starts.

| Response | Meaning | Front end |
|---|---|---|
| `200` `{"email": "a@veanea.org", "name": "Ann Example"}` | Access JWT valid (AccessAuth) | OK; shows "Signed in as …" |
| `204` (no body) | Access JWT valid | OK |
| `401` `{"ok": false, "error": "unauthorized"}` | JWT missing or invalid | Session-expired message, data kept |
| opaque redirect (Access sends a 302 to `*.cloudflareaccess.com`) | Access session expired | Session-expired message, data kept |
| `404` | Endpoint not built yet (before Prompt 4) | "Submission is not enabled yet (test build)." |
| `405` | Not GET | — |
| anything else / network error | | Generic error, data kept |

Session-expired message (PLAN §10): "Your sign-in has expired. [Sign in again]
(opens `/legal/` in a new tab), then come back here and click Submit."

## 2. `POST /legal/submit.php`

`multipart/form-data` (sent with `XMLHttpRequest` for the upload progress bar):

| Part | Content |
|---|---|
| `payload` | one field: the JSON object in §3, UTF-8, at most 64 KB |
| `files[]` | 0–5 files † (the originals as selected, with their file names) |

No other fields are allowed (`Http::readMultipart` returns 400). The whole body is
at most **27 MB** (nginx `client_max_body_size 27m`, PHP `post_max_size 27M`; kept
in RAM by `client_body_buffer_size 27m`). Rate limit: 6 per minute per real client
IP, burst 3 (nginx, 429).

## 3. Payload JSON (`payload` field)

All keys are always present (empty string when not applicable). Unknown keys are
rejected. Strings are trimmed by the server; control characters (C0/C1, DEL,
U+2028/2029, bidi overrides) are rejected, except tab/LF/CR in `narrative`.

| Key | Type | Required | Rule |
|---|---|---|---|
| `formVersion` | string | yes | equals `content.json` `_meta.formVersion` on the server; else `invalid_input` with field `formVersion` (front end: "page is out of date") |
| `dataVersion` | string | yes | equals the server's `form-data.json` `version`; else field `dataVersion` |
| `firstName` | string | yes | 1–50 chars |
| `lastName` | string | yes | 1–50 chars |
| `membershipNumber` | string | yes | `^\d{10}$` (leading zeros kept) |
| `street` | string | yes | 1–100 chars |
| `city` | string | yes | 1–50 chars |
| `state` | string | yes | one of `form-data.json` `states` (US states + DC) |
| `zip` | string | yes | `^\d{5}(-\d{4})?$` |
| `phone` | string | yes | 10 digits, `^[2-9]\d{2}[2-9]\d{6}$` (the front end sends digits only) |
| `email` | string | yes | valid address, at most 254 chars |
| `dateOfBirth` | string | no | `""` or `YYYY-MM-DD`: a real date, not in the future, age 16–100 † on today's date in America/New_York (`Birthdate::check`) |
| `local` | string | yes | one of `form-data.json` locals (exact), including `"OTHER / NOT LISTED"` |
| `localOther` | string | if `local` is NOT LISTED | 1–100 chars; must be `""` otherwise |
| `worksite` | string | yes | one of the chosen local's worksites (exact), including `"OTHER / NOT LISTED"` |
| `worksiteOther` | string | if `worksite` is NOT LISTED | 1–100 chars; must be `""` otherwise |
| `jobTitle` | string | yes | 1–100 chars |
| `timeInPosition` | string | yes | 1–50 chars |
| `issueType` | string | yes | one of `content.json` `issueTypes` (exact) |
| `issueTypeOther` | string | if `issueType` is `"Other"` | 1–100 chars; must be `""` otherwise |
| `incidentDate` | string | yes | `YYYY-MM-DD`, a real date, not after today (America/New_York) |
| `narrative` | string | yes | at most 300 words † (runs of non-whitespace, `Validator::wordCount`) and 3,000 chars †; tab/LF/CR allowed |
| `requestedOutcome` | string | yes | 1–1,000 chars † |
| `website` | string | — | honeypot: must be `""`. Non-empty → the server answers `200 {"ok":true,"id":…}` and sends nothing |

There is **no signature** (PLAN 1.4).

## 4. Files (`files[]`)

Checked by `UploadValidator` (PLAN §4): count ≤ 5 †, each ≤ 10 MB †, total ≤ 25 MB †,
`UPLOAD_ERR_OK` + `is_uploaded_file`; extension in docx, xlsx, csv, pdf, png,
jpg, jpeg, txt **and** the `finfo` MIME type matches it; Office files are valid
ZIPs without macros, at most 100 MB uncompressed and 2,000 entries; PDF/PNG/JPG
magic bytes; CSV/TXT without NUL bytes, UTF-8 or Windows-1252. Failures are
`invalid_input` with field `files`. The browser checks count, size, total and
extension before upload; the rest is server-only.

## 5. Responses of `submit.php`

Always JSON (`Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`).

| Status | Body | Front end |
|---|---|---|
| `200` | `{"ok": true, "id": "YYYYMMDD-HHMM-XXXX"}` | Confirmation screen "Reference #id"; state cleared; history entry replaced |
| `400` | `{"ok": false, "error": "invalid_input", "fields": ["zip", "email"]}` | Field names only (never values). Fields mapped back to the form with an error each; list shown on the review page; data kept. `formVersion`/`dataVersion` → "page is out of date" |
| `401` | `{"ok": false, "error": "unauthorized"}` | Session-expired message, data kept |
| `405` | `{"ok": false, "error": "invalid_input", "fields": []}` | Not POST (should not happen) |
| `413` | `{"ok": false, "error": "invalid_input", "fields": []}` (or nginx's own 413 page) | "The request is too large to send" |
| `429` | nginx's page (not JSON) | "Too many attempts. Wait a minute" |
| `502` | `{"ok": false, "error": "send_failed"}` | Staff email failed: generic error, data kept so the user can retry |
| `503` | `{"ok": false, "error": "unavailable"}` | `/run/vea-intake` is not a mounted tmpfs (TmpfsGuard): "temporarily unavailable", data kept |
| `500` | `{"ok": false, "error": "server_error"}` | Generic error, data kept |
| `404` | (any) | Before Prompt 4: "Submission is not enabled yet (test build)." |
| network error / status 0 | — | "The request could not be sent", data kept |

Error codes are the `HttpError` constants: `invalid_input`, `unauthorized`,
`unavailable`, `send_failed`, `server_error` (`verification_failed` is not used:
no Turnstile). The server never echoes field values, file names or Graph responses.
