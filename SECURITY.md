# Security Policy

## About this project

This is a personal, single-user password manager. It has **not** undergone a
formal, independent security audit. The cryptographic design (envelope
encryption with AES-256-GCM, Argon2id key derivation) is documented in detail
in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), including a section on
known limitations and trade-offs — please read that before relying on this
project for anything beyond personal use.

It is intended to be self-hosted by a single trusted operator on
infrastructure they control. It is **not** designed for multi-tenant,
public-facing, or production-enterprise deployment as-is.

## Supported Versions

There is no long-term-support branch. Security fixes are made against the
latest version on the default branch; there are no backported patches for
older tags.

| Version        | Supported          |
|----------------|--------------------|
| Latest release | :white_check_mark: |
| Older releases | :x:                |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, use GitHub's private vulnerability reporting feature:

1. Go to the **Security** tab of this repository.
2. Click **Report a vulnerability**.
3. Describe the issue, the affected file(s)/version, and — if possible —
   steps to reproduce or a proof of concept.

This opens a private advisory visible only to the maintainer, so the issue
can be discussed and fixed before any public disclosure.

As this is a personal project maintained in spare time, there is no formal
SLA, but reports will be acknowledged and triaged on a best-effort basis.

## Scope

In scope:
- The authentication, session, and vault-lock logic (`inc/Auth.php`).
- The cryptographic implementation (`inc/Crypto.php`).
- The vault data layer and its handling of encrypted fields (`inc/Vault.php`).
- The CSV import flow (`ui/import.php`) and any other user-input handling.

Out of scope / known, accepted trade-offs (see `docs/ARCHITECTURE.md` §10 for
details):
- Lack of multi-user support — this is single-user by design.
- The vault idle-lock overlay being a client-side UI gate rather than a
  server-enforced re-lock.
- Absence of CSRF tokens, mitigated by `SameSite=Strict` session cookies.

If you're unsure whether something is in scope, report it anyway — it's
easier to close a report as out-of-scope than to miss a real issue.
