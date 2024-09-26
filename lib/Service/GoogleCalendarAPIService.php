<?php
/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\Service;

use DateTime;
use DateTimeZone;
use Ds\Set;
use Exception;
use Generator;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportCalendarJob;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;

use Ortic\ColorConverter\Color;
use Ortic\ColorConverter\Colors\Named;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Service to make requests to Google v3 (JSON) API
 */
class GoogleCalendarAPIService {

	public function __construct(
		protected string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private CalDavBackend $caldavBackend,
		private IJobList $jobList,
		private GoogleAPIService $googleApiService
	) {
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $userId): array {
		$result = $this->googleApiService->request($userId, 'calendar/v3/users/me/calendarList');
		if (isset($result['error']) || !isset($result['items'])) {
			return $result;
		}
		return $result['items'];
	}

	/**
	 * @param string $userId
	 * @param string $uri
	 * @return ?int the calendar ID
	 */
	private function calendarExists(string $userId, string $uri): ?int {
		$res = $this->caldavBackend->getCalendarByUri('principals/users/' . $userId, $uri);
		return is_null($res)
			? null
			: $res['id'];
	}

	/**
	 * @param string $hexColor
	 * @return string closest CSS color name
	 */
	private function getClosestCssColor(string $hexColor): string {
		/** @var Color $color */
		$color = Color::fromString($hexColor);
		$rbgColor = [
			'r' => $color->getRed(),
			'g' => $color->getGreen(),
			'b' => $color->getBlue(),
		];
		// init
		$closestColor = 'black';
		$black = Color::fromString(Named::CSS_COLORS['black']);
		$rgbBlack = [
			'r' => $black->getRed(),
			'g' => $black->getGreen(),
			'b' => $black->getBlue(),
		];
		$closestDiff = $this->colorDiff($rbgColor, $rgbBlack);

		foreach (Named::CSS_COLORS as $name => $hex) {
			$c = Color::fromString($hex);
			$rgb = [
				'r' => $c->getRed(),
				'g' => $c->getGreen(),
				'b' => $c->getBlue(),
			];
			$diff = $this->colorDiff($rbgColor, $rgb);
			if ($diff < $closestDiff) {
				$closestDiff = $diff;
				$closestColor = $name;
			}
		}

		return $closestColor;
	}

