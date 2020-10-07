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

use OCP\App\IAppManager;
use OCP\Files\IAppData;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\Contacts\IManager as IContactManager;
use OCP\Constants;

use OCP\IRequest;
use OCP\IDBConnection;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Controller;

use OCA\Google\Service\GoogleAPIService;
use OCA\Google\AppInfo\Application;

class ConfigController extends Controller {

    private $userId;
    private $config;
    private $dbconnection;
    private $dbtype;

    public function __construct($AppName,
                                IRequest $request,
                                IServerContainer $serverContainer,
                                IConfig $config,
                                IAppManager $appManager,
                                IAppData $appData,
                                IDBConnection $dbconnection,
                                IURLGenerator $urlGenerator,
                                IL10N $l,
                                LoggerInterface $logger,
                                IContactManager $contactsManager,
                                GoogleAPIService $googleAPIService,
                                $userId) {
        parent::__construct($AppName, $request);
        $this->l = $l;
        $this->appName = $AppName;
        $this->userId = $userId;
        $this->appData = $appData;
        $this->serverContainer = $serverContainer;
        $this->config = $config;
        $this->dbconnection = $dbconnection;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->contactsManager = $contactsManager;
        $this->googleAPIService = $googleAPIService;
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

        if (isset($values['token'])) {
            if ($values['token'] && $values['token'] !== '') {
                $userName = $this->storeUserInfo($values['token']);
                $result['user_name'] = $userName;
            } else {
                $this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
                $this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
                $this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
                $result['user_name'] = '';
            }
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
     * @param ?string $error
     * @return RedirectResponse to user settings
     */
    public function oauthRedirect(string $code = '', string $state = '', string $error = ''): RedirectResponse {
        $configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state', '');
        $clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
        $clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');

        // anyway, reset state
        $this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

        if ($clientID && $clientSecret && $configState !== '' && $configState === $state) {
            $redirect_uri = $this->urlGenerator->linkToRouteAbsolute('integration_google.config.oauthRedirect');
            $result = $this->googleAPIService->requestOAuthAccessToken([
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
            $message = isset($result['error'])
                ? $result['error']
                : (isset($result['access_token'])
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
		$info = $this->googleAPIService->request($accessToken, $this->userId, 'oauth2/v1/userinfo', ['alt' => 'json']);
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
