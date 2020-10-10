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
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCP\Notification\IManager as INotificationManager;

use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportPhotosJob;
use OCA\Google\BackgroundJob\ImportDriveJob;

class GoogleAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IContactManager $contactsManager,
								CardDavBackend $cdBackend,
								CalDavBackend $caldavBackend,
								IRootFolder $root,
								IJobList $jobList,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->jobList = $jobList;
		$this->clientService = $clientService;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->caldavBackend = $caldavBackend;
		$this->root = $root;
		$this->jobList = $jobList;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @return array
	 */
	public function startImportDrive(string $accessToken, string $userId): array {
		$targetPath = $this->l10n->t('Google Drive import');
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Google Drive folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', '0');

		$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function importDriveJob(string $userId): void {
		$this->logger->error('Importing drive files for ' . $userId);
		$importingDrive = $this->config->getUserValue($userId, Application::APP_ID, 'importing_drive', '0') === '1';
		if (!$importingDrive) {
			return;
		}

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		// import batch of files
		$targetPath = $this->l10n->t('Google Drive import');
		// import by batch of 500 Mo
		$result = $this->importFiles($accessToken, $userId, $targetPath, 500000000);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->sendNCNotification($userId, 'import_drive_finished', [
					'nbImported' => $result['totalSeen'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', $ts);
			$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '');
			$alreadyImported = $alreadyImported ? (int) $alreadyImported : 0;
			$newNbImported = $alreadyImported + $result['nbDownloaded'];
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $newNbImported);
			$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
		}
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadNumber
	 * @return array
	 */
	public function importFiles(string $accessToken, string $userId, string $targetPath, ?int $maxDownloadSize = null): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$directoriesById = [];
		$params = [
			'pageSize' => 1000,
			'fields' => '*',
			'q' => "mimeType='application/vnd.google-apps.folder'",
		];
		$result = $this->request($accessToken, $userId, 'drive/v3/files', $params);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['files'] as $dir) {
			$directoriesById[$dir['id']] = [
				'name' => $dir['name'],
				'parent' => (isset($dir['parents']) && count($dir['parents']) > 0) ? $dir['parents'][0] : null,
			];
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['files'] as $dir) {
				$directoriesById[$dir['id']] = [
					'name' => $dir['name'],
					'parent' => (isset($dir['parents']) && count($dir['parents']) > 0) ? $dir['parents'][0] : null,
				];
			}
		}
		// create top dirs
		if (!$this->createDirsUnder($directoriesById, $folder)) {
			return ['error' => 'Impossible to create Drive directories'];
		}

		// get files
		$info = $this->getDriveSize($accessToken, $userId);
		if (isset($info['error'])) {
			return $info;
		}
		$nbFilesOnDrive = $info['nbFiles'];
		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;

		$params = [
			'pageSize' => 1000,
			'fields' => '*',
			'q' => "mimeType!='application/vnd.google-apps.folder'",
		];
		$result = $this->request($accessToken, $userId, 'drive/v3/files', $params);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['files'] as $fileItem) {
			$totalSeenNumber++;
			$size = $this->getFile($accessToken, $userId, $fileItem, $directoriesById, $folder);
			if (!is_null($size)) {
				$nbDownloaded++;
				$downloadedSize += $size;
				//if ($maxDownloadNumber && $nbDownloaded === $maxDownloadNumber) {
				if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
					return [
						'nbDownloaded' => $nbDownloaded,
						'targetPath' => $targetPath,
						'finished' => ($totalSeenNumber >= $nbFilesOnDrive),
						'totalSeen' => $totalSeenNumber,
					];
				}
			}
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['files'] as $fileItem) {
				$totalSeenNumber++;
				$size = $this->getFile($accessToken, $userId, $fileItem, $directoriesById, $folder);
				if (!is_null($size)) {
					$nbDownloaded++;
					$downloadedSize += $size;
					if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
						return [
							'nbDownloaded' => $nbDownloaded,
							'targetPath' => $targetPath,
							'finished' => ($totalSeenNumber >= $nbFilesOnDrive),
							'totalSeen' => $totalSeenNumber,
						];
					}
				}
			}
		}

		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeen' => $totalSeenNumber,
		];
	}

	/**
	 * recursive directory creation
	 * associate the folder node to directories on the fly
	 *
	 * @param array &$directoriesById
	 * @param Node $currentFolder
	 * @param string $currentFolder
	 * @return bool success
	 */
	private function createDirsUnder(array &$directoriesById, Node $currentFolder, string $currentFolderId = ''): bool {
		foreach ($directoriesById as $id => $dir) {
			$parentId = $dir['parent'];
			// create dir if we are on top OR if its parent is current dir
			if ( ($currentFolderId === '' && !array_key_exists($parentId, $directoriesById))
				|| $parentId === $currentFolderId) {
				$name = $dir['name'];
				if (!$currentFolder->nodeExists($name)) {
					$newDir = $currentFolder->newFolder($name);
				} else {
					$newDir = $currentFolder->get($name);
					if ($newDir->getType() !== FileInfo::TYPE_FOLDER) {
						return false;
					}
				}
				$directoriesById[$id]['node'] = $newDir;
				$success = $this->createDirsUnder($directoriesById, $newDir, $id);
				if (!$success) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param array $fileItem
	 * @param array $directoriesById
	 * @param Node $topFolder
	 * @return ?int downloaded size, null if already existing
	 */
	private function getFile(string $accessToken, string $userId, array $fileItem, array $directoriesById, Node $topFolder): ?int {
		$fileName = $fileItem['name'];
		if (isset($fileItem['parents']) && count($fileItem['parents']) > 0 && array_key_exists($fileItem['parents'][0], $directoriesById)) {
			$saveFolder = $directoriesById[$fileItem['parents'][0]]['node'];
		} else {
			$saveFolder = $topFolder;
		}
		if (!$saveFolder->nodeExists($fileName)) {
			$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . $fileItem['id'] . '?alt=media';
			$res = $this->simpleRequest($accessToken, $userId, $fileUrl);
			if (!isset($res['error'])) {
				$savedFile = $saveFolder->newFile($fileName, $res['content']);
				return $savedFile->getSize();
			}
		}
		return null;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @return array
	 */
	public function startImportPhotos(string $accessToken, string $userId): array {
		$targetPath = $this->l10n->t('Google Photos import');
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
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_photos', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_import_timestamp', '0');

		$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function importPhotosJob(string $userId): void {
		$this->logger->error('Importing photos for ' . $userId);
		$importingPhotos = $this->config->getUserValue($userId, Application::APP_ID, 'importing_photos', '0') === '1';
		if (!$importingPhotos) {
			return;
		}

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		$targetPath = $this->l10n->t('Google Photos import');
		// import photos by batch of 500 Mo
		$result = $this->importPhotos($accessToken, $userId, $targetPath, 500000000);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_photos', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->sendNCNotification($userId, 'import_photos_finished', [
					'nbImported' => $result['totalSeen'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_import_timestamp', $ts);
			$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_photos', '');
			$alreadyImported = $alreadyImported ? (int) $alreadyImported : 0;
			$newNbImported = $alreadyImported + $result['nbDownloaded'];
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', $newNbImported);
			$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
		}
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @return array
	 */
	public function importPhotos(string $accessToken, string $userId, string $targetPath, ?int $maxDownloadSize = null): array {
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

		// get the photos
		$info = $this->getPhotoNumber($accessToken, $userId);
		if (isset($info['error'])) {
			return $info;
		}

		$nbPhotosOnGoogle = $info['nbPhotos'];
		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;
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
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['mediaItems'] as $photo) {
				$totalSeenNumber++;
				$size = $this->getPhoto($accessToken, $userId, $photo, $albumFolder);
				if (!is_null($size)) {
					$nbDownloaded++;
					$downloadedSize += $size;
					if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
						return [
							'nbDownloaded' => $nbDownloaded,
							'targetPath' => $targetPath,
							'finished' => ($totalSeenNumber >= $nbPhotosOnGoogle),
							'totalSeen' => $totalSeenNumber,
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
					$totalSeenNumber++;
					$size = $this->getPhoto($accessToken, $userId, $photo, $albumFolder);
					if (!is_null($size)) {
						$nbDownloaded++;
						$downloadedSize += $size;
						if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
							return [
								'nbDownloaded' => $nbDownloaded,
								'targetPath' => $targetPath,
								'finished' => ($totalSeenNumber >= $nbPhotosOnGoogle),
								'totalSeen' => $totalSeenNumber,
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
			'totalSeen' => $totalSeenNumber,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param array $photo
	 * @param Node $albumFolder
	 * @return ?int downloaded size, null if already existing
	 */
	private function getPhoto(string $accessToken, string $userId, array $photo, Node $albumFolder): ?int {
		$photoName = $photo['filename'];
		if (!$albumFolder->nodeExists($photoName)) {
			$photoUrl = $photo['baseUrl'];
			$res = $this->simpleRequest($accessToken, $userId, $photoUrl);
			if (!isset($res['error'])) {
				$savedFile = $albumFolder->newFile($photoName, $res['content']);
				return $savedFile->getSize();
			}
		}
		return null;
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param string $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
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
	 * @param ?string $uri
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
						$date = new \Datetime($birthday['date']['year'] . '-' . $birthday['date']['month'] . '-' . $birthday['date']['day']);
						$strDate = $date->format('Ymd');

						$type = ['VALUE' => 'DATE'];
						$prop = $vCard->createProperty('BDAY', $strDate, $type);
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
					$addrType = $email['type'] ?? '';
					$type = $addrType ? ['TYPE' => strtoupper($addrType)] : null;
					$prop = $vCard->createProperty('EMAIL', $email['value'], $type);
					$vCard->add($prop);
				}
			}

			if (isset($c['phoneNumbers']) && is_array($c['phoneNumbers'])) {
				foreach ($c['phoneNumbers'] as $ph) {
					$numberType = str_replace('mobile', 'cell', $ph['type']);
					$numberType = str_replace('main', '', $numberType);
					$numberType = $numberType ? $numberType : 'home';
					$type = ['TYPE' => strtoupper($numberType)];
					$prop = $vCard->createProperty('TEL', $ph['value'], $type);
					$vCard->add($prop);
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
		$params = [];
		$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList');
		if (isset($result['error']) || !isset($result['items'])) {
			return $result;
		}
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
		$newCalName = trim($calName) . ' (' . $this->l10n->t('Google Calendar import') .')';
		while ($this->calendarExists($userId, $newCalName)) {
			$calSuffix++;
			$newCalName = trim($calName) . '-' . $calSuffix . ' (' . $this->l10n->t('Google Calendar import') .')';
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
			$calData .= isset($e['summary']) ? ('SUMMARY:' . $e['summary'] . "\n") : '';
			$calData .= isset($e['sequence']) ? ('SEQUENCE:' . $e['sequence'] . "\n") : '';
			$calData .= isset($e['location']) ? ('LOCATION:' . $e['location'] . "\n") : '';
			$calData .= isset($e['description']) ? ('DESCRIPTION:' . $e['description'] . "\n") : '';
			$calData .= isset($e['status']) ? ('STATUS:' . strtoupper($e['status']) . "\n") : '';

			if (isset($e['created'])) {
				$created = new \Datetime($e['created']);
				$calData .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\n";
			}

			if (isset($e['updated'])) {
				$updated = new \Datetime($e['updated']);
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
		$result = $this->request($accessToken, $userId, 'calendar/v3/calendars/'.$calId.'/events', $params);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['items'] as $event) {
			yield $event;
		}
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'calendar/v3/calendars/'.$calId.'/events', $params);
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
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getDriveSize(string $accessToken, string $userId): array {
		$params = [
			'fields' => '*',
		];
		$result = $this->request($accessToken, $userId, 'drive/v3/about', $params);
		if (isset($result['error']) || !isset($result['storageQuota']) || !isset($result['storageQuota']['usageInDrive'])) {
			return $result;
		}
		$info = [
			'usageInDrive' => $result['storageQuota']['usageInDrive']
		];
		// count files
		$nbFiles = 0;
		$params = [
			'pageSize' => 1000,
			'q' => "mimeType!='application/vnd.google-apps.folder'",
		];
		$result = $this->request($accessToken, $userId, 'drive/v3/files', $params);
		if (isset($result['error']) || !isset($result['files'])) {
			return $result;
		}
		$nbFiles += count($result['files']);
		while (isset($result['nextPageToken'])) {
			$params['pageToken'] = $result['nextPageToken'];
			$result = $this->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error']) || !isset($result['files'])) {
				return $result;
			}
			$nbFiles += count($result['files']);
		}
		$info['nbFiles'] = $nbFiles;
		return $info;
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
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			// try to refresh token if it's invalid
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
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
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
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
			$this->logger->warning('Google OAuth error : '.$e->getMessage(), ['app' => $this->appName]);
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
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				// refresh token if it's invalid and we are using oauth
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
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
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}
}
