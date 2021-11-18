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
use Exception;
use Generator;
use OCP\Contacts\IManager as IContactManager;
use Sabre\VObject\Component\VCard;
use OCA\DAV\CardDAV\CardDavBackend;
use Psr\Log\LoggerInterface;
use Throwable;

class GoogleContactsAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IContactManager
	 */
	private $contactsManager;
	/**
	 * @var CardDavBackend
	 */
	private $cdBackend;
	/**
	 * @var GoogleAPIService
	 */
	private $googleApiService;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IContactManager $contactsManager,
								CardDavBackend $cdBackend,
								GoogleAPIService $googleApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->googleApiService = $googleApiService;
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
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			$nbContacts += count($result['connections'] ?? []);
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return ['nbContacts' => $nbContacts];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return Generator
	 */
	public function getContactList(string $accessToken, string $userId): Generator {
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
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['connections']) && is_array($result['connections'])) {
				foreach ($result['connections'] as $contact) {
					yield $contact;
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return [];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param ?string $uri
	 * @param int $key
	 * @param ?string $newAddrBookName
	 * @return array
	 */
	public function importContacts(string $accessToken, string $userId, ?string $uri, int $key, ?string $newAddrBookName): array {
		if ($key === 0) {
			$addressBooks = $this->contactsManager->getUserAddressBooks();
			foreach ($addressBooks as $k => $ab) {
				if ($ab->getDisplayName() === $newAddrBookName) {
					$key = intval($ab->getKey());
					break;
				}
			}
			if ($key === 0) {
				$key = $this->cdBackend->createAddressBook('principals/users/' . $userId, $newAddrBookName, []);
			}
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
			if (!isset($c['names']) || !is_array($c['names'])) {
				continue;
			}
			foreach ($c['names'] as $n) {
				$displayName = $n['displayName'] ?? '';
				$familyName = $n['familyName'] ?? '';
				$firstName = $n['givenName'] ?? '';
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
				continue;
			}

			// photo
			if (isset($c['photos']) && is_array($c['photos'])) {
				foreach ($c['photos'] as $photo) {
					if (isset($photo['url'])) {
						// determine photo type
						$type = '';
						if (preg_match('/\.jpg$/i', $photo['url']) || preg_match('/\.jpeg$/i', $photo['url'])) {
							$type = 'JPEG';
						} elseif (preg_match('/\.png$/i', $photo['url'])) {
							$type = 'PNG';
						}
						if ($type !== '') {
							$photoFile = $this->googleApiService->simpleRequest($accessToken, $userId, $photo['url']);
							if (!isset($photoFile['error'])) {
								$b64Photo = stripslashes('data:image/' . strtolower($type) . ';base64\,') . base64_encode($photoFile['content']);
								try {
									$prop = $vCard->createProperty(
										'PHOTO',
										$b64Photo,
										[
											'type' => $type,
											// 'encoding' => 'b',
										]
									);
									$vCard->add($prop);
								} catch (Exception | Throwable $ex) {
									$this->logger->warning('Error when setting contact photo "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => $this->appName]);
								}
								break;
							}
						}
					}
				}
			}

			// address
			if (isset($c['addresses']) && is_array($c['addresses'])) {
				foreach ($c['addresses'] as $address) {
					$streetAddress = $address['streetAddress'] ?? '';
					$extendedAddress = $address['extendedAddress'] ?? '';
					$postalCode = $address['postalCode'] ?? '';
					$city = $address['city'] ?? '';
					$addrType = $address['type'] ?? '';
					$country = $address['country'] ?? '';
					$postOfficeBox = $address['poBox'] ?? '';

					$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
					$addrProp = $vCard->createProperty('ADR',
						[0 => $postOfficeBox, 1 => $extendedAddress, 2 => $streetAddress, 3 => $city, 4 => '', 5 => $postalCode, 6 => $country],
						$type
					);
					$vCard->add($addrProp);
				}
			}

			// birthday
			if (isset($c['birthdays']) && is_array($c['birthdays'])) {
				foreach ($c['birthdays'] as $birthday) {
					if (isset($birthday['date'], $birthday['date']['year'], $birthday['date']['month'], $birthday['date']['day'])) {
						$date = new Datetime($birthday['date']['year'] . '-' . $birthday['date']['month'] . '-' . $birthday['date']['day']);
						$strDate = $date->format('Ymd');

						$type = ['VALUE' => 'DATE'];
						$prop = $vCard->createProperty('BDAY', $strDate, $type);
						$vCard->add($prop);
					} elseif (isset($birthday['date'], $birthday['date']['month'], $birthday['date']['day'])) {
						$type = ['VALUE' => 'DATE'];
						$month = $birthday['date']['month'];
						$month = strlen($month) === 2 ? $month : '0' . $month;
						$day = $birthday['date']['day'];
						$day = strlen($day) === 2 ? $day : '0' . $day;
						if (strlen($month) === 2 && strlen($day) === 2) {
							$prop = $vCard->createProperty('BDAY', '--' . $month . $day, $type);
							$vCard->add($prop);
						}
					} elseif (isset($birthday['text']) && is_string($birthday['text'])) {
						$type = ['VALUE' => 'text'];
						$prop = $vCard->createProperty('BDAY', $birthday['text'], $type);
						$vCard->add($prop);
					}
				}
			}

			if (isset($c['nicknames']) && is_array($c['nicknames'])) {
				foreach ($c['nicknames'] as $nick) {
					if (isset($nick['value'])) {
						$prop = $vCard->createProperty('NICKNAME', $nick['value']);
						$vCard->add($prop);
					}
				}
			}

			if (isset($c['emailAddresses']) && is_array($c['emailAddresses'])) {
				foreach ($c['emailAddresses'] as $email) {
					if (isset($email['value'])) {
						$addrType = $email['type'] ?? '';
						$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
						$prop = $vCard->createProperty('EMAIL', $email['value'], $type);
						$vCard->add($prop);
					}
				}
			}

			if (isset($c['phoneNumbers']) && is_array($c['phoneNumbers'])) {
				foreach ($c['phoneNumbers'] as $ph) {
					if (isset($ph['value'])) {
						$numberType = str_replace('mobile', 'cell', $ph['type'] ?? '');
						$numberType = str_replace('main', '', $numberType);
						$numberType = $numberType ?: 'home';
						$type = ['TYPE' => strtoupper($numberType)];
						$prop = $vCard->createProperty('TEL', $ph['value'], $type);
						$vCard->add($prop);
					}
				}
			}

			// we just take first org
			if (isset($c['organizations']) && is_array($c['organizations'])) {
				foreach ($c['organizations'] as $org) {
					$name = $org['name'] ?? '';
					if ($name) {
						$prop = $vCard->createProperty('ORG', $name);
						$vCard->add($prop);
					}

					$title = $org['title'] ?? '';
					if ($title) {
						$prop = $vCard->createProperty('TITLE', $title);
						$vCard->add($prop);
					}
					break;
				}
			}

			try {
				$this->cdBackend->createCard($key, 'goog' . $k, $vCard->serialize());
				$nbAdded++;
			} catch (Throwable | Exception $e) {
				$this->logger->warning('Error when creating contact', ['app' => $this->appName]);
			}
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
		// $familyName = null;
		// $firstName = null;
		if (isset($contact['names']) && is_array($contact['names'])) {
			foreach ($contact['names'] as $n) {
				$displayName = $n['displayName'] ?? '';
				// $familyName = $n['familyName'] ?? '';
				// $firstName = $n['givenName'] ?? '';
				break;
			}
			if ($displayName) {
				$searchResult = $this->contactsManager->search($displayName, ['FN']);
				foreach ($searchResult as $resContact) {
					if ($resContact['FN'] === $displayName && (int)$resContact['addressbook-key'] === $addressBookKey) {
						return true;
					}
				}
			}
		}
		return false;
	}
}
