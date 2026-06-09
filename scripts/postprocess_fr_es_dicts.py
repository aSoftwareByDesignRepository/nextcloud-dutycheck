#!/usr/bin/env python3
"""Post-process MT dicts for consistent DutyCheck UI terminology."""

import json
import re
from pathlib import Path

l10n_dir = Path(__file__).resolve().parent.parent / "l10n"

FR_REPLACEMENTS = [
	("must fix", "à corriger obligatoirement"),
	("Must fix", "À corriger obligatoirement"),
	("confirm to continue", "confirmer pour continuer"),
	("Confirm to continue", "Confirmer pour continuer"),
	("Roster", "Planning"),
	("roster", "planning"),
	("Periods", "Périodes"),
	("periods", "périodes"),
	("Employees", "Employés"),
	("employees", "employés"),
	("Locations", "Lieux"),
	("locations", "lieux"),
	("Assignments", "Affectations"),
	("assignments", "affectations"),
	("Assignment", "Affectation"),
	("assignment", "affectation"),
	("Planner", "Planificateur"),
	("planner", "planificateur"),
	("Settings", "Paramètres"),
	("settings", "paramètres"),
	("Dashboard", "Tableau de bord"),
	("Governance", "Gouvernance"),
	("Quick start", "Démarrage rapide"),
	("Open Periods", "Ouvrir Périodes"),
	("Go to Periods", "Aller à Périodes"),
	("Go to Employees", "Aller à Employés"),
	("Go to Locations", "Aller à Lieux"),
	("Open Employees", "Ouvrir Employés"),
	("Open Locations", "Ouvrir Lieux"),
	("My roster", "Mon planning"),
	("My duties", "Mes postes"),
	("shift", "poste"),
	("Shift", "Poste"),
	("shifts", "postes"),
	("Shifts", "Postes"),
	("audit log", "journal d'audit"),
	("Audit log", "Journal d'audit"),
	("audit trail", "piste d'audit"),
	("Audit trail", "Piste d'audit"),
	("snapshot", "instantané"),
	("Snapshot", "Instantané"),
	("snapshots", "instantanés"),
	("Snapshots", "Instantanés"),
	("time zone", "fuseau horaire"),
	("Time zone", "Fuseau horaire"),
	("Timezone", "Fuseau horaire"),
	("timezone", "fuseau horaire"),
	("à résoudre", "à corriger obligatoirement"),
	("problèmes qui doivent être corrigés", "problèmes « À corriger obligatoirement »"),
]

ES_REPLACEMENTS = [
	("must fix", "debe corregirse"),
	("Must fix", "Debe corregirse"),
	("confirm to continue", "confirmar para continuar"),
	("Confirm to continue", "Confirmar para continuar"),
	("Roster", "Cuadro de turnos"),
	("roster", "cuadro de turnos"),
	("Periods", "Períodos"),
	("periods", "períodos"),
	("Employees", "Empleados"),
	("employees", "empleados"),
	("Locations", "Ubicaciones"),
	("locations", "ubicaciones"),
	("Assignments", "Asignaciones"),
	("assignments", "asignaciones"),
	("Assignment", "Asignación"),
	("assignment", "asignación"),
	("Planner", "Planificador"),
	("planner", "planificador"),
	("Settings", "Ajustes"),
	("settings", "ajustes"),
	("Dashboard", "Panel de control"),
	("Governance", "Gobernanza"),
	("Quick start", "Inicio rápido"),
	("Open Periods", "Abrir Períodos"),
	("Go to Periods", "Ir a Períodos"),
	("Go to Employees", "Ir a Empleados"),
	("Go to Locations", "Ir a Ubicaciones"),
	("Open Employees", "Abrir Empleados"),
	("Open Locations", "Abrir Ubicaciones"),
	("My roster", "Mi cuadro de turnos"),
	("My duties", "Mis turnos"),
	("shift", "turno"),
	("Shift", "Turno"),
	("shifts", "turnos"),
	("Shifts", "Turnos"),
	("audit log", "registro de auditoría"),
	("Audit log", "Registro de auditoría"),
	("audit trail", "pista de auditoría"),
	("Audit trail", "Pista de auditoría"),
	("snapshot", "instantánea"),
	("Snapshot", "Instantánea"),
	("snapshots", "instantáneas"),
	("Snapshots", "Instantáneas"),
	("time zone", "zona horaria"),
	("Time zone", "Zona horaria"),
	("Timezone", "Zona horaria"),
	("timezone", "zona horaria"),
	("deben solucionarse", "debe corregirse"),
	("Los problemas que \"deben solucionarse\"", "problemas « Debe corregirse »"),
]

