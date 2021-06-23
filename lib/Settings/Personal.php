<?php
namespace OCA\Google\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCA\Google\AppInfo\Application;

class Personal implements ISettings {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(
								IConfig $config,
								IRootFolder $root,
								IUserManager $userManager,
								IInitialState $initialStateService,
								?string $userId) {
		$this->config = $config;
		$this->root = $root;
		$this->userManager = $userManager;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
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

		// get free space
		$userFolder = $this->root->getUserFolder($this->userId);
		$freeSpace = $userFolder->getStorage()->free_space('/');
		$user = $this->userManager->get($this->userId);

		$userConfig = [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'user_name' => $userName,
			'free_space' => $freeSpace,
			'user_quota' => $user->getQuota(),
			'consider_shared_files' => $considerSharedFiles,
			'consider_shared_albums' => $considerSharedAlbums,
			'document_format' => $documentFormat,
			'drive_output_dir' => $driveOutputDir,
			'photo_output_dir' => $photoOutputDir,
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
}
