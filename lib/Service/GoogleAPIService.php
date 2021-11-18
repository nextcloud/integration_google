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

use DateTime;
use Exception;
use OCP\IL10N;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;
use OCP\Notification\IManager as INotificationManager;

use OCA\Google\AppInfo\Application;
use Throwable;

class GoogleAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var INotificationManager
	 */
	private $notificationManager;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;

	/**
	 * Service to make requests to Google v3 (JSON) API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $baseUrl
	 * @param array $params
	 * @return string
	 */
	private function buildUrl(string $baseUrl, array $params = []): string {
		$paramsContent = http_build_query($params);
		if (strpos($baseUrl, '?') !== false) {
        	$baseUrl .= '&'. $paramsContent;
        } else {
			$baseUrl .= '?' . $paramsContent;
		}
		return $baseUrl;

	}
	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	public function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
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
			$url = $baseUrl ?: 'https://www.googleapis.com/';
			$url = $url . $endPoint;
			$options = [
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$url = $this->buildUrl($url, $params);
				} else {
					$options['body'] = json_encode($params);
				}
			}

			$this->logger->debug(
				'REQUESTING Google API, method '.$method.', URL: ' . $url . ' , params: ' . json_encode($params)
					. 'token length: ' . strlen($accessToken),
				['app' => $this->appName]
			);

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				$this->logger->debug(
					'Google API request 400 FAILURE, method '.$method.', URL: ' . $url . ' , body: ' . $body,
					['app' => $this->appName]
				);
				return ['error' => 'Bad credentials'];
			} else {
				$this->logger->debug(
					'Google API request SUCCESS: , method ' . $method . ', URL: ' . $url
						. ' , body:' . substr($body, 0, 30) . '...',
					['app' => $this->appName]
				);
				return json_decode($body, true);
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			$this->logger->debug(
				'Google API request FAILURE, method '.$method . ', URL: ' . $url
					. ' , body: ' . $body . ' status code: ' . $response->getStatusCode(),
				['app' => $this->appName]
			);
			if ($response->getStatusCode() === 401) {
				// refresh the token if it's invalid
				$result = $this->refreshToken($userId);
				if (isset($result['access_token'])) {
					return $this->request(
						$result['access_token'], $userId, $endPoint, $params, $method, $baseUrl
					);
				}
				return ['error' => 'Impossible to refresh the token'];
			}
			$this->logger->warning(
				'Google API ServerException|ClientException error : '.$e->getMessage()
					. ' status code: ' .$response->getStatusCode()
					. ' body: ' . $body,
				['app' => $this->appName]
			);
			return [
				'error' => 'ServerException|ClientException, message:'
					. $e->getMessage()
					. ' status code: ' . $response->getStatusCode(),
			];
		} catch (ConnectException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => 'Connection error: ' . $e->getMessage()];
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
					$url = $this->buildUrl($url, $params);
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
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (Exception $e) {
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
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$url = $this->buildUrl($url, $params);
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
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return ['content' => $body];
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				// refresh the token if it's invalid
				$result = $this->refreshToken($userId);
				if (isset($result['access_token'])) {
					return $this->simpleRequest(
						$result['access_token'], $userId, $url, $params, $method
					);
				}
				return ['error' => 'Impossible to refresh the token'];
			}
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->error('Google API request connection error: ' . $e->getMessage(), ['app' => $this->appName]);
			return ['error' => 'Connection error: ' . $e->getMessage()];
		}
	}

	/**
	 * Make a simple authenticated HTTP request to download a file
	 * @param string $accessToken
	 * @param string $userId the user from which the request is coming
	 * @param string $url The path to reach
	 * @param resource $resource
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array
	 */
	public function simpleDownload(string $accessToken, string $userId, string $url, $resource, array $params = [], string $method = 'GET'): array {
		try {
			$options = [
				// does not work with sink if SSE is enabled
				// 'sink' => $resource,
				// rather use stream and write to the file ourselves
				'stream' => true,
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$url = $this->buildUrl($url, $params);
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
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$respCode = $response->getStatusCode();

			$body = $response->getBody();
			while (!feof($body)) {
				// write ~5 MB chunks
				$chunk = fread($body, 5000000);
				fwrite($resource, $chunk);
			}

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return ['success' => true];
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				// refresh the token if it's invalid
				$result = $this->refreshToken($userId);
				if (isset($result['access_token'])) {
					return $this->simpleDownload(
						$result['access_token'], $userId, $url, $resource, $params, $method
					);
				}
				return ['error' => 'Impossible to refresh the token'];
			}
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->error('Google API request connection error: ' . $e->getMessage(), ['app' => $this->appName]);
			return ['error' => 'Connection error: ' . $e->getMessage()];
		} catch (Throwable | Exception $e) {
			return ['error' => 'Unknown error: ' . $e->getMessage()];
		}
	}

	public function refreshToken(string $userId): array {
		$this->logger->debug('Trying to REFRESH the access token', ['app' => $this->appName]);
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$result = $this->requestOAuthAccessToken([
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
		], 'POST');

		if (isset($result['access_token'])) {
			$this->logger->debug('Google access token successfully refreshed', ['app' => $this->appName]);
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $result['access_token']);
		} else {
			$responseTxt = json_encode($result);
			$this->logger->warning('Google API error, impossible to refresh the token. Response: ' . $responseTxt, ['app' => $this->appName]);
		}

		return $result;
	}
}
