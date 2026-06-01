#!/usr/bin/env python3
"""Extract translatable strings from DutyCheck PHP/JS, merge de_dict.json, write en/de/de_DE .json and .js."""

import json
import re
from pathlib import Path

base = Path(__file__).resolve().parent.parent
l10n_dir = base / "l10n"


QUOTE = r"('([^'\\]*(?:\\.[^'\\]*)*)'|\"([^\"\\]*(?:\\.[^\"\\]*)*)\")"


def unquote(m_group1: str) -> str:
	raw = m_group1
	s = raw[1:-1]
	return s.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\")


def extract_from_php(text: str) -> set[str]:
	out: set[str] = set()
	prefixes = [
		r"\$l->t\(\s*",
		r"\$this->l10n->t\(\s*",
		r"->get\(\s*self::APP_ID\s*\)->t\(\s*",
	]
	for pref in prefixes:
		for m in re.finditer(pref + QUOTE, text):
			out.add(unquote(m.group(1)))
	return out


def extract_from_js(text: str) -> set[str]:
	out: set[str] = set()
	rx = re.compile(r"t\(\s*['\"]dutycheck['\"]\s*,\s*" + QUOTE)
	for m in rx.finditer(text):
		out.add(unquote(m.group(1)))
	return out


def extract_roster_api_conflict_message_keys() -> set[str]:
	"""Keys from RosterService::rosterApiConflictMessageKeys() (API payloads, not wrapped in $l->t)."""
	path = base / "lib/Service/RosterService.php"
	if not path.is_file():
		return set()
	text = path.read_text(encoding="utf-8")
	start = text.find("public static function rosterApiConflictMessageKeys()")
	if start == -1:
		return set()
	ret = text.find("return [", start)
	if ret == -1:
		return set()
	end = text.find("];", ret)
	if end == -1:
		return set()
	block = text[ret + len("return [") : end]
	out: set[str] = set()
	for m in re.finditer(r"'((?:\\.|[^'\\])*)'", block):
		s = m.group(1).replace("\\'", "'").replace("\\\\", "\\")
		out.add(s)
	return out


def main() -> None:
	strings: set[str] = set()
	for php in base.glob("**/*.php"):
		if "vendor" in str(php) or "tests" in str(php):
			continue
		strings |= extract_from_php(php.read_text(encoding="utf-8"))

	for js in base.glob("**/*.js"):
		if "vendor" in str(js):
			continue
		strings |= extract_from_js(js.read_text(encoding="utf-8"))

	strings |= extract_roster_api_conflict_message_keys()

	print(f"Extracted {len(strings)} strings.")

	de_existing: dict[str, str] = {}
	de_path = l10n_dir / "de.json"
	if de_path.exists():
		de_existing = json.loads(de_path.read_text(encoding="utf-8")).get("translations", {})

	de_dict_path = l10n_dir / "de_dict.json"
	de_dict: dict[str, str] = {}
	if de_dict_path.exists():
		de_dict = json.loads(de_dict_path.read_text(encoding="utf-8"))

	en_translations = {s: s for s in sorted(strings)}
	en_full = {"translations": en_translations, "pluralForm": "nplurals=2; plural=(n != 1);"}
	(l10n_dir / "en.json").write_text(
		json.dumps(en_full, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
	)

	de_out: dict[str, str] = {}
	for s in sorted(strings):
		if s in de_dict:
			de_out[s] = de_dict[s]
		elif s in de_existing and de_existing[s] != s:
			de_out[s] = de_existing[s]
		else:
			de_out[s] = s
	de_full = {"translations": de_out, "pluralForm": "nplurals=2; plural=(n != 1);"}
	(l10n_dir / "de.json").write_text(
		json.dumps(de_full, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
	)

	def gen_js(translations_dict: dict[str, str]) -> str:
		lines = ["OC.L10N.register(", '    "dutycheck",', "    {"]
		keys = sorted(translations_dict.keys())
		for i, s in enumerate(keys):
			key = json.dumps(s, ensure_ascii=False)
			val = json.dumps(translations_dict[s], ensure_ascii=False)
			comma = "," if i < len(keys) - 1 else ""
			lines.append(f"    {key} : {val}{comma}")
		lines.append("},")
		lines.append('"nplurals=2; plural=(n != 1);");')
		return "\n".join(lines) + "\n"

	(l10n_dir / "en.js").write_text(gen_js(en_translations), encoding="utf-8")
	(l10n_dir / "de.js").write_text(gen_js(de_out), encoding="utf-8")

	# de_DE: same strings as de (formal DE used for both generic German and de-DE locale).
	(l10n_dir / "de_DE.json").write_text(
		json.dumps(de_full, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
	)
	(l10n_dir / "de_DE.js").write_text(gen_js(de_out), encoding="utf-8")

	print(f"Wrote en.json/de.json/de_DE.json and en.js/de.js/de_DE.js with {len(en_translations)} entries.")
	de_count = sum(1 for k in de_out if de_out[k] != k)
	print(f"de.json / de_DE.json have {de_count} non-identity German entries.")


if __name__ == "__main__":
	main()
