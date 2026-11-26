<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\DutyCheck\Tests\Unit\Agent;

use OCA\DutyCheck\Agent\AgentException;
use OCA\DutyCheck\Agent\AgentScopeChecker;
use PHPUnit\Framework\TestCase;

/**
 * Scope vocabulary is exact-match only — the checks an attacker would
 * probe first (wildcards, prefix tricks, case games) must all fail closed.
 */
class AgentScopeCheckerTest extends TestCase
{
	public function testValidFormatsAccepted(): void
	{
		$this->assertTrue(AgentScopeChecker::isValidFormat('dutycheck:time_entries:read'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('dutycheck:invoices:write'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('a1_b2:c3_d4:write'));
	}

	public function testInvalidFormatsRejected(): void
	{
		$cases = [
			'',
			'dutycheck:time_entries',                 // missing action
			'dutycheck:time_entries:read:extra',      // too many segments
			'dutycheck:Time_Entries:READ',            // case games
			'dutycheck:*:read',                       // wildcard resource
			'dutycheck:time_entries:*',               // wildcard action
			'*:*:*',
			'dutycheck:*',                            // app-wide wildcard
			'dutycheck :time_entries:read',           // embedded space
			"dutycheck:time_entries:read\n",          // control chars
			'dutycheck:time-entries:read',            // dash not allowed
			'dutycheck::read',                        // empty resource
			':time_entries:read',                      // empty app
			'dutycheck:time_entries:delete',          // v1 action set is read|write only
		];
		foreach ($cases as $scope) {
			$this->assertFalse(
				AgentScopeChecker::isValidFormat($scope),
				"scope should be invalid: " . var_export($scope, true)
			);
		}
	}

	public function testPrefixGrantIsNotSatisfaction(): void
	{
		// granted scope that is a string-prefix of the requirement must NOT
		// satisfy — exact match is the whole security model.
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['dutycheck:time_ent'],
				'dutycheck:time_entries:write',
			)
		);
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['dutycheck:time_entries:write_extra'],
				'dutycheck:time_entries:write',
			)
		);
	}

	public function testReadDoesNotSatisfyWrite(): void
	{
		$granted = ['dutycheck:time_entries:read'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'dutycheck:time_entries:write'),
			'read scope must never satisfy a write requirement'
		);
		$this->assertTrue(AgentScopeChecker::satisfied($granted, 'dutycheck:time_entries:read'));
	}

	public function testPrefixMatchIsNotSatisfaction(): void
	{
		$granted = ['dutycheck:time_entr:write', 'dutycheck:time_entries_extended:write'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'dutycheck:time_entries:write'),
			'prefix/partial matches must not satisfy'
		);
	}

	public function testValidateGrantRequestDeduplicatesAndValidates(): void
	{
		$declared = ['dutycheck:a:read', 'dutycheck:b:write'];
		$grants = AgentScopeChecker::validateGrantRequest(
			['dutycheck:a:read', ' dutycheck:a:read ', 'dutycheck:b:write'],
			$declared,
		);
		$this->assertSame(['dutycheck:a:read', 'dutycheck:b:write'], $grants);
	}

	public function testValidateGrantRequestRejectsUndeclared(): void
	{
		$this->expectException(AgentException::class);
		AgentScopeChecker::validateGrantRequest(
			['dutycheck:secret:read'],
			['dutycheck:a:read'],
		);
	}

	public function testValidateGrantRequestRejectsWildcard(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['dutycheck:*:read'], ['dutycheck:a:read']);
			$this->fail('wildcard grant must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsEmpty(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['  '], ['dutycheck:a:read']);
			$this->fail('empty grant list must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsNonString(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest([['nested']], ['dutycheck:a:read']);
			$this->fail('non-string scope must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}
}
