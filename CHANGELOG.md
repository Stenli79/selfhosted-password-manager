# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.6.0] - 2026-10-02

### Added
- Project architecture documentation (`docs/ARCHITECTURE.md`) covering the data
  model, cryptography, and feature behavior.
- Repository scaffolding for open-source distribution: `README.md`,
  `LICENSE` (MIT), `SECURITY.md`, this changelog, and a `.gitignore` scoped
  to local vault data.
- Empty `tests/` directory, reserved for an upcoming test suite.

### Removed
- Development-only debug helper and its autoload reference, no longer needed
  outside local development.

## [0.5.1] - 2026-05-16

### Added
- Automatic vault lock after a period of inactivity, independent of the
  session timeout.

## [0.4.8] - 2026-05-02

### Changed
- Updated and removed obsolete CSS classes following the Bootstrap migration.

## [0.4.6] - 2026-05-02

### Changed
- Adopted Bootstrap layout patterns and general front-end best practices
  across the interface.

## [0.4.5] - 2026-05-02

### Changed
- Replaced emoji icons with Font Awesome icons across the dashboard, history,
  import, and login/unlock pages for visual consistency.

## [0.4.4] - 2026-05-02

### Added
- Toast notifications for copy-to-clipboard actions.
- Confirmation modal for entry deletion, replacing the native browser
  confirmation dialog.

### Changed
- Refined button press interaction styling.

## [0.4.3] - 2026-05-02

### Changed
- Migrated the vault entry grid to the Bootstrap grid system, with
  responsive breakpoints from one to four columns depending on screen size.

## [0.4.2] - 2026-05-02

### Changed
- Redesigned vault entry cards with dedicated icons for edit, delete, URL,
  copy, password visibility, hint reveal, and last-updated actions.

## [0.4.1] - 2026-05-01

### Added
- Foundation layout and primary navigation bar.
- Application-level logging utility.

## [0.4.0] - 2026-05-01

### Added
- Single-user, single-vault usage model.

## [0.3.0] - 2026-05-01

### Added
- Initial release: authentication, encrypted vault storage, and the core
  encryption layer.
