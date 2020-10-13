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
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportPhotosJob;

class GooglePhotosAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IRootFolder $root,
								IJobList $jobList,
								GoogleAPIService $googleApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->jobList = $jobList;
		$this->root = $root;
		$this->googleApiService = $googleApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getPhotoNumber(string $accessToken, string $userId): array {
		$params = [
			'pageSize' => 100,
		];
		$seenIds = [];
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'v1/mediaItems', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['mediaItems'] as $photo) {
				if (!in_array($photo['id'], $seenIds)) {
					$seenIds[] = $photo['id'];
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		// get free space
		$userFolder = $this->root->getUserFolder($userId);
		$freeSpace = $userFolder->getStorage()->free_space('/');
		return [
			'nbPhotos' => count($seenIds),
			'freeSpace' => $freeSpace,
		];
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
		$this->logger->info('Importing photos for ' . $userId);
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
				$this->googleApiService->sendNCNotification($userId, 'import_photos_finished', [
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
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'v1/albums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['albums'] as $album) {
				$albums[] = $album;
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		// get the photos
		$info = $this->getPhotoNumber($accessToken, $userId);
		if (isset($info['error'])) {
			return $info;
		}

		$nbPhotosOnGoogle = $info['nbPhotos'];
		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;
		$seenIds = [];
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
			do {
				$result = $this->googleApiService->request($accessToken, $userId, 'v1/mediaItems:search', $params, 'POST', 'https://photoslibrary.googleapis.com/');
				if (isset($result['error'])) {
					return $result;
				}
				foreach ($result['mediaItems'] as $photo) {
					$seenIds[] = $photo['id'];
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
				$params['pageToken'] = $result['nextPageToken'] ?? '';
			} while (isset($result['nextPageToken']));
		}

		// get photos that don't belong to an album
		$params = [
			'pageSize' => 100,
		];
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'v1/mediaItems', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['mediaItems'] as $photo) {
				if (!in_array($photo['id'], $seenIds)) {
					$seenIds[] = $photo['id'];
					$totalSeenNumber++;
					$size = $this->getPhoto($accessToken, $userId, $photo, $folder);
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
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

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
			$res = $this->googleApiService->simpleRequest($accessToken, $userId, $photoUrl);
			if (!isset($res['error'])) {
				$savedFile = $albumFolder->newFile($photoName, $res['content']);
				return $savedFile->getSize();
			}
		}
		return null;
	}
}
