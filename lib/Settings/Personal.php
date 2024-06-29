<?php

namespace OCA\Google\Settings;

use OC\User\NoUserException;
use OCA\Google\AppInfo\Application;
use OCA\Google\Service\GoogleAPIService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IRootFolder $root,
		private IUserManager $userManager,
		private IInitialState $initialStateService,
		private GoogleAPIService $googleAPIService,
		private ?string $userId
	) {
	}

	/**
	 * @return TemplateResponse
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws NoUserException
	 */
	public function getForm(): TemplateResponse {
		if ($this->userId === null) {
			return new TemplateResponse(Application::APP_ID, 'personalSettings');
		}
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		$driveOutputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'drive_output_dir', '/Google Drive');
		$driveOutputDir = $driveOutputDir ?: '/Google Drive';
		$photoOutputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'photo_output_dir', '/Google Photos');
		$photoOutputDir = $photoOutputDir ?: '/Google Photos';
		$considerSharedFiles = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_shared_files', '0') === '1';
		$considerSharedAlbums = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_shared_albums', '0') === '1';
		$documentFormat = $this->config->getUserValue($this->userId, Application::APP_ID, 'document_format', 'openxml');
		if (!in_array($documentFormat, ['openxml', 'opendoc'])) {
			$documentFormat = 'openxml';
		}

		// for OAuth
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret') !== '';
		$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0');

		// get free space
		$userFolder = $this->root->getUserFolder($this->userId);
		$freeSpace = self::getFreeSpace($userFolder, $driveOutputDir);
		$user = $this->userManager->get($this->userId);

		// make a request to potentially refresh the token before the settings page is loaded
		$accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		if ($accessToken) {
			$this->googleAPIService->request($this->userId, 'oauth2/v1/userinfo', ['alt' => 'json']);
		}

		// Get scopes of user
		$userScopesString = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_scopes', '{}');
		/** @var bool|null|array $userScopes */
		$userScopes = json_decode($userScopesString, true);
		if (!is_array($userScopes)) {
			$userScopes = ['nothing' => 'nothing'];
		}

		$userConfig = [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'use_popup' => ($usePopup === '1'),
			'user_name' => $userName,
			'free_space' => $freeSpace,
			'user_quota' => $user === null ? '' : $user->getQuota(),
			'consider_shared_files' => $considerSharedFiles,
			'consider_shared_albums' => $considerSharedAlbums,
			'document_format' => $documentFormat,
			'drive_output_dir' => $driveOutputDir,
			'photo_output_dir' => $photoOutputDir,
			'user_scopes' => $userScopes,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'google_synchronization';
	}

	public function getPriority(): int {
		return 10;
	}

	/**
	 * @param \OCP\Files\Folder $userRoot
	 * @param string $outputDir
	 * @return bool|float|int
	 * @throws NotFoundException
	 */
	public static function getFreeSpace(\OCP\Files\Folder $userRoot, string $outputDir) {
		try {
			// OutputDir can be on an external storage which can have more free space
			$freeSpace = $userRoot->get($outputDir)->getStorage()->free_space('/');
		} catch (\Throwable $e) {
			$freeSpace = false;
		}
		return $freeSpace !== false && $freeSpace > 0 ? $freeSpace : $userRoot->getStorage()->free_space('/');
	}
}
