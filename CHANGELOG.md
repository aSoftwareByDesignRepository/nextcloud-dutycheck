# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.7 - 2026-05-21

### Fixed

- App Store `info.xml`: use `https://` for author homepage, website, and donation URL (matches ProjectCheck; store schema requires `https://.+`).

## 0.1.6 - 2026-05-21

### Fixed

- App Store `info.xml`: comply with schema element order (`screenshot` → `donation` → `dependencies`), cap screenshots at 10, remove non-schema `optional-dependencies` and `keywords` elements.

## 0.1.5 - 2026-05-21

### Added

- App Store listing: seventeen screenshots (`dutycheck-screenshot-01.png` … `17`) referenced from `appinfo/info.xml`; slots 15–17 use placeholder assets for future captures.
- `<donation>` link (Software by Design) in `info.xml`.

### Changed

- Project URLs use `nextcloud.software-by-design.de`.
- ArbeitszeitCheck integration hardened (mirror delete helper, access control, reconciliation job/command, expanded unit and integration tests).
- CI: PHPUnit workflow and Docker-based test target in the release `Makefile`.

### Fixed

- Nextcloud compatibility: `max-version` aligned to 33 (latest stable server).

## [0.1.4] - 2026-05-10

- Initial public packaging: App Store metadata (`info.xml`), `LICENSE`, release `Makefile`, and documentation.
- Placeholder screenshot asset for store listing (replace with real UI captures before marketing).
- Harden repository defaults: expanded `.gitignore` for keys, certificates, and local build/signing output.
