<?php
/**
 * modules/strategy_actions/logic/ActionsGrants.php — połączenie Działań z modułem Grantów (tabela grants, grants/*.php).
 *
 * Budżet grantu na działania: kwota przyznana (a gdy jej brak — wnioskowana, oznaczona jako szacunek) kontra suma kwot
 * przypisanych działaniom (action_grants.kwota, bez działań anulowanych). Podpinanie i odpinanie istniejących działań
 * z karty grantu, zestawienie działań grantu do sprawozdania.
 */
require_once __DIR__ . '/ActionsService.php';

final class ActionsGrants
{
    public const STATUSES = [
        'pomysł' => 'Pomysł', 'złożony' => 'Złożony', 'oczekuje' => 'Oczekuje', 'przyznany' => 'Przyznany',
        'w realizacji' => 'W realizacji', 'rozliczany' => 'Rozliczany', 'zamknięty' => 'Zamknięty', 'odrzucony' => 'Odrzucony',
    ];

    /** Statusy, w których grant ma terminy warte kalendarza (realizacja, rozliczenie). */
    public const LIVE = ['przyznany', 'w realizacji', 'rozliczany'];

    /**
     * Budżety wszystkich grantów. $exceptAction — pomija kwoty tego działania (formularz edycji: „zostaje bez tego działania”).
     * @return array<int, array> grant_id => kształt budżetu
     */
    public static function budgets(?int $exceptAction = null): array
    {
        $alloc = [];
        foreach (db_all("SELECT ag.grant_id, ag.action_id, ag.kwota FROM action_grants ag JOIN actions a ON a.id = ag.action_id
                         WHERE a.status != 'anulowane'") as $r) {
            $gid = (int)$r['grant_id'];
            $alloc[$gid]['count'] = ($alloc[$gid]['count'] ?? 0) + 1;
            if ($exceptAction !== null && (int)$r['action_id'] === $exceptAction) continue;
            $alloc[$gid]['sum'] = ($alloc[$gid]['sum'] ?? 0.0) + (float)($r['kwota'] ?? 0);
        }
        $out = [];
        foreach (db_all("SELECT * FROM grants ORDER BY nazwa") as $g) {
            $out[(int)$g['id']] = self::shape($g, (float)($alloc[(int)$g['id']]['sum'] ?? 0), (int)($alloc[(int)$g['id']]['count'] ?? 0));
        }
        return $out;
    }

    public static function budget(int $grantId): ?array
    {
        return self::budgets()[$grantId] ?? null;
    }

    private static function shape(array $g, float $allocated, int $count): array
    {
        $przyznana = $g['kwota_przyznana'] !== null && $g['kwota_przyznana'] !== '' ? (float)$g['kwota_przyznana'] : null;
        $wniosk    = $g['kwota_wnioskowana'] !== null && $g['kwota_wnioskowana'] !== '' ? (float)$g['kwota_wnioskowana'] : null;
        $budget    = $przyznana ?? $wniosk;
        return [
            'id'          => (int)$g['id'],
            'nazwa'       => (string)$g['nazwa'],
            'donator'     => (string)($g['donator'] ?? ''),
            'status'      => (string)$g['status'],
            'statusLabel' => self::STATUSES[$g['status']] ?? (string)$g['status'],
            'waluta'      => (string)($g['waluta'] ?: 'PLN'),
            'od'          => $g['data_od'] ?: null,
            'do'          => $g['data_do'] ?: null,
            'rozliczenie' => $g['data_rozliczenia'] ?: null,
            'budget'      => $budget,
            'estimated'   => $przyznana === null && $wniosk !== null,
            'allocated'   => round($allocated, 2),
            'remaining'   => $budget !== null ? round($budget - $allocated, 2) : null,
            'over'        => $budget !== null && $allocated - $budget > 0.004,
            'pct'         => $budget ? (int)min(999, round($allocated / $budget * 100)) : null,
            'count'       => $count,
        ];
    }

    // ── Podpinanie z karty grantu ─────────────────────────────────────────────

    /** Działania, których jeszcze nie ma w grancie (do listy wyboru na karcie grantu). */
    public static function candidates(int $grantId): array
    {
        return db_all("SELECT id, nazwa, status, data_od FROM actions
                       WHERE status != 'anulowane' AND id NOT IN (SELECT action_id FROM action_grants WHERE grant_id = ?)
                       ORDER BY data_od DESC, nazwa", [$grantId]);
    }

    /** @return string|null komunikat błędu */
    public static function link(int $grantId, int $actionId, ?float $kwota, ?float $udzial): ?string
    {
        $g = grant_by_id($grantId);
        $a = action_by_id($actionId);
        if (!$g || !$a) return 'Nie znaleziono grantu albo działania.';
        if (db_one("SELECT 1 FROM action_grants WHERE grant_id = ? AND action_id = ?", [$grantId, $actionId])) return 'To działanie jest już w tym grancie.';
        db()->prepare("INSERT INTO action_grants (action_id, grant_id, udzial_procent, kwota) VALUES (?,?,?,?)")
            ->execute([$actionId, $grantId, $udzial, $kwota]);
        db()->prepare("UPDATE actions SET updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $actionId]);
        ActionsJournal::log($actionId, 'change', 'Podpięto do grantu „' . $g['nazwa'] . '”' . ($kwota !== null ? ' (' . money($kwota, $g['waluta'] ?: 'PLN') . ')' : '') . '.');
        return null;
    }

    public static function unlink(int $grantId, int $actionId): ?string
    {
        $g = grant_by_id($grantId);
        if (!$g || !db_one("SELECT 1 FROM action_grants WHERE grant_id = ? AND action_id = ?", [$grantId, $actionId])) return 'Działanie nie jest w tym grancie.';
        db()->prepare("DELETE FROM action_grants WHERE grant_id = ? AND action_id = ?")->execute([$grantId, $actionId]);
        db()->prepare("UPDATE actions SET updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $actionId]);
        ActionsJournal::log($actionId, 'change', 'Odpięto od grantu „' . $g['nazwa'] . '”.');
        return null;
    }

    /** Działania grantu w kształcie ekranu (z flagami, wskaźnikami i etapami) + kwota/udział z tego grantu. */
    public static function actionsOf(int $grantId): array
    {
        $out = [];
        foreach (ActionsService::all() as $a) {
            foreach ($a['grants'] as $g) {
                if ($g['id'] !== $grantId) continue;
                $out[] = $a + ['grantKwota' => $g['kwota'], 'grantUdzial' => $g['udzial']];
                break;
            }
        }
        usort($out, fn($x, $y) => strcmp((string)$x['od'], (string)$y['od']) ?: $x['id'] <=> $y['id']);
        return $out;
    }

    /** Zestawienie do sprawozdania z grantu: wiersz na wskaźnik działania (albo jeden wiersz, gdy wskaźników brak). */
    public static function reportDataset(int $grantId): ?array
    {
        $g = grant_by_id($grantId);
        if (!$g) return null;
        $rows = [];
        foreach (self::actionsOf($grantId) as $a) {
            $base = [$a['id'], $a['nazwa'], ActionsService::statusLabel($a['status']),
                     $a['od'] ? date('d.m.Y', strtotime($a['od'])) : '', $a['do'] ? date('d.m.Y', strtotime($a['do'])) : '',
                     $a['koordynator'], $a['grantKwota'], $a['grantUdzial'],
                     $a['ms']['total'] ? $a['ms']['done'] . '/' . $a['ms']['total'] : ''];
            $inds = grant_action_indicators_for($a['id']);
            if (!$inds) { $rows[] = array_merge($base, ['', null, null, null]); continue; }
            foreach ($inds as $i) {
                $p = $i['wartosc_planowana'] !== null ? (float)$i['wartosc_planowana'] : null;
                $r = (float)($i['wartosc_realizowana'] ?? 0);
                $rows[] = array_merge($base, [(string)$i['nazwa'], $p, $r, $p ? (int)round($r / $p * 100) : null]);
            }
        }
        $b = self::budget($grantId);
        return [
            'title'    => 'Działania grantu: ' . $g['nazwa'] . ($g['donator'] ? ' (' . $g['donator'] . ')' : ''),
            'subtitle' => 'Umowa ' . ($g['nr_umowy'] ?: '—') . ' · budżet ' . ($b['budget'] !== null ? money($b['budget'], $b['waluta']) : '—')
                        . ' · rozdysponowano ' . money($b['allocated'], $b['waluta']) . ' · stan na ' . date('d.m.Y H:i'),
            'sheet'    => 'Działania grantu',
            'headers'  => ['ID', 'Działanie', 'Status', 'Rozpoczęcie', 'Zakończenie', 'Koordynator', 'Kwota z grantu', 'Udział %',
                           'Etapy', 'Wskaźnik', 'Plan', 'Wykonanie', 'Realizacja %'],
            'rows'     => $rows,
            'dec'      => [6 => 2, 7 => 2, 10 => 2, 11 => 2],
        ];
    }
}
