<?php
/**
 * includes/contract_transitions.php
 *
 * Reguły biznesowe cyklu życia umowy — obecnie wdrożone dla wolontariatu
 * (jedyny typ z kolumnami niepelnoletni/zgoda_przedstawiciela/pobrana_zaliczka,
 * zob. includes/wolontariat_schema.php). Klasy są bezpieczne dla innych typów
 * umów — brakujące kolumny odczytują się jako "brak blokady"/"rozliczone".
 *
 * Wpięcie: api/ajax.php, case 'set_status' — jedyny wspólny punkt zmiany
 * statusu dla wszystkich 7 typów umów (dropdown w contract_view_header.php
 * woła właśnie ten endpoint).
 */

// Zależność twarda (log_contract_action, money) — deklarujemy sami, bez
// zakładania, że plik wołający już to zrobił we właściwej kolejności
// (edit.php historycznie wymaga approval.php PO zapisie, nie przed).
require_once __DIR__ . '/approval.php';

final class ContractTransitionException extends \RuntimeException {}

/** Task 1: blokada dla niepełnoletnich bez aktualnej zgody przedstawiciela ustawowego. */
final class ContractMinorGuard
{
    public static function shouldBlock(array $row): bool
    {
        if (empty($row['niepelnoletni'])) return false;
        return !guardian_consent_is_valid($row);
    }

    /** @throws ContractTransitionException gdy zgoda wciąż nieaktualna. */
    public static function assertConsentValid(array $row): void
    {
        if (self::shouldBlock($row)) {
            throw new ContractTransitionException(
                'Nie można odblokować — brak aktualnej zgody przedstawiciela ustawowego (niepełnoletni wolontariusz).'
            );
        }
    }

    /**
     * Wołane po zapisie w add.php/edit.php — TYLKO auto-blokada, nigdy
     * auto-odblokowanie (odblokowanie to świadoma decyzja koordynatora,
     * przechodząca przez ContractStatusTransitionValidator::assertAllowed()).
     */
    public static function syncAfterSave(string $type, int $id, array $row, int $byUserId): void
    {
        if (self::shouldBlock($row) && empty($row['is_blocked'])) {
            db_update(table_for_type($type), [
                'is_blocked'       => 1,
                'block_reason'     => 'missing_parental_consent',
                'pre_block_status' => $row['status'] ?: 'projekt',
                'status'           => 'zablokowana',
                'blocked_at'       => date('Y-m-d H:i:s'),
            ], $id);
            log_contract_action($type, $id, $byUserId, 'block',
                'Automatyczna blokada: niepełnoletni wolontariusz bez aktualnej zgody przedstawiciela ustawowego.');
        }
    }
}

/**
 * Alert na widoku umowy, gdy is_blocked=1 — powód blokady + status zgody
 * przedstawiciela ustawowego. Bezpieczne dla wszystkich 7 typów umów
 * (includes/contract_view_header.php) — bez is_blocked po prostu nic nie renderuje.
 */
function contract_block_alert(string $type, array $row): string
{
    if (empty($row['is_blocked'])) return '';

    $reasons = [
        'missing_parental_consent' => 'brak aktualnej zgody przedstawiciela ustawowego (niepełnoletni wolontariusz)',
        'outside_szo_settlement'   => 'umowa rozliczona poza SZO (zawarta przed 01.08.2026)',
    ];
    if (($row['block_reason'] ?? '') === 'outside_szo_settlement') {
        ob_start(); ?>
    <div class="alert alert-secondary no-print mb-2 py-2">
      <strong><i class="bi bi-lock-fill"></i> Umowa zablokowana.</strong>
      Powód: <?= h($reasons['outside_szo_settlement']) ?> — rozliczeń nie prowadzi się w SZO.
      <div class="text-muted small mt-1">Zablokowano: <?= date_pl($row['blocked_at'] ?? null) ?></div>
    </div>
    <?php return ob_get_clean();
    }
    $reason  = $reasons[$row['block_reason'] ?? ''] ?? ($row['block_reason'] ?? 'nieznany powód');
    $consentOk = function_exists('guardian_consent_is_valid') && guardian_consent_is_valid($row);

    ob_start();
    ?>
    <div class="alert alert-danger no-print mb-2 py-2">
      <strong><i class="bi bi-lock-fill"></i> Umowa zablokowana.</strong>
      Powód: <?= h($reason) ?>.
      <?php if ($consentOk): ?>
      <span class="badge bg-success-subtle text-success ms-1">Zgoda już aktualna — admin może odblokować zmieniając status.</span>
      <?php else: ?>
      <span class="badge bg-danger-subtle text-danger ms-1">Zgoda wciąż nieaktualna.</span>
      <?php if ($type === 'wolontariat' && !empty($row['id'])): ?>
      <a href="<?= APP_URL ?>/contracts/wolontariat/guardian_consent_action.php?id=<?= (int)$row['id'] ?>" class="alert-link ms-1">
        Wyślij pismo o zgodę →
      </a>
      <?php endif; ?>
      <?php endif; ?>
      <div class="text-muted small mt-1">Zablokowano: <?= date_pl($row['blocked_at'] ?? null) ?></div>
    </div>
    <?php
    return ob_get_clean();
}

