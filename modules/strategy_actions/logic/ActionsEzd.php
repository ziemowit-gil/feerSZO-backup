<?php
/**
 * modules/strategy_actions/logic/ActionsEzd.php — integracja Działań z EZD Wirtualne biurko (includes/ezd.php).
 *
 * Działanie z celem statutowym §6 ma pozycję JRWA w klasie celu (action_ensure_jrwa, np. 32.001). Tu dochodzi:
 *   segregator (ezd_teczki) na bieżący rok w tej pozycji JRWA — tworzony, gdy go brak;
 *   koszulka (ezd_sprawy) powiązana z działaniem przez ref_type='action', ref_id=id działania;
 *   przypinanie istniejącej koszulki po znaku, odpinanie, liczniki pism / dokumentów / załączników / notatek.
 * Terminologia UI EZD: sprawa = „koszulka”, teczka JRWA = „segregator” (rodzaj męski).
 * Dostęp do treści koszulki nadal egzekwuje EZD (ezd/sprawy/view.php) — tu tylko znak, tytuł i liczniki.
 */
require_once __DIR__ . '/ActionsService.php';

final class ActionsEzd
{
    public const REF = 'action';

    public static function enabled(): bool
    {
        return module_enabled('ezd_enabled');
    }

    private static function load(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
    }

    public static function caseUrl(int $sprawaId): string
    {
        return APP_URL . '/ezd/sprawy/view.php?id=' . $sprawaId;
    }

    /** Koszulki działania z licznikami (najnowsze pierwsze). */
    public static function cases(int $actionId): array
    {
        if (!self::enabled()) return [];
        self::load();
        $out = [];
        foreach (ezd_sprawy_by_ref(self::REF, $actionId) as $s) {
            $sid = (int)$s['id'];
            $cnt = fn(string $t) => (int)(db_one("SELECT COUNT(*) AS c FROM $t WHERE sprawa_id = ?", [$sid])['c'] ?? 0);
            $out[] = ['id' => $sid, 'znak' => (string)$s['znak_sprawy'], 'title' => (string)$s['title'],
                      'open' => ($s['status'] ?? 'open') !== 'closed', 'segregator' => (string)($s['teczka_symbol'] ?? ''),
                      'pisma' => $cnt('ezd_pisma'), 'dokumenty' => $cnt('ezd_dokumenty'),
                      'zalaczniki' => $cnt('ezd_zalaczniki'), 'notatki' => $cnt('ezd_notatki'),
                      'url' => self::caseUrl($sid)];
        }
        return $out;
    }

    /** Segregator bieżącego roku w pozycji JRWA działania (tworzy pozycję JRWA i segregator, gdy ich brak). */
    private static function segregator(array $a, int $uid): array
    {
        $jrwaId = (int)($a['jrwa_id'] ?? 0);
        if (!$jrwaId && !empty($a['cel_statutowy'])) {
            $jrwaId = (int)action_ensure_jrwa((int)$a['id'], (int)$a['cel_statutowy'], (string)$a['nazwa']);
        }
        if (!$jrwaId && !empty($a['cel_statutowy'])) {
            throw new \RuntimeException('W EZD brak klasy JRWA ' . (ACTION_CEL_JRWA[(int)$a['cel_statutowy']] ?? '?')
                . ' dla celu §6 pkt ' . (int)$a['cel_statutowy'] . ' — dodaj ją w EZD (JRWA), potem załóż koszulkę.');
        }
        if (!$jrwaId) throw new \RuntimeException('Ustaw cel statutowy §6 działania — koszulka trafia do segregatora w klasie JRWA tego celu.');
        $jrwa = db_one("SELECT * FROM ezd_jrwa WHERE id = ?", [$jrwaId]);
        if (!$jrwa) throw new \RuntimeException('Pozycja JRWA działania nie istnieje w EZD.');
        $rok = (int)date('Y');
        $t = db_one("SELECT * FROM ezd_teczki WHERE jrwa_id = ? AND rok = ? AND status != 'closed' ORDER BY id LIMIT 1", [$jrwaId, $rok]);
        if ($t) return $t;
        $tid = ezd_teczka_create(['jrwa_id' => $jrwaId, 'symbol' => (string)$jrwa['symbol'], 'title' => (string)$jrwa['title'],
                                  'rok' => $rok, 'owner_id' => $a['koordynator_id'] ?: $uid], $uid);
        return ezd_teczka_get($tid) ?? ['id' => $tid];
    }

