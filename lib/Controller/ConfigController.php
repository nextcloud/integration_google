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

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Contacts\IManager as IContactManager;
use OCP\Constants;

use OCP\IRequest;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Google\Service\GoogleAPIService;
use OCA\Google\AppInfo\Application;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IL10N
	 */
	private $l;
	/**
	 * @var IContactManager
	 */
	private $contactsManager;
	/**
	 * @var GoogleAPIService
	 */
	private $googleApiService;
	/**
	 * @var string|null
	 */
	private $userId;

	const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
	const CONTACTS_SCOPE = 'https://www.googleapis.com/auth/contacts.readonly';
	const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';
	const CALENDAR_EVENTS_SCOPE = 'https://www.googleapis.com/auth/calendar.events.readonly';
	const PHOTOS_SCOPE = 'https://www.googleapis.com/auth/photoslibrary.readonly';

	public function __construct($appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IL10N $l,
								IContactManager $contactsManager,
								GoogleAPIService $googleApiService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->request = $request;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->contactsManager = $contactsManager;
		$this->googleApiService = $googleApiService;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 * Set config values
	 *
	 * @param array $values key/value pairs to store in user preferences
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
			$result['user_name'] = '';
		}
		return new DataResponse($result);
	}

	/**
	 * Set admin config values
	 *
	 * @param array $values key/value pairs to store in app config
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
		foreach ($addressBooks as $k => $ab) {
			if ($ab->getUri() !== 'system') {
				$result[$ab->getKey()] = [
					'uri' => $ab->getUri(),
					'name' => $ab->getDisplayName(),
					'canEdit' => ($ab->getPermissions() & Constants::PERMISSION_CREATE) ? true : false,
				];
			}
		}
		return new DataResponse($result);
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
	 * @param ?string $error
	 * @return RedirectResponse to user settings
	 */
	public function oauthRedirect(string $code = '', string $state = '',  string $scope = '', string $error = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// Store given scopes in space-separated string
		$scopes =  explode(' ', $scope);

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
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				$this->storeUserInfo($accessToken);
				return new RedirectResponse(
					$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration']) .
					'?googleToken=success'
				);
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
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration']) .
			'?googleToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * @param string $accessToken
	 * @return string
	 */
	private function storeUserInfo(string $accessToken): string {
		$info = $this->googleApiService->request($accessToken, $this->userId, 'oauth2/v1/userinfo', ['alt' => 'json']);
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
