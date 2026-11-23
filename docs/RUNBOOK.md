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
  stops PHP-FPM (pdjoin shares it). submit.php refuses with 503 when the tmpfs
  is not mounted (TmpfsGuard, Prompt 4).
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

- **`sudo bash ~/scripts/phase-csp.sh`** (written in Prompt 3): drop `data:` and `blob:` from `img-src` in
  `/etc/nginx/snippets/vea-intake-headers.conf` (they were only for the signature
  pad, which Legal dropped). Exact-line edit with pinned hashes, `nginx -t` with zero
  warnings, reload.
- Optional: delete the ignored `FORM_VERSION_LEGAL=` line from
  `/etc/vea-intake/app.env` with `sudoedit` (the form version lives in
  `app/forms/legal/content.json`).

## Repo operations

- Build after pulling changes: `cd /var/www/vea-intake && umask 027 && composer install && composer build && composer test`.
- Data refresh (yearly): see README.md, "Data: locals and worksites".
- Permissions problem in the tree: `php bin/check-perms.php` lists it; for
  `vendor/`, `php bin/fix-vendor-perms.php`. Anything else: re-create the file as
  developer (it inherits the right group and mode).
