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
use \OCA\DAV\CardDAV\CardDavBackend;

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
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 */
	public function getCalendarList(string $accessToken, string $userId): array {
		$params = [];
		$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList');
		return $result['items'];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 */
	public function getContactList(string $accessToken, string $userId): array {
		$contacts = [];
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
			array_push($contacts, $contact);
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['connections'] as $contact) {
				array_push($contacts, $contact);
			}
		}
		return $contacts;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 */
	public function importContacts(string $accessToken, string $userId, ?string $uri, int $key): array {
		if ($key === 0) {
			// new address book
			return ['aa'];
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
			return ['nbAdded' => $nbAdded];
		}
	}

	private function contactExists(array $contact, int $addressBookKey) {
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
	 */
	public function addCalendars(string $accessToken, string $userId): array {
		$params = [];
		$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList');
		// ical url is https://calendar.google.com/calendar/ical/br3sqt6mgpunkh2dr2p8p5obso%40group.calendar.google.com/private-640b335ca58effb904dd4570b50096eb/basic.ics
		// https://calendar.google.com/calendar/ical/ID/../basic.ics
		// in ->items : list
		// ID : URL encoded item['id']
		return $result['items'];
		//$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList/' . urlencode('br3sqt6mgpunkh2dr2p8p5obso@group.calendar.google.com'));
		//return $result;
	}

	/**
	 * Make the HTTP request
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $endPoint The path to reach in api.google.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 */
	public function request(string $accessToken, string $userId, string $endPoint, ?array $params = [], ?string $method = 'GET', ?string $baseUrl = null): array {
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
				return ['error', $this->l10n->t('Bad credentials')];
			} else {
				file_put_contents('/tmp/aa', $body);
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
			return ['error', $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 */
	public function requestOAuthAccessToken(?array $params = [], ?string $method = 'GET'): array {
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
}
