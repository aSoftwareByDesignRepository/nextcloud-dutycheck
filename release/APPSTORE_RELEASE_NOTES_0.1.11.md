# App Store release notes — DutyCheck 0.1.11

Paste the **English** block into the store changelog (EN). Use **German** for the DE locale if the form asks separately.

---

## English (changelog field)

**0.1.11 — Reliable saves for employees and roster assignments**

- Fixes saves that failed with only “Something went wrong” and no server log entry (common on Snap and behind reverse proxies).
- API writes now use form-encoded bodies with CSRF token in header and body, matching Nextcloud core.
- Safer assignment database transaction; clearer inline errors on the roster form.
- Includes 0.1.10 improvements: date-first employee picker (skips approved leave), CSRF refresh on long sessions, three-step assignment form, plain-language planning checks.

After updating: hard-refresh your browser and confirm version 0.1.11 under Apps.

---

## Deutsch (Changelog DE)

**0.1.11 — Zuverlässiges Speichern von Mitarbeitenden und Dienstplan-Einsätzen**

- Behebt Speichervorgänge, die nur „Etwas ist schiefgelaufen“ anzeigten, ohne Eintrag im Server-Log (häufig bei Snap und hinter Reverse-Proxies).
- API-Schreibvorgänge nutzen jetzt formularcodierte Anfragen mit CSRF-Token in Header und Body — wie der Nextcloud-Kern.
- Sicherere Datenbank-Transaktion beim Anlegen von Einsätzen; klarere Fehlermeldungen direkt im Dienstplan-Formular.
- Enthält Verbesserungen aus 0.1.10: zuerst Datum wählen (genehmigte Abwesenheit wird ausgeblendet), CSRF-Aktualisierung bei langen Sitzungen, dreistufiges Formular, verständliche Planungsprüfungen.

Nach dem Update: Browser hart neu laden (Strg+F5) und Version 0.1.11 unter Apps prüfen.

---

## Short summary (if character-limited)

EN: Fix employee and roster save failures on Snap/proxies; form-encoded API + clearer errors. Hard-refresh after update.

DE: Speichern von Mitarbeitenden und Einsätzen auf Snap/Proxies repariert; formularcodierte API + klarere Fehler. Nach Update hart neu laden.
