<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\AppInfo;

use OCA\FeerPoczta\Dashboard\KomunikatyWidget;
use OCA\FeerPoczta\Dashboard\MailWidget;
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
		$context->registerDashboardWidget(MailWidget::class);
		$context->registerDashboardWidget(KomunikatyWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
