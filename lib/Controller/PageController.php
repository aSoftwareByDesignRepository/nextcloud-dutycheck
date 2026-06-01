<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\LocaleFormatService;
use OCA\DutyCheck\Service\RosterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private AccessControlService $access,
		private LocaleFormatService $localeFormat,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private RosterService $roster,
		private IArbeitszeitCheckIntegration $arbeitszeitCheckIntegration,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse
	{
		$userId = $this->access->currentUserId();
		$route = $this->access->isPlannerOrAdmin($userId)
			? 'dutycheck.page.dashboard'
			: 'dutycheck.page.myRoster';
		return new RedirectResponse($this->urlGenerator->linkToRoute($route));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page('dashboard', 'dashboard', $this->l10n->t('Coverage, conflicts, and publish-readiness at a glance.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function roster(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page('roster', 'roster', $this->l10n->t('Plan assignments with conflict-aware validation.'));
	}

	/**
	 * Printable roster grid for the selected period. DutyCheck / Nextcloud administrators only.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rosterPrint(): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		$this->access->requireAppAdmin($userId);
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'roster-print');

		$raw = $this->request->getParam('periodId');
		$periodId = is_numeric($raw) ? (int) $raw : 0;
		$backUrl = $this->urlGenerator->linkToRoute('dutycheck.page.roster');

		if ($periodId <= 0) {
			$response = new TemplateResponse(Application::APP_ID, 'roster-print-error', [
				'message' => $this->l10n->t('Pick a planning period on the roster page first, then open the printable view again.'),
				'backUrl' => $backUrl,
				'htmlLang' => (string) (($this->localeFormat->clientHints())['htmlLang'] ?? 'en-US'),
			]);
			$response->setStatus(400);
			$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
			return $response;
		}

		try {
			$bundle = $this->roster->rosterExportBundle($periodId);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() === 'PERIOD_NOT_FOUND') {
				$response = new TemplateResponse(Application::APP_ID, 'roster-print-error', [
					'message' => $this->l10n->t('That planning period no longer exists.'),
					'backUrl' => $backUrl,
					'htmlLang' => (string) (($this->localeFormat->clientHints())['htmlLang'] ?? 'en-US'),
				]);
				$response->setStatus(404);
				$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
				return $response;
			}
			throw $e;
		}

		$this->roster->logRosterDataExport($periodId, $userId, 'print_html', [
			'assignmentCount' => count($bundle['assignments']),
		]);

		$hints = $this->localeFormat->clientHints();
		$generated = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$response = new TemplateResponse(Application::APP_ID, 'roster-print', [
			'pageTitle' => $this->l10n->t('Printable roster'),
			'period' => $bundle['period'],
			'assignments' => $bundle['assignments'],
			'generatedAtUtcIso' => $generated->format('Y-m-d\TH:i:s\Z'),
			'generatedAtUtcDisplay' => $generated->format('Y-m-d H:i:s') . ' UTC',
			'rosterUrl' => $this->urlGenerator->linkToRoute('dutycheck.page.roster', ['periodId' => $periodId]),
			'htmlLang' => (string) ($hints['htmlLang'] ?? 'en-US'),
		]);
		$response->renderAs(TemplateResponse::RENDER_AS_BLANK);
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function periods(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page('periods', 'periods', $this->l10n->t('Manage period lifecycle: open, publish, close, and re-open.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employees(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page('employees', 'employees', $this->l10n->t('Manage employee records and account links.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function locations(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		$hints = $this->localeFormat->clientHints();
		return $this->page(
			'locations',
			'locations',
			$this->l10n->t('Manage locations, timezones, and shift templates.'),
			[
				'defaultTimezone' => (string) ($hints['timezone'] ?? 'UTC'),
			],
		);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function absences(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page('absences', 'absences', $this->l10n->t('Review, approve, reject, and cancel absences.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myRoster(): TemplateResponse
	{
		$this->access->requireEmployee($this->access->currentUserId());
		return $this->page('my-roster', 'my-roster', $this->l10n->t('Browse your published shifts by date range and keep a private calendar subscription in sync.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function myAbsences(): TemplateResponse
	{
		$this->access->requireEmployee($this->access->currentUserId());
		return $this->page('my-absences', 'my-absences', $this->l10n->t('See your requests first, then send a new one if you need time off.'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page('settings', 'settings', $this->l10n->t('App policy, privacy, and access controls.'));
	}

	private function page(string $template, string $script, string $help, array $extra = []): TemplateResponse
	{
		$userId = $this->access->currentUserId();
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/session');
		Util::addScript(Application::APP_ID, 'common/dates');
		Util::addScript(Application::APP_ID, 'common/messaging');
		Util::addScript(Application::APP_ID, 'common/conflict-labels');
		Util::addScript(Application::APP_ID, 'common/components');
		if ($template === 'locations') {
			Util::addScript(Application::APP_ID, 'common/timezone-picker');
		}
		Util::addScript(Application::APP_ID, $script);

		$isEmployee = $this->access->isEmployee($userId);
		$hasLinkedEmployee = $this->access->hasActiveLinkedEmployee($userId);
		$isAppAdmin = $this->access->isAppAdmin($userId);
		$isPlannerOrAdmin = $this->access->isPlannerOrAdmin($userId);
		if ($isAppAdmin) {
			$role = AccessControlService::ROLE_ADMIN;
			$roleLabel = $this->l10n->t('Administrator');
		} elseif ($isPlannerOrAdmin && $isEmployee) {
			$role = 'planner_employee';
			$roleLabel = $this->l10n->t('Planner & employee');
		} elseif ($isPlannerOrAdmin) {
			$role = AccessControlService::ROLE_PLANNER;
			$roleLabel = $this->l10n->t('Planner');
		} elseif ($hasLinkedEmployee && !$isEmployee) {
			$role = 'self_service';
			$roleLabel = $this->l10n->t('Roster access');
		} else {
			$role = AccessControlService::ROLE_EMPLOYEE;
			$roleLabel = $this->l10n->t('Employee');
		}

		$integrationBootstrap = $this->arbeitszeitCheckIntegration->buildBootstrapForUser($userId, $hasLinkedEmployee);
		$integrationBootstrapJson = htmlspecialchars(
			json_encode(
				$integrationBootstrap,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
			),
			ENT_QUOTES,
			'UTF-8',
		);

		return new TemplateResponse(Application::APP_ID, $template, array_merge([
			'pageId' => $template,
			'pageTitle' => $this->titleForPage($template),
			'pageHelp' => $help,
			'currentUserId' => $userId,
			'isEmployee' => $isEmployee,
			'hasLinkedEmployee' => $hasLinkedEmployee,
			'isAppAdmin' => $isAppAdmin,
			'isPlannerOrAdmin' => $isPlannerOrAdmin,
			'role' => $role,
			'roleLabel' => $roleLabel,
			'clientHints' => $this->localeFormat->clientHints(),
			'localeFormat' => $this->localeFormat,
			'integrationBootstrapJson' => $integrationBootstrapJson,
			'integrationLocksLinkedDutyCheckAbsences' => (bool)($integrationBootstrap['integrationLocksLinkedDutyCheckAbsences'] ?? false),
			'readonlyAbsencesForCurrentUser' => (bool)($integrationBootstrap['readonlyAbsencesForCurrentUser'] ?? false),
			'urls' => [
				'dashboard' => $this->urlGenerator->linkToRoute('dutycheck.page.dashboard'),
				'roster' => $this->urlGenerator->linkToRoute('dutycheck.page.roster'),
				'periods' => $this->urlGenerator->linkToRoute('dutycheck.page.periods'),
				'employees' => $this->urlGenerator->linkToRoute('dutycheck.page.employees'),
				'locations' => $this->urlGenerator->linkToRoute('dutycheck.page.locations'),
				'absences' => $this->urlGenerator->linkToRoute('dutycheck.page.absences'),
				'myRoster' => $this->urlGenerator->linkToRoute('dutycheck.page.myRoster'),
				'myAbsences' => $this->urlGenerator->linkToRoute('dutycheck.page.myAbsences'),
				'settings' => $this->urlGenerator->linkToRoute('dutycheck.page.settings'),
				'home' => $this->urlGenerator->linkToDefaultPageUrl(),
				'rosterExportCsv' => $this->urlGenerator->linkToRoute('dutycheck.rosterApi.exportRosterCsv'),
				'rosterPrint' => $this->urlGenerator->linkToRoute('dutycheck.page.rosterPrint'),
			],
		], $extra));
	}

	private function titleForPage(string $pageId): string
	{
		return match ($pageId) {
			'dashboard' => $this->l10n->t('Dashboard'),
			'roster' => $this->l10n->t('Roster'),
			'periods' => $this->l10n->t('Periods'),
			'employees' => $this->l10n->t('Employees'),
			'locations' => $this->l10n->t('Locations'),
			'absences' => $this->l10n->t('Absences'),
			'my-roster' => $this->l10n->t('My roster'),
			'my-absences' => $this->l10n->t('My absences'),
			'settings' => $this->l10n->t('Settings'),
			default => $this->l10n->t('DutyCheck'),
		};
	}
}
