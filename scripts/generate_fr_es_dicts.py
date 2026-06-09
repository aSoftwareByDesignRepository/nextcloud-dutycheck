#!/usr/bin/env python3
"""Fast FR/ES dict generation with checkpointing and placeholder protection."""

import json
import re
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from deep_translator import GoogleTranslator

base = Path(__file__).resolve().parent.parent
l10n_dir = base / "l10n"

PH_RE = re.compile(
	r"(\{[a-zA-Z_][a-zA-Z0-9_]*\}|`[^`]+`|DutyCheck|ArbeitszeitCheck|Nextcloud|HH:mm|IANA|UTC|CSV|Google Calendar|Apple Calendar|Outlook|Europe/Berlin|php occ upgrade|iCal)"
)


def protect(text: str) -> tuple[str, list[str]]:
	tokens: list[str] = []

	def repl(m: re.Match[str]) -> str:
		tokens.append(m.group(0))
		return f"⟦{len(tokens)-1}⟧"

	return PH_RE.sub(repl, text), tokens


def restore(text: str, tokens: list[str]) -> str:
	for i, tok in enumerate(tokens):
		text = text.replace(f"⟦{i}⟧", tok)
		text = re.sub(rf"\[\s*{i}\s*\]", tok, text)
	return text


def translate_one(text: str, target: str, retries: int = 4) -> str:
	protected, tokens = protect(text)
	tr = GoogleTranslator(source="en", target=target)
	for attempt in range(retries):
		try:
			return restore(tr.translate(protected), tokens)
		except Exception:
			time.sleep(0.5 * (attempt + 1))
	return text


def translate_all(keys: list[str], target: str, workers: int = 8) -> dict[str, str]:
	out: dict[str, str] = {}
	done = 0
	with ThreadPoolExecutor(max_workers=workers) as pool:
		futs = {pool.submit(translate_one, k, target): k for k in keys}
		for fut in as_completed(futs):
			k = futs[fut]
			out[k] = fut.result()
			done += 1
			if done % 50 == 0:
				print(f"  {target}: {done}/{len(keys)}")
	return out


def postprocess_fr(d: dict[str, str]) -> dict[str, str]:
	replacements = [
		("Must fix", "À corriger obligatoirement"),
		("Confirm to continue", "Confirmer pour continuer"),
		("must fix", "à corriger obligatoirement"),
		("confirm to continue", "confirmer pour continuer"),
	]
	for k, v in d.items():
		for a, b in replacements:
			v = v.replace(a, b)
		d[k] = v
	# Hard overrides for exact keys
	overrides = {
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
		"ArbeitszeitCheck": "ArbeitszeitCheck",
		"DutyCheck": "DutyCheck",
		"Administrator": "Administrateur",
	}
	for k, v in overrides.items():
		if k in d:
			d[k] = v
	return d


def postprocess_es(d: dict[str, str]) -> dict[str, str]:
	replacements = [
		("Must fix", "Debe corregirse"),
		("Confirm to continue", "Confirmar para continuar"),
		("must fix", "debe corregirse"),
		("confirm to continue", "confirmar para continuar"),
	]
	for k, v in d.items():
		for a, b in replacements:
			v = v.replace(a, b)
		d[k] = v
	overrides = {
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
		"ArbeitszeitCheck": "ArbeitszeitCheck",
		"DutyCheck": "DutyCheck",
		"Administrator": "Administrador",
	}
	for k, v in overrides.items():
		if k in d:
			d[k] = v
	return d


def main() -> None:
	en = json.loads((l10n_dir / "en.json").read_text(encoding="utf-8"))["translations"]
	keys = sorted(en.keys())
	print(f"Translating {len(keys)} keys…")
	fr = translate_all(keys, "fr")
	es = translate_all(keys, "es")
	fr = postprocess_fr(fr)
	es = postprocess_es(es)
	(l10n_dir / "fr_dict.json").write_text(json.dumps(fr, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
	(l10n_dir / "es_dict.json").write_text(json.dumps(es, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
	print("Done.")


if __name__ == "__main__":
	main()
