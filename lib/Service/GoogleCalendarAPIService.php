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
use Exception;
use Generator;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Google\AppInfo\Application;
use OCP\IConfig;
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
		string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private CalDavBackend $caldavBackend,
		private GoogleAPIService $googleApiService,
		private IConfig $config,
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
		/** @var Color $color */
		$black = Color::fromString(Named::CSS_COLORS['black']);
		$rgbBlack = [
			'r' => $black->getRed(),
			'g' => $black->getGreen(),
			'b' => $black->getBlue(),
		];
		$closestDiff = $this->colorDiff($rbgColor, $rgbBlack);

		foreach (Named::CSS_COLORS as $name => $hex) {
			/** @var Color $color */
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
	 */
	private function colorDiff(array $rgb1, array $rgb2): int|float {
		return abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']);
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
	 * @return array
	 */
	public function importCalendar(string $userId, string $calId, string $calName, ?string $color = null): array {
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}

		$newCalName = trim($calName) . ' (' . $this->l10n->t('Google Calendar import') . ')';
		$params['{DAV:}displayname'] = $newCalName;
		$newCalUri = urlencode($newCalName);

		$ncCalId = $this->calendarExists($userId, $newCalUri);
		$calendarIsNew = is_null($ncCalId);
		if (is_null($ncCalId)) {
			$ncCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalUri, $params);
		}

		// get color list
		$eventColors = [];
		$colors = $this->googleApiService->request($userId, 'calendar/v3/colors');
		if (!isset($colors['error']) && isset($colors['event'])) {
			$eventColors = $colors['event'];
		}

		date_default_timezone_set('UTC');
		$utcTimezone = new DateTimeZone('-0000');
		$allEvents = $this->config->getUserValue($userId, Application::APP_ID, 'consider_all_events', '1') === '1';
		$events = $this->getCalendarEvents($userId, $calId, $allEvents);
		$nbAdded = 0;
		$nbUpdated = 0;
		/** @var array{id: string, start?: array{date?: string, dateTime?: string, timeZone?: string}, end?: array{date?: string, dateTime?: string, timeZone?: string}, colorId?: string, summary?: string, visibility?: string, sequence?: string, location?: string, description?: string, status?: string, created?: string, updated?: string, reminders?: array{useDefault?: bool, overrides?: list{array{minutes?: string, hours?: string, days?: string, weeks?: string}}}, recurrence?: list<string>} $e */
		foreach ($events as $e) {
			$objectUri = $e['id'];

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

					$localEventUpdatedTimestamp = $existingEvent['lastmodified'] ?? 0;
					if ($remoteEventUpdatedTimestamp <= $localEventUpdatedTimestamp) {
						continue;
					}

					//// in case we don't trust the calendar object's 'lastmodified' attr,
					//// we can check the event real modification date in the ical data
					//$localEventUpdatedTimestamp = $this->getEventLastModifiedTimestamp($existingEvent['calendardata']);
					//if ($localEventUpdatedTimestamp === null || $remoteEventUpdatedTimestamp <= $localEventUpdatedTimestamp) {
					//	continue;
					//}
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
						$nbMin += (int)$o['minutes'];
					}
					if (isset($o['hours'])) {
						$nbMin += ((int)$o['hours']) * 60;
					}
					if (isset($o['days'])) {
						$nbMin += ((int)$o['days']) * 60 * 24;
					}
					if (isset($o['weeks'])) {
						$nbMin += ((int)$o['weeks']) * 60 * 24 * 7;
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

			if (isset($e['start'], $e['start']['dateTime'], $e['end'], $e['end']['dateTime'])) {
				$start = new DateTime($e['start']['dateTime']);
				$end = new DateTime($e['end']['dateTime']);

				if (isset($e['start']['timeZone'], $e['end']['timeZone'])) {
					$timezoneStart = $e['start']['timeZone'];
					$start->setTimezone(new DateTimeZone($timezoneStart));
					$calData .= "DTSTART;TZID=$timezoneStart:" . $start->format('Ymd\THis') . "\n";
					$timezoneEnd = $e['end']['timeZone'];
					$end->setTimezone(new DateTimeZone($timezoneEnd));
					$calData .= "DTEND;TZID=$timezoneEnd:" . $end->format('Ymd\THis') . "\n";
				} else {
					$start->setTimezone($utcTimezone);
					$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
					$end->setTimezone($utcTimezone);
					$calData .= 'DTEND;VALUE=DATE-TIME:' . $end->format('Ymd\THis\Z') . "\n";
				}
			} elseif (isset($e['start'], $e['start']['date'], $e['end'], $e['end']['date'])) {
				// whole days
				$start = new DateTime($e['start']['date']);
				$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
				$end = new DateTime($e['end']['date']);
				$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
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

		$eventGeneratorReturn = $events->getReturn();
		if (isset($eventGeneratorReturn['error'])) {
			return $eventGeneratorReturn;
		}
		return [
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
			'calName' => $newCalName,
		];
	}

	/**
	 * @param string $userId
	 * @param string $calId
	 * @param bool $allEvents
	 * @return Generator
	 */
	private function getCalendarEvents(string $userId, string $calId, bool $allEvents): Generator {
		$params = [
			'maxResults' => 2500,
		];
		if (!$allEvents) {
			$params['eventTypes'] = 'default';
		}
		do {
			$result = $this->googleApiService->request($userId, 'calendar/v3/calendars/' . urlencode($calId) . '/events', $params);
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
