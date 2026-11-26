<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Dashboard;

use OCA\FeerPoczta\AppInfo\Application;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Kafel „SZO” na pulpicie: wiersz „Sprawdź pocztę”, komunikaty i moje zadania z SZO.
 * Dane: GET {szo_url}/api/internal/nc_pulpit.php (nagłówek X-Internal-Key = szo_key), cache 5 min.
 */
class SzoWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget {
	public const MAIL_URL = 'https://poczta.feer.org.pl/';
	private const CACHE_TTL = 300;
	private const MAX_KOMUNIKATY = 3;

	public function __construct(
		private IURLGenerator $url,
		private IClientService $http,
		private IAppConfig $config,
		private ICacheFactory $cacheFactory,
		private IUserManager $users,
		private LoggerInterface $logger,
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
		return $this->img('app-dark.svg');
	}

	public function getUrl(): ?string {
		return $this->szoUrl() . '/';
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = [new WidgetItem('Sprawdź pocztę', 'poczta.feer.org.pl', self::MAIL_URL, $this->img('mail.svg'), 'poczta')];

		$data = $this->fetch($userId, $limit);
		if ($data === null) {
			$items[] = new WidgetItem('Nie udało się pobrać danych z SZO', 'Spróbuj później', $this->getUrl() ?? '', $this->img('komunikaty.svg'), 'blad');
			return new WidgetItems($items, '');
		}

		foreach (array_slice($data['items'] ?? [], 0, self::MAX_KOMUNIKATY) as $a) {
			$parts = [];
			if (empty($a['read'])) {
				$parts[] = 'Nowy komunikat';
			}
			if (!empty($a['date'])) {
				$parts[] = $this->date((string)$a['date']);
			}
			if (!empty($a['kategoria'])) {
				$parts[] = (string)$a['kategoria'];
			}
			$items[] = new WidgetItem((string)($a['title'] ?? ''), implode(' · ', $parts), (string)($a['url'] ?? ''), $this->img('komunikaty.svg'), 'k' . ($a['id'] ?? ''));
		}

		foreach ($data['tasks'] ?? [] as $t) {
			if (count($items) >= $limit) {
				break;
			}
			$parts = [];
			if (!empty($t['due'])) {
				$parts[] = (!empty($t['overdue']) ? 'Po terminie: ' : 'Termin: ') . $this->date((string)$t['due']);
			}
			if (!empty($t['ws'])) {
				$parts[] = (string)$t['ws'];
			}
			$items[] = new WidgetItem(
				(string)($t['title'] ?? ''),
				$parts ? implode(' · ', $parts) : 'Zadanie',
				(string)($t['url'] ?? ''),
				$this->img(!empty($t['overdue']) ? 'zadanie-po-terminie.svg' : 'zadanie.svg'),
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

	private function img(string $file): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, $file));
	}

	private function date(string $ymd): string {
		$ts = strtotime($ymd);
		return $ts ? date('d.m.Y', $ts) : $ymd;
	}

	private function szoUrl(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, 'szo_url', 'https://szo.feer.org.pl'), '/');
	}

	/** @return array<string, mixed>|null */
	private function fetch(string $userId, int $limit): ?array {
		$key = $this->config->getValueString(Application::APP_ID, 'szo_key', '');
		if ($key === '') {
			return null;
		}
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-szo');
		$ck = $userId . ':' . $limit;
		$hit = $cache->get($ck);
		if (is_array($hit)) {
			return $hit;
		}
		$email = (string)($this->users->get($userId)?->getEMailAddress() ?? '');
		try {
			$res = $this->http->newClient()->get(
				$this->szoUrl() . '/api/internal/nc_pulpit.php?' . http_build_query([
					'uid' => $userId, 'email' => $email, 'limit' => $limit,
				]),
				['headers' => ['X-Internal-Key' => $key], 'timeout' => 5],
			);
			$data = json_decode((string)$res->getBody(), true);
		} catch (\Throwable $e) {
			$this->logger->warning('feer_poczta: dane z SZO niedostępne: ' . $e->getMessage());
			return null;
		}
		if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
			return null;
		}
		$cache->set($ck, $data, self::CACHE_TTL);
		return $data;
	}
}
