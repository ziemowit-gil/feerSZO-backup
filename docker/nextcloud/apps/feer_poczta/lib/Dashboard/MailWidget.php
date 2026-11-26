<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Dashboard;

use OCA\FeerPoczta\AppInfo\Application;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

/**
 * Widżet „Poczta” z jednym przyciskiem „Sprawdź pocztę”.
 * Pulpit NC pokazuje przycisk typu „setup”, gdy widżet nie ma elementów.
 */
class MailWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget {
	public const MAIL_URL = 'https://poczta.feer.org.pl/';

	public function __construct(
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		return 'Poczta';
	}

	public function getOrder(): int {
		return 1;
	}

	public function getIconClass(): string {
		return 'icon-mail';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return self::MAIL_URL;
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		return new WidgetItems([], '');
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_SETUP, self::MAIL_URL, 'Sprawdź pocztę'),
		];
	}
}
