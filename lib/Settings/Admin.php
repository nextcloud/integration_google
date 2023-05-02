<?php

namespace OCA\Google\Settings;

use OCA\Google\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0');

		$adminConfig = [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'use_popup' => ($usePopup === '1'),
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
