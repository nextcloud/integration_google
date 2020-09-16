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
use OCP\ILogger;
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;

use OCA\Google\AppInfo\Application;

class GoogleAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								ILogger $logger,
								IL10N $l10n,
								IConfig $config,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 */
	public function addCalendars(string $accessToken, string $userId): array {
		$params = [];
		$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList');
		// ical url is https://calendar.google.com/calendar/ical/br3sqt6mgpunkh2dr2p8p5obso%40group.calendar.google.com/private-640b335ca58effb904dd4570b50096eb/basic.ics
		// https://calendar.google.com/calendar/ical/ID/../basic.ics
		// in ->items : list
		// ID : URL encoded item['id']
		$result = $this->request($accessToken, $userId, 'calendar/v3/users/me/calendarList/' . urlencode('br3sqt6mgpunkh2dr2p8p5obso@group.calendar.google.com'));
		return $result;
	}

	/**
	 * Make the HTTP request
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $endPoint The path to reach in api.google.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 */
	public function request(string $accessToken, string $userId, string $endPoint, ?array $params = [], ?string $method = 'GET'): array {
		try {
			$url = 'https://www.googleapis.com/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error', $this->l10n->t('Bad credentials')];
			} else {
				file_put_contents('/tmp/aa', $body);
				return json_decode($body, true);
			}
		} catch (ClientException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), array('app' => $this->appName));
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			// refresh token if it's invalid and we are using oauth
			$this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
			$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
			$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', '');
			$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', '');
			$result = $this->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'grant_type' => 'refresh_token',
				'refresh_token' => $refreshToken,
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
				return $this->request(
					$accessToken, $userId, $endPoint, $params, $method
				);
			}
			return ['error', $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 */
	public function requestOAuthAccessToken(?array $params = [], ?string $method = 'GET'): array {
		try {
			$url = 'https://oauth2.googleapis.com/token';
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Google OAuth error : '.$e->getMessage(), array('app' => $this->appName));
			return ['error' => $e->getMessage()];
		}
	}
}
