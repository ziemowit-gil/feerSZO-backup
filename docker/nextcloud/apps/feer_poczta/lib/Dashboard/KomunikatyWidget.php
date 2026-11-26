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
 * Widżet „Komunikaty z SZO” — ogłoszenia z modułu Komunikaty SZO widoczne dla tej osoby.
 * Dane z SZO: GET {szo_url}/api/internal/nc_komunikaty.php (nagłówek X-Internal-Key = szo_key).
 * Ustawienia: occ config:app:set feer_poczta szo_key --value … [szo_url].
 */
class KomunikatyWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget {
	private const CACHE_TTL = 300;

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
		return 'feer_komunikaty';
	}

	public function getTitle(): string {
		return 'Komunikaty z SZO';
	}

	public function getOrder(): int {
		return 2;
	}

	public function getIconClass(): string {
		return 'icon-comment';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'komunikaty-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->szoUrl() . '/komunikaty/index.php';
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$data = $this->fetch($userId, $limit);
		if ($data === null) {
			return new WidgetItems([], 'Nie udało się pobrać komunikatów z SZO.');
		}
		$icon = $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'komunikaty.svg'));
		$items = [];
		foreach ($data['items'] ?? [] as $a) {
			$parts = [];
			if (empty($a['read'])) {
				$parts[] = 'Nowy';
			}
			if (!empty($a['date'])) {
				$parts[] = date('d.m.Y', strtotime((string)$a['date']) ?: time());
			}
			if (!empty($a['kategoria'])) {
				$parts[] = (string)$a['kategoria'];
			}
			$items[] = new WidgetItem(
				(string)($a['title'] ?? ''),
				implode(' · ', $parts),
				(string)($a['url'] ?? ''),
				$icon,
				(string)($a['id'] ?? ''),
			);
		}
		return new WidgetItems($items, 'Brak komunikatów');
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->getUrl() ?? '', 'Wszystkie komunikaty'),
		];
	}

	private function szoUrl(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, 'szo_url', 'https://szo.feer.org.pl'), '/');
	}

	/** @return array{items?: list<array<string, mixed>>}|null */
	private function fetch(string $userId, int $limit): ?array {
		$key = $this->config->getValueString(Application::APP_ID, 'szo_key', '');
		if ($key === '') {
			return null;
		}
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-komunikaty');
		$ck = $userId . ':' . $limit;
		$hit = $cache->get($ck);
		if (is_array($hit)) {
			return $hit;
		}
		$email = (string)($this->users->get($userId)?->getEMailAddress() ?? '');
		try {
			$res = $this->http->newClient()->get(
				$this->szoUrl() . '/api/internal/nc_komunikaty.php?' . http_build_query([
					'uid' => $userId, 'email' => $email, 'limit' => $limit,
				]),
				['headers' => ['X-Internal-Key' => $key], 'timeout' => 5],
			);
			$data = json_decode((string)$res->getBody(), true);
		} catch (\Throwable $e) {
			$this->logger->warning('feer_poczta: komunikaty z SZO niedostępne: ' . $e->getMessage());
			return null;
		}
		if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
			return null;
		}
		$cache->set($ck, $data, self::CACHE_TTL);
		return $data;
	}
}
