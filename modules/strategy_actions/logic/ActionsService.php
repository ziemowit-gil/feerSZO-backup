<?php
/**
 * modules/strategy_actions/logic/ActionsService.php — Działania organizacji (moduł Strategia, tabela actions).
 *
 * Odczyt działań z finansowaniem (granty) i wskaźnikami, flagi ostrzeżeń, zapis transakcyjny, usuwanie.
 * Tabele bazowe actions / action_grants / action_indicators oraz segregator JRWA (§6 statutu → ezd_jrwa)
 * zakłada includes/grants.php — ten plik tylko z nich korzysta.
 */
require_once dirname(__DIR__, 3) . '/includes/grants.php';

final class ActionsService
{
    /** Statusy (kolejność = kolumny tablicy). Kolory Metro. */
    public const STATUSES = [
        'planowane'       => ['label' => 'Planowane',       'color' => '#5f5f5f'],
        'w_przygotowaniu' => ['label' => 'W przygotowaniu', 'color' => '#0050ef'],
        'w_trakcie'       => ['label' => 'W trakcie',       'color' => '#008a00'],
        'zawieszone'      => ['label' => 'Zawieszone',      'color' => '#c25100'],
        'zakończone'      => ['label' => 'Zakończone',      'color' => '#1d1d1d'],
        'anulowane'       => ['label' => 'Anulowane',       'color' => '#a20025'],
    ];

    /** Statusy „w toku” — liczą się do kafla Aktywne i do ostrzeżeń o terminach. */
    public const ACTIVE = ['planowane', 'w_przygotowaniu', 'w_trakcie'];

    public const TYPES = [
        'warsztat'              => ['label' => 'Warsztat',              'icon' => 'bi-easel'],
        'konferencja'           => ['label' => 'Konferencja',           'icon' => 'bi-mic'],
        'kampania'              => ['label' => 'Kampania',              'icon' => 'bi-megaphone'],
        'wsparcie_indywidualne' => ['label' => 'Wsparcie indywidualne', 'icon' => 'bi-person-heart'],
        'szkolenie'             => ['label' => 'Szkolenie',             'icon' => 'bi-mortarboard'],
        'spotkanie'             => ['label' => 'Spotkanie',             'icon' => 'bi-people'],
        'inne'                  => ['label' => 'Inne',                  'icon' => 'bi-three-dots'],
    ];

    public const FORMS = ['stacjonarne' => 'Stacjonarne', 'online' => 'Online', 'hybrydowe' => 'Hybrydowe'];
    public const FREQS = ['tygodniowo' => 'Tygodniowo', 'miesiecznie' => 'Miesięcznie', 'kwartalnie' => 'Kwartalnie'];
    public const BENEFIT_TITLES = ['Korzyści', 'Co zyskasz'];

    /** Adres krótki /dzialania/… (pełny, z APP_URL). */
    public static function url(string $path = '', array $query = []): string
    {
        return rtrim(APP_URL, '/') . '/dzialania' . ($path !== '' ? '/' . ltrim($path, '/') : '')
            . ($query ? '?' . http_build_query($query) : '');
    }

    public static function statusLabel(string $s): string { return self::STATUSES[$s]['label'] ?? $s; }
    public static function statusColor(string $s): string { return self::STATUSES[$s]['color'] ?? '#5f5f5f'; }

    /** @return array{label:string, icon:string} */
    public static function typeInfo(string $t): array { return self::TYPES[$t] ?? ['label' => $t, 'icon' => 'bi-three-dots']; }

    /** Cele statutowe §6 (1–9) — etykiety z includes/grants.php. */
    public static function goals(): array { return ACTION_CELE; }

    // ── Odczyt ────────────────────────────────────────────────────────────────

