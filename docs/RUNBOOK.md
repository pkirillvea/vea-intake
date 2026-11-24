# vea-intake runbook (DO2)

Server facts and procedures for intake.veanea.net (and the parts of DO2 it shares
with pdjoin.veanea.net). Seeded from `docs/PLAN.md` section 5. PLAN.md stays the
source of truth; keep both in sync.

Claude Code runs as `developer` and cannot sudo. Every root change is a reviewed
script in `~/scripts/` (idempotent, backups to `~/config-backups/<timestamp>/`,
`nginx -t` with zero warnings before any reload, reload never restart, explicit
FAIL lines, PASS/FAIL summary) that Paul runs.

## Health check

```bash
sudo bash ~/scripts/verify-do2.sh
```

Read-only. Run after any change and after Ubuntu updates. It checks nginx (zero
warnings, listen lines), both PHP-FPM pools, the tmpfs and its systemd wiring,
both sites' certificates and the origin-pull CA, Authenticated Origin Pulls, both
sites through Cloudflare, real client IP, cf-ip-sync, request-body spooling,
the vea-intake permission matrix, the pdjoin Let's Encrypt lineage, core dumps and
swap. Exit 0 = all PASS.

## Rules

- `nginx -t` must show **zero warnings** after any change. All 443 listen lines
  are `listen 443 ssl http2;` / `listen [::]:443 ssl http2;` (`00-catchall` adds
  `default_server`); mismatches cause "protocol options redefined".
- Never `umount /run/vea-intake` by hand; reboot or
  `sudo systemctl restart 'run-vea\x2dintake.mount'` instead. The fstab line has
  `nofail`; PHP-FPM only `Wants=`/`After=` the mount, so a missing tmpfs never
  stops PHP-FPM (pdjoin shares it). submit.php and ping.php refuse with 503 when
  the tmpfs is not mounted (TmpfsGuard).
- In the repo: never chmod/chown/setfacl; Composer only under `umask 027`
  (see README.md).
- `/var/www/vea-join` is live: read it, never modify it.

## cf-ip-sync (real client IP + firewall)

- `/usr/local/sbin/cf-ip-sync`, daily timer (`cf-ip-sync.timer`, up to 1 h random
  delay). Writes `/etc/nginx/conf.d/cloudflare-realip.conf` and keeps
  `do2-firewall` 80/443 sources = Cloudflare ranges (`FIREWALL_MODE=enforce`).
- Config `/etc/cf-ip-sync.env` (root 0600). DO token scopes: firewall read +
  update **and droplet read** (without it the attachment guard refuses).
- Healthchecks.io check `do2-cf-ip-sync` (daily, 2 h grace).
- Logs: `sudo journalctl -u cf-ip-sync.service`.
- **Rollback:** set `FIREWALL_MODE=report` in `/etc/cf-ip-sync.env`, then reset
  80/443 to All IPv4/IPv6 in the DO panel.

## pdjoin (shares DO2)

- Origin CA cert + Authenticated Origin Pulls since 2026-11-23,
  `client_body_buffer_size 1m` on `/submit.php`.
- **Rollback to Let's Encrypt** (only while the LE lineage exists):
  `sudo bash ~/scripts/phase-c2-rollback.sh`.
