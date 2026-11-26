<?php
declare(strict_types=1);

/**
 * modules/contract_links/lib/ContractLinks.php — przypisanie aktywnych umów (2026-11-26).
 *
 * Każda aktywna umowa zlecenia i wolontariacka (TYPES — tylko te rodzaje przypisujemy do jednostek) powinna mieć: kontakt (konto osoby w SZO), jednostkę (Struktura,
 * org_units) i obszar Zadań (task_workspaces). Tabela contract_links (contract_type + contract_id, jak contract_access).
 *   • podpowiedzi: osoba po e-mailu / M365 z umowy (jak TeamService), jednostka z umowy (org_unit_id) albo główna
 *     jednostka osoby (org_members), obszar — obszar zespołu osoby albo jedyny obszar jednostki;
 *   • autoMatch(): przypisuje tylko pewne dopasowania (jedno konto o tym e-mailu), source='auto';
 *   • aiProposal(): propozycja podziału od AI (asai_call, z anonimizacją) — BEZ imion, nazwisk i e-maili stron umów;
 *     administrator zatwierdza wybrane przypisania (source='ai'); nowe jednostki/obszary tylko jako propozycja.
 */

require_once dirname(__DIR__, 3) . '/includes/tasks.php';
require_once dirname(__DIR__, 3) . '/includes/org.php';
require_once dirname(__DIR__, 3) . '/modules/contract_status/lib/contractStatuses.php';

final class ContractLinks
{
    /** Rodzaje umów przypisywane do jednostek (i zespołów — TeamService::CONTRACT_TYPES): tylko zlecenie i wolontariat. */
    public const TYPES = ['zlecenie', 'wolontariat'];

    private static bool $migrated = false;

