# VEA Legal Assistance Request: Standalone Build Plan

**Project:** VEA Intake (Legal Assistance Request now; UD Intake later)
**Owner:** Paul (Director of Technology & Data, VEA)
**Version:** 1.4, Nov 23 2026
**Status:** Prompt 1 done (DO2 server work + `verify-do2.sh`). Prompt 2 (scaffold, shared core, assets, data pipeline) in progress. Open items in §14.

**Change log**
- **1.4** (2026-11-23, Prompt 2 decisions): **no signature** (Legal dropped it: no field, no SignatureImage, no signature_pad, no script font, no signature method in the audit, CSP without `data:`/`blob:`); git repo `pkirillvea/vea-intake` (deploy key + SSH alias, like vea-join); namespaces `VeaIntake\Core\` and `VeaIntake\Forms\Legal\`; full core class list; data sources `locals.json` + `worksites.json` produced by `bin/import-vea-join-data.php`; "OTHER / NOT LISTED" stored in the data; form version in `content.json` (removed from `app.env`); `bin/build-assets.php` + `bin/build-site.php --form=legal` (`?v=` SHA-256 cache busting); Composer with dev dependencies under `umask 027`, `bin/check-perms.php`; `.gitignore`. This file in the repo is the source of truth.
- **1.3** (2026-11-23): Phase C/C2 done: tmpfs Wants/After + nofail, per-form assets, host-wide Access app, zero nginx warnings, pdjoin on Origin CA + AOP.
- **1.2** (2026-11-23): repo root renamed to `/var/www/vea-intake`.
- **1.1** (2026-11-22): cf-ip-sync, pdjoin Origin CA + AOP plan, internal DNS delegations, build status.

> On the server this file lives at `/var/www/vea-intake/docs/PLAN.md`. Every Claude Code prompt refers to it.
> This build reuses patterns and core classes from `vea-join` (see `vea-join-PLAN.md`).

---

## 1. Decisions (locked)

| Topic | Decision |
|---|---|
| Hosting | DO2 (droplet ID 621443486, 167.99.48.129), the same droplet as `pdjoin.veanea.net`. Ubuntu 24.04.5, nginx 1.24, PHP 8.3. Paul is sole admin. |
| Hostname | `intake.veanea.net`, proxied through Cloudflare (zone on CF, DNSSEC on). This form lives at `/legal`; UD Intake later at `/ud`. |
| Naming | Everything is **`vea-intake`**: repo root `/var/www/vea-intake` (nginx root `/var/www/vea-intake/public`), system user/group (uid 997 / gid 987), FPM pool, `/etc/vea-intake`, `/run/vea-intake`, `/var/cache/vea-intake`. Same pattern as vea-join. |
| Users | UniServ Directors and VEA Legal staff only. All are in VEA's Entra tenant (two Entra groups). No outside users. |
| Access gate | **Cloudflare Access**, Microsoft Entra ID as identity provider, Instant Auth, 24 h session. Two apps: **`VEA Intake - Legal`** (path `legal`, allows either group) and **`VEA Intake (host)`** (whole hostname, no path, allows Paul/IT only) so nothing on the host is ever public. Path-specific apps take precedence. The app also verifies the `Cf-Access-Jwt-Assertion` JWT on every PHP request. |
| Origin TLS | Cloudflare **Origin CA** certificate `*.veanea.net` + `veanea.net` (expires 2041-11-19) at `/etc/ssl/cloudflare/veanea.net-origin.pem` / `.key` (root, 0644 / 0600). Used by **intake and pdjoin** (pdjoin switched 2026-11-23). SSL mode **Full (strict)**. |
| Origin pulls | Authenticated Origin Pulls **Global** on in Cloudflare. nginx requires the client cert (`ssl_verify_client on`, CA `/etc/ssl/cloudflare/origin-pull-ca.pem`, pinned SHA-256 `c14fed0c…d7b843`, **expires 2029-11-01**) for **intake and pdjoin**. |
| Real client IP / firewall | **`cf-ip-sync`**, the same tool as DO1 (§5). nginx real-IP via `CF-Connecting-IP`; DO cloud firewall `do2-firewall` 80/443 locked to Cloudflare ranges (enforced 2026-11-22). |
| Internal DNS | VEA's AD DNS `veanea.net` zone **delegates** every Cloudflare-proxied hostname to `ace.ns.cloudflare.com` / `josephine.ns.cloudflare.com` so VPN/office users go through Cloudflare (§6). |
| Stack | Static HTML/CSS/vanilla JS front end. PHP 8.3 endpoints, no framework, no database, no sessions. Composer: `dompdf/dompdf`, `firebase/php-jwt`; dev: `phpunit/phpunit` (installed on the server, like vea-join). No vendored front-end libraries. |
| Storage | **Nothing stored on the server.** nginx keeps the request body in memory; PHP uploads go to a RAM-only tmpfs; the PDF is built in memory, emailed and discarded. |
| Repo | Git repo `pkirillvea/vea-intake` (private), remote `git@github-vea-intake:pkirillvea/vea-intake.git` via the SSH alias `github-vea-intake` and deploy key `~/.ssh/vea-intake_deploy` (same pattern as vea-join). Shared `app/core/` (copied from vea-join, then generalized; namespace `VeaIntake\Core\`) + one folder per form (`app/forms/legal/`, namespace `VeaIntake\Forms\Legal\`). Shared front-end assets live once in `app/shared-assets/`; `bin/build-assets.php` copies them into each form's `public/<form>/assets/`. |
| Mail transport | Microsoft Graph as `no.reply@veanea.org`, using a **new** app registration (`VEA Intake Mailer`) with its own secret, scoped to that one mailbox through Exchange RBAC for Applications. |
| Staff email | To `VEALegal@veanea.org`. PDF + original uploaded files attached. |
| Subject | `Legal Assistance Request - [#<ID>] - <submitter handle>` where the handle is first initial + last name (e.g. `jsmith`), from the signed-in identity. May change later. |
| Submitter confirmation | Short confirmation email to the signed-in UD/Legal staffer. **No PDF, no attachments.** Nothing is sent to the member. |
| Privilege marking | PDF header/footer and email body carry a "Privileged & Confidential / Attorney-Client Communication" banner (wording to confirm with Legal). |
| Uploads | Max 5 files; **10 MB per file, 25 MB total**. Allowed: docx, xlsx, csv, pdf, png, jpg/jpeg, txt. No ClamAV for now; Exchange Online Protection scans on arrival at VEALegal. |
| Signature | **None.** Legal dropped the signature (2026-11-23): no signature field, no SignatureImage class, no signature_pad, no script font, no signature method in the audit block. "Submitted by" comes from the Access identity. |
| Bot defense | Access gate + nginx rate limit + honeypot. **No Turnstile.** |
| Location | Cloudflare "Add visitor location headers" managed transform (city/region/country from IP). No browser geolocation. |
| Failure handling | If the staff email fails, the user sees an error and data stays in the browser to retry. |

## 2. Architecture

```
Browser ──► Cloudflare (proxy + Access: Entra sign-in, group policy)
              │  adds Cf-Access-Jwt-Assertion, CF-Connecting-IP, cf-ipcity/region/country
              ▼
DO Cloud Firewall do2-firewall: 80/443 ONLY from Cloudflare ranges (kept in sync by cf-ip-sync); 22 from Paul's two IPs
              ▼
nginx (intake.veanea.net; Origin CA cert; Authenticated Origin Pulls required;
       real_ip from conf.d/cloudflare-realip.conf; only /legal/submit.php and /legal/ping.php reach PHP;
       client_body_buffer_size 27m on submit so bodies never spool to disk; everything outside /legal/ → 404)
              ▼
PHP-FPM pool "vea-intake" (dedicated user, hardened ini, upload_tmp_dir on tmpfs)
  submit.php:
  0. Refuse with 503 unless /run/vea-intake is a mounted tmpfs
  1. Verify Access JWT (signature via team JWKS, aud tag, exp, iss) → submitter email/name
  2. Honeypot check
  3. Validate + whitelist every field against form-data.json
  4. Validate each upload (count, size, extension, real type, macro/zip-bomb checks), hash SHA-256
  5. Render HTML → dompdf → PDF bytes (memory)
  6. Graph: send to VEALegal with PDF + files (see §12 send method)
  7. Graph: send confirmation to submitter
  8. Return {ok, id}; temp files deleted by PHP at request end
  Request format: multipart/form-data with one JSON field `payload` (validated like vea-join's JSON body) plus the files.
  ping.php: verifies JWT, returns 204 (detects an expired Access session before submit)
```

### Repository layout

```
/var/www/vea-intake/               ← repo root; owner developer, group vea-intake (setgid dirs 2750, files 0640)
  public/                          ← nginx root (www-data reads this only, via ACL)
    legal/
      index.html
      submit.php                   ← 3-line entry → ../../app/forms/legal/handler.php
      ping.php
      index.html                   ← GENERATED by bin/build-site.php --form=legal from app/forms/legal/templates/index.html
      assets/                      ← GENERATED by bin/build-assets.php (css, img/logo) + legal.js (Prompt 3)
      data/form-data.json          ← GENERATED by bin/build-data.php
  app/
    core/src/                      ← namespace VeaIntake\Core\ (see "Code organization" below)
    shared-assets/                 ← single source for CSS and logo (allowlist in bin/build-assets.php)
    forms/legal/                   ← namespace VeaIntake\Forms\Legal\
      handler.php  LegalValidator.php  content.json (texts, issue types, _meta.formVersion)
      templates/  index.html  pdf.php  email_staff.php  email_confirm.php
    data/source/  locals.json  worksites.json      ← written by bin/import-vea-join-data.php from vea-join's source
    data/form-data.json                            ← GENERATED (server copy)
    storage/fonts/                                 ← dompdf font cache (no PII), built by bin/warm-fonts.php
  bin/import-vea-join-data.php  bin/build-data.php  bin/build-assets.php  bin/build-site.php
  bin/warm-fonts.php  bin/render-sample-pdf.php  bin/check-perms.php
  tests/                           ← PHPUnit 12 (namespace VeaIntake\Tests\)
  composer.json  phpunit.xml  .gitignore
  docs/PLAN.md  docs/README.md  docs/RUNBOOK.md
/etc/vea-intake/app.env            ← secrets; root:vea-intake 0640; NOT in repo (skeleton created, values blank)
/run/vea-intake/                   ← tmpfs via /etc/fstab (size=128M, 0700 vea-intake, nosuid,nodev,noexec,nofail)
/var/cache/vea-intake/             ← Access JWKS cache only (public keys, no PII)
/var/log/php-fpm-vea-intake.log    ← pool error log (vea-intake 0640), rotated weekly
```

- `developer` is **not** in the `vea-intake` group, so it (and Claude Code) cannot read `app.env`. Paul edits it with `sudoedit`.
- Default ACLs: new files 0640, new dirs 2750, group vea-intake, nothing for "other". www-data has traverse-only on the repo root and read under `public/` only (default ACL there too). It cannot enter `app/`, `bin/`, `docs/`, `tests/`, `vendor/` or any new top-level dir.
- `/var/www` is root-owned, so creating, renaming or removing top-level entries there needs sudo.
- **Composer runs under `umask 027`** (`umask 027 && composer install`). Composer sets the mode of `vendor/bin/*` from the umask; with the usual 002 those files would get access for "other". `bin/check-perms.php` (read-only) reports any path with "other" access, group write, a wrong group or a non-setgid directory.

### Code organization

| Class (`app/core/src`, `VeaIntake\Core\`) | Origin | Notes |
|---|---|---|
| Config | vea-join, generalized | §9 keys; blank `TEST_MODE` = test mode; secrets `GRAPH_*` hidden as in vea-join |
| Http | vea-join, generalized | `send()`; `readMultipart()` (JSON `payload` field + `$_FILES`, ≤ 27 MB); GET-only check for ping |
| HttpError | vea-join, + `unauthorized` (401), `unavailable` (503) | |
| HttpClient, CurlHttpClient, HttpResponse | vea-join, + GET/PUT/DELETE | for Graph's large-attachment path and the Access JWKS |
| Log | vea-join, as-is | one-line events, no field values |
| Template | vea-join, generalized | one template folder per form |
| FormData | new (smaller than vea-join's) | locals, worksites, states, not-listed value |
| Birthdate | vea-join, as-is | optional date of birth, plausible range |
| Validator | vea-join's generic helpers | string/regex/enum checks, `fieldList()`, `dataHash()`; form rules in `LegalValidator` |
| PdfBuilder | vea-join, generalized | template dir, logo and temp base (`/run/vea-intake`) injected; banner header/footer, "Page X of Y" |
| GraphMailer | vea-join, generalized | several attachments; large path (draft, upload session, send, permanentDelete) |
| SubmissionId | vea-join, as-is | `YYYYMMDD-HHMM-XXXX` |
| AccessAuth, AccessIdentity | new | Access JWT verification (Prompt 4) |
| UploadValidator, UploadedFile | new | upload checks (Prompt 4) |
| Audit | new | audit block (Prompt 4) |
| TmpfsGuard | new | 503 unless `/run/vea-intake` is a mounted tmpfs (Prompt 4) |

Not carried over from vea-join: SignatureImage (no signature), Turnstile, DuesCalculator, SiteConfig (the build never reads `app.env`).

## 3. "No storage" guarantees

| Leak path | Control |
|---|---|
| Database / files | None. Endpoint writes no files except to tmpfs. |
| nginx request body | `client_body_buffer_size 27m` on `/legal/submit.php` (≥ max body) and `1m` on pdjoin's `/submit.php`, so nginx never writes bodies to `/var/lib/nginx/body`. `fastcgi_max_temp_file_size 0` so responses never spool either. |
| Upload temp files | `upload_tmp_dir=/run/vea-intake` (RAM). PHP deletes them at request end. Code never calls `move_uploaded_file`. If the tmpfs isn't mounted, the folder is root-owned and unwritable, `open_basedir` blocks `/tmp`, and submit.php refuses with 503 (TmpfsGuard). |
| dompdf temp | `tempDir=/run/vea-intake`. Remote resources disabled. |
| PHP sessions | Never started. Auth is the per-request Access JWT. |
| nginx logs | `access_log off` for `/legal/submit.php` and `/legal/ping.php`. Bodies never logged. Other intake requests log to `/var/log/nginx/intake.access.log`. |
| App/PHP error logs | Event name, submission ID, exception class, HTTP status only. Never field values, filenames or Graph response bodies. |
| Core dumps / swap | Disabled on DO2 (core limit 0, core_pattern `|/bin/false`, apport inactive, no swap); pool `rlimit_core = 0`. |
| no.reply mailbox | See §12. The large-attachment send path briefly creates a draft; the sent copy is **permanently deleted** right after sending. |
| Browser | No localStorage/sessionStorage/cookies of our own (Access sets its own `CF_Authorization` cookie). State cleared after success. `Cache-Control: no-store` on `/legal/`. |
| Submitter confirmation | Reference ID, date/time, member name and local only. No issue type, narrative or files. |
| **VEALegal mailbox** | Permanent record. Most sensitive copy (CPS/criminal/ADA content). Recommend an Exchange sensitivity label + retention policy later. |

## 4. Security controls

- **Cloudflare (zone `veanea.net`)**: SSL/TLS **Full (strict)** ✅; Origin CA cert ✅; Authenticated Origin Pulls Global ✅; Cloudflare reaches DO2 over HTTP/2 ✅; Managed Transform **Add visitor location headers** (to confirm on); check **Bot Fight Mode** isn't blocking uptime checks.
- **DO Cloud Firewall `do2-firewall`** (applied to DO2 by droplet, no tags): 22 from Paul's two IPs; 80/443 from Cloudflare ranges only, maintained by cf-ip-sync. ufw also active (allows 22/80/443).
- **Access JWT verification (app)**: fetch `https://<team>.cloudflareaccess.com/cdn-cgi/access/certs` (cached ≤1 h), verify RS256 signature, `aud` = app AUD tag, `iss` = team domain, `exp`/`nbf`. Reject with 401 if missing or invalid. AOP's global cert proves "came through Cloudflare", not "your Access app", so the JWT is the real gate.
- **Real client IP**: `/etc/nginx/conf.d/cloudflare-realip.conf` (generated by cf-ip-sync): 22 `set_real_ip_from` ranges + `real_ip_header CF-Connecting-IP`. Verified: forged header from non-Cloudflare peers is ignored.
- **Headers** (`/etc/nginx/snippets/vea-intake-headers.conf`, re-included in every location that sets its own headers): CSP `default-src 'self'; script-src 'self'; img-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'`, HSTS, nosniff, `Referrer-Policy: no-referrer`, Permissions-Policy (camera/mic/geolocation off), COOP same-origin. **Pending:** the live snippet still allows `img-src 'self' data: blob:` (needed only by the dropped signature pad); a small root script removes them (§15).
- **nginx** (`/etc/nginx/sites-available/intake.veanea.net`):
  - All 443 listen lines on DO2 are `listen 443 ssl http2;` / `listen [::]:443 ssl http2;` (`00-catchall` adds `default_server`). nginx 1.24 has no `http2 on`. **`nginx -t` must show zero warnings**; mismatched listen options cause "protocol options redefined".
  - `00-catchall` (444 on 80, `ssl_reject_handshake` on 443) refuses unknown Host/SNI.
  - Port 80 → 301 to https. `/` → 404. `/legal` → 301 `/legal/`. `/legal/` static only. Only `= /legal/submit.php` (POST only) and `= /legal/ping.php` (GET only) reach FPM. Every other `*.php`, every dotfile and anything outside `/legal/` → 404.
  - `client_max_body_size 1k` server-wide; `27m` + `client_body_buffer_size 27m` on submit; `limit_req` zone `intake_submit` (6/min per real IP, burst 3, status 429).
- **PHP-FPM pool `vea-intake`** (`/etc/php/8.3/fpm/pool.d/vea-intake.conf`, socket `/run/php/vea-intake.sock` www-data 0660): `display_errors=off`, `expose_php=off`, `allow_url_fopen=off`, `file_uploads=on`, `upload_max_filesize=10M`, `max_file_uploads=5`, `post_max_size=27M`, `upload_tmp_dir` and `sys_temp_dir=/run/vea-intake`, `session.auto_start=off`, `zend.exception_ignore_args=on`, `memory_limit=256M`, `max_execution_time=90`, `open_basedir=/var/www/vea-intake:/run/vea-intake:/etc/vea-intake:/var/cache/vea-intake`, `disable_functions` as vea-join, `clear_env=yes`, `rlimit_core=0`, `pm.max_children=3`.
- **tmpfs ↔ PHP-FPM**: drop-in `/etc/systemd/system/php8.3-fpm.service.d/vea-intake-tmpfs.conf` has **`Wants=` + `After=run-vea\x2dintake.mount` only** (no Requires/BindsTo), so PHP-FPM waits for the mount at boot but a failed or unmounted tmpfs never stops it (pdjoin shares the service). fstab line has `nofail`, so a failed mount can't block boot.
- **Input validation (server, authoritative)**: reject unknown keys; trim and length-cap all strings; whitelists for local, worksite, state, issue type; regexes for membership number (`^\d{10}$`), ZIP, phone (10 digits), email; dates valid ISO, incident date not in the future, DOB (optional) plausible range; narrative ≤ 300 words (and ≤ 3,000 chars); "Other" / "OTHER / NOT LISTED" texts required when chosen.
- **Upload validation**:
  - Count ≤ 5; each ≤ 10 MB; total ≤ 25 MB; `UPLOAD_ERR_OK` and `is_uploaded_file`.
  - Extension whitelist **and** `finfo` MIME must match the extension's allowed set.
  - docx/xlsx: valid ZIP with `[Content_Types].xml`; reject if it contains `vbaProject.bin` or a macro-enabled content type; reject if total uncompressed > 100 MB or > 2,000 entries. Encrypted Office files (not ZIP) are rejected.
  - pdf: `%PDF-` magic. png/jpg: magic + `getimagesize`. Originals are **not** re-encoded (evidence integrity).
  - csv/txt: no NUL bytes; valid UTF-8 (or Windows-1252, converted for display only).
  - Attachment filename sanitized to `[A-Za-z0-9._-]`, max 100 chars, prefixed `01_`…`05_`; original name shown (escaped) in the PDF.
- **Output encoding**: every value HTML-escaped. Email subject built only from the submission ID and the JWT-derived handle.
- **Honeypot**: hidden field; if filled, fake success, send nothing.
- **Secrets**: `/etc/vea-intake/app.env` (0640 root:vea-intake). Graph secret 12-month expiry, calendar reminder at 11 months.

## 5. DO2 infrastructure state

### cf-ip-sync (same tool as DO1, byte-identical; done 2026-11-22)
- `/usr/local/sbin/cf-ip-sync`, `cf-ip-sync.service`, `cf-ip-sync.timer` (daily, up to 1 h random delay).
- Writes `/etc/nginx/conf.d/cloudflare-realip.conf` (nginx -t, reload, restore on failure; backups in `/var/backups/cf-ip-sync/`). Header comment still says "DO1" (cosmetic).
- **FIREWALL_MODE=enforce**: keeps `do2-firewall` 80/443 sources = Cloudflare ranges. Never touches 22. Refuses if the firewall is attached to another droplet or any tag (droplet ID from the metadata service).
- Config `/etc/cf-ip-sync.env` (root 0600): `DO_TOKEN`, `DO_FIREWALL_ID`, `HC_PING_URL`, `FIREWALL_MODE`.
- **DO token (DO2's own)** scopes: firewall read + update, **droplet read** (required), plus DO's auto-added reads (regions, sizes, actions, image, snapshot).
- Healthchecks.io check **`do2-cf-ip-sync`** (daily, 2 h grace).
- **Rollback:** set `FIREWALL_MODE=report`, then reset 80/443 to All IPv4/IPv6 in the DO panel.

### pdjoin (C2 done 2026-11-23)
- Proxied through Cloudflare. Real visitor IP reaches its rate limit, Turnstile `remoteip` and PDF audit IP.
- Serves the **Origin CA cert**, requires Cloudflare's client cert, `client_body_buffer_size 1m` on `/submit.php`, listen lines identical to intake's. Kept certbot's `options-ssl-nginx.conf` include and `ssl_dhparam` (TLS settings unchanged; both files survive `certbot delete`).
- **Rollback until the LE lineage is deleted:** `sudo bash ~/scripts/phase-c2-rollback.sh`.
- **Run `sudo certbot delete --cert-name pdjoin.veanea.net` ~Nov 30**, and in any case **before ~2027-01-19**: a real renewal would make certbot rewrite pdjoin's block back to the LE cert.
- DO uptime check must target `https://pdjoin.veanea.net`, not the droplet IP.

### Scripts
- One-time installers archived in `~/scripts/archive/` (phase-b, phase-b3-verify, phase-c, phase-c-fix). They expect pre-C2 state; don't re-run.
- **`~/scripts/verify-do2.sh`** (read-only, reusable): run after any change or Ubuntu update (§15, step 1D).

### Runbook notes
- Never `umount /run/vea-intake` by hand; reboot or `systemctl restart 'run-vea\x2dintake.mount'` instead.
- `nginx -t` must show **zero warnings** after any change.
- Origin-pull CA expires **2029-11-01**: before then, download Cloudflare's replacement, update `/etc/ssl/cloudflare/origin-pull-ca.pem` and its pinned hash, or both sites will refuse Cloudflare.

## 6. Internal DNS (VEA AD DNS, zone `veanea.net`)

- Proxied hostnames are **delegations** to `ace.ns.cloudflare.com` and `josephine.ns.cloudflare.com`: act, demo-act, dss, vlt, pdjoin, intake.
- Stale A records for pdjoin, veadel and actsb were deleted 2026-11-22.
- **Rule:** every new Cloudflare-proxied hostname needs a matching delegation in AD DNS, or VPN/office users can't reach it. (UD Intake stays under `intake`, so it needs nothing.)
- After DNS changes: `Clear-DnsServerCache -Force` on each DC; `ipconfig /flushdns` on clients.

## 7. Cloudflare Access setup (done)

- Entra app registration `Cloudflare Access SSO`: redirect URI `https://<team-name>.cloudflareaccess.com/cdn-cgi/access/callback`; delegated Graph permissions `openid`, `email`, `profile`, `offline_access`, `User.Read`, `Directory.Read.All`, `GroupMember.Read.All` with admin consent. Note its secret expiry.
- Zero Trust → Integrations → Identity providers → **Microsoft Entra ID**: Application (client) ID, secret **Value**, Directory (tenant) ID; Support groups on. (Watch for browser autofill on those fields.)
- Applications: **Self-hosted** (not the "private web application" wizard, which sets up a Tunnel). `VEA Intake - Legal` (path `legal`) and `VEA Intake (host)` (no path). The group picker's "Failed to retrieve SCIM groups" error is expected without SCIM; paste group Object IDs and press Enter.
- Record the Legal app's **AUD tag** and the team domain for `app.env`.

## 8. Microsoft Graph setup (manual, Paul — needed before Prompt 4)

New app registration `VEA Intake Mailer`, its own 12-month secret, **no Graph API permissions in Entra**. Reuse the existing management scope:

```powershell
New-ServicePrincipal -AppId <ClientId> -ObjectId <EnterpriseAppObjectId> -DisplayName "VEA Intake Mailer"
New-ManagementRoleAssignment -App <ClientId> -Role "Application Mail.Send"      -CustomResourceScope "VEA Join Form - no.reply only"
New-ManagementRoleAssignment -App <ClientId> -Role "Application Mail.ReadWrite" -CustomResourceScope "VEA Join Form - no.reply only"
Test-ServicePrincipalAuthorization -Identity <ClientId> -Resource no.reply@veanea.org   # InScope True
Test-ServicePrincipalAuthorization -Identity <ClientId> -Resource <your mailbox>        # InScope False
```
`Mail.ReadWrite` is needed for the large-attachment path (§12).

## 9. `/etc/vea-intake/app.env`

```
APP_ENV=production
APP_BASE_URL=https://intake.veanea.net
DISPLAY_TZ=America/New_York
ACCESS_TEAM_DOMAIN=https://<team-name>.cloudflareaccess.com
ACCESS_AUD_LEGAL=
GRAPH_TENANT_ID=
GRAPH_CLIENT_ID=
GRAPH_CLIENT_SECRET=
MAIL_FROM=no.reply@veanea.org
MAIL_TO_LEGAL=VEALegal@veanea.org
MAIL_REPLY_TO=no.reply@veanea.org
TEST_MODE=true          # all mail to TEST_MAIL_TO, "[TEST]" subject prefix
TEST_MAIL_TO=
UPLOAD_MAX_FILES=5
UPLOAD_MAX_FILE_MB=10
UPLOAD_MAX_TOTAL_MB=25
NARRATIVE_MAX_WORDS=300
```
The skeleton on the server has every value blank. The app must treat a blank `TEST_MODE` as test mode (or refuse to start), never as production.
The **form version is not in `app.env`**: it lives in the repo, `app/forms/legal/content.json` → `_meta.formVersion` (the build runs as `developer`, who cannot read `app.env`). The server skeleton still has a blank `FORM_VERSION_LEGAL=` line; it is ignored and can be deleted with `sudoedit`.

## 10. Form specification (`/legal`)

Header: VEA logo, **VEA Legal Assistance Request Form**, privilege banner, "Signed in as <name> (<email>)" from the Access identity.

| Field | Type | Req | Notes |
|---|---|---|---|
| First name / Last name | text | ✔ | max 50 each |
| Membership Number | text, `inputmode=numeric`, maxlength 10 | ✔ | Exactly 10 digits. Small note: "Enter all 10 digits, including leading zeros (e.g., 0001234567)." |
| Address | street ✔, city ✔, state (default Virginia, US states + DC) ✔, ZIP ✔ | ✔ | |
| Personal Phone | tel, auto-formats `(804) 555-1234` | ✔ | |
| Personal Email | email | ✔ | |
| Name of Local Union | select (same list as vea-join) + "OTHER / NOT LISTED" → text | ✔ | Changing it resets worksite |
| Work Location/Worksite | select filtered by local + "OTHER / NOT LISTED" → text | ✔ | |
| Current job title | text | ✔ | max 100 |
| How long in current position | text | ✔ | max 50 |
| Type of issue | select (list below) + "Other" → required text | ✔ | |
| Date of Birth | `input type=date` | | Optional. PDF shows "Not provided" if blank. |
| Brief narrative of the problem | textarea, live word counter | ✔ | ≤ 300 words |
| Date of incident | `input type=date` | ✔ | Not in the future |
| What do you want VEA to do for you? | text (single line) | ✔ | Server cap 1,000 chars (no visible limit) |
| Documentation | file input, multiple; list with remove buttons; client-side checks; upload progress bar | | ≤ 5 files, 10 MB each, 25 MB total; docx, xlsx, csv, pdf, png, jpg, txt |
| Honeypot | hidden | | |

**Issue types:** Disability Retirement · Dismissal · Child Protective Services Allegations · Criminal Allegations · Reasonable Accommodations under the Americans with Disabilities Act · Salary/Pay · Collective Bargaining Agreement Issues · Working Conditions · Leave (including those related to the Family and Medical Leave Act) · Other

**Flow:** Form → Review (all answers + file list, Edit links) → Submit. Before submitting, `GET /legal/ping.php` (`redirect: 'manual'`). If the Access session has expired, show: "Your sign-in has expired. [Sign in again] (opens a new tab), then come back here and click Submit." No data is lost. After success: confirmation screen with the reference ID; state cleared.

## 11. Audit block and PDF

**Audit block (last PDF section):** Submission ID · Submitted (ET, ISO-8601) · Submitted by (name + email from Access JWT) · Client IP (real IP via nginx real-IP) · Approx. location (city, region, country from Cloudflare) · User agent · Form version · Data SHA-256 (canonical JSON of fields) · Per-file: name, size, type, SHA-256. (No signature.) Users on the VPN show the VPN's exit IP (expected).

**PDF:** Letter, portrait. Header: logo, title, submission ID. Header/footer on every page: privilege banner + "Page X of Y". Sections: Member Information · Employment · Issue (type, incident date, narrative, requested outcome) · Attached Documents (table) · Audit. Filename: `Legal-Request_<ID>_<Last>_<First>.pdf` (sanitized).

## 12. Email

### Staff email (to VEALegal)
- Subject: `Legal Assistance Request - [#<ID>] - <handle>`
- Body: privilege banner; submission date; submitted by; member name; local; "Full request attached (PDF) plus N supporting file(s)." **No issue type or narrative in the body.**
- Attachments: PDF + uploaded originals.

### Send method
Graph `sendMail` caps the whole request at about 4 MB, so:
- **Total attachments ≤ 3 MB** → `POST /users/no.reply/sendMail` with `saveToSentItems: false`.
- **Larger** → create a draft in no.reply (`Prefer: IdType="ImmutableId"`), add the PDF, attach each file with an **upload session**, `send`, then `permanentDelete` the same message ID from Sent Items. If the delete fails, log `sent_copy_cleanup_failed id=<ID>` and alert Paul.
- Permanently deleted items can still sit in no.reply's Recoverable Items for the retention period (indefinitely under a litigation hold). Confirm no.reply has no hold.

### Submitter confirmation (to the signed-in user)
- Subject: `Legal Assistance Request received - [#<ID>]`
- Body: "Your Legal Assistance Request for <member name> (<local>) was received on <date/time>. Reference #<ID>." No other data.
- Send order: staff email first (fail → error to user, nothing else sent); confirmation second (fail → still success, log it).

## 13. Shared data and assets

- **Source:** vea-join has no `locals.json`; its list of locals is the distinct `local` values of its `dues.json` (132 locals, 2024-25 files; vea-join's PLAN asks to confirm they are current). `bin/import-vea-join-data.php` reads vea-join's `app/data/source/dues.json` and `worksites.json` (read-only) and writes `app/data/source/locals.json` (names only, no dues figures) and `worksites.json`. Annual refresh: refresh vea-join's data, then run the import here.
- **`bin/build-data.php`** reads the two source files and writes identical `app/data/form-data.json` and `public/legal/data/form-data.json`. It trims, dedupes (with warnings, like vea-join's), errors on anything malformed (nothing written), and stores **"OTHER / NOT LISTED" in the data** as the last local and the last worksite of every local. A real source value equal to that label is an error. Shape:
  `{"version": "<first 16 hex of SHA-256>", "notListed": "OTHER / NOT LISTED", "locals": [{"name": "…", "worksites": ["…", "OTHER / NOT LISTED"]}, …, {"name": "OTHER / NOT LISTED", "worksites": ["OTHER / NOT LISTED"]}], "states": [...]}`. Issue types come from `content.json`, not the data.
- **`bin/build-assets.php`** copies an allowlist of files from `app/shared-assets/` into `public/<form>/assets/`, writes a manifest of `?v=<first 12 hex of SHA-256>` URLs, and fails if anything outside the allowlist would be published.
- **`bin/build-site.php --form=legal`** renders `public/legal/index.html` from `app/forms/legal/templates/index.html` (`{{asset:…}}`, `{{t:…}}`, `{{dataurl}}` placeholders, as vea-join's `build-site.php`). Unknown placeholders are errors.
- Generated files (`public/legal/index.html`, `public/legal/assets/`, `public/legal/data/`, `app/data/form-data.json`, `app/storage/fonts/`) are **not committed**; a fresh clone needs `composer build`.
- **`.gitignore`**: vea-join's rules (vendor, PHPUnit cache, `*.env`, logs, editor files, `.claude/settings.local.json`) plus the generated files above and `/scratch/`.

## 14. Open items (Paul)

- [x] ~~Whose signature~~: **closed 2026-11-23**, Legal dropped the signature.
- [ ] **Privilege banner wording** (Legal). Placeholder until then.
- [ ] **Submitter handle**: is every UD/Legal staff email `firstinitiallast@veanea.org`? If so, use the email local part; otherwise build it from the name claim.
- [ ] **Graph app `VEA Intake Mailer`** + RBAC (§8). Needed before Prompt 4.
- [ ] Confirm no.reply has no litigation hold (§12).
- [ ] Confirm the Cloudflare **visitor location headers** managed transform is on, and Bot Fight Mode isn't blocking uptime checks.
- [ ] Confirm DO uptime check targets the pdjoin hostname, not the IP.
- [ ] **~Nov 30 (no later than ~2027-01-19):** `sudo certbot delete --cert-name pdjoin.veanea.net`.
- [ ] **Mid-2029:** replace the origin-pull CA before it expires 2029-11-01 (§5 runbook).
- [ ] Later: make cf-ip-sync's header comment generic on DO1 and DO2; consider AOP and identical listen lines on DO1 (it has 4 "protocol options redefined" warnings).
- [ ] Legal review of the PDF layout using `bin/render-sample-pdf.php` output.

## 15. Build sequence and status

| Step | Content | Status |
|---|---|---|
| 0. Manual | DNS + proxy, Access + Entra IdP (Legal + host apps), Origin CA cert, Full (strict), AOP, internal DNS delegations | ✅ (Graph app pending, §8) |
| 1A | Read-only audit of DO2 | ✅ |
| 1B | Real IP + firewall: cf-ip-sync, pdjoin proxied, enforce on | ✅ 2026-11-22 |
| — | Repo root renamed to `/var/www/vea-intake` | ✅ 2026-11-23 |
| 1C | intake host: user, layout, ACLs, app.env skeleton, cache, log, fstab tmpfs, php-fpm drop-in, pool, AOP CA, nginx site + headers snippet | ✅ 2026-11-23 |
| 1C-fix | Correct tmpfs dependency check; catch-all listen lines → zero nginx warnings | ✅ 2026-11-23 |
| 1C2 | pdjoin: Origin CA cert + AOP + body buffer 1m + listen lines; rollback script | ✅ 2026-11-23 |
| 1D | `~/scripts/verify-do2.sh` (read-only, reusable) | Written; run with sudo after any change |
| 2 | Scaffold + shared core (from vea-join) + shared assets + data pipeline; git repo | In progress |
| — | Root script: drop `data:`/`blob:` from `img-src` in `vea-intake-headers.conf` | Pending |
| 3 | Front end: the form, cascades, word counter, uploads with progress, review, session-expiry handling | |
| 4 | Back end: TmpfsGuard, Access JWT, validation, upload checks, PDF, Graph send paths, confirmation | Needs §8 |
| 5 | Verification and go-live | |

Working conventions: Claude Code runs as `developer` (VS Code Remote-SSH), cannot sudo, never modifies `/var/www/vea-join` (read-only reference); Composer always under `umask 027`; every root change is a reviewed script in `~/scripts/` (idempotent, backups to `~/config-backups/<timestamp>/`, `nginx -t` with zero warnings before reload, reload never restart, explicit FAIL lines — no silent `set -e` exits; curl `-w` formats end in `\n`; PASS/FAIL summary) that Paul runs.

## 16. Go-live checklist

- [ ] `TEST_MODE=true` submissions received; PDF and attachments reviewed by Legal.
- [ ] 25 MB / 5-file submission succeeds; 6th file, 11 MB file, renamed .exe, macro .docm-as-.docx all rejected.
- [ ] Large-path send leaves nothing in no.reply Drafts or Sent Items.
- [ ] No-storage test: `/run/vea-intake` empty after submit; nothing new in `/var/lib/nginx/body`, `/tmp`, `/var`, `/home`.
- [ ] With the tmpfs unmounted (test only), submit.php returns 503 and pdjoin keeps working.
- [ ] Direct-to-origin request times out (firewall); local request without client cert refused (AOP); forged Host + no JWT → PHP 401.
- [ ] A user outside both groups is denied by Access; `/` and other paths require sign-in (host app).
- [ ] Expired-session flow tested (no data loss).
- [ ] Submission works **on and off the VPN**.
- [ ] Real client IP and location correct in the audit block.
- [ ] Headers (securityheaders.com A); `/app`, `/bin`, `/docs`, `/.git`, `/vendor` → 404 or sign-in.
- [ ] `verify-do2.sh` all PASS.
- [ ] `TEST_MODE=false`; one live submission; Legal confirms receipt.
- [ ] Calendar reminders: Graph secret, Cloudflare Access SSO secret, DO2 cf-ip-sync token, origin-pull CA (2029), certbot delete (Nov 30).
