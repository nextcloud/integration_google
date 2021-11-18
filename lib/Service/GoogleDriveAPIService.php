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
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\BackgroundJob\IJobList;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;

use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportDriveJob;

class GoogleDriveAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IJobList
	 */
	private $jobList;
	/**
	 * @var GoogleAPIService
	 */
	private $googleApiService;
	/**
	 * @var UserScopeService
	 */
	private $userScopeService;

	private const DOCUMENT_MIME_TYPES = [
		'document' => 'application/vnd.google-apps.document',
		'spreadsheet' => 'application/vnd.google-apps.spreadsheet',
		'presentation' => 'application/vnd.google-apps.presentation',
	];

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IConfig $config,
								IRootFolder $root,
								IJobList $jobList,
								UserScopeService $userScopeService,
								GoogleAPIService $googleApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->config = $config;
		$this->root = $root;
		$this->jobList = $jobList;
		$this->googleApiService = $googleApiService;
		$this->userScopeService = $userScopeService;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function getDriveSize(string $accessToken, string $userId): array {
		$considerSharedFiles = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_files', '0') === '1';
		$params = [
			'fields' => '*',
		];
		$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/about', $params);
		if (isset($result['error']) || !isset($result['storageQuota']) || !isset($result['storageQuota']['usageInDrive'])) {
			return $result;
		}
		$info = [
			'usageInDrive' => $result['storageQuota']['usageInDrive'] ?? 0,
		];
		// count files
		$nbFiles = 0;
		$sharedWithMeSize = 0;
		$params = [
			'fields' => 'files/name,files/ownedByMe',
			'pageSize' => 1000,
			'q' => "mimeType!='application/vnd.google-apps.folder'",
		];
		if ($considerSharedFiles) {
			$params['fields'] = 'files/name,files/ownedByMe,files/size';
		}
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error']) || !isset($result['files'])) {
				return ['error' => $result['error'] ?? 'no files found'];
			}
			if ($considerSharedFiles) {
				foreach ($result['files'] as $file) {
					if (!$file['ownedByMe']) {
						$sharedWithMeSize += $file['size'] ?? 0;
					}
				}
				$nbFiles += count($result['files']);
			} else {
				foreach ($result['files'] as $file) {
					if ($file['ownedByMe']) {
						$nbFiles++;
					}
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		$info['nbFiles'] = $nbFiles;
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
		$alreadyImporting = $this->config->getUserValue($userId, Application::APP_ID, 'importing_drive', '0') === '1';
		if ($alreadyImporting) {
			return ['targetPath' => $targetPath];
		}
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Google Drive folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
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
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'drive_import_running', '0') === '1';
		if (!$importingDrive || $jobRunning) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'drive_import_running', '1');

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'drive_output_dir', '/Google Drive');
		$targetPath = $targetPath ?: '/Google Drive';
		// get progress
		$directoryProgressStr = $this->config->getUserValue($userId, Application::APP_ID, 'directory_progress', '[]');
		$directoryProgress = ($directoryProgressStr === '' || $directoryProgressStr === '[]')
			? []
			: json_decode($directoryProgressStr, true);
		// import by batch of 500 Mo
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImported = (int) $alreadyImported;
		try {
			$result = $this->importFiles($accessToken, $userId, $targetPath, 500000000, $alreadyImported, $directoryProgress);
		} catch (\Exception | \Throwable $e) {
			$result = [
				'error' => 'Unknown job failure. ' . $e->getMessage(),
			];
		}
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			if (isset($result['finished']) && $result['finished']) {
				$nbImported = (int) $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
				$this->googleApiService->sendNCNotification($userId, 'import_drive_finished', [
					'nbImported' => $nbImported,
					'targetPath' => $targetPath,
				]);
			}
			if (isset($result['error'])) {
				$this->logger->error('Google Drive import error: ' . $result['error'], ['app' => $this->appName]);
			}
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', '0');
			$this->config->deleteUserValue($userId, Application::APP_ID, 'directory_progress');
		} else {
			$this->config->setUserValue($userId, Application::APP_ID, 'directory_progress', json_encode($directoryProgress));
			$ts = (new Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', $ts);
			$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'drive_import_running', '0');
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @param array $directoryProgress
	 * @return array
	 * @throws NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OCP\PreConditionNotMetException
	 * @throws \OC\User\NoUserException
	 */
	public function importFiles(string $accessToken, string $userId, string $targetPath,
								?int $maxDownloadSize = null, int $alreadyImported = 0, array &$directoryProgress = []): array {
		$considerSharedFiles = $this->config->getUserValue($userId, Application::APP_ID, 'consider_shared_files', '0') === '1';
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create ' . '<redacted>' . ' folder'];
			}
		}

		$directoriesById = [];
		$directoryIdsToExplore = [];
		$params = [
			'pageSize' => 1000,
			'fields' => '*',
			'q' => "mimeType='application/vnd.google-apps.folder'",
		];
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['files'] as $dir) {
				// ignore shared files
				if (!$considerSharedFiles && !$dir['ownedByMe']) {
					continue;
				}
				$directoriesById[$dir['id']] = [
					'name' => preg_replace('/\//', '-slash-', $dir['name']),
					'parent' => (isset($dir['parents']) && count($dir['parents']) > 0) ? $dir['parents'][0] : null,
				];
				// what we should explore
				if (!array_key_exists($dir['id'], $directoryProgress)) {
					$directoryIdsToExplore[] = $dir['id'];
				}
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		// add root if it has not been imported yet
		if (!array_key_exists('root', $directoryProgress)) {
			$directoryIdsToExplore[] = 'root';
		}

		// create directories (recursive powa)
		if (!$this->createDirsUnder($directoriesById, $folder)) {
			return ['error' => 'Impossible to create Drive directories'];
		}

		// get files
		$info = $this->getDriveSize($accessToken, $userId);
		if (isset($info['error'])) {
			return $info;
		}
		// $nbFilesOnDrive = $info['nbFiles'];
		$downloadedSize = 0;
		$nbDownloaded = 0;

		foreach ($directoryIdsToExplore as $dirId) {
			$params = [
				'pageSize' => 1000,
				'fields' => implode(',', [
					'files/id',
					'files/name',
					'files/parents',
					'files/mimeType',
					'files/ownedByMe',
					'files/webContentLink',
					'files/modifiedTime',
				]),
				'q' => "mimeType!='application/vnd.google-apps.folder' and '" . $dirId . "' in parents",
			];
			do {
				$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/files', $params);
				if (isset($result['error'])) {
					return $result;
				}
				foreach ($result['files'] as $fileItem) {
					// ignore shared files
					if (!$considerSharedFiles && !$fileItem['ownedByMe']) {
						continue;
					}

					if (isset($fileItem['parents']) && count($fileItem['parents']) > 0
						&& isset($directoriesById[$fileItem['parents'][0]], $directoriesById[$fileItem['parents'][0]]['node'])) {
						$saveFolder = $directoriesById[$fileItem['parents'][0]]['node'];
					} else {
						$saveFolder = $folder;
					}

					$fileName = $this->getFileName($fileItem, $userId);

					// If file already exists in folder, don't download unless timestamp is different
					if ($saveFolder->nodeExists($fileName)) {
						$savedFile = $saveFolder->get($fileName);
						$timestampOnFile = $savedFile->getMtime();
						$d = new Datetime($fileItem['modifiedTime']);
						$timestampOnDrive = $d->getTimestamp();

						if ($timestampOnFile < $timestampOnDrive) {
							$savedFile->delete();
						} else {
							continue;
						}
					}

					$size = $this->getFile($accessToken, $userId, $fileItem, $saveFolder, $fileName);

					if (!is_null($size)) {
						$nbDownloaded++;
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImported + $nbDownloaded);
						$downloadedSize += $size;
						if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
							return [
								'nbDownloaded' => $nbDownloaded,
								'targetPath' => $targetPath,
								'finished' => false,
							];
						}
					} elseif (!$saveFolder->nodeExists($fileName)) {
						$filePathInDrive = $dirId === 'root' ? '/' . $fileItem['name'] : $directoriesById[$dirId]['name'] . '/' . $fileItem['name'];
						$this->logFailedDownloadsForUser($folder, $filePathInDrive);
					}
				}
				$params['pageToken'] = $result['nextPageToken'] ?? '';
			} while (isset($result['nextPageToken']));
			// this dir was fully imported
			$directoryProgress[$dirId] = 1;
		}

		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
		];
	}

	/**
	 * @param Folder $folder
	 * @param string $fileName
	 * @throws LockedException
	 * @throws \OCP\Files\NotPermittedException
	 */
	private function logFailedDownloadsForUser(Folder $folder, string $fileName): void {
		try {
			$logFile = $folder->get('failed-downloads.md');
		} catch (NotFoundException $e) {
			$logFile = $folder->newFile('failed-downloads.md');
		}

		$stream = $logFile->fopen('a');
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
	 * Create new file in the given folder with given filename
	 * Download contents of the file from Google Drive and save it into the created file
	 * @param string $accessToken
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
	 private function downloadAndSaveFile(string $accessToken, Folder $saveFolder, string $fileName, string $userId,
										  string $fileUrl, array $fileItem, array $params = []): ?int {
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

		$res = $this->googleApiService->simpleDownload($accessToken, $userId, $fileUrl, $resource, $params);
		if (!isset($res['error'])) {
			if (is_resource($resource)) {
				fclose($resource);
			}
			if (isset($fileItem['modifiedTime'])) {
				$d = new Datetime($fileItem['modifiedTime']);
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
	 * @return string name of the file to be saved
	*/
	private function getFileName(array $fileItem, string $userId): string {
		$fileName = preg_replace('/\//', '-', $fileItem['name'] ?? 'Untitled');

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
			}
		}

		$extension = pathinfo($fileName, PATHINFO_EXTENSION);
		$name = pathinfo($fileName, PATHINFO_FILENAME) . '_' . substr($fileItem['id'], -6);

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
					:'application/vnd.oasis.opendocument.presentation';
				break;
		}
		return $params;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param array $fileItem
	 * @param Folder $saveFolder
	 * @param string $fileName
	 * @return ?int downloaded size, null if error getting file
	 */
	private function getFile(string $accessToken, string $userId, array $fileItem, Folder $saveFolder, string $fileName): ?int {
		if (in_array($fileItem['mimeType'], array_values(self::DOCUMENT_MIME_TYPES))) {
			$documentFormat = $this->getUserDocumentFormat($userId);
			// potentially a doc
			$params = $this->getDocumentRequestParams($fileItem['mimeType'], $documentFormat);
			$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileItem['id']) . '/export';
			return $this->downloadAndSaveFile($accessToken, $saveFolder, $fileName, $userId, $fileUrl, $fileItem, $params);
		} elseif (isset($fileItem['webContentLink'])) {
			// classic file
			$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileItem['id']) . '?alt=media';
			return $this->downloadAndSaveFile($accessToken, $saveFolder, $fileName, $userId, $fileUrl, $fileItem);
		}
		return null;
	}
}
