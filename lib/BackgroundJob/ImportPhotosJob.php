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

use OCA\Google\Service\GooglePhotosAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * A QueuedJob to partially import google photos and launch following job
 */
class ImportPhotosJob extends QueuedJob {

	public function __construct(
		ITimeFactory $timeFactory,
		private GooglePhotosAPIService $service) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{user_id:string} $argument
	 * @return void
	 */
	public function run($argument) {
		$userId = $argument['user_id'];
		$this->service->importPhotosJob($userId);
	}
}
