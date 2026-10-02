# Password Manager — Developer Documentation

A single-user, self-hosted password vault. Native PHP (no framework), SQLite storage,
envelope encryption (KEK/DEK) with AES-256-GCM, and an Argon2id-based KDF via libsodium.

This document describes how the application actually works at the code level: request
flow, data model, cryptography, and the behavior of each feature. It is written for a
developer who needs to maintain, extend, or audit the code.

---

## 1. Tech Stack

| Layer            | Technology                                                    |
|-------------------|----------------------------------------------------------------|
| Language          | PHP 8+ (`declare(strict_types=1)` throughout)                  |
| Storage           | SQLite 3 via PDO, WAL journal mode, foreign keys enforced       |
| Crypto primitives | `ext-sodium` (Argon2id KDF), `ext-openssl` (AES-256-GCM AEAD)   |
| Password hashing  | PHP native `password_hash()` / `password_verify()` (Argon2id)  |
| Frontend          | Server-rendered PHP views, Bootstrap 5.3.3, Font Awesome 6.5.2  |
| JS                | Vanilla JS + jQuery (bundled with Bootstrap) — no build step    |
| Autoloading       | Custom `spl_autoload_register` over `inc/` (PSR-0-ish, no Composer) |

No Composer, no framework, no ORM. Every class in `inc/` is a plain static-method
utility class (`Auth`, `Crypto`, `Database`, `Vault`, `Logger`) — there is no dependency
injection; global state lives in `$_SESSION` and the `Database` singleton.

---

## 2. Project Layout

```
manager/
├── config.php              Constants, autoloader, paths
├── index.php                 Front controller / router (all app actions)
├── setup.php                 One-time first-run setup wizard
├── recover.php                Standalone CLI emergency decryption tool
├── db/
│   ├── .htaccess              "Deny from all" — blocks direct HTTP access to the DB file
│   └── vault.db                SQLite database (created by setup.php; gitignored)
├── inc/
│   ├── Auth.php                Session, master login, vault unlock, lockout logic
│   ├── Crypto.php              All cryptographic primitives (hashing, KDF, AEAD)
│   ├── Database.php             PDO/SQLite singleton wrapper
│   ├── Vault.php                 Vault entry CRUD + per-row encrypt/decrypt
│   └── Logger.php                 Static file logger (app.log), unrelated to access_log table
└── ui/
    ├── layout.php                Shared chrome: navbar, modals, toasts, JS (session timer, idle lock)
    ├── login.php                  Master login form
    ├── dashboard.php               Stat cards + vault unlock/lock widget + recent activity
    ├── vault_list.php                Entry grid (cards), copy/reveal JS
    ├── vault_entry.php                Add/Edit entry form, password generator + strength meter
    ├── import.php                      CSV import (2-step: validate/preview → confirm)
    ├── import_result.php                Import summary screen
    ├── access_log.php                    Full audit log, filters, pagination, lockout status
    └── assets/style.css                   All custom CSS (theme variables, components)
```

There is no `logs/` directory checked in — `Logger` creates `inc/../logs/app.log` lazily
on first write.

---

## 3. Request Lifecycle

`index.php` is the single entry point for the authenticated app (everything except
first-run setup and the offline recovery tool). It behaves like a minimal action
router:

1. `config.php` is required → defines constants and registers the autoloader.
2. If `db/vault.db` doesn't exist yet → redirect to `setup.php` (forces first-run setup).
3. `Auth::startSession()` — configures secure session cookie params (`secure`, `httponly`,
   `samesite=Strict`) and starts the PHP session.
4. `Auth::checkSessionTimeout()` — if authenticated and idle longer than `SESSION_TIMEOUT`,
   destroys the session and redirects to the login page with `?timeout=1`.
5. Dispatches on `$_POST['action']` / `$_GET['action']`:
   - `login`, `unlock_vault`, `lock_vault`, `logout` — auth state transitions.
   - `add_entry`, `update_entry`, `delete_entry` — vault CRUD (guarded by
     `Auth::isAuthenticated() && Auth::isVaultUnlocked()`).
   - `vault_unlock_ajax` — JSON endpoint used by the idle-lock overlay (see §7).
6. If none of the POST actions short-circuited the request (no `exit`), falls through to
   rendering: `ui/login.php` if not authenticated, otherwise `ui/layout.php`, which itself
   `require`s the right page partial based on `$action` (`access_log`, `import`,
   `vault` + `edit`/`new`, or default `dashboard`).

