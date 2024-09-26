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
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use OCA\Google\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service to make requests to Google v3 (JSON) API
 */
class GoogleAPIService {

	private \OCP\Http\Client\IClient $client;

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private INotificationManager $notificationManager,
		IClientService $clientService
	) {
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
	 * @param string $userId the user from which the request is coming
	 * @param string $endPoint The path to reach in api.google.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param ?string $baseUrl
	 * @return array
	 */
	public function request(
		string $userId, string $endPoint, array $params = [],
		string $method = 'GET', ?string $baseUrl = null
	): array {
		$this->checkTokenExpiration($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$url = $baseUrl ?: 'https://www.googleapis.com/';
			$url = $url . $endPoint;
			$options = [
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google Synchronization'
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
				['app' => Application::APP_ID]
			);

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if (is_resource($body)) {
				$body = stream_get_contents($body);
			}

			if ($respCode >= 400) {
				$this->logger->debug(
					'Google API request 400 FAILURE, method '.$method.', URL: ' . $url . ' , body: ' . $body,
					['app' => Application::APP_ID]
				);
				return ['error' => 'Bad credentials'];
			} else {
				$this->logger->debug(
					'Google API request SUCCESS: , method ' . $method . ', URL: ' . $url
						. ' , body:' . substr($body, 0, 30) . '...',
					['app' => Application::APP_ID]
				);
				return json_decode($body, true);
			}
		} catch (ServerException | ClientException $e) {
			/** @var IResponse $response */
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			$this->logger->warning(
				'Google API ServerException|ClientException error : '.$e->getMessage()
					. ' status code: ' .$response->getStatusCode()
					. ' body: ' . $body,
				['app' => Application::APP_ID]
			);
			return [
				'error' => 'ServerException|ClientException, message:'
					. $e->getMessage()
					. ' status code: ' . $response->getStatusCode(),
			];
		} catch (ConnectException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
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
					'User-Agent' => 'Nextcloud Google Synchronization'
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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
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
			$this->logger->warning('Google OAuth error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Make a simple authenticated HTTP request
	 * @param string $userId the user from which the request is coming
	 * @param string $url The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array
	 */
	public function simpleRequest(string $userId, string $url, array $params = [], string $method = 'GET'): array {
		$this->checkTokenExpiration($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$options = [
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google Synchronization'
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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return [
					'body' => $body,
					'headers' => $response->getHeaders(),
				];
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->error('Google API request connection error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => 'Connection error: ' . $e->getMessage()];
		}
	}

	/**
	 * Make a simple authenticated HTTP request to download a file
	 *
	 * @param string $userId the user from which the request is coming
	 * @param string $url The path to reach
	 * @param resource $resource
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 *
	 * @return (mixed|string|true)[]
	 *
	 * @psalm-return array{error?: mixed|string, success?: true}
	 */
	public function simpleDownload(string $userId, string $url, $resource, array $params = [], string $method = 'GET'): array {
		$this->checkTokenExpiration($userId);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$options = [
				// does not work with sink if SSE is enabled
				// 'sink' => $resource,
				// rather use stream and write to the file ourselves
				'stream' => true,
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Google Synchronization'
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
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => 'Bad HTTP method'];
			}
			$respCode = $response->getStatusCode();

			$body = $response->getBody();
			if (is_resource($body)) {
				while (!feof($body)) {
					// write ~5 MB chunks
					$chunk = fread($body, 5000000);
					fwrite($resource, $chunk);
				}
			} else {
				fwrite($resource, $body);
			}

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return ['success' => true];
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->error('Google API request connection error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => 'Connection error: ' . $e->getMessage()];
		} catch (Throwable | Exception $e) {
			return ['error' => 'Unknown error: ' . $e->getMessage()];
		}
	}

	private function checkTokenExpiration(string $userId): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new DateTime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than 2 minutes or has already expired
			if ($nowTs > $expireAt - 120) {
				$this->refreshToken($userId);
			}
		}
	}

	public function refreshToken(string $userId): array {
		$this->logger->debug('Trying to REFRESH the access token', ['app' => Application::APP_ID]);
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
			$this->logger->debug('Google access token successfully refreshed', ['app' => Application::APP_ID]);
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $result['access_token']);
			if (isset($result['expires_in'])) {
				$nowTs = (new DateTime())->getTimestamp();
				$expiresAt = $nowTs + (int) $result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', $expiresAt);
			}
		} else {
			$responseTxt = json_encode($result);
			$this->logger->warning('Google API error, impossible to refresh the token. Response: ' . $responseTxt, ['app' => Application::APP_ID]);
		}

		return $result;
	}
}
