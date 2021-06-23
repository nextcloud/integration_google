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
use OCP\AppFramework\Utility\ITimeFactory;

use OCA\Google\Service\GoogleDriveAPIService;

class ImportDriveJob extends QueuedJob {
	/**
	 * @var GoogleDriveAPIService
	 */
	private $service;

	/**
	 * A QueuedJob to partially import google drive files and launch following job
	 *
	 */
	public function __construct(ITimeFactory $timeFactory,
								GoogleDriveAPIService $service) {
		parent::__construct($timeFactory);
		$this->service = $service;
	}

	public function run($arguments) {
		$userId = $arguments['user_id'];
		$this->service->importDriveJob($userId);
	}
}
