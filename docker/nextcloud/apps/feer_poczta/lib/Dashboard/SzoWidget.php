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
 * Kafel „SZO” na pulpicie: wiersz „Sprawdź pocztę”, komunikaty i moje zadania z SZO.
 * Dane z SZO przez SzoClient (nc_pulpit.php, cache 5 min).
 */
class SzoWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget {
	public const MAIL_URL = 'https://poczta.feer.org.pl/';
	private const MAX_KOMUNIKATY = 3;

	public function __construct(
		private SzoClient $szo,
	) {
	}

	public function getId(): string {
		return 'feer_szo';
	}

	public function getTitle(): string {
		return 'SZO';
	}

	public function getOrder(): int {
		return 1;
	}

	public function getIconClass(): string {
		return 'icon-category-dashboard';
	}

	public function getIconUrl(): string {
		return $this->szo->img('app-dark.svg');
	}

	public function getUrl(): ?string {
		return $this->szo->szoUrl() . '/';
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = [new WidgetItem('Sprawdź pocztę', 'poczta.feer.org.pl', self::MAIL_URL, $this->szo->img('mail.svg'), 'poczta')];

		$data = $this->szo->fetch($userId);
		if ($data === null) {
			$items[] = new WidgetItem('Nie udało się pobrać danych z SZO', 'Spróbuj później', $this->getUrl() ?? '', $this->szo->img('komunikaty.svg'), 'blad');
			return new WidgetItems($items, '');
		}

		foreach (array_slice($data['items'] ?? [], 0, self::MAX_KOMUNIKATY) as $a) {
			$parts = [];
			if (empty($a['read'])) {
				$parts[] = 'Nowy komunikat';
			}
			if (!empty($a['date'])) {
				$parts[] = SzoClient::date((string)$a['date']);
			}
			if (!empty($a['kategoria'])) {
				$parts[] = (string)$a['kategoria'];
			}
			$items[] = new WidgetItem((string)($a['title'] ?? ''), implode(' · ', $parts), (string)($a['url'] ?? ''), $this->szo->img('komunikaty.svg'), 'k' . ($a['id'] ?? ''));
		}

		foreach ($data['tasks'] ?? [] as $t) {
			if (count($items) >= $limit) {
				break;
			}
			$parts = [];
			if (!empty($t['due'])) {
				$parts[] = (!empty($t['overdue']) ? 'Po terminie: ' : 'Termin: ') . SzoClient::date((string)$t['due']);
			}
			if (!empty($t['ws'])) {
				$parts[] = (string)$t['ws'];
			}
			$items[] = new WidgetItem(
				(string)($t['title'] ?? ''),
				$parts ? implode(' · ', $parts) : 'Zadanie',
				(string)($t['url'] ?? ''),
				$this->szo->img(!empty($t['overdue']) ? 'zadanie-po-terminie.svg' : 'zadanie.svg'),
				'z' . ($t['id'] ?? ''),
			);
		}

		return new WidgetItems($items, '');
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->getUrl() ?? '', 'Otwórz SZO'),
		];
	}
}
