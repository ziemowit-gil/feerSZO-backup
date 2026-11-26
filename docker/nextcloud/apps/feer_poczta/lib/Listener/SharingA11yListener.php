<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Listener;

use OCA\FeerPoczta\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Nakładka na zakładkę „Udostępnianie” w panelu bocznym Plików: prostszy układ dla osób
 * nietechnicznych i poprawki WCAG (js/share-a11y.js, css/share-a11y.css).
 * Nie zmienia mechanizmu udostępniania Nextclouda — tylko opisy, kolejność uwagi i dostępność.
 *
 * @template-implements IEventListener<Event>
 */
class SharingA11yListener implements IEventListener {
	public function handle(Event $event): void {
		Util::addStyle(Application::APP_ID, 'share-a11y');
		Util::addScript(Application::APP_ID, 'share-a11y', 'files');
	}
}
