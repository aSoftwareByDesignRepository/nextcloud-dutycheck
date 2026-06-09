#!/usr/bin/env python3
"""Apply production-grade terminology fixes to fr_dict.json and es_dict.json."""

import json
import re
from pathlib import Path

l10n_dir = Path(__file__).resolve().parent.parent / "l10n"

FR_REPLACEMENTS = [
	("Doit réparer", "À corriger obligatoirement"),
	("doit résoudre", "à corriger obligatoirement"),
	("Must fix", "À corriger obligatoirement"),
	("must fix", "à corriger obligatoirement"),
	("Confirm to continue", "Confirmer pour continuer"),
	("confirm to continue", "confirmer pour continuer"),
	("liste honnête", "planning fiable"),
	("liste verte", "liste d'autorisation"),
	("réaction de la liste", "réaction du planning"),
	("conflits de liste", "conflits de planning"),
	("données de la liste", "données du planning"),
	("chronologie de votre liste", "chronologie de votre planning"),
	("page de liste", "page Planning"),
	("Sur la page Liste", "Sur la page Planning"),
	("Ma liste", "Mon planning"),
	("dernière liste", "dernier planning"),
	("La liste a changé", "Le planning a changé"),
	("Impossible de charger la liste", "Impossible de charger le planning"),
	("Impossible de charger votre liste", "Impossible de charger votre planning"),
	("Aucune donnée de liste", "Aucune donnée de planning"),
	("page de liste", "page Planning"),
	("Cette page de liste", "Cette page Planning"),
	("montre la liste aux employés", "affiche le planning aux employés"),
	("figurant sur leur liste", "sur leur planning"),
	("affiche la liste de la période", "affiche le planning de la période"),
	("L'ajout de devoirs", "L'ajout d'affectations"),
	("Ajouter un devoir", "Ajouter une affectation"),
	("Créer un devoir", "Créer une affectation"),
	("Enregistrer le devoir", "Enregistrer l'affectation"),
	("Devoir enregistré", "Affectation enregistrée"),
	("Mes devoirs", "Mes services"),
	("planifier des tâches", "planifier des services"),
	("planifiez vos tâches", "planifiez vos services"),
	("Planifier et publier les tâches", "Planifier et publier les services"),
	("Planifiez les tâches", "Planifiez les services"),
	("les tâches se déroulent", "les services se déroulent"),
	("effectuent des tâches", "effectuent des services"),
	("Vos prochaines tâches publiées", "Vos prochains services publiés"),
	("cette équipe puisse", "ce poste puisse"),
	("chaque équipe", "chaque poste"),
	("nouvelle équipe", "nouveau poste"),
	("les équipes qui se chevauchent", "les postes qui se chevauchent"),
	("les équipes publiées", "les postes publiés"),
	("stocke uniquement les équipes", "stocke uniquement les postes"),
	("aucune équipe à afficher", "aucun poste à afficher"),
	("Chaque équipe publiée", "Chaque poste publié"),
	("Ajoutez des équipes", "Ajoutez des postes"),
	("modèles d'équipe", "modèles de poste"),
	("Es-tu sûr?", "Êtes-vous sûr ?"),
]

ES_REPLACEMENTS = [
	("Must fix", "Debe corregirse"),
	("must fix", "debe corregirse"),
	("Confirm to continue", "Confirmar para continuar"),
	("confirm to continue", "confirmar para continuar"),
	("lista honesta", "cuadro de turnos fiable"),
	("datos de la lista", "datos del cuadro de turnos"),
	("cargar la lista", "cargar el cuadro de turnos"),
	("La lista cambió", "El cuadro de turnos cambió"),
	("muestra la lista", "muestra el cuadro de turnos"),
	("página de la lista", "página del cuadro de turnos"),
	("en la Lista", "en el cuadro de turnos"),
	("la lista más reciente", "el cuadro de turnos más reciente"),
	("lista anterior", "lista superior"),
	("Agregar tarea", "Agregar asignación"),
	("agregar tareas", "agregar asignaciones"),
	("Agregar tareas", "Agregar asignaciones"),
	("Crear tarea", "Crear asignación"),
	("Guardar tarea", "Guardar asignación"),
	("ID de tarea", "ID de asignación"),
	("Tarea guardada", "Asignación guardada"),
	("esta tarea", "esta asignación"),
	("otra tarea", "otra asignación"),
	("nueva tarea", "nueva asignación"),
	("lista de tareas", "lista de asignaciones"),
	("disponible para tareas", "disponible para asignaciones"),
	("Planificar tareas", "Planificar asignaciones"),
	("planificar tareas", "planificar asignaciones"),
	("planificar las tareas", "planificar los servicios"),
	("planifica tareas", "planifica servicios"),
	("realizan sus tareas", "realizan servicios"),
	("llevan a cabo las tareas", "tienen lugar los servicios"),
	("asignación de tareas", "asignación de servicio"),
	("Romper", "Descanso"),
	("romper y tomar nota", "Descanso y nota"),
]

