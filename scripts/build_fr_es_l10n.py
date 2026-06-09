#!/usr/bin/env python3
"""Build fr/es/fr_FR/es_ES l10n JSON and JS files from fr_dict.json and es_dict.json."""

import json
from pathlib import Path

base = Path(__file__).resolve().parent.parent
l10n_dir = base / "l10n"

PLURAL_FR = "nplurals=2; plural=(n > 1);"
PLURAL_ES = "nplurals=2; plural=(n != 1);"


def gen_js(translations_dict: dict[str, str], plural_form: str) -> str:
	lines = ["OC.L10N.register(", '    "dutycheck",', "    {"]
	keys = sorted(translations_dict.keys())
	for i, s in enumerate(keys):
		key = json.dumps(s, ensure_ascii=False)
		val = json.dumps(translations_dict[s], ensure_ascii=False)
		comma = "," if i < len(keys) - 1 else ""
		lines.append(f"    {key} : {val}{comma}")
	lines.append("},")
	lines.append(json.dumps(plural_form) + ");")
	return "\n".join(lines) + "\n"


def load_dict(name: str) -> dict[str, str]:
	path = l10n_dir / name
	if not path.exists():
		raise SystemExit(f"Missing {path}")
	return json.loads(path.read_text(encoding="utf-8"))


def main() -> None:
	en = json.loads((l10n_dir / "en.json").read_text(encoding="utf-8"))["translations"]
	fr_dict = load_dict("fr_dict.json")
	es_dict = load_dict("es_dict.json")

	missing_fr = sorted(set(en) - set(fr_dict))
	missing_es = sorted(set(en) - set(es_dict))
	extra_fr = sorted(set(fr_dict) - set(en))
	extra_es = sorted(set(es_dict) - set(en))
	if missing_fr:
		raise SystemExit(f"fr_dict.json missing {len(missing_fr)} keys, e.g. {missing_fr[:3]}")
	if missing_es:
		raise SystemExit(f"es_dict.json missing {len(missing_es)} keys, e.g. {missing_es[:3]}")
	if extra_fr:
		raise SystemExit(f"fr_dict.json has {len(extra_fr)} extra keys, e.g. {extra_fr[:3]}")
	if extra_es:
		raise SystemExit(f"es_dict.json has {len(extra_es)} extra keys, e.g. {extra_es[:3]}")

	fr_out = {k: fr_dict[k] for k in sorted(en)}
	es_out = {k: es_dict[k] for k in sorted(en)}

	for lang, out, plural, suffixes in (
		("fr", fr_out, PLURAL_FR, ["fr"]),
		("es", es_out, PLURAL_ES, ["es"]),
	):
		full = {"translations": out, "pluralForm": plural}
		for suffix in suffixes:
			(l10n_dir / f"{suffix}.json").write_text(
				json.dumps(full, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
			)
			(l10n_dir / f"{suffix}.js").write_text(gen_js(out, plural), encoding="utf-8")

	# Regional mirrors (same strings as generic locale).
	fr_full = {"translations": fr_out, "pluralForm": PLURAL_FR}
	es_full = {"translations": es_out, "pluralForm": PLURAL_ES}
	(l10n_dir / "fr_FR.json").write_text(
		json.dumps(fr_full, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
	)
	(l10n_dir / "fr_FR.js").write_text(gen_js(fr_out, PLURAL_FR), encoding="utf-8")
	(l10n_dir / "es_ES.json").write_text(
		json.dumps(es_full, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
	)
	(l10n_dir / "es_ES.js").write_text(gen_js(es_out, PLURAL_ES), encoding="utf-8")

	print(f"Wrote fr/fr_FR/es/es_ES JSON and JS with {len(en)} entries each.")
	print(f"fr translated: {sum(1 for k, v in fr_out.items() if v != k)} non-identity")
	print(f"es translated: {sum(1 for k, v in es_out.items() if v != k)} non-identity")


if __name__ == "__main__":
	main()
