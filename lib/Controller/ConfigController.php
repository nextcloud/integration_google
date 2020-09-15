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
use OCP\ILogger;

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
                                ILogger $logger,
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * Receive oauth code and get oauth access token
     *
     * @param string $code request code to use when requesting oauth token
     * @param string $state value that was sent with original GET request. Used to check auth redirection is valid
     * @return RedirectResponse to user settings
     */
    public function oauthRedirect(?string $code, ?string $state, ?string $error): RedirectResponse {
        //return $access_token;
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
            if (isset($result['access_token']) && isset($result['refresh_token'])) {
                $accessToken = $result['access_token'];
                $refreshToken = $result['refresh_token'];
                $this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
                $this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
                //$this->storeUserInfo($accessToken);
                return new RedirectResponse(
                    $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
                    '?googleToken=success'
                );
            }
            $result = $this->l->t('Error getting OAuth access token.') . ' ' . $result['error'];
        } else {
            $result = $this->l->t('Error during OAuth exchanges');
        }
        return new RedirectResponse(
            $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
            '?googleToken=error&message=' . urlencode($result)
        );
    }

    private function storeUserInfo(string $accessToken): string {
        $info = $this->googleAPIService->request($accessToken, 'user');
        if (isset($info['login']) && isset($info['id'])) {
            $this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id']);
            $this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['login']);
            return $info['login'];
        } else {
            $this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
            $this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
            return '';
        }
    }
}
