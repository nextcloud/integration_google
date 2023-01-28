<?php

/**
 * Nextcloud - google_synchronization
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

	/**
	 * @param array{user_id:string} $argument
	 * @return void
	 */
	public function run($argument) {
		$userId = $argument['user_id'];
		$this->service->importDriveJob($userId);
	}
}
