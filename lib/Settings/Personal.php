<?php

namespace OCA\Google\Settings;

use OC\User\NoUserException;
use OCA\Google\AppInfo\Application;
use OCA\Google\Service\GoogleAPIService;
use OCA\Google\Service\SecretService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUserManager;
use OCP\Settings\ISettings;
use Throwable;

class Personal implements ISettings {

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IRootFolder $root,
		private IUserManager $userManager,
		private IInitialState $initialStateService,
		private GoogleAPIService $googleAPIService,
		private ?string $userId,
		private SecretService $secretService,
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
		$userName = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_name', lazy: true);
		$driveOutputDir = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'drive_output_dir', '/Google Drive', lazy: true);
		$driveOutputDir = $driveOutputDir ?: '/Google Drive';
		$driveSharedWithMeOutputDir = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'drive_shared_with_me_output_dir', '/Google Drive/Shared with me', lazy: true);
		$driveSharedWithMeOutputDir = $driveSharedWithMeOutputDir ?: '/Google Drive/Shared with me';
		$considerAllEvents = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'consider_all_events', '1', lazy: true) === '1';
		$considerSharedFiles = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'consider_shared_files', '0', lazy: true) === '1';
		$considerSharedAlbums = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'consider_shared_albums', '0', lazy: true) === '1';
		$considerOtherContacts = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'consider_other_contacts', '0', lazy: true) === '1';
		$documentFormat = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'document_format', 'openxml', lazy: true);
		if (!in_array($documentFormat, ['openxml', 'opendoc'])) {
			$documentFormat = 'openxml';
		}

		// for OAuth
		$clientID = $this->appConfig->getAppValueString('client_id', lazy: true);
		$clientSecret = $this->appConfig->getAppValueString('client_secret', lazy: true) !== '';
		$usePopup = $this->appConfig->getAppValueString('use_popup', '0', lazy: true);

		// get free space
		$userFolder = $this->root->getUserFolder($this->userId);
		$freeSpace = self::getFreeSpace($userFolder, $driveOutputDir);
		$user = $this->userManager->get($this->userId);

		// make a request to potentially refresh the token before the settings page is loaded
		$accessToken = $this->secretService->getEncryptedUserValue($this->userId, 'token');
		if ($accessToken) {
			$this->googleAPIService->request($this->userId, 'oauth2/v1/userinfo', ['alt' => 'json']);
		}

		// Get scopes of user
		$userScopesString = $this->userConfig->getValueString($this->userId, Application::APP_ID, 'user_scopes', '{}', lazy: true);
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
			'consider_all_events' => $considerAllEvents,
			'consider_shared_files' => $considerSharedFiles,
			'consider_shared_albums' => $considerSharedAlbums,
			'consider_other_contacts' => $considerOtherContacts,
			'document_format' => $documentFormat,
			'drive_output_dir' => $driveOutputDir,
			'drive_shared_with_me_output_dir' => $driveSharedWithMeOutputDir,
			'user_scopes' => $userScopes,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'migration';
	}

	public function getPriority(): int {
		return 10;
	}

	/**
	 * @param Folder $userRoot
	 * @param string $outputDir
	 * @return bool|float|int
	 * @throws NotFoundException
	 */
	public static function getFreeSpace(Folder $userRoot, string $outputDir) {
		try {
			// OutputDir can be on an external storage which can have more free space
			$freeSpace = $userRoot->get($outputDir)->getStorage()->free_space('/');
		} catch (Throwable $e) {
			$freeSpace = false;
		}
		return $freeSpace !== false && $freeSpace > 0 ? $freeSpace : $userRoot->getStorage()->free_space('/');
	}
}
