<?php

declare(strict_types=1);

namespace OCA\FeerPoczta\Service;

use OCA\DAV\CardDAV\AddressBook;
use OCA\DAV\CardDAV\CardDavBackend;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCard;

/**
 * Wspólna książka adresowa „FEER” w Kontaktach (tylko do odczytu dla wszystkich kont w chmurze).
 * Dane: SZO api/internal/nc_contacts.php — jak /katalog (telefon tylko publiczny), kategorie = grupy w Kontaktach:
 * „Jednostka: …”, „Obszar: …”, „Zespół: …”. Właściciel: konto techniczne (ustawienie addressbook_owner, domyślnie szo-api).
 * Wizytówki „szo-user-{id}.vcf”: dodawane, zmieniane tylko przy różnicy, usuwane gdy osoba zniknie z katalogu.
 * Uruchamiane co godzinę (BackgroundJob\FeerAddressBookJob) i ręcznie: occ feer:addressbook-sync.
 */
class FeerAddressBook {
	public const URI = 'feer-szo';
	public const NAME = 'FEER';

	public function __construct(
		private SzoClient $szo,
		private IClientService $http,
		private IAppConfig $config,
		private CardDavBackend $card,
		private IUserManager $users,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{ok:bool, error?:string, created?:int, updated?:int, deleted?:int, shared?:int, people?:int} */
	public function sync(): array {
		$owner = $this->config->getValueString('feer_poczta', 'addressbook_owner', 'szo-api');
		if (!$this->users->userExists($owner)) {
			return ['ok' => false, 'error' => "Nie ma konta właściciela książki: $owner"];
		}
		$key = $this->config->getValueString('feer_poczta', 'szo_key', '');
		if ($key === '') {
			return ['ok' => false, 'error' => 'Brak klucza SZO (feer_poczta szo_key).'];
		}
		try {
			$res = $this->http->newClient()->get($this->szo->szoUrl() . '/api/internal/nc_contacts.php',
				['headers' => ['X-Internal-Key' => $key], 'timeout' => 30]);
			$data = json_decode((string)$res->getBody(), true);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'SZO nie odpowiada: ' . $e->getMessage()];
		}
		if (!is_array($data) || !isset($data['people']) || !is_array($data['people'])) {
			return ['ok' => false, 'error' => 'Nieprawidłowa odpowiedź SZO.'];
		}

		$principal = 'principals/users/' . $owner;
		$ab = $this->card->getAddressBooksByUri($principal, self::URI);
		if (!$ab) {
			$this->card->createAddressBook($principal, self::URI, ['{DAV:}displayname' => self::NAME]);
			$ab = $this->card->getAddressBooksByUri($principal, self::URI);
		}
		if (!$ab) {
			return ['ok' => false, 'error' => 'Nie udało się utworzyć książki adresowej.'];
		}
		$abId = (int)$ab['id'];

		$existing = [];
		foreach ($this->card->getCards($abId) as $c) {
			$existing[(string)$c['uri']] = (string)$c['carddata'];
		}
		$n = ['created' => 0, 'updated' => 0, 'deleted' => 0];
		$keep = [];
		foreach ($data['people'] as $p) {
			$id = (int)($p['id'] ?? 0);
			$email = (string)($p['email'] ?? '');
			if ($id <= 0 || $email === '') {
				continue;
			}
			$uri = 'szo-user-' . $id . '.vcf';
			$keep[$uri] = true;
			$vc = new VCard([
				'UID' => 'szo-user-' . $id . '@feer.org.pl',
				'FN' => (string)($p['name'] ?? $email),
				'N' => [(string)($p['last_name'] ?? ''), (string)($p['first_name'] ?? ''), '', '', ''],
			]);
			$vc->add('EMAIL', $email, ['TYPE' => 'WORK']);
			$vc->add('ORG', array_values(array_filter(['Fundacja FEER', (string)($p['unit'] ?? '')], fn($x) => $x !== '')));
			if (!empty($p['title'])) {
				$vc->add('TITLE', (string)$p['title']);
			}
			if (!empty($p['phone'])) {
				$vc->add('TEL', (string)$p['phone'], ['TYPE' => 'WORK']);
			}
			$groups = array_values(array_filter(array_map('strval', (array)($p['groups'] ?? []))));
			if ($groups) {
				$vc->add('CATEGORIES', $groups);
			}
			$vc->add('NOTE', 'Z systemu SZO — zmiany wprowadza się w SZO (Struktura, Zadania, Umowy).');
			$ics = $vc->serialize();
			try {
				if (!isset($existing[$uri])) {
					$this->card->createCard($abId, $uri, $ics, false);
					$n['created']++;
				} elseif ($this->norm($existing[$uri]) !== $this->norm($ics)) {
					$this->card->updateCard($abId, $uri, $ics);
					$n['updated']++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('feer_poczta: wizytówka ' . $uri . ': ' . $e->getMessage());
			}
		}
		foreach (array_keys($existing) as $uri) {
			if (str_starts_with($uri, 'szo-user-') && !isset($keep[$uri])) {
				$this->card->deleteCard($abId, $uri);
				$n['deleted']++;
			}
		}

		// Udostępnienie tylko do odczytu każdemu aktywnemu kontu (poza właścicielem).
		$shared = [];
		foreach ($this->card->getShares($abId) as $s) {
			$shared[(string)($s['{http://owncloud.org/ns}principal'] ?? '')] = true;
		}
		$add = [];
		$this->users->callForSeenUsers(function ($u) use ($owner, $shared, &$add) {
			if ($u->getUID() === $owner || !$u->isEnabled()) {
				return;
			}
			$pr = 'principals/users/' . $u->getUID();
			if (!isset($shared[$pr])) {
				$add[] = ['href' => 'principal:' . $pr, 'readOnly' => true];
			}
		});
		if ($add) {
			$node = new AddressBook($this->card, $ab, $this->l10nFactory->get('dav'));
			$this->card->updateShares($node, $add, []);
		}
		return ['ok' => true, 'people' => count($keep), 'shared' => count($add)] + $n;
	}

	private function norm(string $vcf): string {
		return trim((string)preg_replace(['/\r\n[ \t]/', '/\r?\n/', '/^PRODID:.*$/m'], ['', "\n", ''], $vcf));
	}
}