There is no templating engine — view files are plain PHP files with inline HTML,
`require`d directly with the calling scope's local variables in context (e.g.
`$editId`, `$error`, `$saved` are set in `layout.php` and consumed by the included
partials).

**Note:** route handlers do not use CSRF tokens. State-changing actions
(`add_entry`, `delete_entry`, `lock_vault`, etc.) rely solely on session cookies with
`SameSite=Strict`, which mitigates (but does not eliminate) CSRF risk for a
single-user local tool.

---

## 4. Data Model

SQLite schema, created once by `setup.php`:

### `config` (single row, `id = 1`)
| Column                  | Purpose                                                  |
|--------------------------|-----------------------------------------------------------|
| `master_username`        | Plaintext login username (UI access, not vault access)   |
| `master_password_hash`   | Argon2id hash of the master password                     |
| `master_argon2id_salt`   | Generated but **unused** — master login never derives a key |
| `password_hash`           | Argon2id hash of the **vault** password                  |
| `argon2id_salt`            | Salt used to derive the KEK from the vault password        |
| `wrapped_dek`                | The DEK, encrypted (AES-256-GCM) under the KEK            |
| `wrapped_dek_nonce`           | GCM nonce used to wrap the DEK                              |
| `wrapped_dek_tag`              | GCM authentication tag for the wrapped DEK                   |

### `vault` (one row per credential)
| Column                                              | Purpose                              |
|------------------------------------------------------|-----------------------------------------|
| `title`, `url`, `hint`                                 | Stored in **plaintext**                 |
| `username_encrypted/_nonce/_tag`                         | AES-256-GCM ciphertext + nonce + tag     |
| `password_encrypted/_nonce/_tag`                           | AES-256-GCM ciphertext + nonce + tag       |
| `created_at`, `updated_at`                                   | Timestamps                                  |

### `access_log` (append-only audit trail)
Every login/unlock attempt (success, failed, or locked) against either `target`
(`master` or `vault`), with `ip_address`, the running `failed_attempts` count at that
moment, and `locked_until` if the event caused a lockout. Powers both the dashboard's
"Recent Activity" widget and the full `access_log.php` page (filterable by target/event,
paginated 25/page).

### `lockout` (one row per target: `master`, `vault`)
Tracks `failed_attempts` and `locked_until` per target independently — a lockout on
`master` does not affect `vault`, and vice versa.

**Important design point:** `title`, `url`, and `hint` are **not** encrypted. Only
`username` and `password` are. This is deliberate (titles need to be sortable/listable
without unlocking, and the UI needs to render entry cards and the "duplicate title"
import check against plaintext titles) but it means the hint field is explicitly
documented in the UI as "stored in plaintext" — don't put secrets in it.

---

## 5. Cryptography

All crypto lives in `inc/Crypto.php`. Constants are defined in `config.php`.

### 5.1 Two independent credential domains

The app deliberately separates **authentication** (can you see the UI at all) from
**vault access** (can you decrypt stored credentials):

- **Master username/password** — gates the web UI. Verified with
  `password_verify()` against an Argon2id hash. **Does not derive any key** — knowing
  the master password alone cannot decrypt anything. (`master_argon2id_salt` is
  generated at setup time but is dead weight — no code path consumes it.)
- **Vault password** — a second, independent secret. Verified the same way
  (`password_hash`/`password_verify`), but **also** feeds a KDF to derive the
  Key Encryption Key (KEK) that unwraps the Data Encryption Key (DEK).

This means compromising the master login (e.g. via the session cookie) does not expose
vault contents unless the vault password is also known/unlocked in that session.

### 5.2 Key hierarchy (envelope encryption)

```
vault password ──(Argon2id via libsodium)──> KEK (32 bytes)
                                                │
                                   AES-256-GCM wrap/unwrap
                                                │
                                                ▼
                                   DEK (32 bytes, random)
                                                │
                                AES-256-GCM encrypt/decrypt (per field)
                                                │
                                                ▼
                                username / password ciphertext
```

- **DEK (Data Encryption Key)** — a random 32-byte key generated once at setup
  (`Crypto::generateDEK()` → `random_bytes(32)`). This is the key that actually
  encrypts every `username`/`password` field in the `vault` table.