/** Task 2: kontrola rozliczenia pobranej zaliczki przed zamknięciem/anulowaniem. */
final class ContractSettlementGuard
{
    public static function isSettled(array $row): bool
    {
        $advance = (float)($row['pobrana_zaliczka'] ?? 0);
        if ($advance <= 0) return true;
        return !empty($row['otrzymano_dowod_ksiegowy']) && !empty($row['rozliczono_srodki']);
    }

    /** @throws ContractTransitionException */
    public static function assertSettled(array $row): void
    {
        if (!self::isSettled($row)) {
            throw new ContractTransitionException(
                'Nie można zamknąć/anulować umowy — zaliczka ' . money((float)$row['pobrana_zaliczka'])
                . ' nie została rozliczona (brak dowodu księgowego i/lub potwierdzenia rozliczenia środków).'
            );
        }
    }

    /**
     * Pełne rozliczenie przed zamknięciem/rozwiązaniem: zaliczka + hologramy
     * wydane w ramach umowy. Jedno miejsce dla wszystkich ścieżek zmiany statusu:
     * szybka zmiana i ajax (przez ContractStatusTransitionValidator), formularze
     * edycji, akceptacja wniosku o rozwiązanie, kanban, cron auto-zakończenia.
     * @throws ContractTransitionException
     */
    public static function assertClosable(string $type, array $row): void
    {
        self::assertSettled($row);
        if ($type !== '' && !empty($row['id'])) {
            require_once dirname(__DIR__) . '/modules/holograms/logic/holograms.php';
            holo_assert_contract_settled($type, (int)$row['id'], ContractTransitionException::class);
        }
        // Umowa zlecenie z prowadzonym saldem rozliczeń: nie zamykamy z niezerowym saldem
        // ani z otwartym miesiącem z godzinami. Stare umowy bez danych salda — bez zmian.
        if ($type === 'zlecenie' && !empty($row['id'])) {
            require_once __DIR__ . '/zlecenie_rachunki.php';
            if (zl_rozl_uses_saldo((int)$row['id'])) {
                $blocker = zl_rozl_close_blocker($row);
                if ($blocker !== null) {
                    throw new ContractTransitionException('Nie można zamknąć umowy — ' . $blocker
                        . ' Zamknij miesiące w zakładce Rozliczenia (różnice wyrównaj korektą), a potem zakończ umowę.');
                }
            }
        }
    }

    /** Wersja bez wyjątku: null gdy można zamknąć, inaczej powód. */
    public static function closeBlocker(string $type, array $row): ?string
    {
        try { self::assertClosable($type, $row); return null; }
        catch (ContractTransitionException $e) { return $e->getMessage(); }
    }

    /** Statusy zamykające umowę (grupy closed + cancelled) — zapas; w kodzie closingStatuses() (słownik umowy_statusy). */
    public const CLOSING_STATUSES = ['zakończona', 'wygasła', 'rozwiązana', 'anulowana'];

    /** @return list<string> */
    public static function closingStatuses(): array
    {
        return contractStatusesInGroup('closed', 'cancelled') ?: self::CLOSING_STATUSES;
    }
}

/**
 * Task 3: twarda macierz grup statusów (Draft/Active/Blocked/Closed/Cancelled).
 * Obowiązuje WSZYSTKICH, także admina — w przeciwieństwie do miękkiej
 * STATUS_TRANSITIONS (includes/functions.php), którą admin dziś obchodzi.
 * Grupa statusu = atrybut `grupa` w słowniku umowy_statusy (GROUPS niżej to zapas).
 */
