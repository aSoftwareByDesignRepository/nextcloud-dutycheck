# DutyCheck

[![Nextcloud](https://img.shields.io/badge/Nextcloud-32–34-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2–8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](LICENSE)

**[English](#english)** · **[Deutsch](#deutsch)**

Plan shifts. Catch conflicts. Publish with a trail — on your Nextcloud.

---

## English

**Plan shifts. Catch conflicts. Publish with a trail.**

DutyCheck builds duty rosters inside your self-hosted Nextcloud. Open a planning period, assign people to locations, see hard blocks and soft warnings while you edit — then publish, close and keep a snapshot when someone asks who was on duty.

**Free web app** (AGPL-3.0-or-later). Companion apps: https://nextcloud.software-by-design.de/

### Why teams install it

- Conflicts surface before publish — overlaps, understaffing, short breaks, period totals
- Clear lifecycle: open → published → closed (with reopen)
- Grid and list, shift templates, bulk fill, printable roster with integrity hash
- Staff self-service: my roster, absences, acknowledge, open-shift claim, swaps, iCal feed
- Snapshots with hash chain, audit trail, Activity and Notifications
- Optional ArbeitszeitCheck absence mirror (off until enabled; DutyCheck never writes back)

### Clear limits

- HR CSV exports minutes — not salaries.
- Audit entries are append-only.
- Conflict checks are ArbZG-oriented and configurable — not a legal certification.
- Declared databases: MySQL and PostgreSQL.

### Requirements

- Nextcloud 32–34 · PHP 8.2–8.5 · MySQL or PostgreSQL

### Install from Git

```bash
cd /path/to/nextcloud/apps/
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-dutycheck.git dutycheck
cd dutycheck
composer install --no-dev
```

Enable the app in Nextcloud (Apps → DutyCheck) or run `php occ app:enable dutycheck`.

### Security

Do not open public issues that contain production secrets, personal data, or internal hostnames. Report sensitive findings privately to the maintainer (see `appinfo/info.xml` author).

### Project & support

**Software by Design GbR** · [nextcloud.software-by-design.de](https://nextcloud.software-by-design.de/) · [info@software-by-design.de](mailto:info@software-by-design.de)  
[Support packages](https://nextcloud.software-by-design.de/en/support.html#packages)

### License

[AGPL-3.0-or-later](LICENSE).

---

## Deutsch

**Schichten planen. Konflikte sehen. Mit Nachweis veröffentlichen.**

DutyCheck baut Dienstpläne in Ihrer selbst gehosteten Nextcloud. Periode öffnen, Personen Standorten zuweisen, harte Sperren und weiche Warnungen schon beim Bearbeiten sehen — dann veröffentlichen, abschließen und einen Snapshot behalten, wenn jemand fragt, wer im Dienst war.

**Kostenlose Web-App** (AGPL-3.0-or-later). Companion-Apps: https://nextcloud.software-by-design.de/

### Warum Teams es einsetzen

- Konflikte vor dem Veröffentlichen — Überlappungen, Unterbesetzung, zu kurze Pausen, Periodensummen
- Klarer Ablauf: Offen → Veröffentlicht → Abgeschlossen (mit Wiederöffnen)
- Raster und Liste, Schichtvorlagen, Massenfüllung, druckbarer Plan mit Integritätshash
- Self-Service: Mein Dienstplan, Abwesenheiten, Bestätigung, offene Schichten, Tausch, iCal-Feed
- Snapshots mit Hash-Kette, Audit, Activity und Benachrichtigungen
- Optionale ArbeitszeitCheck-Abwesenheitsspiegelung (standardmäßig aus; DutyCheck schreibt nicht zurück)

### Klare Grenzen

- HR-CSV exportiert Minuten — keine Gehälter.
- Audit-Einträge sind nur anhängbar.
- Konfliktprüfungen sind ArbZG-orientiert und konfigurierbar — keine Rechtszertifizierung.
- Deklarierte Datenbanken: MySQL und PostgreSQL.

### Voraussetzungen

- Nextcloud 32–34 · PHP 8.2–8.5 · MySQL oder PostgreSQL

### Lizenz

[AGPL-3.0-or-later](LICENSE).