FR_OVERRIDES = {
	"Must fix": "À corriger obligatoirement",
	"Confirm to continue": "Confirmer pour continuer",
	"Roster": "Planning",
	"Periods": "Périodes",
	"My roster": "Mon planning",
	"Go to Roster": "Aller au planning",
	"Back to roster": "Retour au planning",
	"Open Roster": "Ouvrir le planning",
	"Printable roster": "Planning imprimable",
	"Roster print": "Impression du planning",
	"Roster access": "Accès au planning",
	"review absences": "consulter les absences",
	"1 published shift": "1 poste publié",
	"{n} published shifts": "{n} postes publiés",
	"Publishing blocked: {mustFix} “must fix” issue(s) remain": "Publication bloquée : {mustFix} problème(s) « À corriger obligatoirement » en suspens",
	"{mustFix} must fix · {confirm} confirm to continue ({pending} open)": "{mustFix} à corriger obligatoirement · {confirm} confirmer pour continuer ({pending} ouvert(s))",
	"Ready to publish: {mustFix} must fix · {confirm} confirm to continue ({pending} open)": "Prêt à publier : {mustFix} à corriger obligatoirement · {confirm} confirmer pour continuer ({pending} ouvert(s))",
	"“Must fix” issues block publishing.": "Les problèmes « À corriger obligatoirement » bloquent la publication.",
	"Actor": "Acteur",
	"Role": "Rôle",
	"Shift times use the location timezone ({locationTimezone}). Change it on the Locations page. Your account timezone ({accountTimezone}) is shown in the header.": "Les horaires de poste utilisent le fuseau horaire du lieu ({locationTimezone}). Modifiez-le sur la page Lieux. Le fuseau horaire de votre compte ({accountTimezone}) est affiché dans l'en-tête.",
}

ES_OVERRIDES = {
	"Must fix": "Debe corregirse",
	"Confirm to continue": "Confirmar para continuar",
	"Roster": "Cuadro de turnos",
	"Periods": "Períodos",
	"My roster": "Mi cuadro de turnos",
	"Go to Roster": "Ir al cuadro de turnos",
	"Back to roster": "Volver al cuadro de turnos",
	"Open Roster": "Abrir cuadro de turnos",
	"Printable roster": "Cuadro de turnos imprimible",
	"Roster print": "Imprimir cuadro de turnos",
	"Roster access": "Acceso al cuadro de turnos",
	"review absences": "revisar ausencias",
	"1 published shift": "1 turno publicado",
	"{n} published shifts": "{n} turnos publicados",
	"Publishing blocked: {mustFix} “must fix” issue(s) remain": "Publicación bloqueada: {mustFix} problema(s) « Debe corregirse » pendiente(s)",
	"{mustFix} must fix · {confirm} confirm to continue ({pending} open)": "{mustFix} debe corregirse · {confirm} confirmar para continuar ({pending} abierto(s))",
	"Ready to publish: {mustFix} must fix · {confirm} confirm to continue ({pending} open)": "Listo para publicar: {mustFix} debe corregirse · {confirm} confirmar para continuar ({pending} abierto(s))",
	"“Must fix” issues block publishing.": "Los problemas « Debe corregirse » bloquean la publicación.",
	"Actor": "Interviniente",
	"Role": "Rol",
	"Shift times use the location timezone ({locationTimezone}). Change it on the Locations page. Your account timezone ({accountTimezone}) is shown in the header.": "Los horarios de turno utilizan la zona horaria de la ubicación ({locationTimezone}). Cámbielo en la página Ubicaciones. La zona horaria de su cuenta ({accountTimezone}) se muestra en el encabezado.",
}


def apply_replacements(text: str, replacements: list[tuple[str, str]]) -> str:
	"""Apply replacements without touching text inside {placeholder} tokens."""
	parts: list[str] = []
	cursor = 0
	for m in re.finditer(r"\{[a-zA-Z_][a-zA-Z0-9_]*\}", text):
		segment = text[cursor : m.start()]
		for src, dst in replacements:
			segment = segment.replace(src, dst)
		parts.append(segment)
		parts.append(m.group(0))
		cursor = m.end()
	segment = text[cursor:]
	for src, dst in replacements:
		segment = segment.replace(src, dst)
	parts.append(segment)
	return "".join(parts)


def apply(d: dict[str, str], replacements: list[tuple[str, str]], overrides: dict[str, str]) -> dict[str, str]:
	out = {}
	for k, v in d.items():
		if k in overrides:
			out[k] = overrides[k]
			continue
		out[k] = apply_replacements(v, replacements)
	return out


def main() -> None:
	fr = json.loads((l10n_dir / "fr_dict.json").read_text(encoding="utf-8"))
	es = json.loads((l10n_dir / "es_dict.json").read_text(encoding="utf-8"))
	fr = apply(fr, FR_REPLACEMENTS, FR_OVERRIDES)
	es = apply(es, ES_REPLACEMENTS, ES_OVERRIDES)
	(l10n_dir / "fr_dict.json").write_text(json.dumps(fr, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
	(l10n_dir / "es_dict.json").write_text(json.dumps(es, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
	print("Post-processed fr_dict.json and es_dict.json.")


if __name__ == "__main__":
	main()
