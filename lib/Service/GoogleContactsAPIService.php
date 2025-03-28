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
use Exception;
use Generator;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\Google\AppInfo\Application;
use OCP\Contacts\IManager as IContactManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCard;
use Throwable;

/**
 * Service to make requests to Google v3 (JSON) API
 */
class GoogleContactsAPIService {

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IContactManager $contactsManager,
		private CardDavBackend $cdBackend,
		private GoogleAPIService $googleApiService
	) {
	}

	/**
	 * Get groups that are not empty and with type USER_CONTACT_GROUP
	 *
	 * @param string $userId
	 * @return array
	 */
	public function getContactGroupsById(string $userId): array {
		$groups = [];
		$params = [
			'pageSize' => 100,
		];
		do {
			$result = $this->googleApiService->request($userId, 'v1/contactGroups', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['contactGroups']) && is_array($result['contactGroups'])) {
				foreach ($result['contactGroups'] as $group) {
					$groupType = $group['groupType'] ?? '';
					$memberCount = $group['memberCount'] ?? 0;
					if ($groupType === 'USER_CONTACT_GROUP' && $memberCount > 0) {
						$groupResourceName = $group['resourceName'];
						$groups[$groupResourceName] = $group;
					}
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return $groups;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getContactNumber(string $userId): array {
		$nbContacts = 0;
		$params = [
			'personFields' => implode(',', [
				'names',
			]),
			'pageSize' => 100,
		];
		do {
			$result = $this->googleApiService->request($userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			$nbContacts += count($result['connections'] ?? []);
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return ['nbContacts' => $nbContacts];
	}

	/**
	 * @param string $userId
	 * @return Generator
	 */
	public function getContactList(string $userId): Generator {
		$params = [
			'personFields' => implode(',', [
				'addresses',
				'biographies',
				'birthdays',
				'emailAddresses',
				'genders',
				'memberships',
				'metadata',
				'names',
				'nicknames',
				'organizations',
				'phoneNumbers',
				'photos',
				'relations',
				'residences',
				'urls',
			]),
			'pageSize' => 100,
		];
		do {
			$result = $this->googleApiService->request($userId, 'v1/people/me/connections', $params, 'GET', 'https://people.googleapis.com/');
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
	 * @param string $userId
	 * @param ?string $uri
	 * @param int $key
	 * @param ?string $newAddrBookName
	 * @return array
	 */
	public function importContacts(string $userId, ?string $uri, int $key, ?string $newAddrBookName): array {
		$existingAddressBook = null;
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
			$existingAddressBook = $addressBook;
		}

		$groupsById = $this->getContactGroupsById($userId);
		$contacts = $this->getContactList($userId);
		$nbAdded = 0;
		$nbUpdated = 0;
		$totalContactNumber = 0;
		foreach ($contacts as $k => $c) {
			$totalContactNumber++;

			$googleResourceName = $c['resourceName'] ?? null;
			if ($googleResourceName === null) {
				$this->logger->debug('Skipping contact with no resourceName', ['contact' => $c, 'app' => Application::APP_ID]);
				continue;
			}
			// contacts are not displayed in the Contacts app if there are slashes in their URI...
			$googleResourceName = str_replace('/', '_', $googleResourceName);

			// check if contact exists and needs to be updated
			$existingContact = null;
			if ($existingAddressBook !== null) {
				$existingContact = $this->cdBackend->getCard($key, $googleResourceName);
				if ($existingContact) {
					$googleUpdateTime = $c['metadata']['sources'][0]['updateTime'] ?? null;
					if ($googleUpdateTime === null) {
						$googleUpdateTimestamp = 0;
					} else {
						try {
							$googleUpdateTimestamp = (new DateTime($googleUpdateTime))->getTimestamp();
						} catch (Exception | Throwable $e) {
							$googleUpdateTimestamp = 0;
						}
					}

					if ($googleUpdateTimestamp <= $existingContact['lastmodified']) {
						$this->logger->debug('Skipping existing contact which is up-to-date', ['contact' => $c, 'app' => Application::APP_ID]);
						continue;
					}
				}
			}

			$vCard = new VCard();

			$displayName = '';
			// we just take first name
			if (isset($c['names']) && is_array($c['names'])) {
				/** @var array{displayName?: string, familyName?: string, givenName?: string, middleName?: string, honorificPrefix?: string, honorificSuffix?: string } $n */
				foreach ($c['names'] as $n) {
					$displayName = $n['displayName'] ?? '';
					$familyName = $n['familyName'] ?? '';
					$firstName = $n['givenName'] ?? '';
					$additionalName = $n['middleName'] ?? '';
					$prefix = $n['honorificPrefix'] ?? '';
					$suffix = $n['honorificSuffix'] ?? '';
					if ($familyName || $firstName || $additionalName || $prefix || $suffix) {
						$prop = $vCard->createProperty('N', [0 => $familyName, 1 => $firstName, 2 => $additionalName, 3 => $prefix, 4 => $suffix]);
						$vCard->add($prop);
					}
					break;
				}
			}
			if ($displayName) {
				$prop = $vCard->createProperty('FN', $displayName);
				$vCard->add($prop);
			}

			// notes
			if (isset($c['biographies']) && is_array($c['biographies'])) {
				foreach ($c['biographies'] as $biography) {
					if (isset($biography['value'], $biography['contentType']) && $biography['contentType'] === 'TEXT_PLAIN') {
						$prop = $vCard->createProperty('NOTE', $biography['value']);
						$vCard->add($prop);
					}
				}
			}

			// websites
			if (isset($c['urls']) && is_array($c['urls'])) {
				foreach ($c['urls'] as $url) {
					if (isset($url['value'])) {
						$params = [
							'value' => 'uri',
						];
						if (isset($url['formattedType']) || isset($url['type'])) {
							$params['type'] = $url['formattedType'] ?? $url['type'];
						}
						$prop = $vCard->createProperty('URL', $url['value'], $params);
						$vCard->add($prop);
					}
				}
			}

			// group/label
			if (isset($c['memberships']) && is_array($c['memberships'])) {
				$contactGroupNames = [];
				/** @var array{contactGroupMembership: array{contactGroupResourceName: mixed}} $membership */
				foreach ($c['memberships'] as $membership) {
					if (isset(
						$membership['contactGroupMembership'],
						$membership['contactGroupMembership']['contactGroupResourceName'],
						$groupsById[$membership['contactGroupMembership']['contactGroupResourceName']]
					)) {
						$group = $groupsById[$membership['contactGroupMembership']['contactGroupResourceName']];
						$groupName = $group['formattedName'];
						$contactGroupNames[] = $groupName;
					}
				}
				if (!empty($contactGroupNames)) {
					$prop = $vCard->createProperty('CATEGORIES', $contactGroupNames);
					$vCard->add($prop);
				}
			}

			// photo
			if (isset($c['photos']) && is_array($c['photos'])) {
				foreach ($c['photos'] as $photo) {
					if (isset($photo['url'])) {
						// determine photo type
						$type = 'JPEG';
						if (preg_match('/\.jpg$/i', $photo['url']) || preg_match('/\.jpeg$/i', $photo['url'])) {
							$type = 'JPEG';
						} elseif (preg_match('/\.png$/i', $photo['url'])) {
							$type = 'PNG';
						}
						$photoFile = $this->googleApiService->simpleRequest($userId, $photo['url']);
						if (!isset($photoFile['error'])) {
							// try again to determine photo type from response headers
							if (isset($photoFile['headers'], $photoFile['headers']['Content-Type'])) {
								if (is_array($photoFile['headers']['Content-Type']) && count($photoFile['headers']['Content-Type']) > 0) {
									$contentType = $photoFile['headers']['Content-Type'][0];
								} else {
									$contentType = $photoFile['headers']['Content-Type'];
								}
								if ($contentType === 'image/png') {
									$type = 'PNG';
								} elseif ($contentType === 'image/jpeg') {
									$type = 'JPEG';
								}
							}

							$b64Photo = stripslashes('data:image/' . strtolower($type) . ';base64\,') . base64_encode($photoFile['body']);
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
							} catch (Exception|Throwable $ex) {
								$this->logger->warning('Error when setting contact photo "' . '<redacted>' . '" ' . $ex->getMessage(), ['app' => Application::APP_ID]);
							}
							break;
						}
					}
				}
			}

			// address
			if (isset($c['addresses']) && is_array($c['addresses'])) {
				/** @var array{streetAddress?: string, extendedAddress?: string, postalCode?: string, city?: string, type?: string, country?: string, poBox?: string} $address */
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
						$date = new DateTime($birthday['date']['year'] . '-' . $birthday['date']['month'] . '-' . $birthday['date']['day']);
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
				/** @var array{value?: string, type?: string} $email */
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
				/** @var array{title?: string, name?: string} $org */
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

			if ($existingContact === null || $existingContact === false) {
				try {
					$this->cdBackend->createCard($key, $googleResourceName, $vCard->serialize());
					$nbAdded++;
				} catch (Throwable|Exception $e) {
					$this->logger->warning('Error when creating contact', ['exception' => $e, 'contact' => $c, 'app' => Application::APP_ID]);
				}
			} else {
				try {
					$this->cdBackend->updateCard($key, $googleResourceName, $vCard->serialize());
					$nbUpdated++;
				} catch (Throwable|Exception $e) {
					$this->logger->warning('Error when updating contact', ['exception' => $e, 'contact' => $c, 'app' => Application::APP_ID]);
				}
			}
		}
		$this->logger->debug($totalContactNumber . ' contacts seen', ['app' => Application::APP_ID]);
		$this->logger->debug($nbAdded . ' contacts imported', ['app' => Application::APP_ID]);
		$contactGeneratorReturn = $contacts->getReturn();
		if (isset($contactGeneratorReturn['error'])) {
			return $contactGeneratorReturn;
		}
		return [
			'nbSeen' => $totalContactNumber,
			'nbAdded' => $nbAdded,
			'nbUpdated' => $nbUpdated,
		];
	}
}
