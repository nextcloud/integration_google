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
use OCA\Google\Service\SecretService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\Config\IUserConfig;
use OCP\IRequest;

class GoogleAPIController extends Controller {

	private string $accessToken;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserConfig $userConfig,
		private GooglePhotosAPIService $googlePhotosAPIService,
		private GoogleContactsAPIService $googleContactsAPIService,
		private GoogleDriveAPIService $googleDriveAPIService,
		private GoogleCalendarAPIService $googleCalendarAPIService,
		private ?string $userId,
		private SecretService $secretService,
	) {
		parent::__construct($appName, $request);
		$this->accessToken = $this->userId !== null ? $this->secretService->getEncryptedUserValue($this->userId, 'token') : '';
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
		$pickerSessionQueue = json_decode(
			$this->userConfig->getValueString($this->userId, Application::APP_ID, 'picker_session_queue', '[]', lazy: true),
			true,
		);
		if (!is_array($pickerSessionQueue)) {
			$pickerSessionQueue = [];
		}
		return new DataResponse([
			'importing_photos' => $this->userConfig->getValueString($this->userId, Application::APP_ID, 'importing_photos', lazy: true) === '1',
			'last_import_timestamp' => $this->userConfig->getValueInt($this->userId, Application::APP_ID, 'last_import_timestamp', lazy: true),
			'nb_imported_photos' => $this->userConfig->getValueInt($this->userId, Application::APP_ID, 'nb_imported_photos', lazy: true),
			'nb_queued_sessions' => count($pickerSessionQueue),
		]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * Create a new Google Photos Picker session (Picker API)
	 *
	 * @return DataResponse
	 */
	public function createPickerSession(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		$result = $this->googlePhotosAPIService->createPickerSession($this->userId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], 401);
		}
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 *
	 * Poll a Google Photos Picker session
	 *
	 * @param string $sessionId
	 * @return DataResponse
	 */
	public function getPickerSession(string $sessionId): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		$result = $this->googlePhotosAPIService->getPickerSession($this->userId, $sessionId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], 401);
		}
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 *
	 * Delete a Google Photos Picker session
	 *
	 * @param string $sessionId
	 * @return DataResponse
	 */
	public function deletePickerSession(string $sessionId): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		$result = $this->googlePhotosAPIService->deletePickerSession($this->userId, $sessionId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], 401);
		}
		return new DataResponse($result);
	}

	/**
	 * @NoAdminRequired
	 *
	 * Start downloading photos from a completed Picker session
	 *
	 * @param string $sessionId
	 * @return DataResponse
	 */
	public function importPhotos(string $sessionId = ''): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], 400);
		}
		$result = $this->googlePhotosAPIService->startImportPhotos($this->userId, $sessionId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], 401);
		}
		return new DataResponse($result);
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
			'importing_drive' => $this->userConfig->getValueString($this->userId, Application::APP_ID, 'importing_drive', lazy: true) === '1',
			'last_drive_import_timestamp' => $this->userConfig->getValueInt($this->userId, Application::APP_ID, 'last_drive_import_timestamp', lazy: true),
			'nb_imported_files' => $this->userConfig->getValueInt($this->userId, Application::APP_ID, 'nb_imported_files', lazy: true),
			'drive_imported_size' => $this->userConfig->getValueInt($this->userId, Application::APP_ID, 'drive_imported_size', lazy: true),
		]);
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
