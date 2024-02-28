<?php

namespace OCA\Google\BackgroundJob;

use \OCP\AppFramework\Utility\ITimeFactory;
use \OCP\BackgroundJob\TimedJob;

use OCA\Google\Service\GoogleCalendarAPIService;

class ImportCalendarJob extends TimedJob {

	private $service;

	public function __construct(ITimeFactory $timeFactory, GoogleCalendarAPIService $service) {
		parent::__construct($timeFactory);
		$this->service = $service;
		parent::setInterval(1);
	}

	protected function run($arguments): void {
		echo(date("Y-m-d H:i:s") . ' Importing ' . $arguments['cal_name'] . '...');
		$result = $this->service->safeImportCalendar(
			$arguments['user_id'],
			$arguments['cal_id'],
			$arguments['cal_name'],
			$arguments['color'],
		);
		echo(' done. Added ' . $result['nbAdded'] . PHP_EOL);
	}

}