- **KEK (Key Encryption Key)** — never stored. Re-derived on every vault unlock from
  the vault password + its stored salt via `sodium_crypto_pwhash` (see §5.3). Used only
  to wrap/unwrap the DEK.
- **Wrapped DEK** — the DEK encrypted under the KEK (AES-256-GCM), stored in `config`
  as `wrapped_dek` + `wrapped_dek_nonce` + `wrapped_dek_tag`.

The benefit of this envelope scheme: changing the vault password only requires
re-wrapping the DEK (derive new KEK, re-encrypt the same DEK) — it does **not** require
re-encrypting every vault entry. (Note: no "change vault password" UI flow currently
exists in `index.php`/`ui/`; this would need to be added by re-running the wrap step
against the existing DEK.)

### 5.3 Key derivation — Argon2id via libsodium

`Crypto::deriveKEK(string $password, string $salt): string`:

```php
sodium_crypto_pwhash(
    32,                                   // output length (AES-256 key)
    $password,
    $rawSalt,                             // 16 bytes (SODIUM_CRYPTO_PWHASH_SALTBYTES)
    ARGON2_TIME_COST,                     // opslimit = 4
    ARGON2_MEMORY_COST * 1024,            // memlimit = 65536 KiB * 1024 = 64 MiB
    SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
);
```

