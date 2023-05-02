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

use OCA\Google\Service\GoogleDriveAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * A QueuedJob to partially import google drive files and launch following job
 */
class ImportDriveJob extends QueuedJob {

	public function __construct(
		ITimeFactory $timeFactory,
		private GoogleDriveAPIService $service) {
		parent::__construct($timeFactory);
	}

	public function run($arguments) {
		$userId = $arguments['user_id'];
		$this->service->importDriveJob($userId);
	}
}
