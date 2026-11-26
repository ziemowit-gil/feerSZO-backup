<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Listener;

use OCA\FeerPoczta\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Wygląd całej chmury (css/feer-ui.css): pełne białe tła w kaflach, panelach i kalendarzu zamiast
 * półprzezroczystych/szarych — czytelniej i z pewnym kontrastem tekstu (WCAG 2.2 AA).
 *
 * @template-implements IEventListener<Event>
 */
class UiStyleListener implements IEventListener {
	public function handle(Event $event): void {
		Util::addStyle(Application::APP_ID, 'feer-ui');
	}
}