- The salt is generated as 32 random bytes, base64-encoded, and stored in
  `argon2id_salt`. Since libsodium's `pwhash` requires exactly
  `SODIUM_CRYPTO_PWHASH_SALTBYTES` (16) bytes, `deriveKEK` normalizes any salt to 16
  bytes by SHA-256-hashing and truncating it (`substr(hash('sha256', $rawSalt, true), 0, 16)`
  when the decoded salt is too short, or a straight truncation when it's long enough —
  in practice it's always truncation since the stored salt is 32 raw bytes).
- **This is Argon2id, not PBKDF2**, despite `config.php` defining
  `PBKDF2_ALGO` / `PBKDF2_ITERATIONS` constants under a "PBKDF2-SHA256" comment block.
  Those constants are **dead config** — grep confirms nothing in `inc/` or `ui/`
  references `PBKDF2_ALGO` or `PBKDF2_ITERATIONS`. Key derivation exclusively uses
  `sodium_crypto_pwhash` (Argon2id). Treat the PBKDF2 constants as leftover/aspirational
  and remove or wire them up if you touch this area.
- Cost parameters are shared with the Argon2id *password hashing* parameters
  (`ARGON2_MEMORY_COST` = 64 MiB, `ARGON2_TIME_COST` = 4 passes, plus
  `ARGON2_THREADS` = 2 — though threads only applies to `password_hash()`, not
  `sodium_crypto_pwhash`, which is single-threaded by design).

### 5.4 Data encryption — AES-256-GCM

Every encrypted field (DEK-wrap, username, password) uses the same pattern in
`Crypto::encrypt()` / `Crypto::decrypt()`:

- Cipher: `aes-256-gcm` (authenticated encryption — detects tampering/corruption,
  not just confidentiality).
- Nonce/IV: 12 bytes (`AES_IV_SIZE`), freshly random (`random_bytes`) **per encryption
  call** — i.e. editing an entry re-encrypts username/password with a brand-new nonce,
  it does not reuse the old one. This is correct GCM usage (nonce reuse under the same
  key is catastrophic for GCM).
- Tag: 16 bytes (`AES_TAG_SIZE`), appended/verified via `openssl_encrypt`/`decrypt`'s
  `OPENSSL_RAW_DATA` + tag-by-reference API.
- No AAD (additional authenticated data) is used — each ciphertext/nonce/tag triple
  authenticates only its own field, with no binding to the row ID or other context.
  (A tampering attacker who can also write directly to the SQLite file could, e.g.,
  swap two entries' ciphertext/nonce/tag triples without detection, since nothing binds
  a ciphertext to its row. Out of scope for the current threat model — direct DB file
  access already implies a compromised host — but worth knowing if the threat model
  changes.)
- Encoding: ciphertext, nonce, and tag are each independently base64-encoded for
  storage/transport as `TEXT` columns.

### 5.5 Where the DEK lives at runtime

On successful vault unlock (`Auth::attemptVaultUnlock`), the raw DEK is base64-encoded
and stored in `$_SESSION['dek']`. This means:

- The DEK sits in the PHP session file (server-side, under whatever `session.save_path`
  is configured) for as long as the vault is unlocked.
- `Auth::lockVault()` (manual "Lock Vault" button, or the idle-timeout overlay)
  simply `unset($_SESSION['dek'])` — nothing is zeroed in memory, there is no wiping
  of the session file's prior contents, and PHP's GC will reclaim the string at its own
  pace. This is a standard PHP limitation (no secure-memory primitives without extra
  extensions) — acceptable for a personal single-user tool, worth flagging for anything
  more sensitive.
- All vault reads (`Vault::listEntries`, `Vault::getEntry`) and writes
  (`Vault::addEntry`, `Vault::updateEntry`) pull the DEK via `Auth::getDEK()`, which
  throws if the vault isn't unlocked — so the `vault` DB table's ciphertext is never
  decrypted without an unlocked session.

---

## 6. Authentication & Session Model

### 6.1 Master login (`Auth::attemptMasterLogin`)

- Looks up the single `config` row, compares `username` with **exact, case-sensitive**
  string equality first.
- Only calls `Crypto::verifyPassword()` (i.e. `password_verify`) if the username
  matched — this avoids paying the Argon2id verification cost for a wrong username,
  and the UI always shows a generic "Invalid credentials" message regardless of which
  field was wrong, to avoid username enumeration via error-message difference. (Timing
  differences between "username check failed instantly" vs. "password check ran
  Argon2id" are a residual side channel, acceptable for this threat model.)
- On success: sets `$_SESSION['authenticated'] = true` and `last_activity = time()`.

### 6.2 Vault unlock (`Auth::attemptVaultUnlock`)

- Verifies the vault password hash, then derives the KEK and unwraps the DEK.
- GCM authentication failure during unwrap (wrong password that somehow passed the
  separate `password_verify` check — shouldn't normally happen since both are derived
  from the same password, but defensively handled) is caught and treated as a failed
  attempt.
- On success: stores the DEK in session (§5.5) and records a `success` access-log row.

### 6.3 Lockout (shared logic for both targets)

`Auth::recordAttempt()` / `Auth::isLocked()` implement a simple counter + cooldown:

- `MAX_FAILED_ATTEMPTS = 3` failed attempts (per target — `master` and `vault` tracked
  independently) → `LOCKOUT_DURATION = 1800` seconds (30 min) lockout.
- A successful attempt resets `failed_attempts` to 0 and clears `locked_until`.
- Lockout state is **persisted in SQLite**, not session — so it survives logout, browser
  restarts, and applies regardless of which client/IP is attempting (the `ip_address` is
  logged for audit purposes only; it does not scope the lockout).
- Every attempt (success/failed/locked) is appended to `access_log`, which the
  dashboard and History page both read.

### 6.4 Session timeout vs. vault idle lock — two independent timers

These are easy to conflate but serve different purposes:

| Timer                | Constant             | Default (prod)   | Behavior on expiry                                  |
|------------------------|------------------------|---------------------|---------------------------------------------------------|
| Session timeout        | `SESSION_TIMEOUT`       | 3000s (50 min*)       | Server-side check on each request; destroys session, redirects to `index.php?timeout=1` |
| Vault idle auto-lock   | `VAULT_IDLE_TIMEOUT`     | 60s*                   | Client-side JS countdown; shows in-page unlock overlay, does **not** destroy the master session |

\* `config.php` currently has **debug values active**: `SESSION_TIMEOUT` is
`300*10` = 3000s but the inline comment says "(DEBUG STATE: 50 min)" implying intended
production value differs; `VAULT_IDLE_TIMEOUT` is `300/5` = 60s with a "(DEBUG STATE: 1
min)" comment. **Check these constants before relying on documented defaults in
production** — they appear to be mid-tuning.

- Session timeout (`Auth::checkSessionTimeout`) is enforced **server-side** on every
  request to `index.php`, independent of any JS. The navbar's `#sessionTimer` is a
  client-side countdown display only — it resets on `click`/`keydown`/`mousemove` but
  does **not** itself call the server to refresh `last_activity`; the actual
  server-side `last_activity` is refreshed by `checkSessionTimeout()` on every page
  load/action, so the JS timer can drift from server truth if the user is "active"
  purely client-side (e.g. moving the mouse) without making a request — the JS resets
  its own local countdown, but the server's own idle clock does not actually see that
  activity. In practice, navigating or submitting a form within the window keeps you
  logged in; a long period of only mouse movement with no requests will still log out
  server-side once `SESSION_TIMEOUT` server-reckoned idle time elapses, even though the
  client-side timer looked fine (a UI/server mismatch worth knowing if you modify this).
- Vault idle lock is **entirely client-side JS** (`ui/layout.php`, bottom script block):
  a per-page countdown (`idleLimit = VAULT_IDLE_TIMEOUT`), reset by `click`/`keydown`/
  `mousemove`/`scroll`/`touchstart`. When it elapses, it shows a modal-like overlay
  requiring the vault password, submitted via `fetch` to the `vault_unlock_ajax` action
  (JSON in/out, no page reload needed) — note this **does not actually re-lock the
  server session's DEK** until the overlay appears (the overlay is purely a UI gate);
  the real lock happens via the normal "Lock Vault" button/form (`action=lock_vault`),
  which the idle timer does not itself trigger. In other words, the idle overlay blocks
  *interaction* but the DEK remains decrypt-capable server-side (`Auth::isVaultUnlocked()`
  is still true) until the user explicitly submits `lock_vault` — reloading the page
  while the overlay is showing would still render the unlocked vault. If you need a true
  server-enforced idle-lock, wire the JS timer to also POST `action=lock_vault` on
  expiry, or add its own server-side idle tracking similar to `SESSION_TIMEOUT`.

---

## 7. Feature Walkthroughs

### 7.1 First-run setup (`setup.php`)

- Blocks itself once `db/vault.db` exists (redirects to `index.php`).
- Collects **master username**, **master password** (min 12 chars) + confirm, and
  **vault password** (min 8 chars) + confirm, in a single form.
- Creates all four tables, then runs the full crypto setup in order: hash master
  password → generate master salt (unused, see §5.2) → generate vault salt → derive KEK
  → generate DEK → wrap DEK → hash vault password → insert the `config` row → seed
  `lockout` rows for both targets.
- **Shows the raw DEK exactly once** (base64 + hex) on the success screen, with a
  "copy to clipboard" button per format and a confirmation checkbox that must be ticked
  before the "Proceed to Login" button becomes clickable. This is the **only** copy of
  the DEK outside of its AES-wrapped form in the DB — if both the vault password and
  this backup are lost, the vault is permanently unrecoverable (there's no master-key
  escrow).
- On any exception during setup, the partially-created DB file is deleted
  (`unlink(DB_PATH)`) so a half-initialized vault can't exist.

### 7.2 Vault CRUD (`Vault.php` + `index.php` actions)

- `addEntry` / `updateEntry` always re-encrypt `username` and `password` with a fresh
  random nonce (never reuse nonces, see §5.4).
- `listEntries` decrypts **every** row's username/password on every vault-page load
  (no caching) — fine at personal-project scale, would need pagination/lazy-decryption
  if the entry count grew large.
- `title`/`url`/`hint` pass through as plaintext — no encryption, no sanitization
  beyond `htmlspecialchars()` at render time (stored values are trusted internal data,
  not escaped on write).
- Delete is immediate (`DELETE FROM vault WHERE id = ?`) — the confirmation step is
  purely a JS modal (`ui/layout.php`'s `#deleteModal`) wired to a hidden per-row form;
  there's no server-side "are you sure" or soft-delete/undo.

### 7.3 Vault list UI (`vault_list.php`)

Entry cards render masked passwords (`••••••••••••`) by default; the plaintext value is
embedded in a `data-password` attribute on page load (i.e., it's present in the DOM/HTML
source the moment the vault page renders — "masked" is visual-only, not a security
boundary against anyone with DOM access). Click-to-copy and the "hold to reveal" hint
interaction are implemented as a couple of small `querySelectorAll` + event-listener
blocks at the bottom of the file — no component framework.

### 7.4 CSV Import (`import.php`)

Two-step, session-backed flow:

1. **Step 1 — upload & validate.** Requires a `.csv` extension **and** a MIME type in
   an allow-list (`text/plain`, `text/csv`, `application/csv`,
   `application/octet-stream`); requires valid UTF-8 content; requires the exact
   (case-sensitive) header row `Title,URL,Username,Password,Hint`; caps at
   `IMPORT_MAX_ROWS = 100` data rows. Per-row validation: empty `Title` or `Username`
   → row skipped as an **error**; empty `Password` → skipped as a **warning**; a
   `Title` that already exists in `vault` → skipped as a **duplicate** (checked via a
   live `SELECT` per row during parsing, not a bulk check). Valid rows are stashed in
   `$_SESSION['import_preview']` (not yet written to the DB).
2. **Step 2 — confirm.** Re-reads the session-stashed valid rows and calls
   `Vault::addEntry()` for each — this is the only step that actually touches the DB
   and requires the vault to be unlocked (`Auth::getDEK()` inside `addEntry` throws
   otherwise; the confirm button itself is hidden in the UI if the vault isn't
   unlocked, per `ui/import.php`'s check, but note **this is a UI-only guard** — the
   underlying `index.php` import-confirm POST handler does not itself check
   `Auth::isVaultUnlocked()` before looping and calling `Vault::addEntry()` for every
   row; a direct POST to the confirm path while the vault is locked would hit
   `Auth::getDEK()`'s `RuntimeException` partway through the loop. Each row's exception
   is caught individually and reported in `$skippedErrors`, so a locked vault produces
   a results page listing every row as failed rather than importing anything — but
   it's worth knowing the enforcement point is a caught exception per row, not an
   upfront guard.)

Session key `import_preview` is cleared after step 2 runs, win or lose.

### 7.5 Access Log / Lockout page (`access_log.php`)

- Filter by `target` (`all`/`master`/`vault`) and `event`
  (`all`/`success`/`failed`/`locked`), both validated against an allow-list before use
  in the `WHERE` clause (values are still bound as PDO params, not interpolated — the
  allow-list is belt-and-suspenders against invalid filter values reaching the UI, not
  strictly needed for SQL safety since params are bound).
- Pagination is 25 rows/page, computed with `LIMIT`/`OFFSET` — note `$perPage` and
  `$offset` are interpolated directly into the SQL string rather than bound as
  parameters; they're safe here because both are produced by `max(1, (int) ...)` casts
  (guaranteed integers), but if this code is ever refactored, prefer binding them too.
- A live "lockout status bar" at the top shows both targets' current lock state —
  this reads directly from the `lockout` table on every page load, not from
  `access_log`.

### 7.6 Emergency recovery tool (`recover.php`)

A **standalone CLI script**, intentionally outside the web app (no HTTP access —
would need to be run via `php recover.php [path-to-vault.db]`). Re-implements the
crypto primitives inline (duplicated constants and KDF/AEAD logic, not reusing
`inc/Crypto.php`) so that it has zero dependency on the rest of the app or autoloading —
designed to work even if the web app itself is broken, misconfigured, or inaccessible.

Flow: checks required PHP extensions (`openssl`, `sqlite3`, `pdo_sqlite`, `sodium`) →
locates and opens the DB file (sanity-checks for a `vault` table) → offers two unlock
modes:

1. **Vault password** — same derive-KEK-then-unwrap-DEK path as the web app.
2. **Raw DEK** — paste the base64/hex backup captured at setup time (§7.1) directly,
   bypassing password verification entirely. Validated by shape (`parseDEK()`: 64
   hex chars or 44-char base64 decoding to exactly 32 bytes).

Then decrypts and prints every vault entry to the terminal (masked input via `stty
-echo` when prompting for secrets on non-Windows). Explicitly writes nothing to disk —
its own docblock states the design intent: **a tool of last resort if the web app
environment is unavailable**, as long as you still have either the vault password or
the DEK backup.

---

## 8. Frontend Notes

- **Theming:** a `data-theme` attribute on `<html>`, toggled via a button in the
  navbar, persisted in `localStorage['pm-theme']`. An inline `<script>` in the
  `<head>` of both `login.php` and `layout.php` applies the saved theme **before**
  first paint (to avoid a flash of the wrong theme) — if you add new entry pages,
  replicate that inline snippet at the top of `<head>`.
- **Toasts & modals:** Bootstrap 5's `Toast` and `Modal` components, instantiated from
  vanilla JS (`bootstrap.Toast.getOrCreateInstance(...)`), not Bootstrap's
  data-attribute auto-init for the toast (the delete modal does use
  `data-bs-toggle="modal"`/`data-bs-target`, so both patterns coexist).
  `window.showToast(msg)` is a small global helper defined once in `layout.php` and
  called from page partials (`vault_list.php`'s copy buttons).
- **No bundler/build step.** Bootstrap, Font Awesome, and jQuery are all loaded from
  CDN (`cdn.jsdelivr.net`, `cdnjs.cloudflare.com`, `code.jquery.com`) directly in
  `<head>`/before `</body>`. All custom styling lives in one file,
  `ui/assets/style.css`.
- **Client-side password generator** (`vault_entry.php`): uses
  `crypto.getRandomValues` (Web Crypto API, not `Math.random`) over a fixed charset,
  20 characters. A simple heuristic strength meter (length/case-mix/digit/symbol
  checks) runs on every keystroke and on page load for pre-filled edit forms.

---

## 9. Configuration Reference (`config.php`)

| Constant                   | Value           | Used by                                   |
|------------------------------|-------------------|----------------------------------------------|
| `SESSION_TIMEOUT`              | `300*10` (3000s)     | `Auth::checkSessionTimeout`                      |
| `VAULT_IDLE_TIMEOUT`             | `300/5` (60s)          | Client-side idle-lock JS in `ui/layout.php`          |
| `MAX_FAILED_ATTEMPTS`              | `3`                      | `Auth::recordAttempt`                                  |
| `LOCKOUT_DURATION`                   | `1800` (30 min)            | `Auth::recordAttempt`                                      |
| `ARGON2_MEMORY_COST`                   | `65536` (64 MiB)             | `Crypto::hashPassword`, `Crypto::deriveKEK`                     |
| `ARGON2_TIME_COST`                        | `4`                             | same as above                                                       |
| `ARGON2_THREADS`                            | `2`                                | `Crypto::hashPassword` only (sodium KDF ignores it)                    |
| `PBKDF2_ALGO` / `PBKDF2_ITERATIONS`            | `sha256` / `600000`                  | **Unused** — dead config, see §5.3                                        |
| `AES_CIPHER`                                     | `aes-256-gcm`                           | `Crypto::encrypt/decrypt/wrapDEK/unwrapDEK`                                     |
| `AES_KEY_SIZE` / `AES_IV_SIZE` / `AES_TAG_SIZE`     | `32` / `12` / `16`                        | same                                                                                 |

Both timing constants carry inline "(DEBUG STATE: ...)" comments suggesting they are
currently set to shortened debug values rather than intended production values —
confirm before deploying.

---

## 10. Known Limitations / Things to Check Before Hardening Further

These are observations from reading the code, not necessarily bugs to fix blindly —
most are reasonable trade-offs for a single-user personal tool, but worth being
conscious of:

1. **No CSRF tokens** on any state-changing form (`add_entry`, `update_entry`,
   `delete_entry`, `lock_vault`, `logout`). Mitigated somewhat by `SameSite=Strict`
   cookies, not eliminated.
2. **DEK lives in the PHP session** in memory/session-store for the entire time the
   vault is unlocked (§5.5) — standard for this class of app, but means session-file
   compromise on the server = vault compromise while unlocked.
3. **No AAD binding** on AES-GCM ciphertexts (§5.4) — ciphertext/nonce/tag triples
   aren't cryptographically bound to their row ID.
4. **`PBKDF2_*` constants are dead code** (§5.3) — either wire them in or remove them
   to avoid confusing future readers into thinking PBKDF2 is in use.
5. **Master salt (`master_argon2id_salt`) is generated but never used** — master login
   only ever calls `password_verify`, which has its own salt embedded in the hash
   string. Candidate for removal.
6. **Vault idle-lock overlay is a UI gate only**, not a real server-side re-lock
   (§6.4) — reloading the page while it's showing bypasses it.
7. **Import confirm step doesn't guard `Auth::isVaultUnlocked()` before the loop**
   (§7.4) — relies on a per-row caught exception instead of an upfront check.
8. **No "change vault password" flow exists yet** — the envelope-encryption design
   (§5.2) supports one cheaply (re-wrap the DEK), but no UI/route implements it.
9. **Single-row `config` table assumes exactly one vault/one master identity** — this
   is intentional (personal single-user tool), not a bug, but means multi-user support
   would require schema changes, not just new routes.
