<?php
namespace OCA\Google\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\IInitialStateService;
use OCP\Files\IRootFolder;
use OCA\Google\AppInfo\Application;

class Personal implements ISettings {

	private $request;
	private $config;
	private $dataDirPath;
	private $urlGenerator;
	private $l;

	public function __construct(string $appName,
								IL10N $l,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IRootFolder $root,
								IUserManager $userManager,
								IInitialStateService $initialStateService,
								$userId) {
		$this->appName = $appName;
		$this->urlGenerator = $urlGenerator;
		$this->request = $request;
		$this->l = $l;
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
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name', '');
		$driveOutputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'drive_output_dir', '/Google Drive');
		$driveOutputDir = $driveOutputDir ?: '/Google Drive';
		$photoOutputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'photo_output_dir', '/Google Photos');
		$photoOutputDir = $photoOutputDir ?: '/Google Photos';
		$considerSharedFiles = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_shared_files', '0') === '1';
		$considerSharedDrives = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_shared_drives', '0') === '1';
		$considerSharedAlbums = $this->config->getUserValue($this->userId, Application::APP_ID, 'consider_shared_albums', '0') === '1';
		$documentFormat = $this->config->getUserValue($this->userId, Application::APP_ID, 'document_format', 'openxml');
		if (!in_array($documentFormat, ['openxml', 'opendoc'])) {
			$documentFormat = 'openxml';
		}

		// for OAuth
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '') !== '';

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
			'consider_shared_drives' => $considerSharedDrives,
			'consider_shared_albums' => $considerSharedAlbums,
			'document_format' => $documentFormat,
			'drive_output_dir' => $driveOutputDir,
			'photo_output_dir' => $photoOutputDir,
		];
		$this->initialStateService->provideInitialState($this->appName, 'user-config', $userConfig);
		$response = new TemplateResponse(Application::APP_ID, 'personalSettings');
		return $response;
	}

	public function getSection(): string {
		return 'migration';
	}

	public function getPriority(): int {
		return 10;
	}
}
