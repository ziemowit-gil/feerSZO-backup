<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Service;

use OCA\FeerPoczta\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Dane pulpitu z SZO: GET {szo_url}/api/internal/nc_pulpit.php (nagłówek X-Internal-Key = szo_key),
 * wspólne dla kafli „SZO” i „Moje zespoły”; cache 5 min na osobę.
 */
class SzoClient {
	private const CACHE_TTL = 300;
	public const LIMIT = 7;

	public function __construct(
		private IURLGenerator $url,
		private IClientService $http,
		private IAppConfig $config,
		private ICacheFactory $cacheFactory,
		private IUserManager $users,
		private LoggerInterface $logger,
	) {
	}

	public function szoUrl(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, 'szo_url', 'https://szo.feer.org.pl'), '/');
	}

	public function img(string $file): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, $file));
	}

	public static function date(string $ymd): string {
		$ts = strtotime($ymd);
		return $ts ? date('d.m.Y', $ts) : $ymd;
	}

	/** @return array<string, mixed>|null null = brak klucza albo SZO nie odpowiada */
	public function fetch(string $userId): ?array {
		$key = $this->config->getValueString(Application::APP_ID, 'szo_key', '');
		if ($key === '') {
			return null;
		}
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-szo');
		$hit = $cache->get($userId);
		if (is_array($hit)) {
			return $hit;
		}
		$email = (string)($this->users->get($userId)?->getEMailAddress() ?? '');
		try {
			$res = $this->http->newClient()->get(
				$this->szoUrl() . '/api/internal/nc_pulpit.php?' . http_build_query([
					'uid' => $userId, 'email' => $email, 'limit' => self::LIMIT,
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
		$cache->set($userId, $data, self::CACHE_TTL);
		return $data;
	}
}