    /** Wszystkie działania w kształcie dla ekranu (lista, tablica, oś czasu, kalendarz) — jedno zapytanie na tabelę. */
    public static function all(): array
    {
        $rows = db_all(
            "SELECT a.*, u.name AS koordynator_name
             FROM actions a LEFT JOIN users u ON u.id = a.koordynator_id
             ORDER BY a.data_od DESC, a.id DESC"
        );
        $grants = [];
        foreach (db_all("SELECT ag.action_id, ag.grant_id, ag.kwota, ag.udzial_procent, g.nazwa, g.waluta
                         FROM action_grants ag JOIN grants g ON g.id = ag.grant_id ORDER BY g.nazwa") as $g) {
            $grants[(int)$g['action_id']][] = $g;
        }
        $inds = [];
        foreach (db_all("SELECT * FROM action_indicators ORDER BY id") as $i) $inds[(int)$i['action_id']][] = $i;
        return array_map(fn(array $a) => self::decorate($a, $grants[(int)$a['id']] ?? [], $inds[(int)$a['id']] ?? []), $rows);
    }

    /** Jedno działanie w kształcie ekranu (jak all()). */
    public static function one(int $id): ?array
    {
        $a = action_by_id($id);
        if (!$a) return null;
        $grants = db_all("SELECT ag.action_id, ag.grant_id, ag.kwota, ag.udzial_procent, g.nazwa, g.waluta
                          FROM action_grants ag JOIN grants g ON g.id = ag.grant_id WHERE ag.action_id = ? ORDER BY g.nazwa", [$id]);
        return self::decorate($a, $grants, grant_action_indicators_for($id));
    }

    /** Wiersz bazy → kształt ekranu z sumami wskaźników, kwotą finansowania i flagami ostrzeżeń. */
    public static function decorate(array $a, array $grants, array $inds): array
    {
        $today = date('Y-m-d');
        $in14  = date('Y-m-d', strtotime('+14 days'));
        $plan = 0.0; $real = 0.0; $behind = false;
        foreach ($inds as $i) {
            $p = (float)($i['wartosc_planowana'] ?? 0);
            $r = (float)($i['wartosc_realizowana'] ?? 0);
            $plan += $p; $real += $r;
            if ($p > 0 && $r < $p * 0.5) $behind = true;
        }
        $status = (string)$a['status'];
        $active = in_array($status, self::ACTIVE, true);
        $do     = $a['data_do'] ?: null;
        $kwota  = 0.0;
        $g = [];
        foreach ($grants as $x) {
            $kwota += (float)($x['kwota'] ?? 0);
            $g[] = ['id' => (int)$x['grant_id'], 'nazwa' => (string)$x['nazwa'], 'waluta' => (string)($x['waluta'] ?: 'PLN'),
                    'kwota' => $x['kwota'] !== null && $x['kwota'] !== '' ? (float)$x['kwota'] : null,
                    'udzial' => $x['udzial_procent'] !== null && $x['udzial_procent'] !== '' ? (float)$x['udzial_procent'] : null];
        }
        return [
            'id'            => (int)$a['id'],
            'nazwa'         => (string)$a['nazwa'],
            'typ'           => (string)$a['typ'],
            'status'        => $status,
            'opis'          => (string)($a['opis'] ?? ''),
            'koordynatorId' => $a['koordynator_id'] !== null && $a['koordynator_id'] !== '' ? (int)$a['koordynator_id'] : null,
            'koordynator'   => (string)($a['koordynator_name'] ?? ''),
            'od'            => $a['data_od'] ?: null,
            'do'            => $do,
            'forma'         => (string)($a['forma'] ?? ''),
            'lokalizacja'   => (string)($a['lokalizacja'] ?? ''),
            'cel'           => !empty($a['cel_statutowy']) ? (int)$a['cel_statutowy'] : null,
            'wlasne'        => (bool)$a['wlasne_dzialanie'],
            'cykliczne'     => (bool)$a['cykliczne'],
            'grants'        => $g,
            'kwota'         => $kwota,
            'indCount'      => count($inds),
            'indPlan'       => $plan,
            'indReal'       => $real,
            'pct'           => $plan > 0 ? (int)min(100, round($real / $plan * 100)) : null,
            'createdBy'     => (int)($a['created_by'] ?? 0),
            'updatedAt'     => (string)($a['updated_at'] ?? ''),
            'flags'         => [
                'noGrant' => !$g && !$a['wlasne_dzialanie'] && $status !== 'anulowane',
                'ending'  => $active && $do !== null && $do >= $today && $do <= $in14,
                'overdue' => $active && $do !== null && $do < $today,
                'behind'  => $behind && $status !== 'anulowane',
            ],
        ];
    }

    /** Surowy wiersz z finansowaniem, wskaźnikami i symbolem segregatora JRWA (karta i formularz). */
    public static function find(int $id): ?array
    {
        $a = action_by_id($id);
        if (!$a) return null;
        $a['grants']     = action_grants_for($id);
        $a['indicators'] = grant_action_indicators_for($id);
        $a['jrwa_symbol'] = null;
        if (!empty($a['jrwa_id'])) {
            try { $a['jrwa_symbol'] = db_one("SELECT symbol FROM ezd_jrwa WHERE id = ?", [(int)$a['jrwa_id']])['symbol'] ?? null; }
            catch (\Throwable $e) {}
        }
        return $a;
    }

    /** Osoby do wyboru koordynatora: admin/redaktor + każdy, kto już koordynuje działanie (np. prowadzący kursu TI). */
    public static function coordinators(): array
    {
        return db_all("SELECT id, name FROM users
                       WHERE role IN ('admin','editor')
                          OR id IN (SELECT DISTINCT koordynator_id FROM actions WHERE koordynator_id IS NOT NULL)
                       ORDER BY name");
    }

    public static function grantsList(): array
    {
        return db_all("SELECT id, nazwa, donator, waluta FROM grants ORDER BY nazwa");
    }

    public static function canDelete(array $raw): bool
    {
        if (is_admin()) return true;
        return can_edit() && (int)($raw['created_by'] ?? 0) === (int)(current_user()['id'] ?? -1);
    }

    // ── Zapis ─────────────────────────────────────────────────────────────────

    /** Pola formularza → kolumny actions (biała lista; wartości spoza słowników zastąpione domyślnymi). */
    public static function normalize(array $in): array
    {
        $t = fn(string $k) => trim((string)($in[$k] ?? ''));
        $forma = $t('forma');
        $freq  = $t('czestotliwosc');
        $cel   = (int)$t('cel_statutowy');
        $kt    = $t('korzysci_tytul');
        $cykl  = !empty($in['cykliczne']) ? 1 : 0;
        return [
            'nazwa'            => $t('nazwa'),
            'typ'              => isset(self::TYPES[$t('typ')]) ? $t('typ') : 'inne',
            'status'           => isset(self::STATUSES[$t('status')]) ? $t('status') : 'planowane',
            'opis'             => $t('opis') !== '' ? $t('opis') : null,
            'koordynator_id'   => (int)$t('koordynator_id') ?: null,
            'data_od'          => self::date($t('data_od')),
            'data_do'          => self::date($t('data_do')),
            'cykliczne'        => $cykl,
            'czestotliwosc'    => $cykl && isset(self::FREQS[$freq]) ? $freq : null,
            'lokalizacja'      => $t('lokalizacja') !== '' ? $t('lokalizacja') : null,
            'forma'            => isset(self::FORMS[$forma]) ? $forma : 'stacjonarne',
            'link_online'      => $t('link_online') !== '' ? $t('link_online') : null,
            'wlasne_dzialanie' => !empty($in['wlasne_dzialanie']) ? 1 : 0,
            'korzysci_tytul'   => in_array($kt, self::BENEFIT_TITLES, true) ? $kt : 'Korzyści',
            'korzysci'         => $t('korzysci') !== '' ? $t('korzysci') : null,
            'dla_kogo'         => $t('dla_kogo') !== '' ? $t('dla_kogo') : null,
            'cel_statutowy'    => isset(ACTION_CELE[$cel]) ? $cel : null,
        ];
    }

    /** @return list<string> komunikaty błędów (puste = poprawne) */
    public static function validate(array $d): array
    {
        $e = [];
        if ($d['nazwa'] === '') $e[] = 'Podaj nazwę działania.';
        if (mb_strlen($d['nazwa']) > 300) $e[] = 'Nazwa może mieć najwyżej 300 znaków.';
        if ($d['data_od'] && $d['data_do'] && $d['data_do'] < $d['data_od']) $e[] = 'Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.';
        if ($d['link_online'] !== null && $d['forma'] !== 'stacjonarne' && !preg_match('#^https?://#i', $d['link_online'])) $e[] = 'Link do spotkania online musi zaczynać się od http:// lub https://.';
        return $e;
    }

    /** Wiersze finansowania z formularza (grant_id[], udzial_procent[], grant_kwota[]). */
    public static function grantRows(array $post): array
    {
        $out = [];
        foreach ((array)($post['grant_id'] ?? []) as $i => $gid) {
            $gid = (int)$gid;
            if (!$gid || isset($out[$gid])) continue;
            $out[$gid] = ['grant_id' => $gid,
                          'udzial_procent' => self::num($post['udzial_procent'][$i] ?? ''),
                          'kwota'          => self::num($post['grant_kwota'][$i] ?? '')];
        }
        return array_values($out);
    }

    /** Wiersze wskaźników z formularza (ind_id[], ind_nazwa[], ind_planowana[], ind_realizowana[]). */
    public static function indicatorRows(array $post): array
    {
        $out = [];
        foreach ((array)($post['ind_nazwa'] ?? []) as $i => $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $out[] = ['id' => (int)($post['ind_id'][$i] ?? 0), 'nazwa' => mb_substr($name, 0, 200),
                      'wartosc_planowana' => self::num($post['ind_planowana'][$i] ?? ''),
                      'wartosc_realizowana' => self::num($post['ind_realizowana'][$i] ?? '') ?? 0.0];
        }
        return $out;
    }

    /**
     * Zapis działania razem z finansowaniem i wskaźnikami (jedna transakcja). Wskaźniki aktualizowane po id —
     * zachowują identyfikatory (historia, szybka edycja); usunięte z formularza są kasowane. Zwraca id działania.
     */
    public static function save(?int $id, array $d, array $grantRows, array $indRows, int $userId): int
    {
        $pdo = db();
        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $id = (int)db_insert('actions', $d + ['created_by' => $userId ?: null, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
                $pdo->prepare("UPDATE actions SET $sets, updated_at = ? WHERE id = ?")->execute([...array_values($d), $now, $id]);
            }
            $pdo->prepare("DELETE FROM action_grants WHERE action_id = ?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO action_grants (action_id, grant_id, udzial_procent, kwota) VALUES (?,?,?,?)");
            foreach ($grantRows as $g) $ins->execute([$id, $g['grant_id'], $g['udzial_procent'], $g['kwota']]);

            $existing = array_map('intval', array_column(db_all("SELECT id FROM action_indicators WHERE action_id = ?", [$id]), 'id'));
            $keep = [];
            $upd = $pdo->prepare("UPDATE action_indicators SET nazwa = ?, wartosc_planowana = ?, wartosc_realizowana = ? WHERE id = ? AND action_id = ?");
            $add = $pdo->prepare("INSERT INTO action_indicators (action_id, nazwa, wartosc_planowana, wartosc_realizowana) VALUES (?,?,?,?)");
            foreach ($indRows as $r) {
                if ($r['id'] && in_array($r['id'], $existing, true)) {
                    $upd->execute([$r['nazwa'], $r['wartosc_planowana'], $r['wartosc_realizowana'], $r['id'], $id]);
                    $keep[] = $r['id'];
                } else {
                    $add->execute([$id, $r['nazwa'], $r['wartosc_planowana'], $r['wartosc_realizowana']]);
                    $keep[] = (int)$pdo->lastInsertId();
                }
            }
            foreach (array_diff($existing, $keep) as $gone) {
                $pdo->prepare("DELETE FROM action_indicators WHERE id = ? AND action_id = ?")->execute([$gone, $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($d['cel_statutowy']) action_ensure_jrwa($id, (int)$d['cel_statutowy'], $d['nazwa']);
        return $id;
    }

    /** Zmiana samego statusu (tablica, panel szczegółów). Zwraca poprzedni status albo null, gdy działania nie ma. */
    public static function setStatus(int $id, string $status): ?string
    {
        $old = db_one("SELECT status FROM actions WHERE id = ?", [$id]);
        if (!$old) return null;
        db()->prepare("UPDATE actions SET status = ?, updated_at = ? WHERE id = ?")->execute([$status, date('Y-m-d H:i:s'), $id]);
        return (string)$old['status'];
    }

    /**
     * Wpisanie wykonania wskaźników z panelu: [id wskaźnika => wartość]. Zmienia tylko wskaźniki tego działania.
     * @return list<array{nazwa:string, from:float, to:float}> faktyczne zmiany
     */
    public static function setIndicatorValues(int $id, array $values): array
    {
        $changes = [];
        $upd = db()->prepare("UPDATE action_indicators SET wartosc_realizowana = ? WHERE id = ? AND action_id = ?");
        foreach (grant_action_indicators_for($id) as $ind) {
            $iid = (int)$ind['id'];
            if (!array_key_exists($iid, $values) && !array_key_exists((string)$iid, $values)) continue;
            $v = self::num($values[$iid] ?? $values[(string)$iid]);
            if ($v === null || $v < 0) continue;
            $from = (float)($ind['wartosc_realizowana'] ?? 0);
            if (abs($from - $v) < 0.0001) continue;
            $upd->execute([$v, $iid, $id]);
            $changes[] = ['nazwa' => (string)$ind['nazwa'], 'from' => $from, 'to' => $v];
        }
        if ($changes) db()->prepare("UPDATE actions SET updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id]);
        return $changes;
    }

    /** Kopia działania (status „planowane”, „(kopia)” w nazwie, te same granty, wskaźniki z wyzerowanym wykonaniem). */
    public static function duplicate(int $id, int $userId): ?int
    {
        $a = self::find($id);
        if (!$a) return null;
        $d = self::normalize($a);
        $d['nazwa']  = mb_substr($d['nazwa'] . ' (kopia)', 0, 300);
        $d['status'] = 'planowane';
        $grants = array_map(fn($g) => ['grant_id' => (int)$g['grant_id'], 'udzial_procent' => $g['udzial_procent'], 'kwota' => $g['kwota']], $a['grants']);
        $inds = array_map(fn($i) => ['id' => 0, 'nazwa' => (string)$i['nazwa'], 'wartosc_planowana' => $i['wartosc_planowana'], 'wartosc_realizowana' => 0.0], $a['indicators']);
        return self::save(null, $d, $grants, $inds, $userId);
    }

    /** Szczegóły do panelu bocznego listy (kształt ekranu + pola opisowe i wskaźniki z id). */
    public static function panel(int $id): ?array
    {
        $a = self::find($id);
        if (!$a) return null;
        $goals = self::goals();
        return [
            'action'     => self::one($id),
            'opis'       => (string)($a['opis'] ?? ''),
            'forma'      => self::FORMS[$a['forma'] ?? ''] ?? (string)($a['forma'] ?? ''),
            'link'       => (string)($a['link_online'] ?? ''),
            'miejsce'    => (string)($a['lokalizacja'] ?? ''),
            'cel'        => !empty($a['cel_statutowy']) && isset($goals[(int)$a['cel_statutowy']]) ? (int)$a['cel_statutowy'] . '. ' . $goals[(int)$a['cel_statutowy']] : '',
            'jrwa'       => (string)($a['jrwa_symbol'] ?? ''),
            'dlaKogo'    => (string)($a['dla_kogo'] ?? ''),
            'indicators' => array_map(fn($i) => ['id' => (int)$i['id'], 'nazwa' => (string)$i['nazwa'],
                                                 'plan' => $i['wartosc_planowana'] !== null ? (float)$i['wartosc_planowana'] : null,
                                                 'real' => (float)($i['wartosc_realizowana'] ?? 0)], $a['indicators']),
            'canDelete'  => self::canDelete($a),
        ];
    }

    /** Usuwa działanie z finansowaniem, wskaźnikami i powiązaniami (umowy wolontariatu / kursy TI tracą tylko odnośnik). */
    public static function delete(int $id): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach (self::childTables() as $table) {
                try { $pdo->prepare("DELETE FROM $table WHERE action_id = ?")->execute([$id]); } catch (\Throwable $e) {}
            }
            foreach (['umowy_wolontariat', 'k30_ti_courses'] as $table) {
                try { $pdo->prepare("UPDATE $table SET action_id = NULL WHERE action_id = ?")->execute([$id]); } catch (\Throwable $e) {}
            }
            $pdo->prepare("DELETE FROM actions WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Tabele zależne kasowane razem z działaniem. */
    public static function childTables(): array
    {
        return ['action_grants', 'action_indicators', 'crm_action_links'];
    }

    /** Zdarzenia kalendarza (format FullCalendar; także dawny calendar.php). */
    public static function calendarEvents(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'anulowane' || !$r['od']) continue;
            $out[] = ['id' => $r['id'], 'title' => $r['nazwa'], 'start' => $r['od'],
                      'end' => $r['do'] ? date('Y-m-d', strtotime($r['do'] . ' +1 day')) : null,
                      'color' => self::statusColor($r['status']),
                      'extendedProps' => ['koordynator' => $r['koordynator'], 'status' => $r['status']]];
        }
        return $out;
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    private static function date(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }

    public static function num($v): ?float
    {
        $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string)$v));
        return $v !== '' && is_numeric($v) ? (float)$v : null;
    }
}
