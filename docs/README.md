# vea-intake

VEA intake forms at `https://intake.veanea.net`. The first form is the **Legal
Assistance Request** at `/legal` (UD Intake later at `/ud`). The source of truth
for every decision is [`docs/PLAN.md`](PLAN.md); the server runbook is
[`docs/RUNBOOK.md`](RUNBOOK.md).

The reference implementation is **vea-join** (`/var/www/vea-join`,
pdjoin.veanea.net). Core classes and patterns are copied from it and generalized.
vea-join is live: read it, never modify it.

## Layout

```
public/legal/            nginx root for the form (www-data can read public/ only)
  index.html             GENERATED (bin/build-site.php) — do not edit
  assets/                GENERATED (bin/build-assets.php) — do not edit
  data/form-data.json    GENERATED (bin/build-data.php)
app/
  core/src/              shared PHP, namespace VeaIntake\Core\
  forms/legal/           the Legal form, namespace VeaIntake\Forms\Legal\
    content.json         texts, issue types, _meta.formVersion (the form version)
    templates/           index.html (page template), later pdf.php and the emails
  shared-assets/         CSS, logo, icons: the single source for every form
  data/source/           locals.json, worksites.json (from vea-join, see below)
  data/form-data.json    GENERATED (server copy, read by PHP)
  data/assets-legal.json GENERATED (asset URLs with ?v= hashes, read by build-site)
  storage/fonts/         GENERATED dompdf font cache
bin/                     build, import and check scripts (below)
tests/                   PHPUnit 12
docs/                    PLAN.md, README.md, RUNBOOK.md
```

Generated files are not committed (`.gitignore`). A fresh clone serves nothing
until it is built.

## Permissions (read this before running anything)

The tree is owned by `developer`, group `vea-intake` (the PHP-FPM pool reads the
code through the group). Directories are setgid 2750 and carry a default ACL, so
new files come out `developer:vea-intake 0640` and new directories `2750`
without chmod. www-data reaches `public/` through ACLs only. `developer` is **not**
in the `vea-intake` group, on purpose: it cannot read `/etc/vea-intake/app.env`,
and it cannot `chgrp` anything to `vea-intake`.

- **Never chmod, chown or setfacl** in the tree.
- **Run Composer under `umask 027`**: `umask 027 && composer install`.
- After every `composer install`/`update`, two scripts run automatically:
  - `bin/fix-vendor-perms.php`: composer's `unzip` chmods directories, which
    clears their setgid bit (developer is not in the group), so everything below
    gets group `developer`. The script re-creates `vendor/` by copying it inside
    the repo, so the copy inherits the right group and modes, then swaps it in.
    (vea-join fixes this with `chgrp` in `bin/fix-perms.sh`, which works there
    only because developer is in the vea-join group.)
  - `bin/check-perms.php` (read-only): reports any path with access for "other",
    group write, a group other than `vea-intake`, or a non-setgid directory.
    `~/scripts/verify-do2.sh` makes the same check as root.

## First build (fresh clone)

```bash
cd /var/www/vea-intake
umask 027 && composer install          # dev dependencies too (PHPUnit), like vea-join
composer build                         # build-data, build-assets, build-site, warm-fonts, check-perms
composer test                          # PHPUnit
```

## Data: locals and worksites

vea-join has no `locals.json`: its list of locals is the distinct `local` values
of its `dues.json`. `bin/import-vea-join-data.php` reads vea-join's
`app/data/source/dues.json` and `worksites.json` (read-only) and writes
`app/data/source/locals.json` (names only, no dues figures) and
`app/data/source/worksites.json` (byte for byte). It prints the SHA-256 of
both vea-join files.

Annual refresh: refresh vea-join's data first, then

```bash
composer import-data && composer build && composer test
git add app/data/source && git commit -m "Data refresh <year>"
```

`bin/build-data.php` trims, dedupes (with warnings, as vea-join's), fails on
anything malformed (nothing written), and stores **"OTHER / NOT LISTED"** in the
data: last worksite of every local, and last local. The current data is vea-join's
2024-25 files (132 locals; vea-join's plan asks to confirm they are current).

## Assets and the page

- `bin/build-assets.php --form=legal` publishes an **allowlist** of files from
  `app/shared-assets/` (and `app/forms/legal/assets/`) into
  `public/legal/assets/`, rebuilt from scratch each time, and writes
  `app/data/assets-legal.json` with `?v=<first 12 hex of SHA-256>` URLs. A file
  in a source folder that is not on the allowlist (dotfiles too) is an error: add
  new files to the allowlist in the script deliberately.
- `bin/build-site.php --form=legal` renders `public/legal/index.html` from
  `app/forms/legal/templates/index.html`: `{{t:key.path}}` (content.json,
  escaped), `{{asset:path}}`, `{{dataurl}}`. Unknown placeholders are errors. It
  never reads `app.env`.
- The **form version** is `app/forms/legal/content.json` → `_meta.formVersion`.
  Bump it when fields or validation rules change. It is not in `app.env`.

## Scripts

| Script | What it does |
|---|---|
| `bin/import-vea-join-data.php` | vea-join source → `app/data/source/` (read-only on vea-join) |
| `bin/build-data.php` | `form-data.json` (both copies) |
| `bin/build-assets.php --form=legal` | allowlisted assets → `public/legal/assets/` + manifest |
| `bin/build-site.php --form=legal` | `public/legal/index.html` |
| `bin/warm-fonts.php` | dompdf font cache in `app/storage/fonts/` (the pool cannot write `app/`) |
| `bin/check-perms.php` | read-only permission check |
| `bin/fix-vendor-perms.php` | re-create `vendor/` with the right group (runs after composer) |

All accept `--root=DIR` (used by the tests) and exit non-zero on any error.

## Tests

`composer test` (or `vendor/bin/phpunit`). The build-script tests run the real
scripts against throw-away trees in the system temp dir. Run `composer build`
first: some tests check the built `public/` files.

## Git

- Remote: `git@github-vea-intake:pkirillvea/vea-intake.git` (private), through
  the SSH alias `github-vea-intake` in `~/.ssh/config` and the deploy key
  `~/.ssh/vea-intake_deploy` (same pattern as vea-join).
- Commit identity: the same as vea-join's.

## Third-party components

| Component | Where | License |
|---|---|---|
| dompdf/dompdf ^3.1 (+ php-font-lib, php-svg-lib, masterminds/html5, sabberworm/php-css-parser) | server (`vendor/`) | LGPL-2.1, LGPL-2.1/3.0, MIT |
| firebase/php-jwt ^7.2 | server (`vendor/`) | BSD-3-Clause |
| phpunit/phpunit ^12 | dev only | BSD-3-Clause |

Nothing third-party is shipped to browsers (no signature_pad, no web fonts, no
Turnstile).