- **Delete the LE lineage ~2026-11-30, no later than ~2027-01-19:**
  `sudo certbot delete --cert-name pdjoin.veanea.net` (a real renewal would make
  certbot rewrite pdjoin's server block back to the LE cert).
- DO uptime check must target `https://pdjoin.veanea.net`, not the droplet IP.

## Certificates and Cloudflare

- Origin CA cert `*.veanea.net` + `veanea.net`, `/etc/ssl/cloudflare/veanea.net-origin.pem`
  / `.key`, expires **2041-11-19**.
- Origin-pull CA `/etc/ssl/cloudflare/origin-pull-ca.pem`, pinned SHA-256
  `c14fed0c…d7b843`, expires **2029-11-01**. Before then: download Cloudflare's
  replacement, install it, update the pinned hash in `verify-do2.sh`, or both
  sites will refuse Cloudflare. `verify-do2.sh` FAILs from 180 days before.
- Access: `VEA Intake - Legal` (path `legal`, the two Entra groups) and
  `VEA Intake (host)` (whole host, Paul/IT only).

## Pending root changes

- **`sudo bash ~/scripts/phase-p4.sh`** (written in Prompt 4): nginx
  `fastcgi_read_timeout 90s` on `= /legal/submit.php`; vea-intake pool
  `request_terminate_timeout = 95s`, `max_file_uploads` 5 → 6, `open_basedir` +
  `:/proc/self/mountinfo` (without it TmpfsGuard cannot read mountinfo and every
  request is 503). Both files pinned before/after by SHA-256, `nginx -t` with zero
  warnings and `php-fpm8.3 -t` before reloading; reloads only. Then `verify-do2.sh`.
- Optional: delete the ignored lines from `/etc/vea-intake/app.env` with `sudoedit`:
  `FORM_VERSION_LEGAL=` (form version is in `content.json`) and `UPLOAD_MAX_FILES`,
  `UPLOAD_MAX_FILE_MB`, `UPLOAD_MAX_TOTAL_MB`, `NARRATIVE_MAX_WORDS` (limits are in
  `content.json` → `limits`, read by browser and server). `Config` ignores them.
- Done: `phase-csp.sh` (CSP `img-src 'self'`, 2026-11-23).

## Submissions (Prompt 4)

- Endpoints: `public/legal/ping.php` → `app/forms/legal/ping.php` (`LegalPing`),
  `public/legal/submit.php` → `app/forms/legal/handler.php` (`LegalSubmitHandler`).
- **Log** (`/var/log/php-fpm-vea-intake.log`): one line per event, `vea-intake
  event=<name>` plus only `form`, `id`, `status`, `stage`, `reason`, `class`,
  `fields` (payload key names). Never values, names, emails or file names.
  `sudo grep 'vea-intake event=' /var/log/php-fpm-vea-intake.log | tail -50`
- **`sent_copy_cleanup_failed id=<ID>`**: the large-path copy is still in no.reply's
  Sent Items. Find the message with `[#<ID>]` in the subject in OWA (as no.reply,
  or via an admin) and delete it permanently (Shift+Del, then purge from Recoverable
  Items if needed). **`draft_cleanup_failed id=<ID>`**: same, in Drafts.
- **`auth_failed reason=…`**: a token was rejected (401). `jwks_unavailable`:
  Cloudflare's key endpoint could not be reached and the cache is stale → 503 until
  it can. Cache: `/var/cache/vea-intake/access-jwks.json` (public keys only, safe
  to delete; it is refetched).
- **`tmpfs_not_ready`**: `/run/vea-intake` is not a mounted tmpfs (or unreadable
  mountinfo) → 503. `findmnt /run/vea-intake`; `sudo systemctl restart 'run-vea\x2dintake.mount'`.
- **TEST_MODE**: blank or `true` = test (all mail to `TEST_MAIL_TO`, `[TEST] `
  subject). Go live: set `TEST_MODE=false` with `sudoedit /etc/vea-intake/app.env`
  (read per request; no reload needed).
- Sample PDF for layout review: `php bin/render-sample-pdf.php` → `scratch/` (fake data).

## Repo operations

- Build after pulling changes: `cd /var/www/vea-intake && umask 027 && composer install && composer build && composer test`.
- Data refresh (yearly): see README.md, "Data: locals and worksites".
- Permissions problem in the tree: `php bin/check-perms.php` lists it; for
  `vendor/`, `php bin/fix-vendor-perms.php`. Anything else: re-create the file as
  developer (it inherits the right group and mode).
