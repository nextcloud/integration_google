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

use OCP\IL10N;
use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\DAV\Exception\BadRequest;
use Psr\Log\LoggerInterface;

use OCA\Google\AppInfo\Application;

class GoogleCalendarAPIService {

	private $l10n;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								CalDavBackend $caldavBackend,
								GoogleAPIService $googleApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->caldavBackend = $caldavBackend;
		$this->googleApiService = $googleApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $accessToken, string $userId): array {
		$params = [];
		$result = $this->googleApiService->request($accessToken, $userId, 'calendar/v3/users/me/calendarList');
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
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return array
	 */
	public function importCalendar(string $accessToken, string $userId, string $calId, string $calName, ?string $color = null): array {
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}

		$newCalName = trim($calName) . ' (' . $this->l10n->t('Google Calendar import') .')';
		$ncCalId = $this->calendarExists($userId, $newCalName);
		if (is_null($ncCalId)) {
			$ncCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalName, $params);
		}

		date_default_timezone_set('UTC');
		$utcTimezone = new \DateTimeZone('-0000');
		$events = $this->getCalendarEvents($accessToken, $userId, $calId);
		$nbAdded = 0;
		foreach ($events as $e) {
			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. 'BEGIN:VEVENT' . "\n";

			$objectUri = $e['id'] . '-' . $e['etag'];
			$calData .= 'UID:' . $ncCalId . '-' . $objectUri . "\n";
			$calData .= isset($e['summary']) ? ('SUMMARY:' . str_replace("\n", '\n', $e['summary']) . "\n") : '';
			$calData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
			$calData .= isset($e['location']) ? ('LOCATION:' . str_replace("\n", '\n', $e['location']) . "\n") : '';
			$calData .= isset($e['description']) ? ('DESCRIPTION:' . str_replace("\n", '\n', $e['description']) . "\n") : '';
			$calData .= isset($e['status']) ? ('STATUS:' . strtoupper(str_replace("\n", '\n', $e['status'])) . "\n") : '';

			if (isset($e['created'])) {
				$created = new \Datetime($e['created']);
				$created->setTimezone($utcTimezone);
				$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['updated'])) {
				$updated = new \Datetime($e['updated']);
				$updated->setTimezone($utcTimezone);
				$calData .= 'LAST-MODIFIED:' . $created->format('Ymd\THis\Z') . "\n";
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
				$start = new \Datetime($e['start']['date']);
				$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
				$end = new \Datetime($e['end']['date']);
				$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
			} elseif (isset($e['start']['dateTime']) && isset($e['end']['dateTime'])) {
				$start = new \Datetime($e['start']['dateTime']);
				$start->setTimezone($utcTimezone);
				$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
				$end = new \Datetime($e['end']['dateTime']);
				$end->setTimezone($utcTimezone);
				$calData .= 'DTEND;VALUE=DATE-TIME:' . $end->format('Ymd\THis\Z') . "\n";
			} else {
				// skip entries without any date
				continue;
			}

			$calData .= 'CLASS:PUBLIC' . "\n"
				. 'END:VEVENT' . "\n"
				. 'END:VCALENDAR';

			try {
				$this->caldavBackend->createCalendarObject($ncCalId, $objectUri, $calData);
				$nbAdded++;
			} catch (BadRequest $ex) {
				if (strpos($ex->getMessage(), 'uid already exists') !== false) {
					$this->logger->info('Skip existing event "' . ($e['summary'] ?? 'no title') . '"', ['app' => $this->appName]);
				} else {
					$this->logger->warning('Error when creating calendar event "' . ($e['summary'] ?? 'no title') . '" ' . $ex->getMessage(), ['app' => $this->appName]);
				}
			} catch (\Exception | \Throwable $ex) {
				$this->logger->warning('Error when creating calendar event "' . ($e['summary'] ?? 'no title') . '" ' . $ex->getMessage(), ['app' => $this->appName]);
			}
		}

		$eventGeneratorReturn = $events->getReturn();
		if (isset($eventGeneratorReturn['error'])) {
			return $eventGeneratorReturn;
		}
		return [
			'nbAdded' => $nbAdded,
			'calName' => $newCalName,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $calId
	 * @return \Generator
	 */
	private function getCalendarEvents(string $accessToken, string $userId, string $calId): \Generator {
		$params = [
			'maxResults' => 100,
		];
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'calendar/v3/calendars/'.$calId.'/events', $params);
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
