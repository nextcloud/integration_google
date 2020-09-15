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
	 * Request an avatar image
	 * @param string $url The avatar URL
	 * @return string Avatar image data
	 */
	public function getAvatar(string $url): string {
		return $this->client->get($url)->getBody();
	}

	/**
	 * Actually get notifications
	 * @param string $accessToken
	 * @param ?string $since optional date to filter notifications
	 * @param ?bool $participating optional param to only show notifications the user is participating to
	 */
	public function getNotifications(string $accessToken, ?string $since, ?bool $participating): array {
		$params = [];
		if (is_null($since)) {
			$twoWeeksEarlier = new \DateTime();
			$twoWeeksEarlier->sub(new \DateInterval('P14D'));
			$params['since'] = $twoWeeksEarlier->format('Y-m-d\TH:i:s\Z');
		} else {
			$params['since'] = $since;
		}
		if (!is_null($participating)) {
			$params['participating'] = $participating ? 'true' : 'false';
		}
		$result = $this->request($accessToken, 'notifications', $params);
		return $result;
	}

	/**
	 * Unsubscribe a notification, does the same as in Google notifications page
	 * @param string $accessToken
	 * @param int $id Notification id
	 * @return array request result
	 */
	public function unsubscribeNotification(string $accessToken, int $id): array {
		$params = [
			'ignored' => true
		];
		$result = $this->request($accessToken, 'notifications/threads/' . $id . '/subscription', $params, 'PUT');
		return $result;
	}

	/**
	 * Mark a notification as read
	 * @param string $accessToken
	 * @param int $id Notification id
	 * @return array request result
	 */
	public function markNotificationAsRead(string $accessToken, int $id): array {
		$result = $this->request($accessToken, 'notifications/threads/' . $id, [], 'POST');
		return $result;
	}

	/**
	 * Search Google
	 * @param string $accessToken
	 * @param string $query What to search for
	 * @return array request result
	 */
	public function search(string $accessToken, string $query): array {
		$entries = [];
		// 5 repositories
		$result = $this->searchRepositories($accessToken, $query);
		if (isset($result['items'])) {
			$result['items'] = array_slice($result['items'], 0, 5);
			foreach($result['items'] as $k => $entry) {
				$entry['entry_type'] = 'repository';
				array_push($entries, $entry);
			}
		}
		// 10 issues
		$result = $this->searchIssues($accessToken, $query);
		if (isset($result['items'])) {
			$result['items'] = array_slice($result['items'], 0, 10);
			foreach($result['items'] as $k => $entry) {
				$entry['entry_type'] = 'issue';
				array_push($entries, $entry);
			}
		}

		//// sort by score
		//$a = usort($entries, function($a, $b) {
		//	$sa = floatval($a['score']);
		//	$sb = floatval($b['score']);
		//	return ($sa > $sb) ? -1 : 1;
		//});
		return $entries;
	}

	/**
	 * Search repositories
	 * @param string $accessToken
	 * @param string $query What to search for
	 * @return array request result
	 */
	private function searchRepositories(string $accessToken, string $query): array {
		$params = [
			'q' => $query,
			'order' => 'desc'
		];
		$result = $this->request($accessToken, 'search/repositories', $params, 'GET');
		return $result;
	}

	/**
	 * Search issues and PRs
	 * @param string $accessToken
	 * @param string $query What to search for
	 * @return array request result
	 */
	private function searchIssues(string $accessToken, string $query): array {
		$params = [
			'q' => $query,
			'order' => 'desc'
		];
		$result = $this->request($accessToken, 'search/issues', $params, 'GET');
		return $result;
	}

	/**
	 * Make the HTTP request
	 * @param string $accessToken
	 * @param string $endPoint The path to reach in api.google.com
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 */
	public function request(string $accessToken, string $endPoint, ?array $params = [], ?string $method = 'GET'): array {
		try {
			$url = 'https://api.google.com/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'token ' . $accessToken,
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
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Google API error : '.$e->getMessage(), array('app' => $this->appName));
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
