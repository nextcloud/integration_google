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
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service to make requests to Google Photos Picker API
 */
class GooglePhotosAPIService {

	private const PICKER_BASE_URL = 'https://photospicker.googleapis.com/';
	private const METADATA_KEY = 'integration_google_photo_id';

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IUserConfig $userConfig,
		private IRootFolder $root,
		private IJobList $jobList,
		private UserScopeService $userScopeService,
		private GoogleAPIService $googleApiService,
		private FileUtils $fileUtils,
		private IFilesMetadataManager $metadataManager,
	) {
	}

	/**
	 * Create a new Picker API session (max 2000 items per session)
	 *
	 * @param string $userId
	 * @return array{id?:string, pickerUri?:string, pollingConfig?:array, expireTime?:string, error?:string}
	 */
	public function createPickerSession(string $userId): array {
		$result = $this->googleApiService->request(
			$userId,
			'v1/sessions',
			['pickingConfig' => ['maxItemCount' => 2000]],
			'POST',
			self::PICKER_BASE_URL,
		);
		if (isset($result['error'])) {
			return $result;
		}
		// append /autoclose so Google Photos closes its window after selection is done
		if (isset($result['pickerUri'])) {
			$result['pickerUri'] .= '/autoclose';
		}
		return $result;
	}

	/**
	 * Poll an existing Picker session to check if the user has finished selecting
	 *
	 * @param string $userId
	 * @param string $sessionId
	 * @return array{mediaItemsSet?:bool, pollingConfig?:array, expireTime?:string, error?:string}
	 */
	public function getPickerSession(string $userId, string $sessionId): array {
		return $this->googleApiService->request(
			$userId,
			'v1/sessions/' . urlencode($sessionId),
			[],
			'GET',
			self::PICKER_BASE_URL,
		);
	}

	/**
	 * Delete a Picker session (cleanup after import)
	 *
	 * Only clears the stored picker_session_id when it matches the session being deleted,
	 * so deleting a UI/queued session does not corrupt the active import session ID.
	 *
	 * @param string $userId
	 * @param string $sessionId
	 * @return array
	 */
	public function deletePickerSession(string $userId, string $sessionId): array {
		$result = $this->googleApiService->request(
			$userId,
			'v1/sessions/' . urlencode($sessionId),
			[],
			'DELETE',
			self::PICKER_BASE_URL,
		);
		$storedSessionId = $this->userConfig->getValueString($userId, Application::APP_ID, 'picker_session_id', '', lazy: true);
		if ($storedSessionId === $sessionId) {
			$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_id', '', lazy: true);
		}
		return $result;
	}

	/**
	 * Start a background import job for the given Picker session
	 *
	 * @param string $userId
	 * @param string $sessionId
	 * @return array{targetPath?:string, error?:string}
	 */
	public function startImportPhotos(string $userId, string $sessionId): array {
		if (trim($sessionId) === '') {
			return ['error' => 'No picker session ID provided'];
		}

		$targetPath = $this->userConfig->getValueString($userId, Application::APP_ID, 'photo_output_dir', '/Google Photos', lazy: true);
		$targetPath = $targetPath ?: '/Google Photos';

		$alreadyImporting = $this->userConfig->getValueString($userId, Application::APP_ID, 'importing_photos', '0', lazy: true) === '1';
		if ($alreadyImporting) {
			// Queue this session to run after the current one finishes
			$queueRaw = $this->userConfig->getValueString($userId, Application::APP_ID, 'picker_session_queue', '[]', lazy: true);
			$queue = json_decode($queueRaw, true);
			if (!is_array($queue)) {
				$queue = [];
			}
			$queue[] = $sessionId;
			$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_queue', json_encode($queue), lazy: true);
			return ['targetPath' => $targetPath, 'queued' => true];
		}

		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if (!($folder instanceof Folder)) {
				return ['error' => 'Impossible to create Google Photos folder'];
			}
		}

		$this->userConfig->setValueString($userId, Application::APP_ID, 'importing_photos', '1', lazy: true);
		$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_id', $sessionId, lazy: true);
		$this->userConfig->setValueInt($userId, Application::APP_ID, 'nb_imported_photos', 0, lazy: true);
		$this->userConfig->setValueInt($userId, Application::APP_ID, 'last_import_timestamp', 0, lazy: true);
		$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_next_page_token', '', lazy: true);
		$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_queue', '[]', lazy: true);

		$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * Cancel any pending import and optionally delete the active picker session
	 *
	 * @param string $userId
	 * @return void
	 */
	public function cancelImport(string $userId): void {
		$this->jobList->remove(ImportPhotosJob::class, ['user_id' => $userId]);
		$sessionId = $this->userConfig->getValueString($userId, Application::APP_ID, 'picker_session_id', '', lazy: true);
		if ($sessionId !== '') {
			$this->deletePickerSession($userId, $sessionId);
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_queue', '[]', lazy: true);
	}

	/**
	 * Background job entry point: import a batch of photos then re-queue if unfinished
	 *
	 * @param string $userId
	 * @return void
	 */
	public function importPhotosJob(string $userId): void {
		$this->logger->debug('Importing photos (Picker API) for ' . $userId);

		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingPhotos = $this->userConfig->getValueString($userId, Application::APP_ID, 'importing_photos', '0', lazy: true) === '1';
		if (!$importingPhotos) {
			// Clear the concurrency guard so it does not delay future imports
			$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_import_running', '0', lazy: true);
			$this->userConfig->setValueInt($userId, Application::APP_ID, 'photo_import_job_last_start', 0, lazy: true);
			return;
		}

		$jobRunning = $this->userConfig->getValueString($userId, Application::APP_ID, 'photo_import_running', '0', lazy: true) === '1';
		$nowTs = (new DateTime())->getTimestamp();
		if ($jobRunning) {
			$lastJobStart = $this->userConfig->getValueInt($userId, Application::APP_ID, 'photo_import_job_last_start', lazy: true);
			if ($lastJobStart !== 0 && ($nowTs - $lastJobStart < Application::IMPORT_JOB_TIMEOUT)) {
				$this->logger->info(
					'Last job execution (' . strval($nowTs - $lastJobStart) . ') is less than '
					. strval(Application::IMPORT_JOB_TIMEOUT) . ' seconds ago, delaying execution',
				);
				$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
				return;
			}
		}

		$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_import_running', '1', lazy: true);
		$this->userConfig->setValueInt($userId, Application::APP_ID, 'photo_import_job_last_start', $nowTs, lazy: true);

		$targetPath = $this->userConfig->getValueString($userId, Application::APP_ID, 'photo_output_dir', '/Google Photos', lazy: true);
		$targetPath = $targetPath ?: '/Google Photos';
		$sessionId = $this->userConfig->getValueString($userId, Application::APP_ID, 'picker_session_id', '', lazy: true);
		$alreadyImported = $this->userConfig->getValueInt($userId, Application::APP_ID, 'nb_imported_photos', lazy: true);

		try {
			$result = $this->importFromPickerSession($userId, $sessionId, $targetPath, 500000000, $alreadyImported);
		} catch (Exception|Throwable $e) {
			$result = ['error' => 'Unknown job failure. ' . $e->getMessage()];
		}

		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			if (isset($result['finished']) && $result['finished']) {
				$this->googleApiService->sendNCNotification($userId, 'import_photos_finished', [
					'nbImported' => $alreadyImported + ($result['nbDownloaded'] ?? 0),
					'targetPath' => $targetPath,
				]);
				// Clean up the picker session now that we have all items
				if ($sessionId !== '') {
					$this->deletePickerSession($userId, $sessionId);
				}
			}
			if (isset($result['error'])) {
				$this->logger->error('Google Photo import error: ' . $result['error'], ['app' => Application::APP_ID]);
				// Clean up the picker session on error to avoid stale sessions
				if ($sessionId !== '') {
					$this->deletePickerSession($userId, $sessionId);
				}
				// Clear the queue and page token so queued sessions are not left stuck after a failure
				$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_queue', '[]', lazy: true);
				$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_next_page_token', '', lazy: true);
			}
			// On successful completion, atomically transition to the next queued session if any,
			// so importing_photos never has a transient '0' that would stop the polling client.
			if (isset($result['finished']) && $result['finished']) {
				$queueRaw = $this->userConfig->getValueString($userId, Application::APP_ID, 'picker_session_queue', '[]', lazy: true);
				$queue = json_decode($queueRaw, true) ?? [];
				if (!empty($queue)) {
					$nextSessionId = array_shift($queue);
					$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_queue', json_encode($queue), lazy: true);
					$this->userConfig->setValueString($userId, Application::APP_ID, 'picker_session_id', $nextSessionId, lazy: true);
					$this->userConfig->setValueInt($userId, Application::APP_ID, 'nb_imported_photos', 0, lazy: true);
					$this->userConfig->setValueInt($userId, Application::APP_ID, 'last_import_timestamp', 0, lazy: true);
					$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_next_page_token', '', lazy: true);
					$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
				} else {
					$this->userConfig->setValueString($userId, Application::APP_ID, 'importing_photos', '0', lazy: true);
					$this->userConfig->setValueInt($userId, Application::APP_ID, 'nb_imported_photos', 0, lazy: true);
					$this->userConfig->setValueInt($userId, Application::APP_ID, 'last_import_timestamp', 0, lazy: true);
				}
			} else {
				$this->userConfig->setValueString($userId, Application::APP_ID, 'importing_photos', '0', lazy: true);
				$this->userConfig->setValueInt($userId, Application::APP_ID, 'nb_imported_photos', 0, lazy: true);
				$this->userConfig->setValueInt($userId, Application::APP_ID, 'last_import_timestamp', 0, lazy: true);
			}
		} else {
			$ts = (new DateTime())->getTimestamp();
			$this->userConfig->setValueInt($userId, Application::APP_ID, 'last_import_timestamp', $ts, lazy: true);
			$this->jobList->add(ImportPhotosJob::class, ['user_id' => $userId]);
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_import_running', '0', lazy: true);
	}

	/**
	 * Download picked media items from a Picker session into a Nextcloud folder.
	 * Processes up to $maxDownloadSize bytes per call, then returns so the job can re-queue.
	 *
	 * @param string $userId
	 * @param string $sessionId
	 * @param string $targetPath
	 * @param int|null $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importFromPickerSession(
		string $userId, string $sessionId, string $targetPath,
		?int $maxDownloadSize = null, int $alreadyImported = 0,
	): array {
		if ($sessionId === '') {
			return ['error' => 'No picker session ID stored'];
		}

		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if (!$folder instanceof Folder) {
				return ['error' => 'Impossible to create Google Photos folder'];
			}
		}

		// Page through all picked media items
		$downloadedSize = 0;
		$nbDownloaded = 0;
		$params = ['sessionId' => $sessionId, 'pageSize' => 100];
		$resumeToken = $this->userConfig->getValueString($userId, Application::APP_ID, 'photo_next_page_token', '', lazy: true);
		if ($resumeToken !== '') {
			$params['pageToken'] = $resumeToken;
		}

		do {
			$currentPageToken = $params['pageToken'] ?? '';
			$result = $this->googleApiService->request(
				$userId,
				'v1/mediaItems',
				$params,
				'GET',
				self::PICKER_BASE_URL,
			);
			if (isset($result['error'])) {
				return $result;
			}
			$items = $result['mediaItems'] ?? [];
			foreach ($items as $item) {
				$size = $this->downloadPickerItem($userId, $item, $folder);
				if ($size !== null) {
					$nbDownloaded++;
					$this->userConfig->setValueInt(
						$userId, Application::APP_ID, 'nb_imported_photos',
						$alreadyImported + $nbDownloaded, lazy: true,
					);
					$downloadedSize += $size;
					if ($maxDownloadSize !== null && $downloadedSize > $maxDownloadSize) {
						$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_next_page_token', $currentPageToken, lazy: true);
						return [
							'nbDownloaded' => $nbDownloaded,
							'targetPath' => $targetPath,
							'finished' => false,
						];
					}
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		$this->userConfig->setValueString($userId, Application::APP_ID, 'photo_next_page_token', '', lazy: true);
		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
		];
	}

	/**
	 * Download a single PickedMediaItem into a Nextcloud folder.
	 *
	 * @param string $userId
	 * @param array{id:string, mediaFile?:array, createTime?:string} $item
	 * @param Folder $folder
	 * @return int|null downloaded byte count, or null if skipped/error
	 */
	private function downloadPickerItem(string $userId, array $item, Folder $folder): ?int {
		$mediaFile = $item['mediaFile'] ?? null;
		if ($mediaFile === null) {
			return null;
		}

		$baseUrl = $mediaFile['baseUrl'] ?? '';
		if ($baseUrl === '') {
			return null;
		}

		$mimeType = $mediaFile['mimeType'] ?? '';
		$rawName = $mediaFile['filename'] ?? ($item['id'] ?? 'unknown');
		$itemId = (string)($item['id'] ?? '');
		$baseName = $this->fileUtils->sanitizeFilename($rawName, $itemId);

		// Determine the target filename.
		// Happy path: use the original name. If a file with that name already exists,
		// check its metadata: same Google ID means this exact photo was already
		// imported, so skip it. A different ID (name collision with another photo)
		// falls back to an ID-prefixed filename, which is then the definitive name
		// for this item across all future sessions.
		if ($folder->nodeExists($baseName)) {
			try {
				$existing = $folder->get($baseName);
				$meta = $this->metadataManager->getMetadata($existing->getId());
				if ($meta->hasKey(self::METADATA_KEY) && $meta->getString(self::METADATA_KEY) === $itemId) {
					return null; // already imported
				}
			} catch (\Throwable) {
				// fall through to ID-prefixed name on any unexpected error
			}
			$fileName = $itemId . '_' . $baseName;
			if ($folder->nodeExists($fileName)) {
				return null; // ID-prefixed version already imported too
			}
		} else {
			$fileName = $baseName;
		}

		// Build the download URL: images get =d (full quality + EXIF), videos get =dv
		$isVideo = str_starts_with($mimeType, 'video/');
		$downloadUrl = $isVideo ? ($baseUrl . '=dv') : ($baseUrl . '=d');

		try {
			$savedFile = $folder->newFile($fileName);
		} catch (NotPermittedException|InvalidPathException $e) {
			$this->logger->warning('Google Photo, skipping file creation for picker item: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}

		try {
			$resource = $savedFile->fopen('w');
		} catch (LockedException $e) {
			$this->logger->warning('Google Photo, error opening target file: file is locked', ['app' => Application::APP_ID]);
			if ($savedFile->isDeletable()) {
				$savedFile->delete();
			}
			return null;
		}
		if ($resource === false) {
			$this->logger->warning('Google Photo, error opening target file', ['app' => Application::APP_ID]);
			if ($savedFile->isDeletable()) {
				$savedFile->delete();
			}
			return null;
		}

		$res = $this->googleApiService->simpleDownload($userId, $downloadUrl, $resource);
		if (!isset($res['error'])) {
			if (is_resource($resource)) {
				fclose($resource);
			}
			if (isset($item['createTime'])) {
				try {
					$d = new DateTime($item['createTime']);
					$savedFile->touch($d->getTimestamp());
				} catch (Exception $e) {
					$this->logger->warning('Google Photo, invalid createTime, using current time: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					$savedFile->touch();
				}
			} else {
				$savedFile->touch();
			}
			// Store the Google media ID in file metadata so future imports can
			// identify this file by ID rather than filename alone.
			if ($itemId !== '') {
				try {
					$meta = $this->metadataManager->getMetadata($savedFile->getId(), true);
					$meta->setString(self::METADATA_KEY, $itemId);
					$this->metadataManager->saveMetadata($meta);
				} catch (\Throwable $e) {
					$this->logger->warning('Google Photo, could not save file metadata: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
			$stat = $savedFile->stat();
			return (int)($stat['size'] ?? 0);
		} else {
			$this->logger->warning('Google API error downloading photo: ' . $res['error'], ['app' => Application::APP_ID]);
			if (is_resource($resource)) {
				fclose($resource);
			}
			if ($savedFile->isDeletable()) {
				$savedFile->unlock(ILockingProvider::LOCK_EXCLUSIVE);
				$savedFile->delete();
			}
		}
		return null;
	}
}
