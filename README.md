# Password Manager

A self-hosted, single-user password vault written in native PHP — no framework,
no build step. Credentials are stored in a local SQLite database and protected
with envelope encryption (AES-256-GCM + Argon2id), separate from the master
login that simply gates access to the web UI.

For a deep technical breakdown of the architecture, data model, and
cryptography, see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). For the
vulnerability-reporting process, see [`SECURITY.md`](SECURITY.md).

## Features

- Encrypted vault for usernames and passwords (AES-256-GCM), with titles, URLs,
  and hints stored in plain text for fast browsing.
- Two independent credentials: a **master login** (gates the UI) and a
  **vault password** (decrypts entries) — compromising one does not expose the
  other.
- Argon2id key derivation (via libsodium) and per-field random nonces — no key
  or nonce reuse.
- Automatic vault lock on idle, plus a separate session timeout.
- Failed-login lockout with a cooldown period, tracked per credential and
  fully audited.
- CSV import with validation, duplicate detection, and a confirmation preview
  step before anything is written to the vault.
- Full access/audit log with filtering and pagination.
- Light/dark theme, responsive layout (Bootstrap 5 + Font Awesome).
- Standalone CLI emergency-recovery tool, independent of the web app.

## Requirements

- PHP 8.1 or newer, with `strict_types` support.
- PHP extensions: `openssl`, `sodium`, `sqlite3`, `pdo_sqlite`, `session`.
- A web server (Apache/Nginx) capable of serving PHP, with `.htaccess`
  support honored if using Apache (the bundled `db/.htaccess` blocks direct
  HTTP access to the database file — see **Deployment notes** below if you're
  on Nginx or anything else that doesn't read `.htaccess`).
- HTTPS in any environment reachable over a network. Session cookies are
  issued with the `secure` flag, so login will not work correctly over plain
  HTTP on a non-`localhost` origin.

## Setup

1. **Deploy the files** to your web server, with this project's root
   (the folder containing `index.php`) as the document root, or as a
   subdirectory of one.
2. **Make sure `db/` and `logs/` are writable** by the web server process —
   both are created automatically on first use, but the parent directory
   permissions must allow it.
3. **Open the app in a browser.** With no `db/vault.db` present yet, you will
   be redirected automatically to `setup.php`.
4. **Complete the setup form:**
   - A **master username/password** (minimum 12 characters) — this only logs
     you into the UI, it does not decrypt anything.
   - A **vault password** (minimum 8 characters) — this is the credential
     that derives the encryption key for your vault. Choose it carefully and
     store it in a password manager of its own, or memorize it.
5. **Back up the Data Encryption Key (DEK)** shown immediately after setup
   completes. It is displayed **exactly once**, in both Base64 and hex.
   Copy it to a secure offline location (e.g. a different password manager,
   or printed and stored physically). If you lose both the vault password
   *and* this backup, your vault **cannot** be recovered under any
   circumstances — there is no master key escrow.

### ⚠️ Delete `setup.php` immediately after setup

`setup.php` only guards itself by checking whether `db/vault.db` already
exists — it is still reachable over HTTP otherwise. Once setup is complete:

```
rm setup.php
```

Leaving it in place in a production deployment is a security risk: at best
it's dead weight, at worst a bug or a restored/deleted database could let it
re-run and silently reset your vault configuration. **Do not deploy or leave
`setup.php` on any server you don't fully control the access to.**

### ⚠️ Delete `recover.php` in production

`recover.php` is a **standalone CLI tool**, intended to be run with `php
recover.php` from a trusted local machine — never through a web server. It
ships in this repository purely as an emergency fallback (e.g. the web app
itself becomes unusable, but you still have your vault password or DEK
backup and need to get your credentials out).

It is **not designed to be web-accessible** and contains no safeguards
against being invoked over HTTP if your server is misconfigured to execute
it as a request handler. Treat it as a break-glass tool, not part of the
running application:

- Keep a copy somewhere **off** the production server (alongside your DEK
  backup), and
- **delete it from the production deployment**:

```
rm recover.php
```

If you need to recover a vault later, copy it back onto a machine with PHP
installed (no web server needed) and run it against a copy of `vault.db`.

## Project Structure

```
.
├── config.php          Constants, paths, autoloader
├── index.php             Front controller / router
├── setup.php               First-run setup wizard — delete after use (see above)
├── recover.php               CLI emergency recovery tool — delete from prod (see above)
├── db/                          SQLite database lives here (gitignored, created at setup)
├── inc/                          Core classes: Auth, Crypto, Database, Vault, Logger
├── ui/                            Views (dashboard, vault, import, access log, layout)
├── docs/                            Architecture & cryptography documentation
├── tests/                            Automated tests (placeholder)
├── CHANGELOG.md
├── LICENSE
└── SECURITY.md
```

## Usage

- **Dashboard** — vault status, quick unlock/lock, recent activity.
- **Vault** — browse, add, edit, and delete entries; copy username/password to
  clipboard; reveal hints on hold; generate strong passwords.
- **Import** — upload a CSV (`Title,URL,Username,Password,Hint` header
  required) to bulk-add entries, with a validation/preview step before
  anything is committed.
- **History** — full audit log of login/unlock attempts, filterable by
  target and event, with live lockout status.

## Deployment notes

- The included `db/.htaccess` (`Deny from all`) only takes effect on Apache
  with `AllowOverride` enabled for that directory. If you're on Nginx,
  IIS, or Apache with overrides disabled, add an equivalent rule at the
  server-config level to block any direct request to `db/` and `logs/` —
  do not rely on the `.htaccess` file alone.
- Nothing in this project reads environment variables or an `.env` file —
  all configuration is in `config.php`. Review the constants there
  (session timeout, idle-lock timeout, lockout thresholds) before deploying.
- This is a single-user, single-vault application by design. It does not
  support multiple accounts or shared/multi-user vaults.

## License

See [`LICENSE`](LICENSE).