    public static function allows(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function migrate(): void
    {
        if (self::$migrated) return;
        self::$migrated = true;
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS contract_links (
            contract_type VARCHAR(30) NOT NULL,
            contract_id   INTEGER     NOT NULL,
            user_id       INTEGER     NULL,
            unit_id       INTEGER     NULL,
            workspace_id  INTEGER     NULL,
            source        VARCHAR(10) NOT NULL DEFAULT 'manual',
            updated_by    INTEGER     NULL,
            updated_at    DATETIME    NULL,
            PRIMARY KEY (contract_type, contract_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS contract_ai_proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_by INTEGER NULL,
            created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
            proposal_json TEXT NOT NULL,
            applied_at DATETIME NULL
        )");
    }

    private static function cols(string $table): array
    {
        static $c = [];
        if (!isset($c[$table])) {
            try { $c[$table] = array_column(db_all("PRAGMA table_info($table)"), 'name'); } catch (\Throwable $e) { $c[$table] = []; }
        }
        return $c[$table];
    }

    /** Aktywne umowy (grupa statusów „active” i data zakończenia pusta albo nie minęła) z przypisaniem i podpowiedziami. */
    public static function activeContracts(): array
    {
        self::migrate();
        $today = date('Y-m-d');
        $links = [];
        foreach (db_all("SELECT * FROM contract_links") as $l) $links[$l['contract_type'] . ':' . $l['contract_id']] = $l;
        $out = [];
        foreach (CONTRACT_TYPES as $type => $label) {
            if (!self::allows($type)) continue;
            $table = table_for_type($type);
            $c = self::cols($table);
            if (!$c) continue;
            $nameCol = CONTRACT_REGISTRY_FIELDS[$type]['name_col'] ?? 'numer_umowy';
            $pick = function (array $cands, string $as) use ($c) {
                foreach ($cands as $x) if (in_array($x, $c, true)) return "$x AS $as";
                return "'' AS $as";
            };
            $sql = "SELECT id, status, " . implode(', ', [
                $pick(['numer_umowy'], 'numer_umowy'), $pick(['nr_rejestru'], 'nr_rejestru'), $pick([$nameCol], 'strona'),
                $pick(['data_zakonczenia'], 'data_zakonczenia'), $pick(['email'], 'email'), $pick(['m365_login'], 'm365_login'),
                $pick(['m365_user_id'], 'm365_user_id'), $pick(['org_unit_id'], 'org_unit_id'),
                $pick(['stanowisko', 'przedmiot_zlecenia', 'przedmiot_umowy', 'przedmiot', 'nazwa_zadania', 'opis'], 'zakres'),
                $pick(['numer_projektu', 'projekt_program', 'dzial_projekt'], 'projekt'),
            ]) . " FROM $table WHERE " . contractStatusSqlIn(['active']);
            try { $rows = db_all($sql); } catch (\Throwable $e) { continue; }
            foreach ($rows as $r) {
                $end = substr((string)$r['data_zakonczenia'], 0, 10);
                if ($end !== '' && $end < $today) continue;
                $k = $type . ':' . $r['id'];
                $l = $links[$k] ?? [];
                $out[] = $r + [
                    'contract_type' => $type, 'contract_type_label' => $label, 'key' => $k,
                    'user_id' => isset($l['user_id']) ? (int)$l['user_id'] : 0,
                    'unit_id' => isset($l['unit_id']) ? (int)$l['unit_id'] : 0,
                    'workspace_id' => isset($l['workspace_id']) ? (int)$l['workspace_id'] : 0,
                    'source' => (string)($l['source'] ?? ''),
                    'url' => rtrim(APP_URL, '/') . '/contracts/' . $type . '/view.php?id=' . (int)$r['id'],
                ];
            }
        }
        return $out;
    }

    public static function missing(array $c): array
    {
        $m = [];
        if (!$c['user_id']) $m[] = 'kontakt';
        if (!$c['unit_id']) $m[] = 'jednostka';
        if (!$c['workspace_id']) $m[] = 'obszar';
        return $m;
    }

    /** Liczba aktywnych umów z brakami (do paska przypomnienia). */
    public static function missingCount(): int
    {
        return count(array_filter(self::activeContracts(), fn($c) => self::missing($c)));
    }

    /** Konta SZO pasujące do umowy po e-mailu / M365 (aktywne). */
    public static function matchUsers(array $c): array
    {
        $conds = []; $p = [];
        $email = strtolower(trim((string)($c['email'] ?? '')));
        $login = strtolower(trim((string)($c['m365_login'] ?? '')));
        $ms = trim((string)($c['m365_user_id'] ?? ''));
        foreach (array_unique(array_filter([$email, $login])) as $e) { $conds[] = 'LOWER(email)=?'; $p[] = $e; }
        if ($ms !== '') { $conds[] = 'microsoft_id=?'; $p[] = $ms; }
        if (!$conds) return [];
        try { return db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 AND (" . implode(' OR ', $conds) . ")", $p); }
        catch (\Throwable $e) { return []; }
    }

    /** Podpowiedź: osoba (gdy jedna), jednostka (z umowy albo główna jednostka osoby), obszar (zespół osoby albo jedyny obszar). */
    public static function suggest(array $c): array
    {
        $users = self::matchUsers($c);
        $uid = $c['user_id'] ?: (count($users) === 1 ? (int)$users[0]['id'] : 0);
        $unit = $c['unit_id'] ?: (int)($c['org_unit_id'] ?: 0);
        if (!$unit && $uid) {
            $r = db_one("SELECT unit_id FROM org_members WHERE user_id=? AND status='active' ORDER BY is_primary DESC, id DESC LIMIT 1", [$uid]);
            $unit = (int)($r['unit_id'] ?? 0);
        }
        $ws = $c['workspace_id'];
        if (!$ws && $uid) {
            task_teams_migrate();
            $r = db_all("SELECT DISTINCT wt.workspace_id FROM task_workspace_teams wt JOIN task_team_members tm ON tm.team_id=wt.team_id
                         JOIN task_workspaces w ON w.id=wt.workspace_id AND w.is_active=1 WHERE tm.user_id=?", [$uid]);
            if (count($r) === 1) $ws = (int)$r[0]['workspace_id'];
            if (!$ws) {
                $r = db_all("SELECT m.workspace_id FROM task_workspace_members m JOIN task_workspaces w ON w.id=m.workspace_id AND w.is_active=1 WHERE m.user_id=?", [$uid]);
                if (count($r) === 1) $ws = (int)$r[0]['workspace_id'];
            }
        }
        return ['user_id' => $uid, 'unit_id' => $unit, 'workspace_id' => (int)$ws, 'user_candidates' => $users];
    }

    public static function save(string $type, int $id, array $data, string $source, int $by): void
    {
        if (!self::allows($type)) return;
        self::migrate();
        $cur = db_one("SELECT * FROM contract_links WHERE contract_type=? AND contract_id=?", [$type, $id]) ?: [];
        $val = fn(string $k) => array_key_exists($k, $data) ? ((int)$data[$k] ?: null) : (isset($cur[$k]) ? ((int)$cur[$k] ?: null) : null);
        db()->prepare("INSERT INTO contract_links (contract_type, contract_id, user_id, unit_id, workspace_id, source, updated_by, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                       ON CONFLICT(contract_type, contract_id) DO UPDATE SET user_id=excluded.user_id, unit_id=excluded.unit_id,
                       workspace_id=excluded.workspace_id, source=excluded.source, updated_by=excluded.updated_by, updated_at=excluded.updated_at")
            ->execute([$type, $id, $val('user_id'), $val('unit_id'), $val('workspace_id'), $source, $by, date('Y-m-d H:i:s')]);
        try {
            require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
            audit_log('contract_links.' . $source, ['contract' => $type . ':' . $id, 'before' => $cur ?: null,
                'after' => ['user_id' => $val('user_id'), 'unit_id' => $val('unit_id'), 'workspace_id' => $val('workspace_id')]]);
        } catch (\Throwable $e) {}
    }

    /** Uzupełnia braki pewnymi dopasowaniami. @return array{contracts:int, fields:int} */
    public static function autoMatch(int $by): array
    {
        $n = 0; $f = 0;
        foreach (self::activeContracts() as $c) {
            if (!self::missing($c)) continue;
            $s = self::suggest($c);
            $data = [];
            foreach (['user_id', 'unit_id', 'workspace_id'] as $k) if (!$c[$k] && $s[$k]) $data[$k] = $s[$k];
            if (!$data) continue;
            self::save($c['contract_type'], (int)$c['id'], $data, 'auto', $by);
            $n++; $f += count($data);
        }
        return ['contracts' => $n, 'fields' => $f];
    }

    // ── Propozycja podziału od AI ─────────────────────────────────────────────

    public static function aiProposal(int $by): array
    {
        self::migrate();
        require_once dirname(__DIR__, 3) . '/includes/asystent_ai.php';
        if (!asai_enabled()) return ['error' => 'Brak klucza Anthropic API (Admin → Ustawienia AI).'];
        $units = array_map(fn($u) => ['id' => (int)$u['id'], 'nazwa' => $u['name'], 'kod' => $u['code'], 'nadrzedna_id' => (int)($u['parent_id'] ?? 0)], org_units_all('active'));
        $ws = array_map(fn($w) => ['id' => (int)$w['id'], 'nazwa' => $w['name']], db_all("SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name"));
        task_teams_migrate();
        $teams = array_map(fn($t) => ['nazwa' => $t['name'], 'jednostka_id' => (int)($t['unit_id'] ?? 0)], db_all("SELECT name, unit_id FROM task_teams WHERE COALESCE(status,'active')<>'archived'"));
        $contracts = array_map(fn($c) => [
            'klucz' => $c['key'], 'rodzaj' => $c['contract_type_label'],
            'zakres' => mb_substr(trim(strip_tags((string)$c['zakres'])), 0, 160), 'projekt' => mb_substr((string)$c['projekt'], 0, 80),
            'jednostka_id' => $c['unit_id'] ?: null, 'obszar_id' => $c['workspace_id'] ?: null,
        ], self::activeContracts());
        if (!$contracts) return ['error' => 'Nie ma aktywnych umów do podziału.'];

        $system = "Jesteś doradcą organizacyjnym fundacji (NGO) w Polsce. Na podstawie struktury (jednostki, obszary zadań, zespoły) "
            . "i listy AKTYWNYCH umów zlecenia i wolontariackich (bez danych osobowych) zaproponuj czytelny podział: do jakiej jednostki i jakiego obszaru zadań "
            . "przypisać każdą umowę, oraz czy brakuje jednostek lub obszarów (np. dla projektu/grantu albo grupy zadań). "
            . "Używaj istniejących id, gdy pasują. Nowe jednostki/obszary proponuj oszczędnie i tylko z uzasadnieniem. "
            . "Odpowiedz WYŁĄCZNIE poprawnym JSON (bez komentarzy) wg schematu: "
            . '{"podsumowanie":"2-4 zdania po polsku","nowe_jednostki":[{"nazwa":"","nadrzedna_id":null,"uzasadnienie":""}],'
            . '"nowe_obszary":[{"nazwa":"","jednostka_id":null,"uzasadnienie":""}],'
            . '"przypisania":[{"klucz":"typ:id","jednostka_id":null,"obszar_id":null,"nowa_jednostka":null,"nowy_obszar":null,"uzasadnienie":""}]}'
            . ' — w przypisaniach podaj albo istniejące id, albo nazwę nowej jednostki/obszaru z list powyżej. Pisz prostym językiem.';
        $user = json_encode(['jednostki' => $units, 'obszary_zadan' => $ws, 'zespoly' => $teams, 'umowy' => $contracts], JSON_UNESCAPED_UNICODE);
        $resp = asai_call([
            'model' => asai_model(), 'max_tokens' => 8000, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ]);
        if (!empty($resp['error'])) return ['error' => $resp['error']];
        $text = '';
        foreach ($resp['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
        if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
        $json = json_decode($text, true);
        if (!is_array($json) || !isset($json['przypisania'])) return ['error' => 'AI zwróciło odpowiedź w nieoczekiwanym formacie. Spróbuj ponownie.'];
        $id = db_insert('contract_ai_proposals', ['created_by' => $by, 'proposal_json' => json_encode($json, JSON_UNESCAPED_UNICODE), 'created_at' => date('Y-m-d H:i:s')]);
        return ['id' => $id, 'proposal' => $json];
    }

    public static function lastProposal(): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM contract_ai_proposals ORDER BY id DESC LIMIT 1");
        if (!$r) return null;
        $r['proposal'] = json_decode((string)$r['proposal_json'], true) ?: [];
        return $r;
    }

    /** Zastosuj wybrane przypisania z propozycji (tylko istniejące jednostki/obszary). @return int liczba umów */
    public static function applyProposal(int $proposalId, array $keys, int $by): int
    {
        $r = db_one("SELECT proposal_json FROM contract_ai_proposals WHERE id=?", [$proposalId]);
        if (!$r) return 0;
        $p = json_decode((string)$r['proposal_json'], true) ?: [];
        $want = array_flip($keys);
        $unitIds = array_flip(array_map(fn($u) => (int)$u['id'], org_units_all('active')));
        $wsIds = array_flip(array_map('intval', array_column(db_all("SELECT id FROM task_workspaces WHERE is_active=1"), 'id')));
        $n = 0;
        foreach ($p['przypisania'] ?? [] as $a) {
            $k = (string)($a['klucz'] ?? '');
            if (!isset($want[$k]) || !preg_match('/^([a-z_]+):(\d+)$/', $k, $m) || !self::allows($m[1])) continue;
            $data = [];
            if (!empty($a['jednostka_id']) && isset($unitIds[(int)$a['jednostka_id']])) $data['unit_id'] = (int)$a['jednostka_id'];
            if (!empty($a['obszar_id']) && isset($wsIds[(int)$a['obszar_id']])) $data['workspace_id'] = (int)$a['obszar_id'];
            if (!$data) continue;
            self::save($m[1], (int)$m[2], $data, 'ai', $by);
            $n++;
        }
        db()->prepare("UPDATE contract_ai_proposals SET applied_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $proposalId]);
        return $n;
    }
}
