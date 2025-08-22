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
use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportPhotosJob;
use OCA\Google\Service\Utils\FileUtils;
use OCP\BackgroundJob\IJobList;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

use Throwable;

/**
 * Service to make requests to Google v3 (JSON) API
 */
class GooglePhotosAPIService {

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IConfig $config,
		private IRootFolder $root,
		private IJobList $jobList,
		private UserScopeService $userScopeService,
		private GoogleAPIService $googleApiService,
		private FileUtils $fileUtils,
	) {
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getPhotoNumber(string $userId): array {
		$nbPhotos = 0;
		$params = [
			'pageSize' => 50,
		];
		do {
			$this->logger->debug(
				'Photos service::getPhotoNumber LAUNCHING ALBUM LIST REQUEST, userid: "' . $userId . '"',
				['app' => Application::APP_ID]
			);
			$result = $this->googleApiService->request($userId, 'v1/albums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['albums']) && is_array($result['albums'])) {
				foreach ($result['albums'] as $album) {
					$nbPhotos += $album['mediaItemsCount'] ?? 0;
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		// shared albums
		$considerSharedAlbums = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_albums', '0') === '1';
		if ($considerSharedAlbums) {
			$params = [
				'pageSize' => 50,
			];
			do {
				$result = $this->googleApiService->request($userId, 'v1/sharedAlbums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
				if (isset($result['error'])) {
					return $result;
				}
				if (isset($result['sharedAlbums']) && is_array($result['sharedAlbums'])) {
					foreach ($result['sharedAlbums'] as $album) {
						$nbPhotos += $album['mediaItemsCount'] ?? 0;
					}
				}
				$params['pageToken'] = $result['nextPageToken'] ?? '';
			} while (isset($result['nextPageToken']));
		}

		// check if there is any photo outside albums
		// (number is not relevant here as we just make one paginated request to avoid reaching request limit)
		if ($nbPhotos === 0) {
			$params = [
				'pageSize' => 50,
			];

			$result = $this->googleApiService->request($userId, 'v1/mediaItems', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}

			if (isset($result['mediaItems']) && is_array($result['mediaItems'])) {
				$nbPhotos += count($result['mediaItems']);
			} else {
				$this->logger->warning(
					'Google API error getting media items list to get photo number, no "mediaItems" key in '
					. json_encode($result),
					['app' => Application::APP_ID]
				);
			}
		}

		return [
			'nbPhotos' => $nbPhotos,
		];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function startImportPhotos(string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'photo_output_dir', '/Google Photos');
		$targetPath = $targetPath ?: '/Google Photos';
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
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
	 * @return void
	 */
	public function importPhotosJob(string $userId): void {
		$this->logger->debug('Importing photos for ' . $userId);

		// Set the user to register the change under his name
		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingPhotos = $this->config->getUserValue($userId, Application::APP_ID, 'importing_photos', '0') === '1';
		if (!$importingPhotos) {
			return;
		}
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'photo_import_running', '0') === '1';
		$nowTs = (new DateTime())->getTimestamp();
		if ($jobRunning) {
			$lastJobStart = $this->config->getUserValue($userId, Application::APP_ID, 'photo_import_job_last_start');
			if ($lastJobStart !== '' && ($nowTs - intval($lastJobStart) < Application::IMPORT_JOB_TIMEOUT)) {
				// last job has started less than an hour ago => we consider it can still be running
				$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
				return;
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'photo_import_running', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'photo_import_job_last_start', strval($nowTs));

		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'photo_output_dir', '/Google Photos');
		$targetPath = $targetPath ?: '/Google Photos';
		// import photos by batch of 500 Mo
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_photos', '0');
		$alreadyImported = (int)$alreadyImported;
		try {
			$result = $this->importPhotos($userId, $targetPath, 500000000, $alreadyImported);
		} catch (Exception|Throwable $e) {
			$result = [
				'error' => 'Unknown job failure. ' . $e->getMessage(),
			];
		}
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
			if (isset($result['error'])) {
				$this->logger->error('Google Photo import error: ' . $result['error'], ['app' => Application::APP_ID]);
			}
		} else {
			$ts = (new DateTime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_import_timestamp', $ts);
			$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'photo_import_running', '0');
	}

	/**
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importPhotos(
		string $userId, string $targetPath,
		?int $maxDownloadSize = null, int $alreadyImported = 0,
	): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if (!$folder instanceof Folder) {
				return ['error' => 'Impossible to create Google folder'];
			}
		}

		$albums = [];
		$params = [
			'pageSize' => 50,
		];
		do {
			$this->logger->debug(
				'Photos service::importPhotos LAUNCHING ALBUM LIST REQUEST, userid: "' . $userId . '"',
				['app' => Application::APP_ID]
			);
			$result = $this->googleApiService->request($userId, 'v1/albums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['albums']) && is_array($result['albums'])) {
				foreach ($result['albums'] as $album) {
					$albums[] = $album;
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		// shared albums
		$considerSharedAlbums = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_albums', '0') === '1';
		if ($considerSharedAlbums) {
			$params = [
				'pageSize' => 50,
			];
			do {
				$result = $this->googleApiService->request($userId, 'v1/sharedAlbums', $params, 'GET', 'https://photoslibrary.googleapis.com/');
				if (isset($result['error'])) {
					return $result;
				}
				if (isset($result['sharedAlbums']) && is_array($result['sharedAlbums'])) {
					foreach ($result['sharedAlbums'] as $album) {
						$albums[] = $album;
					}
				}
				$params['pageToken'] = $result['nextPageToken'] ?? '';
			} while (isset($result['nextPageToken']));
		}

		// get the photos
		$this->logger->debug(
			'Photos service::importPhotos GETTING PHOTOS, nb albums: "' . count($albums) . '"',
			['app' => Application::APP_ID]
		);
		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;
		$seenIds = [];
		foreach ($albums as $album) {
			$albumId = $album['id'];
			$albumName = $this->fileUtils->sanitizeFilename((string)($album['title']), (string)$album['id']);
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
				$result = $this->googleApiService->request($userId, 'v1/mediaItems:search', $params, 'POST', 'https://photoslibrary.googleapis.com/');
				if (isset($result['error'])) {
					return $result;
				}
				if (isset($result['mediaItems']) && is_array($result['mediaItems'])) {
					foreach ($result['mediaItems'] as $photo) {
						$seenIds[] = $photo['id'];
						$totalSeenNumber++;
						$size = $this->getPhoto($userId, $photo, $albumFolder);
						if (!is_null($size)) {
							$nbDownloaded++;
							$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', $alreadyImported + $nbDownloaded);
							$downloadedSize += $size;
							if ($maxDownloadSize !== null && $downloadedSize > $maxDownloadSize) {
								return [
									'nbDownloaded' => $nbDownloaded,
									'targetPath' => $targetPath,
									'finished' => false,
									'totalSeen' => $totalSeenNumber,
								];
							}
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
			$result = $this->googleApiService->request($userId, 'v1/mediaItems', $params, 'GET', 'https://photoslibrary.googleapis.com/');
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['mediaItems']) && is_array($result['mediaItems'])) {
				foreach ($result['mediaItems'] as $photo) {
					if (!in_array($photo['id'], $seenIds)) {
						$seenIds[] = $photo['id'];
						$totalSeenNumber++;
						$size = $this->getPhoto($userId, $photo, $folder);
						if (!is_null($size)) {
							$nbDownloaded++;
							$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_photos', $alreadyImported + $nbDownloaded);
							$downloadedSize += $size;
							if ($maxDownloadSize !== null && $downloadedSize > $maxDownloadSize) {
								return [
									'nbDownloaded' => $nbDownloaded,
									'targetPath' => $targetPath,
									'finished' => false,
									'totalSeen' => $totalSeenNumber,
								];
							}
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
	 * @param string $userId
	 * @param array{baseUrl: string, id: string, filename: string, mediaMetadata: array} $photo
	 * @param Folder $albumFolder
	 * @return int|null downloaded size, null if already existing
	 * @throws \OCP\Files\InvalidPathException
	 * @throws \OCP\Files\NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 */
	private function getPhoto(string $userId, array $photo, Folder $albumFolder): ?int {
		$photoName = $this->fileUtils->sanitizeFilename($photo['filename'], (string)$photo['id']);
		if ($albumFolder->nodeExists($photoName)) {
			$photoName = $photo['id'] . '_' . $photoName;
		}
		if (!$albumFolder->nodeExists($photoName)) {
			if (isset($photo['mediaMetadata']['photo'])) {
				$photoUrl = $photo['baseUrl'] . '=d';
			} elseif (isset($photo['mediaMetadata']['video'])) {
				$photoUrl = $photo['baseUrl'] . '=dv';
			} else {
				return null;
			}
			$savedFile = $albumFolder->newFile($photoName);
			try {
				$resource = $savedFile->fopen('w');
			} catch (LockedException $e) {
				$this->logger->warning('Google Photo, error opening target file ' . '<redacted>' . ' : file is locked', ['app' => Application::APP_ID]);
				return null;
			}
			if ($resource === false) {
				$this->logger->warning('Google Photo, error opening target file ' . '<redacted>', ['app' => Application::APP_ID]);
				return null;
			}
			$res = $this->googleApiService->simpleDownload($userId, $photoUrl, $resource);
			if (!isset($res['error'])) {
				if (is_resource($resource)) {
					fclose($resource);
				}
				if (isset($photo['mediaMetadata']['creationTime'])) {
					$d = new DateTime($photo['mediaMetadata']['creationTime']);
					$ts = $d->getTimestamp();
					$savedFile->touch($ts);
				} else {
					$savedFile->touch();
				}
				$stat = $savedFile->stat();
				return (int)($stat['size'] ?? 0);
			} else {
				$this->logger->warning('Google API error downloading photo ' . '<redacted>' . ' : ' . (string)$res['error'], ['app' => Application::APP_ID]);
				if ($savedFile->isDeletable()) {
					$savedFile->unlock(ILockingProvider::LOCK_EXCLUSIVE);
					$savedFile->delete();
				}
			}
		}
		return null;
	}
}
