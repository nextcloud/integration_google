<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Google\Migration;

use Closure;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version04003001Date20251022113940 extends SimpleMigrationStep {

	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// migrate api credentials in app config to sensitive instead of manually encrypting and decrypting
		foreach (['client_id', 'client_secret'] as $key) {
			$value = $this->appConfig->getAppValueString($key, lazy: true);
			if ($value === '') {
				continue;
			}
			$value = $this->crypto->decrypt($value);
			$this->appConfig->setAppValueString($key, $value, lazy: true, sensitive: true);
		}
	}
}