FR_OVERRIDES = {
	" page first.": " en premier.",
	"Actions": "Actions",
	"Actor": "Acteur",
	"Add assignment": "Ajouter une affectation",
	"Adding assignments is not available right now.": "L'ajout d'affectations n'est pas disponible pour le moment.",
	"Are you sure?": "Êtes-vous sûr ?",
	"Back": "Retour",
	"Break": "Pause",
	"Break and note": "Pause et note",
	"Create assignment": "Créer une affectation",
	"My duties": "Mes services",
	"Save assignment": "Enregistrer l'affectation",
	"3. Watch the roster react": "3. Observez la réaction du planning",
	"Absences keep the roster honest: once approved, someone cannot be scheduled on those days, and any overlapping shift shows as “Must fix” on the Roster.": "Les absences maintiennent le planning fiable : une fois approuvées, une personne ne peut pas être planifiée ces jours-là, et tout poste qui se chevauche apparaît comme « À corriger obligatoirement » dans le planning.",
	"Account not linked — no shifts to show.": "Compte non lié — aucun poste à afficher.",
	"After approval, open the Roster — overlapping shifts appear as “Must fix” and must be changed or removed before you publish.": "Après approbation, ouvrez le planning — les postes qui se chevauchent apparaissent comme « À corriger obligatoirement » et doivent être modifiés ou supprimés avant la publication.",
	"Assignment saved — {employee} on {date}, {times}, at {location}. The list above is updated.": "Affectation enregistrée — {employee} le {date}, {times}, à {location}. La liste ci-dessus est mise à jour.",
	"Automatic checks for the same period as the assignment list. “Must fix” blocks publishing until resolved. “Confirm to continue” lets you proceed after you confirm with a short reason.": "Contrôles automatiques pour la même période que la liste des affectations. « À corriger obligatoirement » bloque la publication jusqu'à résolution. « Confirmer pour continuer » vous permet de poursuivre après confirmation avec un bref motif.",
	"Could not load roster.": "Impossible de charger le planning.",
	"Could not load the roster. Reload the page or contact an administrator if this keeps happening.": "Impossible de charger le planning. Rechargez la page ou contactez un administrateur si le problème persiste.",
	"Could not load your roster. Reload the page or contact an administrator if this keeps happening.": "Impossible de charger votre planning. Rechargez la page ou contactez un administrateur si le problème persiste.",
	"Done — the app can store roster data.": "Terminé — l'application peut stocker les données du planning.",
	"Mirror absences from ArbeitszeitCheck for roster conflicts. DutyCheck never writes to ArbeitszeitCheck.": "Refléter les absences d'ArbeitszeitCheck pour les conflits de planning. DutyCheck n'écrit jamais dans ArbeitszeitCheck.",
	"My roster — see published shifts": "Mon planning — voir les postes publiés",
	"No roster data — account not linked to an employee.": "Aucune donnée de planning — compte non lié à un employé.",
	"On the Roster page, use “Add assignment” for each shift. “Must fix” issues (e.g. someone in two places at once) block saving. “Confirm to continue” items need a short written reason. Then publish the period so employees can see their roster.": "Sur la page Planning, utilisez « Ajouter une affectation » pour chaque poste. Les problèmes « À corriger obligatoirement » (par ex. une personne à deux endroits en même temps) bloquent l'enregistrement. Les éléments « Confirmer pour continuer » nécessitent un bref motif écrit. Publiez ensuite la période pour que les employés voient leur planning.",
	"Open Roster, use “Add assignment” for each shift, and resolve every “Must fix” issue before continuing.": "Ouvrez le planning, utilisez « Ajouter une affectation » pour chaque poste et résolvez chaque problème « À corriger obligatoirement » avant de continuer.",
	"Opens a dialog to create a new shift for the selected open period.": "Ouvre une boîte de dialogue pour créer un nouveau poste pour la période ouverte sélectionnée.",
	"Periods are the timeline of your roster. Each period flows through three stages: open, published, closed.": "Les périodes sont la chronologie de votre planning. Chaque période passe par trois étapes : ouverte, publiée, fermée.",
	"Publishing freezes a tamper-evident snapshot and shows the roster to employees. Re-opening later is possible but always recorded with a written reason.": "La publication fige un instantané inviolable et affiche le planning aux employés. Une réouverture ultérieure est possible mais toujours enregistrée avec un motif écrit.",
	"Shifts for the period selected above. Use “Add assignment” to plan the next one.": "Postes pour la période sélectionnée ci-dessus. Utilisez « Ajouter une affectation » pour planifier le suivant.",
	"That planning period is not available. Showing the roster for the current period instead.": "Cette période de planification n'est pas disponible. Affichage du planning de la période en cours à la place.",
	"The roster changed — please confirm again with a new reason.": "Le planning a changé — veuillez confirmer à nouveau avec un nouveau motif.",
	"This Roster page stores shifts only. Publishing and closing happen on Periods — employees see My roster only after you publish there. Publish only when every “Must fix” issue is resolved and “Confirm to continue” items are confirmed.": "Cette page Planning stocke uniquement les postes. La publication et la clôture se font dans Périodes — les employés voient Mon planning seulement après publication. Publiez uniquement lorsque chaque problème « À corriger obligatoirement » est résolu et que les éléments « Confirmer pour continuer » sont confirmés.",
	"This exact assignment already exists. Reload the page to see the latest roster.": "Cette affectation existe déjà. Rechargez la page pour voir le dernier planning.",
	"Visible to employees on their roster.": "Visible par les employés sur leur planning.",
	"Your upcoming published duties": "Vos prochains services publiés",
}

