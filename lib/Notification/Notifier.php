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

namespace OCA\Google\Notification;

use InvalidArgumentException;
use OCA\Google\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {

	public function __construct(
		private IFactory $factory,
		private IURLGenerator $url
	) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'google_synchronization';
	}
	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->factory->get('google_synchronization')->t('Google');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'google_synchronization') {
			// Not my app => throw
			throw new InvalidArgumentException();
		}

		$l = $this->factory->get('google_synchronization', $languageCode);

		switch ($notification->getSubject()) {
			case 'import_photos_finished':
				/** @var array{nbImported?:string, targetPath: string} $p */
				$p = $notification->getSubjectParameters();
				$nbImported = (int) ($p['nbImported'] ?? 0);
				$targetPath = $p['targetPath'];
				$content = $l->n('%n photo was imported from Google.', '%n photos were imported from Google.', $nbImported);

				$notification->setParsedSubject($content)
					->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')))
					->setLink($this->url->linkToRouteAbsolute('files.view.index', ['dir' => $targetPath]));
				return $notification;

			case 'import_drive_finished':
				/** @var array{nbImported?:string, targetPath: string} $p */
				$p = $notification->getSubjectParameters();
				$nbImported = (int) ($p['nbImported'] ?? 0);
				$targetPath = $p['targetPath'];
				$content = $l->n('%n file was imported from Google Drive.', '%n files were imported from Google Drive.', $nbImported);

				$notification->setParsedSubject($content)
					->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')))
					->setLink($this->url->linkToRouteAbsolute('files.view.index', ['dir' => $targetPath]));
				return $notification;

			default:
				// Unknown subject => Unknown notification => throw
				throw new InvalidArgumentException();
		}
	}
}
