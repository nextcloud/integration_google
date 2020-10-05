<?php

/**
 * Nextcloud - integration_google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\BackgroundJob;

use OCP\BackgroundJob\QueuedJob;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use OCP\IConfig;

use OCA\Google\AppInfo\Application;
use OCA\Google\Service\GoogleAPIService;

class ImportPhotosJob extends QueuedJob {

	private $jobList;

	/**
	 * A QueuedJob to partially import google photos and launch following job
	 *
	 * @param IJobList $jobList
	 */
	public function __construct(ITimeFactory $timeFactory,
								IJobList $jobList,
								IUserManager $userManager,
								GoogleAPIService $service,
								IConfig $config,
								LoggerInterface $logger) {
		parent::__construct($timeFactory);
		$this->jobList = $jobList;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->config = $config;
		$this->service = $service;
	}

	public function run($arguments) {
		$userId = $arguments['user_id'];
		$this->logger->debug('Importing photos for ' . $userId);
		$importingPhotos = $this->config->getUserValue($userId, Application::APP_ID, 'importing_photos', '0') === '1';
		if (!$importingPhotos) {
			return;
		}

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		$result = $this->service->importPhotos($accessToken, $userId, 'Google', 2);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_photos', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				// notify user
			}
		} else {
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_import_timestamp', $ts);
			$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_photos', '');
			$alreadyImported = $alreadyImported ? (int) $alreadyImported : 0;
			$newNbImported = $alreadyImported + $result['nbDownloaded'];
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', $newNbImported);
			$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
		}
	}
}