final class ContractStatusTransitionValidator
{
    private const GROUPS = [
        'projekt' => 'draft', 'do podpisu' => 'draft', 'jednostronnie' => 'draft', 'w podpisie w instytucji' => 'draft',
        'podpisana' => 'active', 'w realizacji' => 'active', 'zawieszona' => 'active',
        'do rozliczenia' => 'active', 'obowiązująca' => 'active',
        'zablokowana' => 'blocked',
        'zakończona' => 'closed', 'wygasła' => 'closed',
        'rozwiązana' => 'cancelled', 'anulowana' => 'cancelled',
        // 'aneks' pozostaje poza modelem grup — obsługiwane osobno przez contract_is_locked().
    ];

    /** Grupa statusu ze słownika (null — poza modelem grup, np. aneks). */
    private static function group(string $status): ?string
    {
        $g = function_exists('contractStatusGroup') ? contractStatusGroup($status) : (self::GROUPS[$status] ?? '');
        return $g !== '' ? $g : null;
    }

    private const GROUP_TRANSITIONS = [
        'draft'     => ['active', 'cancelled', 'blocked'],
        'active'    => ['closed', 'cancelled', 'blocked'],
        'blocked'   => ['active', 'cancelled'],
        'closed'    => [],
        'cancelled' => [],
    ];

    /**
     * @param string $type typ umowy (zlecenie, wolontariat…) — potrzebny do reguł
     *                     spoza wiersza umowy, np. rozliczenia hologramów.
     * @throws ContractTransitionException
     */
    public static function assertAllowed(string $from, string $to, array $row, string $type = ''): void
    {
        $fromGroup = self::group($from);
        $toGroup   = self::group($to);
        if ($fromGroup === null || $toGroup === null) return; // status poza modelem grup (np. aneks) — bez zmian

        if ($fromGroup !== $toGroup && !in_array($toGroup, self::GROUP_TRANSITIONS[$fromGroup] ?? [], true)) {
            throw new ContractTransitionException("Niedozwolone przejście statusu: {$from} → {$to}.");
        }

        if (in_array($toGroup, ['closed', 'cancelled'], true)) {
            ContractSettlementGuard::assertClosable($type, $row);
        }
        if ($fromGroup === 'blocked' && $toGroup === 'active') {
            ContractMinorGuard::assertConsentValid($row);
        }
    }

    /** Czy ten przejście wyprowadza umowę z grupy "blocked" — sygnał do zdjęcia is_blocked. */
    public static function isBlockedGroupExit(string $from, string $to): bool
    {
        return self::group($from) === 'blocked' && self::group($to) !== 'blocked';
    }
}

/**
 * Strażnik zmiany statusu dla ścieżek poza api/ajax.php (szybka zmiana z listy umów, kanban) — te same reguły
 * co nagłówek umowy: blokada aneksu, status dostępny w typie, macierz grup + rozliczenie zaliczki/hologramów/salda
 * i zgoda przedstawiciela (ContractStatusTransitionValidator), pliki wymagane w powierzeniu.
 * Miękkie przejścia edytora (status_allowed_next) sprawdza wywołujący. Zwraca komunikat błędu albo null.
 */
function contract_status_change_error(string $type, array $row, string $to): ?string
{
    if (contract_is_locked($row)) return 'Umowa jest zablokowana aneksem — statusu nie można zmienić.';
    if ($to === 'aneks' || !contractStatusExists($to, $type)) return 'Nieznany status: ' . $to . '.';
    require_once __DIR__ . '/guardian_consent.php';
    try {
        ContractStatusTransitionValidator::assertAllowed((string)($row['status'] ?? ''), $to, $row, $type);
    } catch (ContractTransitionException $e) {
        return $e->getMessage();
    }
    if ($type === 'powierzenie') {
        require_once __DIR__ . '/powierzenie_pliki.php';
        if ($braki = powierzenie_braki_plikow($to, $row)) return 'Nie zmieniono statusu. ' . implode(' ', $braki) . ' Dołącz pliki w edycji umowy.';
    }
    return null;
}

/** Po zapisie nowego statusu: wyjście z grupy „zablokowana” zdejmuje flagę is_blocked (jak api/ajax.php). */
function contract_status_after_change(string $type, int $id, string $from, string $to): void
{
    if (!ContractStatusTransitionValidator::isBlockedGroupExit($from, $to)) return;
    $uid = (int)(current_user()['id'] ?? 0);
    db_update(table_for_type($type), ['is_blocked' => 0, 'unblocked_by' => $uid, 'unblocked_at' => date('Y-m-d H:i:s')], $id);
    log_contract_action($type, $id, $uid, 'unblock', 'Odblokowano po potwierdzeniu zgody przedstawiciela ustawowego.');
}
