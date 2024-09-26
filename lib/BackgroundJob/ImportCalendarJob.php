<?php

namespace OCA\Google\BackgroundJob;

use \OCP\AppFramework\Utility\ITimeFactory;
use \OCP\BackgroundJob\TimedJob;

use OCA\Google\Service\GoogleCalendarAPIService;

class ImportCalendarJob extends TimedJob {

	private GoogleCalendarAPIService $service;

	public function __construct(ITimeFactory $timeFactory, GoogleCalendarAPIService $service) {
		parent::__construct($timeFactory);
		$this->service = $service;
		parent::setInterval(1);
	}

	/**
	 * @param array{user_id: string, cal_id: string, cal_name: string,color: string} $argument
	 */
	protected function run($argument): void {
		echo(date("Y-m-d H:i:s") . ' Importing ' . $argument['cal_name'] . '...');
		$result = $this->service->safeImportCalendar(
			$argument['user_id'],
			$argument['cal_id'],
			$argument['cal_name'],
			$argument['color'],
		);
		if (isset($result['error'])) {
			echo(' error: ' . $result['error'] . PHP_EOL);
		} else {
			echo(' done. Added ' . $result['nbAdded'] . PHP_EOL);
		}
	}

}
