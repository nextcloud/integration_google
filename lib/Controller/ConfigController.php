<?php
/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\Controller;

use DateTime;
use Exception;
use OCA\Google\AppInfo\Application;
use OCA\Google\Service\GoogleAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Constants;
use OCP\Contacts\IManager as IContactManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Throwable;

class ConfigController extends Controller {

	public const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
	public const CONTACTS_SCOPE = 'https://www.googleapis.com/auth/contacts.readonly';
	public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';
	public const CALENDAR_EVENTS_SCOPE = 'https://www.googleapis.com/auth/calendar.events.readonly';
	public const PHOTOS_SCOPE = 'https://www.googleapis.com/auth/photoslibrary.readonly';

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private IContactManager $contactsManager,
		private IInitialState $initialStateService,
		private GoogleAPIService $googleApiService,
		private ?string $userId
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * Set config values
	 *
	 * @param array<string,string> $values key/value pairs to store in user preferences
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_expires_at');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
			$result['user_name'] = '';
		} else {
			if (isset($values['drive_output_dir'])) {
				$root = \OCP\Server::get(\OCP\Files\IRootFolder::class);
				$userRoot = $root->getUserFolder($this->userId);
				$result['free_space'] = \OCA\Google\Settings\Personal::getFreeSpace($userRoot, $values['drive_output_dir']);
			}
		}
		return new DataResponse($result);
	}

	/**
	 * Set admin config values
	 *
	 * @param array<string,string> $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	/**
	 * @NoAdminRequired
	 * Get local address book list
	 *
	 * @return DataResponse
	 */
	public function getLocalAddressBooks(): DataResponse {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		$result = [];
		foreach ($addressBooks as $ab) {
			try {
				$canEdit = (bool)(((int)$ab->getPermissions()) & Constants::PERMISSION_CREATE);
				if ($ab->getUri() !== 'system' && $canEdit) {
					$result[$ab->getKey()] = [
						'uri' => $ab->getUri(),
						'name' => $ab->getDisplayName(),
						'canEdit' => $canEdit,
					];
				}
			} catch (Exception | Throwable $e) {
			}
		}
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $username
	 * @return TemplateResponse
	 */
	public function popupSuccessPage(string $username): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', ['user_name' => $username]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * Receive oauth code and get oauth access token
	 *
	 * @param string $code request code to use when requesting oauth token
	 * @param string $state value that was sent with original GET request. Used to check auth redirection is valid
	 * @param string $scope scopes allowed by user
	 * @param string $error
	 *
	 * @return RedirectResponse to user settings
	 */
	public function oauthRedirect(string $code = '', string $state = '', string $scope = '', string $error = ''): RedirectResponse {
		if ($this->userId === null) {
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration']) .
				'?googleToken=error&message=' . urlencode($this->l->t('No logged in user'))
			);
		}

		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// Store given scopes in space-separated string
		$scopes = explode(' ', $scope);

		$scopesArray = [
			'can_access_drive' => in_array(self::DRIVE_SCOPE, $scopes) ? 1 : 0,
			'can_access_contacts' => in_array(self::CONTACTS_SCOPE, $scopes) ? 1 : 0,
			'can_access_photos' => in_array(self::PHOTOS_SCOPE, $scopes) ? 1 : 0,
			'can_access_calendar' => (in_array(self::CALENDAR_SCOPE, $scopes) && in_array(self::CALENDAR_EVENTS_SCOPE, $scopes)) ? 1 : 0,
		];

		$this->config->setUserValue($this->userId, Application::APP_ID, 'user_scopes', json_encode($scopesArray));

		// anyway, reset state
		$this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

		if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			/** @var array{access_token?:string, refresh_token?:string, expires_in?:string, error?:string} $result */
			$result = $this->googleApiService->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'grant_type' => 'authorization_code',
				'redirect_uri' => $redirect_uri,
				'code' => $code,
			], 'POST');
			if (isset($result['access_token'], $result['refresh_token'])) {
				$accessToken = $result['access_token'];
				$refreshToken = $result['refresh_token'];
				if (isset($result['expires_in'])) {
					$nowTs = (new DateTime())->getTimestamp();
					$expiresAt = $nowTs + (int) $result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', (string)$expiresAt);
				}
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				$username = $this->storeUserInfo();
				$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0') === '1';
				if ($usePopup) {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('google_synchronization.config.popupSuccessPage', ['username' => $username])
					);
				} else {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'google_synchronization']) .
						'?googleToken=success'
					);
				}
			}
			$message = $result['error']
				?? (isset($result['access_token'])
					? $this->l->t('Missing refresh token in Google response.')
					: '');
			$result = $this->l->t('Error getting OAuth access token.') . ' ' . $message;
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'google_synchronization']) .
			'?googleToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * @return string
	 */
	private function storeUserInfo(): string {
		if ($this->userId === null) {
			return '';
		}
		/** @var array{id?:string, name?:string} $info */
		$info = $this->googleApiService->request($this->userId, 'oauth2/v1/userinfo', ['alt' => 'json']);
		if (isset($info['name'], $info['id'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id']);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['name']);
			return $info['name'];
		} else {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			return '';
		}
	}
}
