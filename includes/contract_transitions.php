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

    $reasons = ['missing_parental_consent' => 'brak aktualnej zgody przedstawiciela ustawowego (niepełnoletni wolontariusz)'];
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
    }

    /** Wersja bez wyjątku: null gdy można zamknąć, inaczej powód. */
    public static function closeBlocker(string $type, array $row): ?string
    {
        try { self::assertClosable($type, $row); return null; }
        catch (ContractTransitionException $e) { return $e->getMessage(); }
    }

    /** Statusy zamykające umowę (grupy closed + cancelled). */
    public const CLOSING_STATUSES = ['zakończona', 'wygasła', 'rozwiązana', 'anulowana'];
}

/**
 * Task 3: twarda macierz grup statusów (Draft/Active/Blocked/Closed/Cancelled).
 * Obowiązuje WSZYSTKICH, także admina — w przeciwieństwie do miękkiej
 * STATUS_TRANSITIONS (includes/functions.php), którą admin dziś obchodzi.
 * Grupuje istniejące 12 statusów PL bez ich zastępowania.
 */
final class ContractStatusTransitionValidator
{
    private const GROUPS = [
        'projekt' => 'draft', 'do podpisu' => 'draft',
        'podpisana' => 'active', 'w realizacji' => 'active', 'zawieszona' => 'active',
        'do rozliczenia' => 'active', 'obowiązująca' => 'active',
        'zablokowana' => 'blocked',
        'zakończona' => 'closed', 'wygasła' => 'closed',
        'rozwiązana' => 'cancelled', 'anulowana' => 'cancelled',
        // 'aneks' pozostaje poza modelem grup — obsługiwane osobno przez contract_is_locked().
    ];

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
        $fromGroup = self::GROUPS[$from] ?? null;
        $toGroup   = self::GROUPS[$to] ?? null;
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
        return (self::GROUPS[$from] ?? null) === 'blocked' && (self::GROUPS[$to] ?? null) !== 'blocked';
    }
}
