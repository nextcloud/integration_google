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

use OCA\Google\AppInfo\Application;
use OCA\Google\Service\GoogleCalendarAPIService;
use OCA\Google\Service\GoogleContactsAPIService;
use OCA\Google\Service\GoogleDriveAPIService;
use OCA\Google\Service\GooglePhotosAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class GoogleAPIController extends Controller {

	private string $accessToken;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private GooglePhotosAPIService $googlePhotosAPIService,
		private GoogleContactsAPIService $googleContactsAPIService,
		private GoogleDriveAPIService $googleDriveAPIService,
		private GoogleCalendarAPIService $googleCalendarAPIService,
		private ?string $userId
	) {
		parent::__construct($appName, $request);
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getImportPhotosInformation(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse([], 400);
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
			return new DataResponse([], 400);
		}
		return new DataResponse([
			'importing_drive' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_drive') === '1',
			'last_drive_import_timestamp' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'last_drive_import_timestamp', '0'),
			'nb_imported_files' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_files', '0'),
			'drive_imported_size' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'drive_imported_size', '0'),
		]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getPhotoNumber(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
        /** @var array{error?:string} $result */
		$result = $this->googlePhotosAPIService->getPhotoNumber($this->userId);
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
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
        /** @var array{error?:string} $result */
		$result = $this->googleContactsAPIService->getContactNumber($this->userId);
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
        if ($this->accessToken === '' || $this->userId === null) {
            return new DataResponse([], 400);
        }
        /** @var array{error?:string} $result */
		$result = $this->googleCalendarAPIService->getCalendarList($this->userId);
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
        if ($this->accessToken === '' || $this->userId === null) {
            return new DataResponse([], 400);
        }
        /** @var array{error?:string} $result */
		$result = $this->googleDriveAPIService->getDriveSize($this->userId);
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
        if ($this->accessToken === '' || $this->userId === null) {
            return new DataResponse([], 400);
        }
        /** @var array{error?:string} $result */
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
        if ($this->accessToken === '' || $this->userId === null) {
            return new DataResponse([], 400);
        }
        /** @var array{error?:string} $result */
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
        if ($this->accessToken === '' || $this->userId === null) {
            return new DataResponse([], 400);
        }
        /** @var array{error?:string} $result */
		$result = $this->googleCalendarAPIService->importCalendar($this->userId, $calId, $calName, $color);
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
        if ($this->accessToken === '' || $this->userId === null) {
            return new DataResponse([], 400);
        }
        /** @var array{error?:string} $result */
		$result = $this->googleContactsAPIService->importContacts($this->userId, $uri, $key, $newAddressBookName);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}
}
