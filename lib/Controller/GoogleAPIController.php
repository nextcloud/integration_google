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

use OCP\IConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Google\Service\GooglePhotosAPIService;
use OCA\Google\Service\GoogleContactsAPIService;
use OCA\Google\Service\GoogleDriveAPIService;
use OCA\Google\Service\GoogleCalendarAPIService;
use OCA\Google\AppInfo\Application;

class GoogleAPIController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var GooglePhotosAPIService
	 */
	private $googlePhotosAPIService;
	/**
	 * @var GoogleContactsAPIService
	 */
	private $googleContactsAPIService;
	/**
	 * @var GoogleDriveAPIService
	 */
	private $googleDriveAPIService;
	/**
	 * @var GoogleCalendarAPIService
	 */
	private $googleCalendarAPIService;
	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var string
	 */
	private $accessToken;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								GooglePhotosAPIService $googlePhotosAPIService,
								GoogleContactsAPIService $googleContactsAPIService,
								GoogleDriveAPIService $googleDriveAPIService,
								GoogleCalendarAPIService $googleCalendarAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->googlePhotosAPIService = $googlePhotosAPIService;
		$this->googleContactsAPIService = $googleContactsAPIService;
		$this->googleDriveAPIService = $googleDriveAPIService;
		$this->googleCalendarAPIService = $googleCalendarAPIService;
		$this->userId = $userId;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getImportPhotosInformation(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		return new DataResponse([
			'importing_photos' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_photos') === '1',
			'last_import_timestamp' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'last_import_timestamp', '0'),
			'nb_imported_photos' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_photos', '0'),
		]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getImportDriveInformation(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		return new DataResponse([
			'importing_drive' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_drive') === '1',
			'last_drive_import_timestamp' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'last_drive_import_timestamp', '0'),
			'nb_imported_files' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_files', '0'),
		]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getPhotoNumber(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googlePhotosAPIService->getPhotoNumber($this->accessToken, $this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getContactNumber(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googleContactsAPIService->getContactNumber($this->accessToken, $this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getCalendarList(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googleCalendarAPIService->getCalendarList($this->accessToken, $this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getDriveSize(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googleDriveAPIService->getDriveSize($this->accessToken, $this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function importPhotos(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googlePhotosAPIService->startImportPhotos($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function importDrive(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googleDriveAPIService->startImportDrive($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return DataResponse
	 */
	public function importCalendar(string $calId, string $calName, ?string $color = null): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}
		$result = $this->googleCalendarAPIService->importCalendar($this->accessToken, $this->userId, $calId, $calName, $color);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param ?string $uri
	 * @param int $key
	 * @param ?string $newAddressBookName
	 * @return DataResponse
	 */
	public function importContacts(?string $uri = '', int $key = 0, ?string $newAddressBookName = ''): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->googleContactsAPIService->importContacts($this->accessToken, $this->userId, $uri, $key, $newAddressBookName);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}
}
