<?php

declare(strict_types=1);

namespace OCA\DutyCheck\Middleware;

use OCA\DutyCheck\AppInfo\Application;
use OCA\DutyCheck\Exception\AppAccessDeniedException;
use OCA\DutyCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private IUserSession $userSession,
		private AccessControlService $accessControl,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\DutyCheck\\Controller\\')) {
			return;
		}
		// Token-authenticated calendar feed must work even if the browser still has an NC
		// session for a user who no longer passes canUseApp() (subscription URLs are shared
		// with calendar clients that reuse the browser context).
		if ($methodName === 'publicIcal') {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}
		if ($this->accessControl->canUseApp($user->getUID())) {
			return;
		}
		throw new AppAccessDeniedException($this->accessControl->denialReasonWhenCannotUseApp($user->getUID()));
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}

		$path = (string)($this->request->getPathInfo() ?? '');
		$isApi = str_contains($path, '/api/') || $this->request->getMethod() !== 'GET';
		if ($isApi) {
			$reason = $exception->getDenialReason();
			$code = match ($reason) {
				AccessControlService::DENIAL_INSUFFICIENT_ROLE => 'INSUFFICIENT_ROLE',
				AccessControlService::DENIAL_EMPLOYEE_NOT_LINKED => 'EMPLOYEE_RECORD_LINK_REQUIRED',
				default => 'access_denied',
			};
			return new JSONResponse([
				'ok' => false,
				'error' => ['code' => $code],
			], Http::STATUS_FORBIDDEN);
		}

		$l = $this->l10nFactory->get(Application::APP_ID);
		$reason = $exception->getDenialReason();
		[$message, $hint] = match ($reason) {
			AccessControlService::DENIAL_RESTRICTION => [
				$l->t('You are currently not allowed to use DutyCheck based on directory restrictions.'),
				$l->t('If your organisation restricts DutyCheck, ask an administrator to add you to the allowed users or groups in Settings.'),
			],
			AccessControlService::DENIAL_INSUFFICIENT_ROLE => [
				$l->t('You do not have access to this DutyCheck area. Use the navigation to return to a page that matches your role, or ask an administrator if you need a different role.'),
				$l->t('If you believe this is a mistake, contact your DutyCheck administrator and ask to be granted a planner or employee role.'),
			],
			AccessControlService::DENIAL_EMPLOYEE_NOT_LINKED => [
				$l->t('DutyCheck is unavailable until your Nextcloud account is linked to an active employee record.'),
				$l->t('You already have the employee role, but no row in the Employees catalog references your user yet. Ask a planner or DutyCheck administrator to link your account there.'),
			],
			default => [
				$l->t('You are currently not linked to DutyCheck. Ask an administrator to assign your role.'),
				$l->t('If you believe this is a mistake, contact your DutyCheck administrator and ask to be granted a planner or employee role.'),
			],
		};

		$response = new TemplateResponse(Application::APP_ID, 'access-denied', [
			'message' => $message,
			'hint' => $hint,
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
		]);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}
}