	/**
	 * @param array{r:int, g:int, b:int} $rgb1 first color
	 * @param array{r:int, g:int, b:int} $rgb2 second color
	 *
	 * @return int the distance between colors
	 *
	 * @psalm-return 0|float|positive-int
	 */
	private function colorDiff(array $rgb1, array $rgb2): int|float {
		return (int) (abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']));
	}

	/**
	 * Get last modified timestamp from the calendar data of a calendar object
	 *
	 * @param string $calData
	 * @return int|null
	 * @throws Exception
	 */
	private function getEventLastModifiedTimestamp(string $calData): ?int {
		/** @var VCalendar $vCalendar */
		$vCalendar = Reader::read($calData);
		/** @var VEvent $vEvent */
		$vEvent = $vCalendar->{'VEVENT'};
		$iCalEvents = $vEvent->getIterator();
		foreach ($iCalEvents as $event) {
			if (isset($event->{'LAST-MODIFIED'})) {
				$lastMod = $event->{'LAST-MODIFIED'};
				if (is_string($lastMod)) {
					return (new DateTime($lastMod))->getTimestamp();
				} elseif ($lastMod instanceof \Sabre\VObject\Property\ICalendar\DateTime) {
					return $lastMod->getDateTime()->getTimestamp();
				}
			}
		}
		return null;
	}

	/**
	 * get the most recent event update date in a calendar
	 *
	 * @param int $calendarId
	 * @return int
	 */
	private function getCalendarLastEventModificationTimestamp(int $calendarId): int {
		$objects = $this->caldavBackend->getCalendarObjects($calendarId);
		$lastModifieds = array_map(static function (array $object) {
			return $object['lastmodified'] ?? 0;
		}, $objects);
		return max($lastModifieds);
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{error: string}|array{nbAdded: int, nbUpdated: int, calName: string}
	 */
	public function safeImportCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$startTime = microtime(true);
		$this->logger->debug("Starting calendar import of $calId", ['app' => $this->appName]);

		$lockFile = sys_get_temp_dir() .
			"/nextcloud_google_synchronization_calendar_import_$calId.lock";

		if (file_exists($lockFile)) {
			throw new Exception('Could not acquire lock');
		}

		touch($lockFile);

		try {
			return $this->importCalendar($userId, $calId, $calName, $color);
		} finally {
			$this->logger->debug('Elapsed time is: ' . (microtime(true) - $startTime) . ' seconds', ['app' => $this->appName]);
			try {
				unlink($lockFile);
			} catch (Exception) {
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array{nbAdded: int, nbUpdated: int, calName: string}
	 */
	public function importCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}

		$newCalName = urlencode(trim($calName) . ' (' . $this->l10n->t('Google Calendar import') .')');
		$ncCalId = $this->calendarExists($userId, $newCalName);
		$calendarIsNew = is_null($ncCalId);
		if (is_null($ncCalId)) {
			$ncCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalName, $params);
		}

		/** @var Set<string> $unseenURIs */
		$unseenURIs = new Set();
		/** @var array{uri: string} $e */
		foreach ($this->caldavBackend->getCalendarObjects($ncCalId) as $e) {
			$unseenURIs->add($e['uri']);
		}

		// get color list
		$eventColors = [];
		/** @type array{error: string}|array{event: array} $colors */
		$colors = $this->googleApiService->request($userId, 'calendar/v3/colors');
		if (!isset($colors['error']) && isset($colors['event'])) {
			$eventColors = $colors['event'];
		}

		date_default_timezone_set('UTC');
		$utcTimezone = new DateTimeZone('-0000');
		$events = $this->getCalendarEvents($userId, $calId);
		$nbAdded = 0;
		$nbUpdated = 0;

		/** @var array{id: string, start?: array{date?: string, dateTime?: string}, end?: array{date?: string, dateTime?: string}, colorId?: string, summary?: string, visibility?: string, sequence?: string, location?: string, description?: string, status?: string, created?: string, updated?: string, reminders?: array{useDefault?: bool, overrides?: list{array{minutes?: string, hours?: string, days?: string, weeks?: string}}}, recurrence?: list<string>} $e */
		foreach ($events as $e) {
			$objectUri = $e['id'];

			// If this event exists in NC, remove it from the set of events to be
			// deleted. Continue processing it, it could have been updated.
			if ($unseenURIs->contains($objectUri)) {
				$unseenURIs->remove($objectUri);
			}

			$existingEvent = null;
			// check if we should update existing events (on existing calendars only :-)
			if (!$calendarIsNew) {
				// check if it already exists and if we should update it
				$existingEvent = $this->caldavBackend->getCalendarObject($ncCalId, $objectUri);
				if ($existingEvent !== null) {
					if (!isset($e['updated'])) {
						continue;
					}
					$remoteEventUpdatedTimestamp = (new DateTime($e['updated']))->getTimestamp();
					$localEventUpdatedTimestamp = $this->getEventLastModifiedTimestamp($existingEvent['calendardata']);
					if ($localEventUpdatedTimestamp !== null && $remoteEventUpdatedTimestamp <= $localEventUpdatedTimestamp) {
						continue;
					}
				}
			}

			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. 'BEGIN:VEVENT' . "\n";

			$calData .= 'UID:' . $ncCalId . '-' . $objectUri . "\n";
			if (isset($e['colorId'], $eventColors[$e['colorId']], $eventColors[$e['colorId']]['background'])) {
				$closestCssColor = $this->getClosestCssColor($eventColors[$e['colorId']]['background']);
				$calData .= 'COLOR:' . $closestCssColor . "\n";
			}
			$calData .= isset($e['summary'])
				? ('SUMMARY:' . substr(str_replace("\n", '\n', $e['summary']), 0, 250) . "\n")
				: (($e['visibility'] ?? '') === 'private'
					? ('SUMMARY:' . $this->l10n->t('Private event') . "\n")
					: '');
			$calData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
			$calData .= isset($e['location'])
				? ('LOCATION:' . substr(str_replace("\n", '\n', $e['location']), 0, 250) . "\n")
				: '';
			$calData .= isset($e['description'])
				? ('DESCRIPTION:' . substr(str_replace("\n", '\n', $e['description']), 0, 250) . "\n")
				: '';
			$calData .= isset($e['status']) ? ('STATUS:' . strtoupper(str_replace("\n", '\n', $e['status'])) . "\n") : '';

			if (isset($e['created'])) {
				$created = new DateTime($e['created']);
				$created->setTimezone($utcTimezone);
				$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['updated'])) {
				$updated = new DateTime($e['updated']);
				$updated->setTimezone($utcTimezone);
				$calData .= 'LAST-MODIFIED:' . $updated->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['reminders'], $e['reminders']['useDefault']) && $e['reminders']['useDefault']) {
				// 15 min before, default alarm
				$calData .= 'BEGIN:VALARM' . "\n"
					. 'ACTION:DISPLAY' . "\n"
					. 'TRIGGER;RELATED=START:-PT15M' . "\n"
					. 'END:VALARM' . "\n";
			}
			if (isset($e['reminders'], $e['reminders']['overrides'])) {
				foreach ($e['reminders']['overrides'] as $o) {
					$nbMin = 0;
					if (isset($o['minutes'])) {
						$nbMin += (int) $o['minutes'];
					}
					if (isset($o['hours'])) {
						$nbMin += ((int) $o['hours']) * 60;
					}
					if (isset($o['days'])) {
						$nbMin += ((int) $o['days']) * 60 * 24;
					}
					if (isset($o['weeks'])) {
						$nbMin += ((int) $o['weeks']) * 60 * 24 * 7;
					}
					$calData .= 'BEGIN:VALARM' . "\n"
						. 'ACTION:DISPLAY' . "\n"
						. 'TRIGGER;RELATED=START:-PT' . $nbMin . 'M' . "\n"
						. 'END:VALARM' . "\n";
				}
			}

			if (isset($e['recurrence']) && is_array($e['recurrence'])) {
				foreach ($e['recurrence'] as $r) {
					$calData .= $r . "\n";
				}
			}

			if (isset($e['start'], $e['start']['date'], $e['end'], $e['end']['date'])) {
				// whole days
				$start = new DateTime($e['start']['date']);
				$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
				$end = new DateTime($e['end']['date']);
				$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
			} elseif (isset($e['start']['dateTime']) && isset($e['end']['dateTime'])) {
				$start = new DateTime($e['start']['dateTime']);
				$start->setTimezone($utcTimezone);
				$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
				$end = new DateTime($e['end']['dateTime']);
				$end->setTimezone($utcTimezone);
				$calData .= 'DTEND;VALUE=DATE-TIME:' . $end->format('Ymd\THis\Z') . "\n";
			} else {
				// skip entries without any date
				continue;
			}

			$calData .= 'CLASS:PUBLIC' . "\n"
				. 'END:VEVENT' . "\n"
				. 'END:VCALENDAR';

			if ($existingEvent !== null) {
				try {
					$this->caldavBackend->updateCalendarObject($ncCalId, $objectUri, $calData);
					$nbUpdated++;
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when updating calendar event ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			} else {
				try {
					$this->caldavBackend->createCalendarObject($ncCalId, $objectUri, $calData);
					$nbAdded++;
				} catch (BadRequest $ex) {
					if (strpos($ex->getMessage(), 'uid already exists') !== false) {
						$this->logger->debug('Skip existing event', ['app' => Application::APP_ID]);
					} else {
						$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
					}
				} catch (Exception|Throwable $ex) {
					$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}

		// Anything still unseen was deleted in Google Calendar
		// Reflect that here
		foreach ($unseenURIs as $uri) {
			$this->caldavBackend->deleteCalendarObject($ncCalId, $uri, $this->caldavBackend::CALENDAR_TYPE_CALENDAR, true);
		}

		$eventGeneratorReturn = $events->getReturn();
		if (isset($eventGeneratorReturn['error'])) {
			/* return $eventGeneratorReturn; */
		}
		return [
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
			'calName' => $newCalName,
		];
	}

	/**
	 * Delete all the registered calendar sync jobs from the database.
	 */
	public function resetRegisteredSyncCalendar(): void {
		$this->jobList->remove(ImportCalendarJob::class);
	}

	/**
	 * Check if a background job is registered.
	 * @param string $userId The user id of the job.
	 * @param string $calId The calendar id of the job.
	 * @return bool Whether the job with the given parameters is registered.
	 */
	public function isJobRegisteredForCalendar(string $userId, string $calId): bool {
		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			$args = $job->getArgument();

			if ($args["user_id"] == $userId && $args["cal_id"] == $calId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register a calendar to periodically be synced and kept up to date in the
	 * background
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return void
	 */
	public function registerSyncCalendar(string $userId, string $calId, string $calName, ?string $color = null): void {
		$argument = [
			'user_id' => $userId,
			'cal_id' => $calId,
			'cal_name' => $calName,
			'color' => $color,
		];

		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			$args = $job->getArgument();

			if ($args["user_id"] == $argument["user_id"] && $args["cal_id"] == $argument["cal_id"]) {
				$job->setArgument($argument);
				return;
			}
		}

		$this->jobList->add(ImportCalendarJob::class, $argument);
	}

	/**
	 * Unregister a calendar to periodically be synced and kept up to date in the
	 * background
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return void
	 */
	public function unregisterSyncCalendar(string $userId, string $calId): void {

		foreach ($this->jobList->getJobsIterator(ImportCalendarJob::class, null, 0) as $job) {
			/** @var array{user_id: string, cal_id: string} $args */
			$args = $job->getArgument();

			if ($args["user_id"] == $userId && $args["cal_id"] == $calId) {
				$this->jobList->remove($job, $args);
				return;
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @return Generator
	 */
	private function getCalendarEvents(string $userId, string $calId): Generator {
		$params = [
			'maxResults' => 2500,
		];
		do {
			$result = $this->googleApiService->request($userId, 'calendar/v3/calendars/'. urlencode($calId) .'/events', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['items'] as $event) {
				yield $event;
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return [];
	}
}