    /**
     * Nowa koszulka dla działania. Zwraca [id, znak]. Wyjątek RuntimeException z komunikatem dla użytkownika.
     * @return array{0:int,1:string}
     */
    public static function createCase(int $actionId, int $uid): array
    {
        if (!self::enabled()) throw new \RuntimeException('Moduł EZD Wirtualne biurko jest wyłączony.');
        self::load();
        $a = ActionsService::find($actionId);
        if (!$a) throw new \RuntimeException('Działanie nie istnieje.');
        $t = self::segregator($a, $uid);
        $desc = trim((string)($a['opis'] ?? '') . "\n\nDziałanie: " . ActionsService::url((string)$actionId));
        $sid = ezd_sprawa_create(['teczka_id' => (int)$t['id'], 'title' => (string)$a['nazwa'], 'description' => $desc,
                                  'owner_id' => $a['koordynator_id'] ?: $uid, 'deadline' => $a['data_do'] ?: null,
                                  'ref_type' => self::REF, 'ref_id' => $actionId], $uid);
        $znak = (string)(db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id = ?", [$sid])['znak_sprawy'] ?? '');
        ActionsJournal::log($actionId, 'ezd', 'Założono koszulkę EZD ' . $znak . '.', $uid);
        return [$sid, $znak];
    }

    /** Przypięcie istniejącej koszulki po znaku (np. 32.001.3.2026). @return string|null komunikat błędu */
    public static function attach(int $actionId, string $znak, int $uid): ?string
    {
        if (!self::enabled()) return 'Moduł EZD Wirtualne biurko jest wyłączony.';
        self::load();
        $znak = trim($znak);
        if ($znak === '') return 'Podaj znak koszulki.';
        $s = db_one("SELECT id, znak_sprawy, ref_type, ref_id FROM ezd_sprawy WHERE UPPER(znak_sprawy) = UPPER(?)", [$znak]);
        if (!$s) return 'Nie ma koszulki o znaku ' . $znak . '.';
        if ($s['ref_type'] === self::REF && (int)$s['ref_id'] === $actionId) return 'Ta koszulka jest już przypięta do działania.';
        if (!empty($s['ref_type'])) {
            return 'Koszulka ' . $s['znak_sprawy'] . ' jest już powiązana ' . ($s['ref_type'] === self::REF
                ? 'z działaniem #' . (int)$s['ref_id'] . ' — najpierw odepnij ją tam.'
                : 'z innym rekordem (' . $s['ref_type'] . ' #' . (int)$s['ref_id'] . ').');
        }
        db()->prepare("UPDATE ezd_sprawy SET ref_type = ?, ref_id = ?, updated_at = datetime('now') WHERE id = ?")->execute([self::REF, $actionId, (int)$s['id']]);
        ezd_log(null, (int)$s['id'], null, null, $uid, 'sprawa_update', 'Powiązano z działaniem #' . $actionId);
        ActionsJournal::log($actionId, 'ezd', 'Przypięto koszulkę EZD ' . $s['znak_sprawy'] . '.', $uid);
        return null;
    }

    public static function detach(int $actionId, int $sprawaId, int $uid): ?string
    {
        if (!self::enabled()) return 'Moduł EZD Wirtualne biurko jest wyłączony.';
        self::load();
        $s = db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id = ? AND ref_type = ? AND ref_id = ?", [$sprawaId, self::REF, $actionId]);
        if (!$s) return 'Koszulka nie jest przypięta do tego działania.';
        db()->prepare("UPDATE ezd_sprawy SET ref_type = NULL, ref_id = NULL, updated_at = datetime('now') WHERE id = ?")->execute([$sprawaId]);
        ezd_log(null, $sprawaId, null, null, $uid, 'sprawa_update', 'Odpięto od działania #' . $actionId);
        ActionsJournal::log($actionId, 'ezd', 'Odpięto koszulkę EZD ' . $s['znak_sprawy'] . ' (koszulka zostaje w EZD).', $uid);
        return null;
    }
}
