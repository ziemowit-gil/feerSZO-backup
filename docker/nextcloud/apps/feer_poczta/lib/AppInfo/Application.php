<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\AppInfo;

use OCA\FeerPoczta\Dashboard\SzoWidget;
use OCA\FeerPoczta\Dashboard\ZespolyWidget;
use OCA\FeerPoczta\Listener\SharingA11yListener;
use OCA\FeerPoczta\Listener\UiStyleListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\App;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
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
		// Zakładka „Udostępnianie” w Plikach: prostszy układ + WCAG.
		$context->registerEventListener(LoadSidebar::class, SharingA11yListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, SharingA11yListener::class);
		// Cała chmura: białe tła (css/feer-ui.css).
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, UiStyleListener::class);
	}

	public function boot(IBootContext $context): void {
		// Białe tła i okienka (css/feer-ui.css) na każdej stronie — także w aplikacjach, które nie wywołują
		// BeforeTemplateRenderedEvent przed zebraniem stylów (np. Kalendarz).
		\OCP\Util::addStyle(self::APP_ID, 'feer-ui');
		// Selektor aplikacji FEER zamiast menu „kafelki” w nagłówku (js/app-menu.js).
		\OCP\Util::addScript(self::APP_ID, 'app-menu');
		// Etykiety pól i inne poprawki dostępności formularzy (np. harmonogram spotkań, strona rezerwacji).
		\OCP\Util::addScript(self::APP_ID, 'a11y-forms');
	}
}
