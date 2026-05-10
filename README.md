# DutyCheck (Nextcloud app)

DutyCheck is a Nextcloud app for duty roster planning: period-based schedules, validation, publish/close workflows, absence handling, and audit-oriented snapshots. The server enforces conflict and lifecycle rules; the UI is role-aware and accessibility-oriented.

## Requirements

- Nextcloud 32 or 33
- PHP 8.2–8.4
- MySQL or PostgreSQL

Optional: [ArbeitszeitCheck](https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck) for absence mirroring when enabled in settings.

## Install from Git (development)

```bash
cd /path/to/nextcloud/apps/
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-dutycheck.git dutycheck
cd dutycheck
composer install --no-dev
```

Enable the app in Nextcloud (Apps → DutyCheck) or run `php occ app:enable dutycheck`.

## Development

```bash
composer install
./vendor/bin/phpunit
```

Regenerate translation scaffolding after string changes:

```bash
python3 scripts/sync_l10n.py
```

## Releasing

See the monorepo document `ready2publish/APPSTORE-RELEASE.md` (or your team’s equivalent): bump `appinfo/info.xml` `<version>`, update this changelog, run `make release-signed` from this directory with Nextcloud `occ` and app signing certificates, then upload the tarball to [apps.nextcloud.com](https://apps.nextcloud.com).

Store listing images live under `screenshots/` as `dutycheck-screenshot-NN.png`; keep them in sync with `appinfo/info.xml` before a release.

## Security

Do not open issues or pull requests that contain production secrets, personal data, or internal hostnames. Report sensitive findings privately to the maintainer (see `appinfo/info.xml` author).

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
