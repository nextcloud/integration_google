<?php

namespace OCA\Google\Settings;

use OCA\Google\AppInfo\Application;
use OCA\Google\Service\SecretService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

final class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private SecretService $secretService,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	#[\Override]
	public function getForm(): TemplateResponse {
		$clientID = $this->secretService->getEncryptedAppValue('client_id');
		$clientSecret = $this->secretService->getEncryptedAppValue('client_secret');
		$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0');

		$adminConfig = [
			'client_id' => $clientID,
			'client_secret' => $clientSecret === '' ? $clientSecret : 'dummySecret',
			'use_popup' => ($usePopup === '1'),
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	#[\Override]
	public function getSection(): string {
		return 'connected-accounts';
	}

	#[\Override]
	public function getPriority(): int {
		return 10;
	}
}