ES_OVERRIDES = {
	" page first.": " primero.",
	"Actions": "Acciones",
	"Actor": "Interviniente",
	"Pick something short and recognisable, e.g. \"Reception desk\" or \"Warehouse - Munich\". This is what planners and employees see in every assignment.": "Elija algo breve y reconocible, p. ej. « Recepción » o « Almacén — Múnich ». Es lo que planificadores y empleados ven en cada asignación.",
	"Active – available for assignments": "Activo — disponible para asignaciones",
	"Add assignment": "Agregar asignación",
	"Adding assignments is not available right now.": "Agregar asignaciones no está disponible en este momento.",
	"Assignment ID": "ID de asignación",
	"Break": "Descanso",
	"Break and note": "Descanso y nota",
	"Breadcrumb": "Ruta de navegación",
	"Create assignment": "Crear asignación",
	"Save assignment": "Guardar asignación",
	"2. Plan duties inside it": "2. Planifique servicios dentro de él",
	"3. Plan and publish duties": "3. Planificar y publicar servicios",
	"A period is a window (e.g. a month) in which you plan duties. Pick start and end dates below — overlapping ranges are blocked automatically.": "Un período es una ventana (por ejemplo, un mes) en la que planifica servicios. Elija las fechas de inicio y fin a continuación — los rangos superpuestos se bloquean automáticamente.",
	"Absences keep the roster honest: once approved, someone cannot be scheduled on those days, and any overlapping shift shows as “Must fix” on the Roster.": "Las ausencias mantienen el cuadro de turnos fiable: una vez aprobadas, no se puede programar a alguien esos días, y cualquier turno superpuesto aparece como « Debe corregirse » en el cuadro de turnos.",
	"After approval, open the Roster — overlapping shifts appear as “Must fix” and must be changed or removed before you publish.": "Tras la aprobación, abra el cuadro de turnos — los turnos superpuestos aparecen como « Debe corregirse » y deben modificarse o eliminarse antes de publicar.",
	"Assignment saved — {employee} on {date}, {times}, at {location}. The list above is updated.": "Asignación guardada — {employee} el {date}, {times}, en {location}. La lista superior está actualizada.",
	"Automatic checks for the same period as the assignment list. “Must fix” blocks publishing until resolved. “Confirm to continue” lets you proceed after you confirm with a short reason.": "Comprobaciones automáticas para el mismo período que la lista de asignaciones. « Debe corregirse » bloquea la publicación hasta resolverlo. « Confirmar para continuar » le permite continuar tras confirmar con un breve motivo.",
	"Click “Add assignment” next to the assignments list, fill in the three steps in the dialog, and save. Violations of hard rules are blocked with a clear message.": "Haga clic en « Agregar asignación » junto a la lista de asignaciones, complete los tres pasos del diálogo y guarde. Las infracciones de reglas estrictas se bloquean con un mensaje claro.",
	"Complete setup before adding assignments. See the checklist in this dialog.": "Complete la configuración antes de agregar asignaciones. Consulte la lista de verificación en este diálogo.",
	"Complete these steps before your team can plan duties. Each item links to the right page.": "Complete estos pasos antes de que su equipo pueda planificar servicios. Cada elemento enlaza a la página correcta.",
	"Could not load roster.": "No se pudo cargar el cuadro de turnos.",
	"Could not load the roster. Reload the page or contact an administrator if this keeps happening.": "No se pudo cargar el cuadro de turnos. Vuelva a cargar la página o contacte a un administrador si el problema persiste.",
	"Done — the app can store roster data.": "Listo — la aplicación puede almacenar datos del cuadro de turnos.",
	"Employees are the people who work duties. Once added, they can be assigned in the Roster.": "Los empleados son las personas que realizan servicios. Una vez añadidos, pueden asignarse en el cuadro de turnos.",
	"Form cleared. You can enter another assignment.": "Formulario borrado. Puede introducir otra asignación.",
	"How to add a duty assignment without breaking labour rules or double-booking anyone.": "Cómo añadir una asignación de servicio sin infringir las normas laborales ni reservar a alguien dos veces.",
	"Locations are the physical or virtual places where duties happen. Each one carries a timezone so shift times stay correct year-round.": "Las ubicaciones son los lugares físicos o virtuales donde tienen lugar los servicios. Cada una tiene una zona horaria para que los horarios de turno sean correctos todo el año.",
	"Open Roster, use “Add assignment” for each shift, and resolve every “Must fix” issue before continuing.": "Abra el cuadro de turnos, use « Agregar asignación » para cada turno y resuelva cada problema « Debe corregirse » antes de continuar.",
	"Periods are listed newest first. Closed periods are read-only. Changing the period updates the list below — no full page reload.": "Los períodos se listan del más reciente al más antiguo. Los períodos cerrados son de solo lectura. Al cambiar el período se actualiza la lista inferior — sin recargar la página.",
	"Pick a planning period on the roster page first, then open the printable view again.": "Elija primero un período de planificación en la página del cuadro de turnos y luego abra de nuevo la vista imprimible.",
	"Plan assignments": "Planificar asignaciones",
	"Plan assignments with conflict-aware validation.": "Planificar asignaciones con validación consciente de conflictos.",
	"Publishing and closing happen on the Periods page. Use “Add assignment” on this page to plan shifts.": "La publicación y el cierre se realizan en la página Períodos. Use « Agregar asignación » en esta página para planificar turnos.",
	"Publishing freezes a tamper-evident snapshot and shows the roster to employees. Re-opening later is possible but always recorded with a written reason.": "La publicación congela una instantánea a prueba de manipulaciones y muestra el cuadro de turnos a los empleados. Reabrirlo más tarde es posible, pero siempre queda registrado con un motivo escrito.",
	"Select a period before adding assignments.": "Seleccione un período antes de agregar asignaciones.",
	"Setup is required before you can plan duties.": "Se requiere configuración antes de poder planificar servicios.",
	"Shifts for the period selected above. Use “Add assignment” to plan the next one.": "Turnos del período seleccionado arriba. Use « Agregar asignación » para planificar el siguiente.",
	"That planning period is not available. Showing the roster for the current period instead.": "Ese período de planificación no está disponible. Se muestra el cuadro de turnos del período actual en su lugar.",
	"The roster changed — please confirm again with a new reason.": "El cuadro de turnos cambió — confirme de nuevo con un nuevo motivo.",
	"The server could not save this assignment. Reload the page and try again, or contact an administrator.": "El servidor no pudo guardar esta asignación. Vuelva a cargar la página e inténtelo de nuevo, o contacte a un administrador.",
	"This break is filled in automatically when someone adds a new assignment. They can still change it for each shift.": "Este descanso se rellena automáticamente cuando alguien añade una nueva asignación. Aún puede cambiarse para cada turno.",
	"This exact assignment already exists. Reload the page to see the latest roster.": "Esta asignación ya existe. Vuelva a cargar la página para ver el cuadro de turnos más reciente.",
	"This value is loaded fresh whenever someone opens “Add assignment”. If the default is 0, planners may still see their last break from this browser.": "Este valor se carga de nuevo cada vez que alguien abre « Agregar asignación ». Si el valor predeterminado es 0, los planificadores pueden seguir viendo su último descanso en este navegador.",
	"You cannot add assignments for this period.": "No puede agregar asignaciones para este período.",
	"Your choice here controls the assignment list and planning checks below. Only open periods accept new assignments.": "Su elección aquí controla la lista de asignaciones y las comprobaciones de planificación siguientes. Solo los períodos abiertos aceptan nuevas asignaciones.",
}


def apply_replacements(text: str, replacements: list[tuple[str, str]]) -> str:
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
	out: dict[str, str] = {}
	for k, v in d.items():
		if k in overrides:
			out[k] = overrides[k]
		else:
			out[k] = apply_replacements(v, replacements)
	return out


def main() -> None:
	fr = json.loads((l10n_dir / "fr_dict.json").read_text(encoding="utf-8"))
	es = json.loads((l10n_dir / "es_dict.json").read_text(encoding="utf-8"))
	fr = apply(fr, FR_REPLACEMENTS, FR_OVERRIDES)
	es = apply(es, ES_REPLACEMENTS, ES_OVERRIDES)
	(l10n_dir / "fr_dict.json").write_text(json.dumps(fr, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
	(l10n_dir / "es_dict.json").write_text(json.dumps(es, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
	print("Applied production terminology fixes to fr_dict.json and es_dict.json.")


if __name__ == "__main__":
	main()
