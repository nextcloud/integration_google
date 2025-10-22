<?php

namespace OCA\Google\Service;

use OCA\Google\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class SecretService {
	public function __construct(
		private IUserConfig $userConfig,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param string $value
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	public function setEncryptedUserValue(string $userId, string $key, string $value): void {
		if ($value === '') {
			$this->userConfig->setValueString($userId, Application::APP_ID, $key, '', lazy: true);
			return;
		}
		$encryptedValue = $this->crypto->encrypt($value);
		$this->userConfig->setValueString($userId, Application::APP_ID, $key, $encryptedValue, lazy: true);
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @return string
	 * @throws \Exception
	 */
	public function getEncryptedUserValue(string $userId, string $key): string {
		$storedValue = $this->userConfig->getValueString($userId, Application::APP_ID, $key, lazy: true);
		if ($storedValue === '') {
			return '';
		}
		return $this->crypto->decrypt($storedValue);
	}
}
