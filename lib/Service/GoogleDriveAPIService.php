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
use OC\User\NoUserException;
use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportDriveJob;
use OCA\Google\Service\Utils\FileUtils;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class GoogleDriveAPIService {

	private const DOCUMENT_MIME_TYPES = [
		'document' => 'application/vnd.google-apps.document',
		'spreadsheet' => 'application/vnd.google-apps.spreadsheet',
		'presentation' => 'application/vnd.google-apps.presentation',
		'drawing' => 'application/vnd.google-apps.drawing',
	];

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
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
	public function getDriveSize(string $userId): array {
		$considerSharedFiles = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_files', '0') === '1';
		$params = [
			'fields' => '*',
		];
		$result = $this->googleApiService->request($userId, 'drive/v3/about', $params);
		if (isset($result['error']) || !isset($result['storageQuota']) || !isset($result['storageQuota']['usageInDrive'])) {
			return $result;
		}
		$info = [
			'usageInDrive' => (int)$result['storageQuota']['usageInDrive'],
			'sharedWithMeSize' => 0,
		];
		if (!$considerSharedFiles) {
			return $info;
		}
		// get total size of files that are shared with me
		$sharedWithMeSize = 0;
		$params = [
			'fields' => 'nextPageToken,files/name,files/ownedByMe,files/size',
			'pageSize' => 1000,
			'q' => "mimeType!='application/vnd.google-apps.folder' and sharedWithMe = true",
		];
		do {
			$result = $this->googleApiService->request($userId, 'drive/v3/files', $params);
			if (isset($result['error']) || !isset($result['files'])) {
				return ['error' => $result['error'] ?? 'no files found'];
			}

			foreach ($result['files'] as $file) {
				// extra check the file is not owned by me
				if (!$file['ownedByMe']) {
					$sharedWithMeSize += $file['size'] ?? 0;
				}
			}

			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		$info['sharedWithMeSize'] = $sharedWithMeSize;
		return $info;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function startImportDrive(string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'drive_output_dir', '/Google Drive');
		$targetPath = $targetPath ?: '/Google Drive';
		$considerSharedFiles = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_files', '0') === '1';
		$targetSharedPath = $this->config->getUserValue($userId, Application::APP_ID, 'drive_shared_with_me_output_dir', '/Google Drive/Shared with me');
		$targetSharedPath = $targetSharedPath ?: '/Google Drive/Shared with me';

		$alreadyImporting = $this->config->getUserValue($userId, Application::APP_ID, 'importing_drive', '0') === '1';
		if ($alreadyImporting) {
			return ['targetPath' => $targetPath];
		}

		// create root folder(s)
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if (!($folder instanceof Folder)) {
				return ['error' => 'Impossible to create Google Drive folder'];
			}
		}
		if ($considerSharedFiles) {
			if (!$userFolder->nodeExists($targetSharedPath)) {
				$userFolder->newFolder($targetSharedPath);
			} else {
				$folder = $userFolder->get($targetSharedPath);
				if (!($folder instanceof Folder)) {
					return ['error' => 'Impossible to create Google Drive "shared with me" folder'];
				}
			}
		}

		$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'drive_imported_size', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', '0');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'directory_progress');

		$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function importDriveJob(string $userId): void {
		$this->logger->debug('Importing drive files for ' . $userId);

		// Set the user to register the change under his name
		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingDrive = $this->config->getUserValue($userId, Application::APP_ID, 'importing_drive', '0') === '1';
		if (!$importingDrive) {
			return;
		}
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'drive_import_running', '0') === '1';
		$nowTs = (new DateTime())->getTimestamp();
		if ($jobRunning) {
			$lastJobStart = $this->config->getUserValue($userId, Application::APP_ID, 'drive_import_job_last_start');
			if ($lastJobStart !== '' && ($nowTs - intval($lastJobStart) < Application::IMPORT_JOB_TIMEOUT)) {
				$this->logger->info('Last job execution (' . strval($nowTs - intval($lastJobStart)) . ') is less than ' . strval(Application::IMPORT_JOB_TIMEOUT) . ' seconds ago, delaying execution');
				// last job has started less than an hour ago => we consider it can still be running
				$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
				return;
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'drive_import_running', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'drive_import_job_last_start', strval($nowTs));

		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'drive_output_dir', '/Google Drive');
		$targetPath = $targetPath ?: '/Google Drive';
		$targetSharedPath = $this->config->getUserValue($userId, Application::APP_ID, 'drive_shared_with_me_output_dir', '/Google Drive/Shared with me');
		$targetSharedPath = $targetSharedPath ?: '/Google Drive/Shared with me';

		// check if target paths are suitable
		$targetPath = $this->getNonSharedTargetPath($userId, $targetPath);
		$targetSharedPath = $this->getNonSharedTargetPath($userId, $targetSharedPath);

		// get progress
		$directoryProgressStr = $this->config->getUserValue($userId, Application::APP_ID, 'directory_progress', '[]');
		$directoryProgress = ($directoryProgressStr === '' || $directoryProgressStr === '[]')
			? []
			: json_decode($directoryProgressStr, true);
		// import by batch of 500 Mo
		$alreadyImported = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImportedSize = (int)$this->config->getUserValue($userId, Application::APP_ID, 'drive_imported_size', '0');
		try {
			$result = $this->importFiles(
				$userId, $targetPath, $targetSharedPath, 500000000,
				$alreadyImported, $alreadyImportedSize, $directoryProgress
			);
		} catch (Exception|Throwable $e) {
			$result = [
				'error' => 'Unknown job failure. ' . $e,
			];
		}
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			if (isset($result['finished']) && $result['finished']) {
				$nbImported = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
				$this->googleApiService->sendNCNotification($userId, 'import_drive_finished', [
					'nbImported' => $nbImported,
					'targetPath' => $targetPath,
				]);
			}
			if (isset($result['error'])) {
				$this->logger->error('Google Drive import error: ' . $result['error'], ['app' => Application::APP_ID]);
			}
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'drive_imported_size', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', '0');
			$this->config->deleteUserValue($userId, Application::APP_ID, 'directory_progress');
		} else {
			$this->config->setUserValue($userId, Application::APP_ID, 'directory_progress', json_encode($directoryProgress));
			$ts = (new DateTime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', $ts);
			$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'drive_import_running', '0');
	}

	/**
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @param int $alreadyImportedSize
	 * @param array $directoryProgress
	 * @return array
	 * @throws InvalidPathException
	 * @throws LockedException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 * @throws NoUserException
	 */
	public function importFiles(
		string $userId, string $targetPath, string $targetSharedPath,
		?int $maxDownloadSize = null, int $alreadyImported = 0, int $alreadyImportedSize = 0,
		array &$directoryProgress = [],
	): array {
		$considerSharedFiles = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_files', '0') === '1';

		// create root folder(s)
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$rootImportFolder = $userFolder->newFolder($targetPath);
		} else {
			$rootImportFolder = $userFolder->get($targetPath);
			if (!($rootImportFolder instanceof Folder)) {
				return ['error' => 'Impossible to create ' . '<redacted>' . ' folder'];
			}
		}
		if ($considerSharedFiles) {
			if (!$userFolder->nodeExists($targetSharedPath)) {
				$rootSharedWithMeImportFolder = $userFolder->newFolder($targetSharedPath);
			} else {
				$rootSharedWithMeImportFolder = $userFolder->get($targetSharedPath);
				if (!($rootSharedWithMeImportFolder instanceof Folder)) {
					return ['error' => 'Impossible to create Google Drive "shared with me" folder'];
				}
			}
		}

		$directoryIdsToExplore = [];
		try {
			// "ownedByMe" or "'me' in owners" doesn't work for files created by you in a folder that has been shared with you.
			$directoriesById = $this->collectFolders($directoryProgress, $directoryIdsToExplore, $userId, "mimeType='application/vnd.google-apps.folder' and 'me' in owners");
			if (isset($rootSharedWithMeImportFolder)) {
				// misses *files* without folders that are shared with you (those don't have any parent not even 'root').
				$sharedDirectoriesById = $this->collectFolders($directoryProgress, $directoryIdsToExplore, $userId, "mimeType='application/vnd.google-apps.folder' and sharedWithMe = true");
			} else {
				$sharedDirectoriesById = [];
			}
		} catch (Throwable $e) {
			return ['error' => $e->getMessage()];
		}

		// add root if it has not been imported yet
		if (!array_key_exists('root', $directoryProgress)) {
			$directoryIdsToExplore[] = 'root';
		}

		// filter all directories that belong to you but whose parent is shared with you
		if (isset($rootSharedWithMeImportFolder)) {
			try {
				$rootId = $this->retrieveRootId($userId);
				foreach ($directoriesById as $id => $dir) {
					$allParentsOwnedByMe = $this->recursivelyCheckParentOwnership($rootId, $directoriesById, $dir);
					if (!$allParentsOwnedByMe) {
						unset($directoriesById[$id]);
						$sharedDirectoriesById[$id] = $dir;
					}
				}
			} catch (Throwable $e) {
				return ['error' => $e->getMessage()];
			}
		}

		// create directories (recursive powa)
		if (!$this->createDirsUnder($directoriesById, $rootImportFolder)
			|| (isset($rootSharedWithMeImportFolder) && !$this->createDirsUnder($sharedDirectoriesById, $rootSharedWithMeImportFolder))) {
			return ['error' => 'Impossible to create Drive directories'];
		}

		// get files
		$info = $this->getDriveSize($userId);
		if (isset($info['error'])) {
			return $info;
		}
		$downloadedSize = 0;
		$nbDownloaded = 0;

		if (isset($rootSharedWithMeImportFolder)) {
			// retrieve "missed" shared files
			$query = "mimeType!='application/vnd.google-apps.folder' and sharedWithMe = true";
			$earlyResult = $this->retrieveFiles($userId, 'sharedRoot', $query, true,
				$rootImportFolder, $rootSharedWithMeImportFolder, $directoriesById, $sharedDirectoriesById,
				$nbDownloaded, $downloadedSize, $maxDownloadSize,
				$targetPath, $alreadyImported, $alreadyImportedSize, false);
			if ($earlyResult != null) {
				return $earlyResult;
			}
		}

		foreach ($directoryIdsToExplore as $dirId) {
			$query = "mimeType!='application/vnd.google-apps.folder' and '" . $dirId . "' in parents";
			$earlyResult = $this->retrieveFiles($userId, $dirId, $query, $considerSharedFiles,
				$rootImportFolder, $rootSharedWithMeImportFolder, $directoriesById, $sharedDirectoriesById,
				$nbDownloaded, $downloadedSize, $maxDownloadSize,
				$targetPath, $alreadyImported, $alreadyImportedSize);
			if ($earlyResult != null) {
				return $earlyResult;
			}

			// this dir was fully imported
			$directoryProgress[$dirId] = 1;
			if ($dirId !== 'root') {
				if (isset($directoriesById[$dirId])) {
					$this->touchFolder($directoriesById[$dirId]);
				} elseif (isset($sharedDirectoriesById[$dirId])) {
					$this->touchFolder($sharedDirectoriesById[$dirId]);
				}
			}
		}

		$this->touchRootImportFolder($userId, $rootImportFolder);
		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
		];
	}

	/**
	 * @param array $dirInfo
	 * @return void
	 * @throws Exception
	 */
	private function touchFolder(array $dirInfo): void {
		if (isset($dirInfo['modifiedTime']) && $dirInfo['modifiedTime'] !== null) {
			$d = new DateTime($dirInfo['modifiedTime']);
			$ts = $d->getTimestamp();
			$dirInfo['node']->touch($ts);
		}
	}

	/**
	 * @param string $userId
	 * @param Folder $rootImportFolder
	 * @return void
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function touchRootImportFolder(string $userId, Folder $rootImportFolder): void {
		$maxTs = 0;

		$params = [
			'pageSize' => 1000,
			'fields' => implode(',', [
				'nextPageToken',
				'files/id',
				'files/name',
				'files/parents',
				'files/mimeType',
				'files/ownedByMe',
				'files/webContentLink',
				'files/modifiedTime',
			]),
			'q' => "'root' in parents",
		];
		do {
			$result = $this->googleApiService->request($userId, 'drive/v3/files', $params);
			foreach ($result['files'] as $fileItem) {
				if (isset($fileItem['modifiedTime'])) {
					$d = new DateTime($fileItem['modifiedTime']);
					$ts = $d->getTimestamp();
					if ($ts > $maxTs) {
						$maxTs = $ts;
					}
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		if ($maxTs !== 0) {
			$rootImportFolder->touch($maxTs);
		}
	}

	/**
	 * @param string $userId
	 * @param string $dirId
	 * @param bool $considerSharedFiles
	 * @return array
	 */
	private function getFilesWithNameConflict(string $userId, string $query, bool $considerSharedFiles): array {
		$fileItems = [];
		$params = [
			'pageSize' => 1000,
			'fields' => implode(',', [
				'nextPageToken',
				'files/id',
				'files/name',
				'files/parents',
				'files/ownedByMe',
			]),
			'q' => $query,
		];
		do {
			$result = $this->googleApiService->request($userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return [];
			}

			$fileItems = array_merge($fileItems, $result['files']);

			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		// ignore shared files
		if (!$considerSharedFiles) {
			$fileItems = array_filter($fileItems, static function (array $fileItem) {
				return $fileItem['ownedByMe'];
			});
		}

		// detect duplicates
		$nbPerName = array_count_values(
			array_map(static function (array $fileItem) {
				return $fileItem['name'];
			}, $fileItems)
		);

		return array_map(static function (array $fileItem) {
			return $fileItem['id'];
		}, array_filter($fileItems, static function (array $fileItem) use ($nbPerName) {
			return $nbPerName[$fileItem['name']] > 1;
		}));
	}

	/**
	 * @param Folder $folder
	 * @param string $fileName
	 * @throws LockedException
	 * @throws NotPermittedException
	 */
	private function logFailedDownloadsForUser(Folder $folder, string $fileName): void {
		try {
			$logFile = $folder->get('failed-downloads.md');
		} catch (NotFoundException $e) {
			$logFile = $folder->newFile('failed-downloads.md');
		}

		if (!$logFile instanceof File) {
			return;
		}

		$stream = $logFile->fopen('a');
		if ($stream === false) {
			$this->logger->error('Could not open log file');
			return;
		}
		fwrite($stream, '1. Failed to download file: ' . $fileName . PHP_EOL);
		fclose($stream);
	}

	/**
	 * recursive directory creation
	 * associate the folder node to directories on the fly
	 *
	 * @param array &$directoriesById
	 * @param Folder $currentFolder
	 * @param string $currentFolderId
	 * @return bool success
	 */
	private function createDirsUnder(array &$directoriesById, Folder $currentFolder, string $currentFolderId = ''): bool {
		foreach ($directoriesById as $id => $dir) {
			$parentId = $dir['parent'];
			// create dir if we are on top OR if its parent is current dir
			if (($currentFolderId === '' && !array_key_exists($parentId, $directoriesById))
				|| $parentId === $currentFolderId) {
				$name = $this->fileUtils->sanitizeFilename((string)($dir['name']), (string)$id);
				if (!$currentFolder->nodeExists($name)) {
					$newDir = $currentFolder->newFolder($name);
				} else {
					$newDir = $currentFolder->get($name);
					if (!($newDir instanceof Folder)) {
						return false;
					}
				}
				$directoriesById[$id]['node'] = $newDir;
				$success = $this->createDirsUnder($directoriesById, $newDir, (string)$id);
				if (!$success) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Create new file in the given folder with given filename
	 * Download contents of the file from Google Drive and save it into the created file
	 * @param Folder $saveFolder
	 * @param string $fileName
	 * @param string $userId
	 * @param string $fileUrl
	 * @param array $fileItem
	 * @param array $params
	 * @return ?int downloaded size, null if error during file creation or download
	 * @throws InvalidPathException
	 * @throws LockedException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function downloadAndSaveFile(
		Folder $saveFolder, string $fileName, string $userId,
		string $fileUrl, array $fileItem, array $params = [],
	): ?int {
		try {
			$savedFile = $saveFolder->newFile($fileName);
		} catch (NotPermittedException $e) {
			return null;
		}

		try {
			$resource = $savedFile->fopen('w');
		} catch (LockedException $e) {
			return null;
		}
		if ($resource === false) {
			return null;
		}

		$res = $this->googleApiService->simpleDownload($userId, $fileUrl, $resource, $params);
		if (!isset($res['error'])) {
			if (is_resource($resource)) {
				fclose($resource);
			}
			if (isset($fileItem['modifiedTime'])) {
				$d = new DateTime($fileItem['modifiedTime']);
				$ts = $d->getTimestamp();
				$savedFile->touch($ts);
			} else {
				$savedFile->touch();
			}
			$stat = $savedFile->stat();
			return $stat['size'] ?? 0;
		} else {
			if ($savedFile->isDeletable()) {
				$savedFile->unlock(ILockingProvider::LOCK_EXCLUSIVE);
				$savedFile->delete();
			}
		}
		return null;
	}

	/**
	 * @param array $fileItem
	 * @param string $userId
	 * @param bool $hasNameConflict
	 * @return string name of the file to be saved
	 */
	private function getFileName(array $fileItem, string $userId, bool $hasNameConflict): string {
		$fileName = $this->fileUtils->sanitizeFilename((string)($fileItem['name']), (string)$fileItem['id']);

		if (in_array($fileItem['mimeType'], array_values(self::DOCUMENT_MIME_TYPES))) {
			$documentFormat = $this->getUserDocumentFormat($userId);
			switch ($fileItem['mimeType']) {
				case self::DOCUMENT_MIME_TYPES['document']:
					$fileName .= $documentFormat === 'openxml' ? '.docx' : '.odt';
					break;
				case self::DOCUMENT_MIME_TYPES['spreadsheet']:
					$fileName .= $documentFormat === 'openxml' ? '.xlsx' : '.ods';
					break;
				case self::DOCUMENT_MIME_TYPES['presentation']:
					$fileName .= $documentFormat === 'openxml' ? '.pptx' : '.odp';
					break;
				case self::DOCUMENT_MIME_TYPES['drawing']:
					$fileName .= '.pdf';
					break;
			}
		}

		$extension = pathinfo($fileName, PATHINFO_EXTENSION);
		$name = pathinfo($fileName, PATHINFO_FILENAME);
		if ($hasNameConflict) {
			$name .= '_' . substr($fileItem['id'], -6);
		}

		return strlen($extension) ? $name . '.' . $extension : $name;
	}

	/**
	 * @param string $userId
	 * @return string User's preferred document format
	 */
	private function getUserDocumentFormat(string $userId): string {
		$documentFormat = $this->config->getUserValue($userId, Application::APP_ID, 'document_format', 'openxml');
		if (!in_array($documentFormat, ['openxml', 'opendoc'])) {
			$documentFormat = 'openxml';
		}
		return $documentFormat;
	}

	/**
	 * @param string $mimeType
	 * @param string $documentFormat
	 * @return array Request parameters for document
	 */
	private function getDocumentRequestParams(string $mimeType, string $documentFormat): array {
		$params = [];
		switch ($mimeType) {
			case self::DOCUMENT_MIME_TYPES['document']:
				$params['mimeType'] = $documentFormat === 'openxml'
					? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
					: 'application/vnd.oasis.opendocument.text';
				break;
			case self::DOCUMENT_MIME_TYPES['spreadsheet']:
				$params['mimeType'] = $documentFormat === 'openxml'
					? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
					: 'application/vnd.oasis.opendocument.spreadsheet';
				break;
			case self::DOCUMENT_MIME_TYPES['presentation']:
				$params['mimeType'] = $documentFormat === 'openxml'
					? 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
					: 'application/vnd.oasis.opendocument.presentation';
				break;
		}
		return $params;
	}

	/**
	 * @param string $userId
	 * @param array $fileItem
	 * @param Folder $saveFolder
	 * @param string $fileName
	 * @return ?int downloaded size, null if error getting file
	 */
	private function getFile(string $userId, array $fileItem, Folder $saveFolder, string $fileName): ?int {
		if (in_array($fileItem['mimeType'], array_values(self::DOCUMENT_MIME_TYPES))) {
			$documentFormat = $this->getUserDocumentFormat($userId);
			// potentially a doc
			$params = $this->getDocumentRequestParams($fileItem['mimeType'], $documentFormat);
			$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . urlencode((string)$fileItem['id']) . '/export';
			$result = $this->downloadAndSaveFile($saveFolder, $fileName, $userId, $fileUrl, $fileItem, $params);
			if ($result !== null) {
				return $result;
			}
			$this->logger->debug('Document export failed, trying through exportLinks', ['fileItem' => $fileItem]);
			// Try a different method to export if that fails due to the file being too large
			$exportUrl = '/drive/v3/files/' . urlencode((string)$fileItem['id']);
			$result = $this->googleApiService->request($userId, $exportUrl, ['fields' => 'exportLinks']);
			if (!isset($result['exportLinks'])) {
				return null;
			}
			$fileUrl = null;
			$formatStart = $documentFormat === 'openxml' ? 'application/vnd.openxmlformats-officedocument.' : 'application/vnd.oasis.opendocument.';
			foreach ($result['exportLinks'] as $exportType => $potentialUrl) {
				if (str_starts_with($exportType, $formatStart)) {
					$fileUrl = $potentialUrl;
					break;
				}
			}
			// Fallback to PDF if needed
			if ($fileUrl === null) {
				if (isset($result['exportLinks']['application/pdf'])) {
					$this->logger->debug('Falling back to pdf', ['fileItem' => $fileItem]);
					$fileUrl = $result['exportLinks']['application/pdf'];
				} else {
					$this->logger->error('Could not export document', ['fileItem' => $fileItem]);
					return null;
				}
			}
			$this->logger->debug('Document export succeeded', ['fileItem' => $fileItem, 'fileUrl' => $fileUrl]);
			return $this->downloadAndSaveFile($saveFolder, $fileName, $userId, $fileUrl, $fileItem);
		} elseif (isset($fileItem['webContentLink'])) {
			// classic file
			$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . urlencode((string)$fileItem['id']) . '?alt=media';
			return $this->downloadAndSaveFile($saveFolder, $fileName, $userId, $fileUrl, $fileItem);
		}
		return null;
	}

	/**
	 * @param string $userId
	 * @param string $targetPath
	 */
	private function getNonSharedTargetPath(string $userId, string $targetPath): string {
		try {
			$targetNode = $this->root->getUserFolder($userId)->get($targetPath);
			if ($targetNode->isShared()) {
				$this->logger->error('Target path ' . $targetPath . 'is shared, resorting to user root folder');
				return '/';
			}
		} catch (NotFoundException) {
			// noop, folder doesn't exist
		} catch (NotPermittedException) {
			$this->logger->error('Cannot determine if target path ' . $targetPath . 'is shared, resorting to root folder');
			return '/';
		}
		return $targetPath;
	}

	private function retrieveRootId(string $userId): string {
		$fileId = 'root';
		$result = $this->googleApiService->request($userId, 'drive/v3/files/' . $fileId);
		if (isset($result['error'])) {
			throw new RuntimeException($result['error']);
		}
		return $result['id'];
	}

	private function collectFolders(array $directoryProgress, array &$directoryIdsToExplore, string $userId, string $query): array {
		$directoriesById = [];
		$params = [
			'pageSize' => 1000,
			'fields' => '*',
			'q' => $query,
		];
		do {
			$result = $this->googleApiService->request($userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				throw new RuntimeException($result['error']);
			}
			foreach ($result['files'] as $dir) {
				$directoriesById[$dir['id']] = [
					'name' => preg_replace('/\//', '-slash-', $dir['name']),
					'parent' => (isset($dir['parents']) && count($dir['parents']) > 0) ? $dir['parents'][0] : null,
					'modifiedTime' => $dir['modifiedTime'] ?? null,
					'ownedByMe' => $dir['ownedByMe'] ?? false,
				];

				// what we should explore
				if (!array_key_exists($dir['id'], $directoryProgress)) {
					$directoryIdsToExplore[] = $dir['id'];
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return $directoriesById;
	}

	private function recursivelyCheckParentOwnership(string $rootId, $directoriesById, $dir_entry): bool {
		$parentId = $dir_entry['parent'];
		if (isset($parentId)) {
			if (!isset($directoriesById[$parentId])) {
				return $rootId === $parentId;
			}

			$parent = $directoriesById[$parentId];
			if (!$parent['ownedByMe']) {
				return false;
			}
			return $this->recursivelyCheckParentOwnership($rootId, $directoriesById, $parent);
		} else {
			return $dir_entry['ownedByMe'];
		}
	}

	private function retrieveFiles(string $userId, string $dirId, string $query, bool $considerSharedFiles, Folder $rootImportFolder, ?Folder $rootSharedWithMeImportFolder, array $directoriesById, array $sharedDirectoriesById, &$nbDownloaded, &$downloadedSize, $maxDownloadSize, $targetPath, $alreadyImported, $alreadyImportedSize, bool $allowParents = true): ?array {
		$conflictingIds = $this->getFilesWithNameConflict($userId, $query, $considerSharedFiles);
		$params = [
			'pageSize' => 1000,
			'fields' => implode(',', [
				'nextPageToken',
				'files/id',
				'files/name',
				'files/parents',
				'files/mimeType',
				'files/ownedByMe',
				'files/webContentLink',
				'files/modifiedTime',
			]),
			'q' => $query,
		];
		do {
			$result = $this->googleApiService->request($userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['files'] as $fileItem) {
				try {
					if (isset($fileItem['parents']) && count($fileItem['parents']) > 0) {
						if (!$allowParents) {
							continue;
						}

						$parent = $fileItem['parents'][0];
						if (isset($directoriesById[$parent]['node'])) {
							$saveFolder = $directoriesById[$parent]['node'];
						} elseif (isset($sharedDirectoriesById[$parent]['node'])) {
							$saveFolder = $sharedDirectoriesById[$parent]['node'];
						}
					}

					if (!isset($saveFolder)) {
						if ($dirId === 'sharedRoot') {
							$saveFolder = $rootSharedWithMeImportFolder;
						} else {
							$saveFolder = $rootImportFolder;
						}
					}

					$fileName = $this->getFileName($fileItem, $userId, in_array($fileItem['id'], $conflictingIds));

					// If file already exists in folder, don't download unless timestamp is different
					if ($saveFolder->nodeExists($fileName) === true) {
						$savedFile = $saveFolder->get($fileName);
						$timestampOnFile = $savedFile->getMtime();
						$d = new DateTime($fileItem['modifiedTime']);
						$timestampOnDrive = $d->getTimestamp();

						if ($timestampOnFile < $timestampOnDrive) {
							$savedFile->delete();
						} else {
							continue;
						}
					}

					$size = $this->getFile($userId, $fileItem, $saveFolder, $fileName);

					if (!is_null($size)) {
						$nbDownloaded++;
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImported + $nbDownloaded);
						$downloadedSize += $size;
						$this->config->setUserValue($userId, Application::APP_ID, 'drive_imported_size', $alreadyImportedSize + $downloadedSize);
						if ($maxDownloadSize !== null && $downloadedSize > $maxDownloadSize) {
							return [
								'nbDownloaded' => $nbDownloaded,
								'targetPath' => $targetPath,
								'finished' => false,
							];
						}
					} elseif (!$saveFolder->nodeExists($fileName)) {
						if ($dirId === 'sharedRoot' || (isset($parent) && isset($sharedDirectoriesById[$parent]['node']))) {
							$filePathInDrive = '/' . $rootSharedWithMeImportFolder->getName() . $rootSharedWithMeImportFolder->getRelativePath($saveFolder->getPath());
						} else {
							$filePathInDrive = $rootImportFolder->getRelativePath($saveFolder->getPath());
						}
						if (!str_ends_with($filePathInDrive, '/')) {
							$filePathInDrive .= '/';
						}
						$filePathInDrive .= $fileItem['name'];
						$this->logFailedDownloadsForUser($rootImportFolder, $filePathInDrive);
					}
				} catch (Throwable $e) {
					$this->logger->warning('Error while importing file', ['exception' => $e]);
					$this->logger->debug('Skipping file ' . strval($fileItem['id']));
					continue;
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		return null;
	}
}
