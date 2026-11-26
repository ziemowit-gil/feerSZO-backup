<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Dashboard;

use OCA\FeerPoczta\Service\SzoClient;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;

/**
 * Kafel „Moje zespoły” — zespoły osoby ze Struktury Organizacyjnej SZO (org/teams.php),
 * zamiast kafla „Zespoły” Nextclouda (circles).
 */
class ZespolyWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget {
	public function __construct(
		private SzoClient $szo,
	) {
	}

	public function getId(): string {
		return 'feer_zespoly';
	}

	public function getTitle(): string {
		return 'Moje zespoły';
	}

	public function getOrder(): int {
		return 2;
	}

	public function getIconClass(): string {
		return 'icon-group';
	}

	public function getIconUrl(): string {
		return $this->szo->img('zespol-dark.svg');
	}

	public function getUrl(): ?string {
		return $this->szo->szoUrl() . '/org/teams.php';
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$data = $this->szo->fetch($userId);
		$icon = $this->szo->img('zespol.svg');
		if ($data === null) {
			return new WidgetItems([new WidgetItem('Nie udało się pobrać danych z SZO', 'Spróbuj później', $this->getUrl() ?? '', $icon, 'blad')], '');
		}
		$items = [];
		foreach (array_slice($data['teams'] ?? [], 0, $limit) as $t) {
			$n = (int)($t['members'] ?? 0);
			$parts = [];
			if (!empty($t['unit'])) {
				$parts[] = (string)$t['unit'];
			}
			$parts[] = $n . ' ' . ($n === 1 ? 'osoba' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 'osoby' : 'osób'));
			$items[] = new WidgetItem((string)($t['name'] ?? ''), implode(' · ', $parts), (string)($t['url'] ?? ''), $icon, 't' . ($t['id'] ?? ''));
		}
		if (!$items) {
			$items[] = new WidgetItem('Nie należysz do żadnego zespołu', 'Zespoły w Strukturze SZO', $this->getUrl() ?? '', $icon, 'brak');
		}
		return new WidgetItems($items, '');
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->getUrl() ?? '', 'Wszystkie zespoły'),
		];
	}
}
