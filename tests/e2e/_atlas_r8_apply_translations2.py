#!/usr/bin/env python3
"""R8 TR-02 pass 2: category-CRUD keys, loanword msgids (non-de), pt locale."""
import json

LOCALES = ['de', 'de_DE', 'fr', 'fr_FR', 'es', 'es_ES', 'it', 'it_IT',
           'nl', 'nl_NL', 'pl', 'pl_PL', 'sv', 'sv_SE', 'da', 'da_DK',
           'nb', 'nb_NO', 'pt', 'pt_BR']
ALIAS = {'de_DE': 'de', 'fr_FR': 'fr', 'es_ES': 'es', 'it_IT': 'it',
         'nl_NL': 'nl', 'pl_PL': 'pl', 'sv_SE': 'sv', 'da_DK': 'da',
         'nb_NO': 'nb', 'pt': 'pt_BR'}

CAT = {
 'de': {
  'Category activated.': 'Kategorie aktiviert.',
  'Category added.': 'Kategorie hinzugefügt.',
  'Category deleted.': 'Kategorie gelöscht.',
  'Check the category name and colour.': 'Prüfen Sie Name und Farbe der Kategorie.',
  'Could not save the category.': 'Die Kategorie konnte nicht gespeichert werden.',
  'Delete category': 'Kategorie löschen',
  'Delete category {name}': 'Kategorie {name} löschen',
  'Enter a category name.': 'Geben Sie einen Kategorienamen ein.',
  'No category': 'Keine Kategorie',
 },
 'fr': {
  'Bereitschaft': 'Astreinte',
  'Category activated.': 'Catégorie activée.',
  'Category added.': 'Catégorie ajoutée.',
  'Category deleted.': 'Catégorie supprimée.',
  'Check the category name and colour.': 'Vérifiez le nom et la couleur de la catégorie.',
  'Could not save the category.': 'Impossible d’enregistrer la catégorie.',
  'Delete category': 'Supprimer la catégorie',
  'Delete category {name}': 'Supprimer la catégorie {name}',
  'Enter a category name.': 'Saisissez un nom de catégorie.',
  'No category': 'Aucune catégorie',
  'Reaktionszeit': 'Délai d’intervention',
  'Standort': 'Emplacement',
 },
 'es': {
  'Bereitschaft': 'Guardia',
  'Category activated.': 'Categoría activada.',
  'Category added.': 'Categoría añadida.',
  'Category deleted.': 'Categoría eliminada.',
  'Check the category name and colour.': 'Compruebe el nombre y el color de la categoría.',
  'Could not save the category.': 'No se pudo guardar la categoría.',
  'Delete category': 'Eliminar categoría',
  'Delete category {name}': 'Eliminar categoría {name}',
  'Enter a category name.': 'Introduzca un nombre de categoría.',
  'No category': 'Sin categoría',
  'Reaktionszeit': 'Tiempo de respuesta',
  'Standort': 'Ubicación',
 },
 'it': {
  'Bereitschaft': 'Reperibilità',
  'Category activated.': 'Categoria attivata.',
  'Category added.': 'Categoria aggiunta.',
  'Category deleted.': 'Categoria eliminata.',
  'Check the category name and colour.': 'Controlla il nome e il colore della categoria.',
  'Could not save the category.': 'Impossibile salvare la categoria.',
  'Delete category': 'Elimina categoria',
  'Delete category {name}': 'Elimina categoria {name}',
  'Enter a category name.': 'Inserisci un nome di categoria.',
  'No category': 'Nessuna categoria',
  'Reaktionszeit': 'Tempo di risposta',
  'Standort': 'Posizione',
 },
 'nl': {
  'Bereitschaft': 'Beschikbaarheidsdienst',
  'Category activated.': 'Categorie geactiveerd.',
  'Category added.': 'Categorie toegevoegd.',
  'Category deleted.': 'Categorie verwijderd.',
  'Check the category name and colour.': 'Controleer de naam en kleur van de categorie.',
  'Could not save the category.': 'De categorie kon niet worden opgeslagen.',
  'Delete category': 'Categorie verwijderen',
  'Delete category {name}': 'Categorie {name} verwijderen',
  'Enter a category name.': 'Voer een categorienaam in.',
  'No category': 'Geen categorie',
  'Reaktionszeit': 'Reactietijd',
  'Standort': 'Locatie',
 },
 'pl': {
  'Bereitschaft': 'Dyżur',
  'Category activated.': 'Kategoria aktywowana.',
  'Category added.': 'Kategoria dodana.',
  'Category deleted.': 'Kategoria usunięta.',
  'Check the category name and colour.': 'Sprawdź nazwę i kolor kategorii.',
  'Could not save the category.': 'Nie można zapisać kategorii.',
  'Delete category': 'Usuń kategorię',
  'Delete category {name}': 'Usuń kategorię {name}',
  'Enter a category name.': 'Wpisz nazwę kategorii.',
  'No category': 'Brak kategorii',
  'Reaktionszeit': 'Czas reakcji',
  'Standort': 'Lokalizacja',
 },
 'sv': {
  'Bereitschaft': 'Beredskapstjänst',
  'Category activated.': 'Kategori aktiverad.',
  'Category added.': 'Kategori tillagd.',
  'Category deleted.': 'Kategori borttagen.',
  'Check the category name and colour.': 'Kontrollera kategorins namn och färg.',
  'Could not save the category.': 'Kunde inte spara kategorin.',
  'Delete category': 'Ta bort kategori',
  'Delete category {name}': 'Ta bort kategori {name}',
  'Enter a category name.': 'Ange ett kategorinamn.',
  'No category': 'Ingen kategori',
  'Reaktionszeit': 'Svarstid',
  'Standort': 'Plats',
 },
 'da': {
  'Bereitschaft': 'Rådighedsvagt',
  'Category activated.': 'Kategori aktiveret.',
  'Category added.': 'Kategori tilføjet.',
  'Category deleted.': 'Kategori slettet.',
  'Check the category name and colour.': 'Kontrollér kategorinavn og -farve.',
  'Could not save the category.': 'Kategorien kunne ikke gemmes.',
  'Delete category': 'Slet kategori',
  'Delete category {name}': 'Slet kategori {name}',
  'Enter a category name.': 'Indtast et kategorinavn.',
  'No category': 'Ingen kategori',
  'Reaktionszeit': 'Svartid',
  'Standort': 'Placering',
 },
 'nb': {
  'Bereitschaft': 'Beredskapsvakt',
  'Category activated.': 'Kategori aktivert.',
  'Category added.': 'Kategori lagt til.',
  'Category deleted.': 'Kategori slettet.',
  'Check the category name and colour.': 'Kontroller kategoriens navn og farge.',
  'Could not save the category.': 'Kunne ikke lagre kategorien.',
  'Delete category': 'Slett kategori',
  'Delete category {name}': 'Slett kategori {name}',
  'Enter a category name.': 'Skriv inn et kategorinavn.',
  'No category': 'Ingen kategori',
  'Reaktionszeit': 'Responstid',
  'Standort': 'Sted',
 },
 'pt_BR': {
  'Bereitschaft': 'Sobreaviso',
  'Category activated.': 'Categoria ativada.',
  'Category added.': 'Categoria adicionada.',
  'Category deleted.': 'Categoria excluída.',
  'Check the category name and colour.': 'Verifique o nome e a cor da categoria.',
  'Could not save the category.': 'Não foi possível salvar a categoria.',
  'Delete category': 'Excluir categoria',
  'Delete category {name}': 'Excluir categoria {name}',
  'Enter a category name.': 'Insira um nome de categoria.',
  'No category': 'Sem categoria',
  'Reaktionszeit': 'Tempo de resposta',
  'Standort': 'Local',
 },
}

