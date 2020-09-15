<?php
/**
 * Nextcloud - Google
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\AppInfo;

use OCP\IContainer;
use OCP\IUser;

use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCA\Google\Dashboard\GoogleWidget;
use OCA\Google\Search\GoogleSearchProvider;

/**
 * Class Application
 *
 * @package OCA\Google\AppInfo
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'integration_google';

    /**
     * Constructor
     *
     * @param array $urlParams
     */
    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);

        $container = $this->getContainer();
    }

    public function register(IRegistrationContext $context): void {
        //$context->registerDashboardWidget(GoogleWidget::class);
    }

    public function boot(IBootContext $context): void {
    }
}

