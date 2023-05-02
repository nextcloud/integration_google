<?php
/**
 * Nextcloud - Google
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\AppInfo;

use OCA\Google\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Notification\IManager as INotificationManager;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_google';
	// consider that a job is not running anymore after N seconds
	public const IMPORT_JOB_TIMEOUT = 3600;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$manager = $container->get(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
