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
use OCP\ITempManager;

use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportDriveJob;

class GoogleDriveAPIService {

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
								ITempManager $tempManager,
								GoogleAPIService $googleApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->jobList = $jobList;
		$this->root = $root;
		$this->tempManager = $tempManager;
		$this->googleApiService = $googleApiService;
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
		$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/about', $params);
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
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error']) || !isset($result['files'])) {
				return $result;
			}
			$nbFiles += count($result['files']);
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));
		$info['nbFiles'] = $nbFiles;
		return $info;
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @return array
	 */
	public function startImportDrive(string $accessToken, string $userId): array {
		$targetPath = $this->l10n->t('Google Drive import');
		$alreadyImporting = $this->config->getUserValue($userId, Application::APP_ID, 'importing_drive', '0') === '1';
		if ($alreadyImporting) {
			return ['targetPath' => $targetPath];
		}
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
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImported = (int) $alreadyImported;
		$result = $this->importFiles($accessToken, $userId, $targetPath, 500000000, $alreadyImported);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_drive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->googleApiService->sendNCNotification($userId, 'import_drive_finished', [
					'nbImported' => $result['totalSeen'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_drive_import_timestamp', $ts);
			$this->jobList->add(ImportDriveJob::class, ['user_id' => $userId]);
		}
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importFiles(string $accessToken, string $userId, string $targetPath,
								?int $maxDownloadSize = null, int $alreadyImported): array {
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
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['files'] as $dir) {
				$directoriesById[$dir['id']] = [
					'name' => preg_replace('/\//', '_', $dir['name']),
					'parent' => (isset($dir['parents']) && count($dir['parents']) > 0) ? $dir['parents'][0] : null,
				];
			}
			$params['pageToken'] = $result['nextPageToken'] ?? '';
		} while (isset($result['nextPageToken']));

		// create directories (recursive powa)
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
		do {
			$result = $this->googleApiService->request($accessToken, $userId, 'drive/v3/files', $params);
			if (isset($result['error'])) {
				return $result;
			}
			foreach ($result['files'] as $fileItem) {
				$totalSeenNumber++;
				$size = $this->getFile($accessToken, $userId, $fileItem, $directoriesById, $folder);
				if (!is_null($size)) {
					$nbDownloaded++;
					$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImported + $nbDownloaded);
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
		$fileName = preg_replace('/\//', '_', $fileItem['name']);
		if (isset($fileItem['parents']) && count($fileItem['parents']) > 0 && array_key_exists($fileItem['parents'][0], $directoriesById)) {
			$saveFolder = $directoriesById[$fileItem['parents'][0]]['node'];
		} else {
			$saveFolder = $topFolder;
		}
		// classic file
		if (isset($fileItem['webContentLink'])) {
			if (!$saveFolder->nodeExists($fileName)) {
				$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . $fileItem['id'] . '?alt=media';
				$tmpFilePath = $this->tempManager->getTemporaryFile();
				$res = $this->googleApiService->simpleDownload($accessToken, $userId, $fileUrl, $tmpFilePath);
				if (!isset($res['error'])) {
					$savedFile = $saveFolder->newFile($fileName);
					$resource = $savedFile->fopen('w');
					$copied = $this->googleApiService->chunkedCopy($tmpFilePath, $resource);
					$savedFile->touch();
					unlink($tmpFilePath);
					return $copied;
				}
				unlink($tmpFilePath);
			}
		} else {
			// potentially a doc
			if ($fileItem['mimeType'] === 'application/vnd.google-apps.document') {
				$fileName .= '.odt';
				$mimeType = 'application/vnd.oasis.opendocument.text';
				//$fileName .= '.docx';
				//$mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			} elseif ($fileItem['mimeType'] === 'application/vnd.google-apps.spreadsheet') {
				$fileName .= '.ods';
				$mimeType = 'application/vnd.oasis.opendocument.spreadsheet';
				//$fileName .= '.xlsx';
				//$mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			} elseif ($fileItem['mimeType'] === 'application/vnd.google-apps.presentation') {
				$fileName .= '.odp';
				$mimeType = 'application/vnd.oasis.opendocument.presentation';
				//$fileName .= '.pptx';
				//$mimeType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
			} else {
				return null;
			}
			if (!$saveFolder->nodeExists($fileName)) {
				$params = [
					'mimeType' => $mimeType,
				];
				$fileUrl = 'https://www.googleapis.com/drive/v3/files/' . $fileItem['id'] . '/export';
				$tmpFilePath = $this->tempManager->getTemporaryFile();
				$res = $this->googleApiService->simpleDownload($accessToken, $userId, $fileUrl, $tmpFilePath, $params);
				if (!isset($res['error'])) {
					$savedFile = $saveFolder->newFile($fileName);
					$resource = $savedFile->fopen('w');
					$copied = $this->googleApiService->chunkedCopy($tmpFilePath, $resource);
					$savedFile->touch();
					unlink($tmpFilePath);
					return $copied;
				}
				unlink($tmpFilePath);
			}
		}
		return null;
	}
}
