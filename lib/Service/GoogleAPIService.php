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
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;
use OCP\Contacts\IManager as IContactManager;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property\Text;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCP\Notification\IManager as INotificationManager;

use OCA\Google\AppInfo\Application;
use OCA\Google\BackgroundJob\ImportPhotosJob;
use OCA\Google\BackgroundJob\ImportDriveJob;

class GoogleAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IContactManager $contactsManager,
								CardDavBackend $cdBackend,
								CalDavBackend $caldavBackend,
								IRootFolder $root,
								IJobList $jobList,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->logger = $logger;
		$this->jobList = $jobList;
		$this->clientService = $clientService;
		$this->contactsManager = $contactsManager;
		$this->cdBackend = $cdBackend;
		$this->caldavBackend = $caldavBackend;
		$this->root = $root;
		$this->jobList = $jobList;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param string $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * Make the HTTP request
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $endPoint The path to reach in api.google.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param ?string $baseUrl
	 * @return array
	 */
	public function request(string $accessToken, string $userId,
							string $endPoint, array $params = [], string $method = 'GET', ?string $baseUrl = null): array {
		try {
			$url = $baseUrl ? $baseUrl : 'https://www.googleapis.com/';
			$url = $url . $endPoint;
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
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ClientException $e) {
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			// try to refresh token if it's invalid
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
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
						$accessToken, $userId, $endPoint, $params, $method, $baseUrl
					);
				}
			}
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make the request to get an OAuth token
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array
	 */
	public function requestOAuthAccessToken(array $params = [], string $method = 'GET'): array {
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
			$this->logger->warning('Google OAuth error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make a simple authenticated HTTP request
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $url The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array
	 */
	public function simpleRequest(string $accessToken, string $userId, string $url, array $params = [], string $method = 'GET'): array {
		try {
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
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return ['content' => $body];
			}
		} catch (ClientException $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				// refresh token if it's invalid and we are using oauth
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
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
					return $this->simpleRequest(
						$accessToken, $userId, $url, $params, $method
					);
				}
			}
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}
}
