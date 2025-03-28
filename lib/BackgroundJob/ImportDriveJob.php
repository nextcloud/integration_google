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

/**
 * A QueuedJob to partially import google drive files and launch following job
 */
final class ImportDriveJob extends QueuedJob {

	/**
	 * @param array{user_id:string} $argument
	 * @return void
	 */
	#[\Override]
	public function run($argument) {
		$userId = $argument['user_id'];
		$this->service->importDriveJob($userId);
	}
}
