# Eshcol Recruit

Eshcol Global's recruitment tracking app. Plain PHP 8.5 + MariaDB 11.4, no framework,
built to run on the existing Namecheap cPanel hosting. Design: `../../Eshcol_ATS_Design_FreeFirst_04Nov2026.md`.

## Run it locally

- Start: `ATS\tools\start-local.bat` (starts the test database and web server, opens http://127.0.0.1:8090)
- Stop: `ATS\tools\stop-local.bat`
- Sign-in for the local test account: `dev_login` in `ATS\config.local.php`. First sign-in asks you to set up an authenticator app.

## Layout

| Path | What |
|---|---|
| `public/` | The only web-reachable folder: `index.php` (front door) and `assets/` |
| `src/` | App code. `Services/` holds the free engine: `Skills`, `TextExtractor`, `FieldExtractor`, `Scorer`, `CandidateImporter` |
| `views/` | Page templates. Every printed value goes through `e()` |
| `routes.php` | Every URL. All POSTs are CSRF-checked; everything except sign-in needs a signed-in user |
| `migrations/` | Database schema and seed data, applied by `bin/migrate.php` |
| `bin/` | `migrate.php`, `create-user.php` |
| `tests/` | `run.php` (engine tests), `make-fixtures.php` (fictional test CVs), `http-upload.php` (end-to-end upload over HTTP) |

Private, never deployed: `ATS/config.local.php` (credentials), `ATS/storage/` (CV files, logs), `ATS/tools/`.

## Rules this code keeps

- Every SQL query is a prepared statement.
- CVs live outside the web root and are only served to signed-in users, with every download audited.
- Nothing is rejected automatically; scores are advisory and can be overridden with a reason.
- Details a person confirmed are never overwritten by a later CV.
- Free first: no paid API is called anywhere in phase 1.

## Tests

```
..\tools\php-8.5\php.exe tests\run.php
```