# pt needs the full pt_BR standby set too — reuse pass-1 map by importing it.
import importlib.util, sys
spec = importlib.util.spec_from_file_location('p1', 'tests/e2e/_atlas_r8_apply_translations.py')
p1 = importlib.util.module_from_spec(spec)
# prevent p1 from executing its apply-loop on import: it only defines T + loop at bottom.
# Easiest: exec with a flag — but simplest is to inline-copy needed entries via sys.modules hack.
# Instead: just re-apply by loading the dict through exec of the T block.
src = open('tests/e2e/_atlas_r8_apply_translations.py').read()
ns = {}
exec(src.split('changed = {}')[0], ns)  # defines T only
for loc in ['pt', 'pt_BR']:
    CAT.setdefault('pt_BR', {}).update(ns['T']['pt_BR'])

changed = {}
for loc in LOCALES:
    base = ALIAS.get(loc, loc)
    path = f'l10n/{loc}.json'
    try:
        data = json.load(open(path))
    except FileNotFoundError:
        continue
    tr = data['translations']
    n = 0
    for msgid, msgstr in CAT[base].items():
        if msgid in tr and tr[msgid] == msgid:
            tr[msgid] = msgstr
            n += 1
    if n:
        with open(path, 'w') as f:
            json.dump(data, f, ensure_ascii=False, indent='\t')
            f.write('\n')
    changed[loc] = n
print(changed)
