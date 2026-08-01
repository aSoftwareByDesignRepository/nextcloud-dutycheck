<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Portfolio rule (planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md
 * §1 "Never ask humans to type raw IDs"): humans must never be asked to type a raw
 * Nextcloud user id. Pins the settings sub-pages + settings.js so a free-text
 * "Nextcloud user id" / "Planner user ID" field, or an "allowDirectEntry" typed-id
 * fallback, cannot silently reappear on the company-member, planner-scope,
 * app-policy, or duty-role surfaces.
 */
final class DirectoryPickerContractTest extends TestCase
{
	private function settingsPartPhp(string $name): string
	{
		return (string) file_get_contents(dirname(__DIR__, 3) . '/templates/parts/settings/' . $name);
	}

	private function settingsJs(): string
	{
		return (string) file_get_contents(dirname(__DIR__, 3) . '/js/settings.js');
	}

	private function allSettingsPartsPhp(): string
	{
		$dir = dirname(__DIR__, 3) . '/templates/parts/settings';
		$files = glob($dir . '/*.php') ?: [];
		$combined = '';
		foreach ($files as $file) {
			$combined .= (string) file_get_contents($file) . "\n";
		}
		return $combined;
	}

	public function testSettingsMarkupNeverMentionsARawUserIdLabel(): void
	{
		$html = $this->allSettingsPartsPhp();
		self::assertStringNotContainsString('Nextcloud user id', $html);
		self::assertStringNotContainsString('Planner user ID', $html);
	}

	public function testSettingsMarkupNeverAsksForAnExactIdViaHintCopy(): void
	{
		self::assertStringNotContainsString('press Enter to add an exact', $this->allSettingsPartsPhp());
		self::assertStringNotContainsString('press Enter to add an exact', $this->settingsJs());
	}

	public function testCompanyMemberFieldIsASearchPickerNotAFreeTextUserIdInput(): void
	{
		$html = $this->settingsPartPhp('companies.php');

		self::assertMatchesRegularExpression(
			'/<input\s+id="dc-company-member-user-search"\s+type="search"/',
			$html,
			'Company member picker must be a search box, not a free-text id field.',
		);
		self::assertStringContainsString('id="dc-company-member-user-results"', $html);
		// The picked uid is committed to a hidden field the search fills in — never a visible text box a human types into.
		self::assertMatchesRegularExpression(
			'/<input\s+type="hidden"\s+id="dc-company-member-user"\s+name="userId">/',
			$html,
		);
		self::assertDoesNotMatchRegularExpression(
			'/id="dc-company-member-user"[^>]*\stype="text"/',
			$html,
		);
	}

	public function testPlannerScopeFieldIsASearchPickerNotAFreeTextUserIdInput(): void
	{
		$html = $this->settingsPartPhp('planner-scope.php');

		self::assertMatchesRegularExpression(
			'/<input\s+id="dc-scope-user-search"\s+type="search"/',
			$html,
			'Planner scope picker must be a search box, not a free-text id field.',
		);
		self::assertStringContainsString('id="dc-scope-user-results"', $html);
		self::assertMatchesRegularExpression(
			'/<input\s+type="hidden"\s+id="dc-scope-user"\s+name="userId">/',
			$html,
		);
		self::assertDoesNotMatchRegularExpression(
			'/id="dc-scope-user"[^>]*\stype="text"/',
			$html,
		);
	}

	public function testColleaguePickersAreLabelledColleagueNotAnIdField(): void
	{
		$labelPattern = '/<label class="dc-field__label" for="%s"><\?php p\(\$l->t\(\x27Colleague\x27\)\); \?><\/label>/';

		$companies = $this->settingsPartPhp('companies.php');
		self::assertMatchesRegularExpression(
			sprintf($labelPattern, 'dc-company-member-user-search'),
			$companies,
			'dc-company-member-user-search must be labelled Colleague, not a raw user-id field.',
		);

		$scope = $this->settingsPartPhp('planner-scope.php');
		self::assertMatchesRegularExpression(
			sprintf($labelPattern, 'dc-scope-user-search'),
			$scope,
			'dc-scope-user-search must be labelled Colleague, not a raw user-id field.',
		);
	}

	public function testPolicyAndDutyRolePickersDoNotAllowDirectEntry(): void
	{
		self::assertStringNotContainsString('allowDirectEntry: true', $this->settingsJs());
	}

	public function testCompanyMemberAndScopeFormsWireDirectorySearchOnly(): void
	{
		$js = $this->settingsJs();

		self::assertMatchesRegularExpression(
			"/wireSearch\\('dc-company-member-user-search',\\s*'dc-company-member-user-results',\\s*fetchUsers/",
			$js,
			'Company member form must resolve uids via the shared directory search (fetchUsers/wireSearch).',
		);
		self::assertMatchesRegularExpression(
			"/wireSearch\\('dc-scope-user-search',\\s*'dc-scope-user-results',\\s*fetchUsers/",
			$js,
			'Planner scope form must resolve uids via the shared directory search (fetchUsers/wireSearch).',
		);
	}

	public function testCompanyMemberSubmitRejectsBlankHiddenUidBeforePosting(): void
	{
		// Guards against a regression where the submit handler posts the raw (possibly
		// empty/typed) form field value directly instead of the value the picker committed.
		$js = $this->settingsJs();
		self::assertMatchesRegularExpression(
			"/const userId = String\\(memberForm\\.userId\\?\\.value \\|\\| ''\\)\\.trim\\(\\);\\s*\\n\\s*if \\(!userId\\) \\{/",
			$js,
		);
		self::assertMatchesRegularExpression(
			"/const userId = String\\(form\\.userId\\.value \\|\\| ''\\)\\.trim\\(\\);\\s*\\n\\s*if \\(!userId\\) \\{/",
			$js,
		);
	}

	public function testEmployeesNavHintDoesNotSayUserIds(): void
	{
		$nav = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/common/navigation.php');
		self::assertStringContainsString('Directory and linked Nextcloud accounts', $nav);
		self::assertStringNotContainsString('linked user IDs', $nav);
	}

}
