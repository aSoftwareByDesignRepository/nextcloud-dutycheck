<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Controller;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Integration\IArbeitszeitCheckIntegration;
use OCA\DutyCheck\Service\AccessControlService;
use OCA\DutyCheck\Service\CompanyService;
use OCA\DutyCheck\Service\LicenseService;
use OCA\DutyCheck\Service\LicenseUiStrings;
use OCA\DutyCheck\Service\LocaleFormatService;
use OCA\DutyCheck\Service\RosterService;
use OCA\DutyCheck\Service\SettingsSectionCatalog;
use OCA\DutyCheck\Support\SupportUsLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
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
		private CompanyService $companies,
		private IArbeitszeitCheckIntegration $arbeitszeitCheckIntegration,
		private SettingsSectionCatalog $settingsSections,
		private LicenseService $licenseService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse|TemplateResponse
	{
		$userId = $this->access->currentUserId();
		if ($this->access->needsRoleEnrollment($userId)) {
			return $this->needsRolePage();
		}
		$route = $this->access->isPlannerOrAdmin($userId)
			? 'dutycheck.page.dashboard'
			: 'dutycheck.page.myRoster';
		return new RedirectResponse($this->urlGenerator->linkToRoute($route));
	}

	/**
	 * Calm enrollment shell (HTTP 200): door open, membership still required.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function needsRole(): TemplateResponse|RedirectResponse
	{
		$userId = $this->access->currentUserId();
		if (!$this->access->needsRoleEnrollment($userId)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('dutycheck.page.index'));
		}
		return $this->needsRolePage();
	}

	private function needsRolePage(): TemplateResponse
	{
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		$l = $this->l10n;
		return new TemplateResponse(Application::APP_ID, 'needs-role', [
			'l' => $l,
			'message' => $l->t('You are not enrolled in DutyCheck yet.'),
			'hint' => $l->t('Ask a DutyCheck administrator to link your account as an employee or assign you a planner role before you can use rosters and absences.'),
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return $this->page(
			'dashboard',
			'dashboard',
			$this->l10n->t('Coverage, conflicts, and publish-readiness at a glance.'),
			$this->dashboardPageExtras(),
		);
	}

	/**
	 * First-paint KPI/pulse payload for the dashboard HTML shell.
	 * Revalidation GET is skipped when this JSON is present (same request-age data).
	 *
	 * @return array{dashboardSummary: ?array<string, mixed>, dashboardSummaryJson: string}
	 */
	private function dashboardPageExtras(): array
	{
		try {
			$summary = $this->roster->dashboardSummary($this->access->currentUserId());
			$json = htmlspecialchars(
				json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
				ENT_QUOTES,
				'UTF-8',
			);
			return [
				'dashboardSummary' => $summary,
				'dashboardSummaryJson' => $json,
			];
		} catch (\Throwable) {
			return [
				'dashboardSummary' => null,
				'dashboardSummaryJson' => '',
			];
		}
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
			$this->roster->assertPeriodCompanyAccess($userId, $periodId);
			$bundle = $this->roster->rosterExportBundle($periodId);
		} catch (\InvalidArgumentException $e) {
			if (in_array($e->getMessage(), ['PERIOD_NOT_FOUND', 'NOT_FOUND', 'FORBIDDEN'], true)) {
				$response = new TemplateResponse(Application::APP_ID, 'roster-print-error', [
					'message' => $this->l10n->t('That planning period no longer exists.'),
					'backUrl' => $backUrl,
					'htmlLang' => (string) (($this->localeFormat->clientHints())['htmlLang'] ?? 'en-US'),
				]);
				$response->setStatus($e->getMessage() === 'FORBIDDEN' ? 403 : 404);
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
			'snapshotHash' => $bundle['snapshotHash'] ?? null,
			'snapshotKind' => $bundle['snapshotKind'] ?? null,
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

	/**
	 * Legacy single-page settings URL — now redirects to the default sub-page.
	 * The route (and its name, dutycheck.page.settings) is kept so every old
	 * bookmark and cross-app link keeps resolving.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'dutycheck.page.settingsSection',
			['section' => SettingsSectionCatalog::DEFAULT_SECTION],
		));
	}

	/**
	 * One settings sub-page per former section (DeskCheck pattern).
	 *
	 * The route requirement already restricts {section} to the allowlist; the
	 * catalog check below is defense in depth so a route change can never open
	 * an unvalidated template dispatch.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): Response
	{
		$this->access->requirePlannerOrAdmin($this->access->currentUserId());
		$section = strtolower(trim($section));
		if (!$this->settingsSections->isSection($section)) {
			return new NotFoundResponse();
		}

		$extra = [
			'settingsSection' => $section,
			'pageTitle' => $this->settingsSections->label($this->l10n, $section),
		];
		if ($section === 'license') {
			$extra = array_merge($extra, $this->licenseSettingsExtras());
			Util::addStyle(Application::APP_ID, 'license-settings');
			Util::addScript(Application::APP_ID, 'license-settings');
		}
		if ($section === 'support') {
			$extra['supportUsLinks'] = new SupportUsLinks(
				'DutyCheck',
				true,
				$this->urlGenerator->linkToRouteAbsolute(
					'dutycheck.page.settingsSection',
					['section' => 'license'],
				) . '#dutycheck-license',
			);
		}

		return $this->page(
			'settings',
			'settings',
			$this->settingsSections->help($this->l10n, $section),
			$extra,
		);
	}

	/**
	 * License panel data for the license sub-page. Failures inside the license
	 * subsystem must never take the settings shell down, so status/seats fall
	 * back to null and the panel renders its "not configured" state.
	 *
	 * @return array<string, mixed>
	 */
	private function licenseSettingsExtras(): array
	{
		$licenseStatus = null;
		$licenseSeatsList = null;
		try {
			$licenseStatus = $this->licenseService->status();
			$licenseSeatsList = $this->licenseService->listSeats(50, 0);
		} catch (\Throwable) {
			$licenseStatus = null;
			$licenseSeatsList = null;
		}
		$licenseApiUrl = $this->urlGenerator->linkToRouteAbsolute('dutycheck.license.show');
		$licenseSeatsUrl = $this->urlGenerator->linkToRouteAbsolute('dutycheck.license.seats');
		return [
			'licenseStatus' => $licenseStatus,
			'licenseSeatsList' => $licenseSeatsList,
			'licenseI18n' => LicenseUiStrings::forPanel($this->l10n),
			'licenseApiUrl' => $licenseApiUrl,
			'licenseClearUrl' => $licenseApiUrl,
			'licenseSeatsUrl' => $licenseSeatsUrl,
			'licenseAssignSeatUrl' => $licenseSeatsUrl,
			'licenseRemoveSeatBase' => rtrim($licenseSeatsUrl, '/') . '/',
			'licenseSearchUsersUrl' => $this->urlGenerator->linkToRouteAbsolute('dutycheck.license.searchUsers'),
			'requesttoken' => Util::callRegister(),
		];
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
		if (in_array($template, ['dashboard', 'roster', 'periods'], true)) {
			Util::addScript(Application::APP_ID, 'common/conflict-labels');
		}
		Util::addScript(Application::APP_ID, 'common/components');
		Util::addScript(Application::APP_ID, 'common/app-feedback');
		// Soft keyboard / visualViewport: keep focused notes & inputs above the IME on phones.
		Util::addScript(Application::APP_ID, 'common/keep-focused-visible');
		if ($template === 'locations') {
			Util::addScript(Application::APP_ID, 'common/timezone-picker');
		}
		if ($template === 'roster') {
			Util::addScript(Application::APP_ID, 'common/virtual-window');
		}
		if (in_array($template, ['employees', 'locations', 'absences'], true)) {
			Util::addScript(Application::APP_ID, 'common/virtual-window');
			Util::addScript(Application::APP_ID, 'common/windowed-table');
		}
		if ($template === 'settings') {
			Util::addScript(Application::APP_ID, 'settings-legacy-redirect');
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

		$settingsSectionUrls = [];
		foreach (SettingsSectionCatalog::SECTIONS as $sectionId) {
			$settingsSectionUrls[$sectionId] = $this->urlGenerator->linkToRoute(
				'dutycheck.page.settingsSection',
				['section' => $sectionId],
			);
		}
		$settingsSectionLabels = [];
		foreach (SettingsSectionCatalog::SECTIONS as $sectionId) {
			// Short chip / sidebar labels; page H1 still uses label() via $extra.
			$settingsSectionLabels[$sectionId] = $this->settingsSections->navLabel($this->l10n, $sectionId);
		}

		$breadcrumbParent = null;
		if (($extra['settingsSection'] ?? '') !== '') {
			$breadcrumbParent = [
				'label' => $this->l10n->t('Settings'),
				'url' => $this->urlGenerator->linkToRoute('dutycheck.page.settings'),
			];
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

		$companyAccessDenied = $isPlannerOrAdmin && !$this->companies->hasCompanyMembership($userId);

		return new TemplateResponse(Application::APP_ID, $template, array_merge([
			'pageId' => $template,
			'pageTitle' => $this->titleForPage($template),
			'pageHelp' => $help,
			'breadcrumbParent' => $breadcrumbParent,
			'settingsSectionLabels' => $settingsSectionLabels,
			'currentUserId' => $userId,
			'isEmployee' => $isEmployee,
			'hasLinkedEmployee' => $hasLinkedEmployee,
			'isAppAdmin' => $isAppAdmin,
			'isPlannerOrAdmin' => $isPlannerOrAdmin,
			'companyAccessDenied' => $companyAccessDenied,
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
				'settingsSections' => $settingsSectionUrls,
				'companiesSettings' => $this->urlGenerator->linkToRoute(
					'dutycheck.page.settingsSection',
					['section' => 'companies'],
				),
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
