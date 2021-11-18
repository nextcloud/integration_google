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

use Datetime;
use DateTimeZone;
use Exception;
use Generator;
use OCP\IL10N;
use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\DAV\Exception\BadRequest;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../vendor/autoload.php';
use Ortic\ColorConverter\Color;
use Ortic\ColorConverter\Colors\Named;
use Throwable;

class GoogleCalendarAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var CalDavBackend
	 */
	private $caldavBackend;
	/**
	 * @var GoogleAPIService
	 */
	private $googleApiService;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								CalDavBackend $caldavBackend,
								GoogleAPIService $googleApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->caldavBackend = $caldavBackend;
		$this->googleApiService = $googleApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $accessToken, string $userId): array {
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
	 * @param string $hexColor
	 * @return string closest CSS color name
	 */
	private function getClosestCssColor(string $hexColor): string {
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
	 * @param array $rgb1 first color
	 * @param array $rgb2 second color
	 * @return int the distance between colors
	 */
	private function colorDiff(array $rgb1, array $rgb2): int {
		return abs($rgb1['r'] - $rgb2['r']) + abs($rgb1['g'] - $rgb2['g']) + abs($rgb1['b'] - $rgb2['b']);
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

		// get color list
		$eventColors = [];
		$colors = $this->googleApiService->request($accessToken, $userId, 'calendar/v3/colors');
		if (!isset($colors['error']) && isset($colors['event'])) {
			$eventColors = $colors['event'];
		}

		date_default_timezone_set('UTC');
		$utcTimezone = new DateTimeZone('-0000');
		$events = $this->getCalendarEvents($accessToken, $userId, $calId);
		$nbAdded = 0;
		foreach ($events as $e) {
			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. 'BEGIN:VEVENT' . "\n";

			$objectUri = $e['id'] . '-' . $e['etag'];
			$calData .= 'UID:' . $ncCalId . '-' . $objectUri . "\n";
			if (isset($e['colorId'], $eventColors[$e['colorId']], $eventColors[$e['colorId']]['background'])) {
				$closestCssColor = $this->getClosestCssColor($eventColors[$e['colorId']]['background']);
				$calData .= 'COLOR:' . $closestCssColor . "\n";
			}
			$calData .= isset($e['summary'])
				? ('SUMMARY:' . substr(str_replace("\n", '\n', $e['summary']), 0, 250) . "\n")
				: ($e['visibility'] ?? '' === 'private'
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
				$created = new Datetime($e['created']);
				$created->setTimezone($utcTimezone);
				$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['updated'])) {
				$updated = new Datetime($e['updated']);
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
				$start = new Datetime($e['start']['date']);
				$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
				$end = new Datetime($e['end']['date']);
				$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
			} elseif (isset($e['start']['dateTime']) && isset($e['end']['dateTime'])) {
				$start = new Datetime($e['start']['dateTime']);
				$start->setTimezone($utcTimezone);
				$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
				$end = new Datetime($e['end']['dateTime']);
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
					$this->logger->debug('Skip existing event', ['app' => $this->appName]);
				} else {
					$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => $this->appName]);
				}
			} catch (Exception | Throwable $ex) {
				$this->logger->warning('Error when creating calendar event "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => $this->appName]);
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
	 * @return Generator
	 */
	private function getCalendarEvents(string $accessToken, string $userId, string $calId): Generator {
		$params = [
			'maxResults' => 100,
		];
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'calendar/v3/calendars/'. urlencode($calId) .'/events', $params);
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
