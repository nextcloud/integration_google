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

return [
	'routes' => [
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#getLocalAddressBooks', 'url' => '/local-addressbooks', 'verb' => 'GET'],
		['name' => 'config#popupSuccessPage', 'url' => '/popup-success', 'verb' => 'GET'],

		['name' => 'googleAPI#getDriveSize', 'url' => '/drive-size', 'verb' => 'GET'],
		['name' => 'googleAPI#getCalendarList', 'url' => '/calendars', 'verb' => 'GET'],
		['name' => 'googleAPI#getContactNumber', 'url' => '/contact-number', 'verb' => 'GET'],
		['name' => 'googleAPI#getPhotoNumber', 'url' => '/photo-number', 'verb' => 'GET'],
		['name' => 'googleAPI#importCalendar', 'url' => '/import-calendar', 'verb' => 'GET'],
		['name' => 'googleAPI#registerSyncCalendar', 'url' => '/sync-calendar', 'verb' => 'GET'],
		['name' => 'googleAPI#setSyncCalendar', 'url' => '/set-sync-calendar', 'verb' => 'GET'],
		['name' => 'googleAPI#resetRegisteredSyncCalendar', 'url' => '/reset-sync-calendar', 'verb' => 'DELETE'],
		['name' => 'googleAPI#importContacts', 'url' => '/import-contacts', 'verb' => 'GET'],
		['name' => 'googleAPI#importPhotos', 'url' => '/import-photos', 'verb' => 'GET'],
		['name' => 'googleAPI#getImportPhotosInformation', 'url' => '/import-photos-info', 'verb' => 'GET'],
		['name' => 'googleAPI#importDrive', 'url' => '/import-files', 'verb' => 'GET'],
		['name' => 'googleAPI#getImportDriveInformation', 'url' => '/import-files-info', 'verb' => 'GET'],
	]
];
