<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\AppInfo;

use OCA\FeerPoczta\Dashboard\SzoWidget;
use OCA\FeerPoczta\Dashboard\ZespolyWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'feer_poczta';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(SzoWidget::class);
		$context->registerDashboardWidget(ZespolyWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
