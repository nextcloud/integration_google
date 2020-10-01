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
use OCP\IConfig;
use OCP\ILogger;
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;
use OCP\Contacts\IManager as IContactManager;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property\Text;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;

use OCA\Google\AppInfo\Application;

class GoogleAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								ILogger $logger,
								IL10N $l10n,
								IConfig $config,
								IContactManager $contactsManager,
								CardDavBackend $cdBackend,
								CalDavBackend $caldavBackend,
								IRootFolder $root,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->caldavBackend = $caldavBackend;
		$this->root = $root;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @return array
	 */
	public function importPhotos(string $accessToken, string $userId, string $targetPath = 'Google', ?int $maxDownloadNumber = null): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Google folder'];
			}
		}

		$albums = [];
		$params = [
			'pageSize' => 50,
		];
		$result = $this->request($accessToken, $userId, 'v1/albums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['albums'] as $album) {
			$albums[] = $album;
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'v1/albums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['albums'] as $album) {
				$albums[] = $album;
			}
		}

		$nbDownloaded = 0;
		foreach ($albums as $album) {
			$albumId = $album['id'];
			$albumName = $album['title'];
			if (!$folder->nodeExists($albumName)) {
				$albumFolder = $folder->newFolder($albumName);
			} else {
				$albumFolder = $folder->get($albumName);
				if ($albumFolder->getType() !== FileInfo::TYPE_FOLDER) {
					return ['error' => 'Impossible to create album folder'];
				}
			}

			$params = [
				'pageSize' => 100,
				'albumId' => $albumId,
			];
			$result = $this->request($accessToken, $userId, 'v1/mediaItems:search', $params, 'POST', 'https://photoslibrary.googleapis.com/');
			foreach ($result['mediaItems'] as $photo) {
				if ($this->getPhoto($accessToken, $userId, $photo, $albumFolder)) {
					$nbDownloaded++;
					if ($maxDownloadNumber && $nbDownloaded === $maxDownloadNumber) {
						return [
							'nbDownloaded' => $nbDownloaded,
							'targetPath' => $targetPath,
						];
					}
				}
			}
			while (isset($result['nextPageToken'])) {
				$params['pageToken'] = $result['nextPageToken'];
				$result = $this->request($accessToken, $userId, 'v1/mediaItems:search', $params, 'POST', 'https://photoslibrary.googleapis.com/');
				if (isset($result['error'])) {
					return $result;
				}
				foreach ($result['mediaItems'] as $photo) {
					if ($this->getPhoto($accessToken, $userId, $photo, $albumFolder)) {
						$nbDownloaded++;
						if ($maxDownloadNumber && $nbDownloaded === $maxDownloadNumber) {
							return [
								'nbDownloaded' => $nbDownloaded,
								'targetPath' => $targetPath,
							];
						}
					}
				}
			}
		}
		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
		];
	}

	private function getPhoto(string $accessToken, string $userId, array $photo, Node $albumFolder): bool {
		$photoName = $photo['filename'];
		if (!$albumFolder->nodeExists($photoName)) {
			$photoUrl = $photo['baseUrl'];
			$res = $this->simpleRequest($accessToken, $userId, $photoUrl);
			if (!isset($res['error'])) {
				$albumFolder->newFile($photoName, $res['content']);
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getPhotoNumber(string $accessToken, string $userId): array {
		$nbPhotos = 0;
		$params = [
			'pageSize' => 100,
		];
		$result = $this->request($accessToken, $userId, 'v1/mediaItems', $params, 'GET', 'https://photoslibrary.googleapis.com/');
		if (isset($result['error'])) {
			return $result;
		}
		$nbPhotos += count($result['mediaItems']);
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'v1/mediaItems', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			$nbPhotos += count($result['mediaItems']);
		}
		// get free space
		$userFolder = $this->root->getUserFolder($userId);
		$freeSpace = $userFolder->getStorage()->free_space('/');
		return [
			'nbPhotos' => $nbPhotos,
			'freeSpace' => $freeSpace,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getContactNumber(string $accessToken, string $userId): array {
		$nbContacts = 0;
		$params = [
			'personFields' => implode(',', [
				'names',
			]),
			'pageSize' => 100,
		];
		$result = $this->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
		if (isset($result['error'])) {
			return $result;
		}
		$nbContacts += count($result['connections']);
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			$nbContacts += count($result['connections']);
		}
		return ['nbContacts' => $nbContacts];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return \Generator
	 */
	public function getContactList(string $accessToken, string $userId): \Generator {
		$params = [
			'personFields' => implode(',', [
				'addresses',
				'birthdays',
				'emailAddresses',
				'genders',
				'metadata',
				'names',
				'nicknames',
				'organizations',
				'phoneNumbers',
				'photos',
				'relations',
				'residences',
			]),
			'pageSize' => 100,
		];
		$result = $this->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['connections'] as $contact) {
			yield $contact;
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['connections'] as $contact) {
				yield $contact;
			}
		}
		return [];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $uri
	 * @param string $key
	 * @param ?string $newAddrBookName
	 * @return array
	 */
	public function importContacts(string $accessToken, string $userId, ?string $uri, int $key, ?string $newAddrBookName): array {
		if ($key === 0) {
			$key = $this->cdBackend->createAddressBook('principals/users/' . $userId, $newAddrBookName, []);
		} else {
			// existing address book
			// check if it exists
			$addressBooks = $this->contactsManager->getUserAddressBooks();
			$addressBook = null;
			foreach ($addressBooks as $k => $ab) {
				if ($ab->getUri() === $uri && intval($ab->getKey()) === $key) {
					$addressBook = $ab;
					break;
				}
			}
			if (!$addressBook) {
				return ['error' => 'no such address book'];
			}
		}

		$contacts = $this->getContactList($accessToken, $userId);
		$nbAdded = 0;
		foreach ($contacts as $k => $c) {
			// avoid existing contacts
			if ($this->contactExists($c, $key)) {
				continue;
			}
			$vCard = new VCard();

			$displayName = null;
			$familyName = null;
			$firstName = null;
			// we just take first name
			foreach ($c['names'] as $n) {
				$displayName = $n['displayName'];
				$familyName = $n['familyName'];
				$firstName = $n['givenName'];
				if ($displayName) {
					$prop = $vCard->createProperty('FN', $displayName);
					$vCard->add($prop);
				}
				if ($familyName || $firstName) {
					$prop = $vCard->createProperty('N', [0 => $familyName, 1 => $firstName, 2 => '', 3 => '', 4 => '']);
					$vCard->add($prop);
				}
				break;
			}
			// we don't want empty names
			if (!$displayName && !$familyName && !$firstName) {
				return true;
			}

			// address
			foreach ($c['addresses'] as $address) {
				$streetAddress = $address['streetAddress'];
				$extendedAddress = $address['extendedAddress'];
				$postalCode = $address['postalCode'];
				$city = $address['city'];
				$addrType = $address['type'];
				$country = $address['country'];
				$postOfficeBox = $address['poBox'];

				$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
				$addrProp = $vCard->createProperty('ADR',
					[0 => $postOfficeBox, 1 => $extendedAddress, 2 => $streetAddress, 3 => $city, 4 => '', 5 => $postalCode, 6 => $country, 'TYPE' => $addressType],
					$type
				);
				$vCard->add($addrProp);
			}

			// birthday
			foreach ($c['birthdays'] as $birthday) {
				$date = new \Datetime($birthday['date']['year'] . '-' . $birthday['date']['month'] . '-' . $birthday['date']['day']);
				$strDate = $date->format('Ymd');

				$type = ['VALUE' => 'DATE'];
				$prop = $vCard->createProperty('BDAY', $strDate, $type);
				$vCard->add($prop);
			}

			foreach ($c['nicknames'] as $nick) {
				$prop = $vCard->createProperty('NICKNAME', $nick['value']);
				$vCard->add($prop);
			}

			foreach ($c['emailAddresses'] as $email) {
				$addrType = $email['type'];
				$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
				$prop = $vCard->createProperty('EMAIL', $email['value'], $type);
				$vCard->add($prop);
			}

			foreach ($c['phoneNumbers'] as $ph) {
				$numberType = str_replace('mobile', 'cell', $ph['type']);
				$numberType = str_replace('main', '', $numberType);
				$numberType = $numberType ? $numberType : 'home';
				$type = ['TYPE' => strtoupper($numberType)];
				$prop = $vCard->createProperty('TEL', $ph['value'], $type);
				$vCard->add($prop);
			}

			// we just take first org
			foreach ($c['organizations'] as $org) {
				$name = $org['name'];
				if ($name) {
					$prop = $vCard->createProperty('ORG', $name);
					$vCard->add($prop);
				}

				$title = $org['title'];
				if ($title) {
					$prop = $vCard->createProperty('TITLE', $title);
					$vCard->add($prop);
				}
				break;
			}

			$this->cdBackend->createCard($key, 'goog' . $k, $vCard->serialize());
			$nbAdded++;
		}
		$contactGeneratorReturn = $contacts->getReturn();
		if (isset($contactGeneratorReturn['error'])) {
			return $contactGeneratorReturn;
		}
		return ['nbAdded' => $nbAdded];
	}

	/**
	 * @param array $contact
	 * @param int $addressBookKey
	 * @return bool
	 */
	private function contactExists(array $contact, int $addressBookKey): bool {
		$displayName = null;
		$familyName = null;
		$firstName = null;
		foreach ($contact['names'] as $n) {
			$displayName = $n['displayName'];
			$familyName = $n['familyName'];
			$firstName = $n['givenName'];
			break;
		}
		if ($displayName) {
			$searchResult = $this->contactsManager->search($displayName, ['FN']);
			foreach ($searchResult as $resContact) {
				if ($resContact['FN'] === $displayName) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getCalendarList(string $accessToken, string $userId): array {
		//$events = $this->getCalendarEvents($accessToken, $userId, 'ju-ggl@cassio.pe');
		//$ee = [];
		//foreach ($events as $e) {
		//	array_push($ee, $e);
		//}
		//return $ee;

		$params = [];
		$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList');
		// ical url is https://calendar.google.com/calendar/ical/br3sqt6mgpunkh2dr2p8p5obso%40group.calendar.google.com/private-640b335ca58effb904dd4570b50096eb/basic.ics
		// https://calendar.google.com/calendar/ical/ID/../basic.ics
		// in ->items : list
		// ID : URL encoded item['id']
		// !! problem is there is no way to get the 'private-...' string except with the web interface :-)
		return $result['items'];
	}

	/**
	 * @param string $userId
	 * @param string $uri
	 * @return bool
	 */
	private function calendarExists(string $userId, string $uri): bool {
		$res = $this->caldavBackend->getCalendarByUri('principals/users/' . $userId, $uri);
		return !is_null($res);
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
		$calSuffix = 0;
		$newCalName = $calName;
		while ($this->calendarExists($userId, $newCalName)) {
			$calSuffix++;
			$newCalName = $calName . '-' . $calSuffix;
		}
		$params = [];
		if ($color) {
			$params['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}
		$newCalId = $this->caldavBackend->createCalendar('principals/users/' . $userId, $newCalName, $params);

		date_default_timezone_set('UTC');
		$events = $this->getCalendarEvents($accessToken, $userId, $calId);
		$nbAdded = 0;
		foreach ($events as $e) {
			$calData = 'BEGIN:VCALENDAR' . "\n"
				. 'VERSION:2.0' . "\n"
				. 'PRODID:NextCloud Calendar' . "\n"
				. 'BEGIN:VEVENT' . "\n";

			$calData .= 'UID:' . $newCalId . '-' . $nbAdded . "\n";
			$calData .= 'SUMMARY:' . $e['summary'] . "\n";
			$calData .= 'SEQUENCE:' . $e['sequence'] . "\n";
			$calData .= 'LOCATION:' . $e['location'] . "\n";
			$calData .= 'DESCRIPTION:' . $e['description'] . "\n";
			$calData .= 'STATUS:' . strtoupper($e['status']) . "\n";

			$created = new \Datetime($e['created']);
			$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";

			$updated = new \Datetime($e['updated']);
			$calData .= 'LAST-MODIFIED:' . $created->format('Ymd\THis\Z') . "\n";

			if (isset($e['reminders']) && $e['reminders']['useDefault']) {
				// 30 min before
				$calData .= 'BEGIN:VALARM' . "\n"
					. 'ACTION:DISPLAY' . "\n"
					. 'TRIGGER;RELATED=START:-PT15M' . "\n"
					. 'END:VALARM' . "\n";
			}
			if (isset($e['reminders']) && isset($e['reminders']['overrides'])) {
				foreach ($e['reminders']['overrides'] as $o) {
					$nbMin = 0;
					if (isset($o['minutes'])) {
						$nbMin += $o['minutes'];
					}
					if (isset($o['hours'])) {
						$nbMin += $o['hours'] * 60;
					}
					if (isset($o['days'])) {
						$nbMin += $o['days'] * 60 * 24;
					}
					if (isset($o['weeks'])) {
						$nbMin += $o['weeks'] * 60 * 24 * 7;
					}
					$calData .= 'BEGIN:VALARM' . "\n"
						. 'ACTION:DISPLAY' . "\n"
						. 'TRIGGER;RELATED=START:-PT'.$nbMin.'M' . "\n"
						. 'END:VALARM' . "\n";
				}
			}

			if (isset($e['recurrence'])) {
				foreach ($e['recurrence'] as $r) {
					$calData .= $r . "\n";
				}
			}

			if (isset($e['start']['date']) && isset($e['end']['date'])) {
				// whole days
				$start = new \Datetime($e['start']['date']);
				$calData .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\n";
				$end = new \Datetime($e['end']['date']);
				$calData .= 'DTEND;VALUE=DATE:' . $end->format('Ymd') . "\n";
			} elseif (isset($e['start']['dateTime']) && isset($e['end']['dateTime'])) {
				$start = new \Datetime($e['start']['dateTime']);
				$calData .= 'DTSTART;VALUE=DATE-TIME:' . $start->format('Ymd\THis\Z') . "\n";
				$end = new \Datetime($e['end']['dateTime']);
				$calData .= 'DTEND;VALUE=DATE-TIME:' . $end->format('Ymd\THis\Z') . "\n";
			}

			$calData .= 'CLASS:PUBLIC' . "\n"
				. 'END:VEVENT' . "\n"
				. 'END:VCALENDAR';

			$this->caldavBackend->createCalendarObject($newCalId, $nbAdded, $calData);
			$nbAdded++;
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
		$result = $this->request($accessToken, $userId, 'calendar/v3/calendars/'.$calId.'/events');
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['items'] as $event) {
			yield $event;
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'calendar/v3/calendars/'.$calId.'/events');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['items'] as $event) {
				yield $event;
			}
		}
		return [];
	}

	/**
	 * Make the HTTP request
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $endPoint The path to reach in api.google.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param ?string $baseUrl
	 * @return array
	 */
	public function request(string $accessToken, string $userId,
							string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null): array {
		try {
			$url = $baseUrl ? $baseUrl : 'https://www.googleapis.com/';
			$url = $url . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ClientException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), array('app' => $this->appName));
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			if (strpos($body, 'Request had invalid authentication credentials') !== false) {
				// refresh token if it's invalid and we are using oauth
				$this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
				$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
				$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
				$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
				$result = $this->requestOAuthAccessToken([
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					return $this->request(
						$accessToken, $userId, $endPoint, $params, $method, $baseUrl
					);
				}
			}
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array
	 */
	public function requestOAuthAccessToken(array $params = [], string $method = 'GET'): array {
		try {
			$url = 'https://oauth2.googleapis.com/token';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Google OAuth error : '.$e->getMessage(), array('app' => $this->appName));
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make a simple authenticated HTTP request
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $url The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array
	 */
	public function simpleRequest(string $accessToken, string $userId, string $url, array $params = [], string $method = 'GET'): array {
		try {
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return ['content' => $body];
			}
		} catch (ClientException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), array('app' => $this->appName));
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			if (strpos($body, 'Request had invalid authentication credentials') !== false) {
				// refresh token if it's invalid and we are using oauth
				$this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
				$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
				$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
				$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
				$result = $this->requestOAuthAccessToken([
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					return $this->simpleRequest(
						$accessToken, $userId, $url, $params, $method
					);
				}
			}
			return ['error' => $e->getMessage()];
		}
	}
}
