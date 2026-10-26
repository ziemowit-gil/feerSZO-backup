<?php
declare(strict_types=1);

/**
 * modules/doc_registry/lib/DocRegistry.php — rejestr numerów i kodów KAŻDEGO pliku wgranego do systemu (EZD, CRM, Zadania…).
 *
 * Każdy wgrany plik dostaje:
 *   • NUMER rejestrowy  DOK/{rok}/{id 6 cyfr}  — kolejny, czytelny dla człowieka,
 *   • KOD Code128       {TYP}/{6 losowych cyfr}{2 litery}, np. EZD/483920KP — TYP = system (EZD, CRM, ZAD…), reszta LOSOWA (nie do odgadnięcia),
 *   • QR                → /espis/?c=KOD: publiczna weryfikacja (stan, bez danych osobowych) + przycisk „Otwórz dokument online” (logowanie + uprawnienia systemu źródłowego).
 * Stan jest liczony NA ŻYWO ze źródła (plik usunięty → unieważniony, zastąpiony nowszą wersją → nieaktualny), więc nie trzeba haków przy kasowaniu.
 *
 * Użycie:   DocRegistry::assign('ezd_zalacznik', $id);            // przy wgraniu (idempotentne)
 *           DocRegistry::forMany('crm_case_file', $ids);          // do list — dopisuje brakujące (stare pliki) leniwie
 *           echo DocRegistry::badge($row);                        // numer + kod + QR w interfejsie
 * Nowy system plików = wpis w DocRegistry::SOURCES + jedno wywołanie assign() po zapisie pliku.
 */
final class DocRegistry
{
    /**
     * Źródła: typ => [prefiks, etykieta, adres otwarcia ({id}), tabela, tryb numeracji].
     * Tryb 'rand': kod Code128 TYP/6 cyfr+2 litery (losowy) + numer DOK/rok/id + etykieta do druku.
     * Tryb 'seq' : tylko numer w stylu KDOK/EODoK — PREFIKS/NNNN/ROK (kolejny w roku) i QR; numer jest zarazem kodem w QR (bez Code128 i etykiety).
     */
    public const SOURCES = [
        'ezd_zalacznik' => ['EZD', 'Plik w koszulce EZD', '/ezd/serve.php?id={id}', 'ezd_zalaczniki', 'rand'],
        'crm_case_file' => ['CRM', 'Plik sprawy CRM', '/crm/cases/download.php?id={id}', 'crm_case_files', 'rand'],
        'task_file'     => ['ZAD', 'Plik zadania', '/tasks/api/file.php?id={id}', 'task_files', 'rand'],
        'ezd_rpw_scan'  => ['RPW', 'Skan w dzienniku podawczym EZD (RPW)', '/ezd/rpw/scan.php?id={id}', 'ezd_rpw', 'rand'],
        'helpdesk_attachment' => ['HD', 'Załącznik zgłoszenia helpdesk', '/helpdesk/attachment.php?id={id}', 'helpdesk_attachments', 'seq'],
        'correspondence_file' => ['KOR', 'Załącznik korespondencji', '/correspondence/download.php?id={id}', 'correspondence', 'seq'],
        'rekr_file'           => ['REK', 'Dokument kandydata (nabór)', '/rekrutacja/attachment.php?id={id}', 'rekr_files', 'seq'],
        // Umowy (kody w stopce wydruków i DOCX): jeden rodzaj źródła na typ umowy; „plikiem” jest sama umowa, stan = status umowy.
        'contract_zlecenie'    => ['UZL', 'Umowa zlecenie', '/contracts/zlecenie/view.php?id={id}', 'umowy_zlecenie', 'rand'],
        'contract_uslugi'      => ['UUS', 'Umowa o świadczenie usług', '/contracts/uslugi/view.php?id={id}', 'umowy_uslugi', 'rand'],
        'contract_wolontariat' => ['UWL', 'Umowa wolontariacka', '/contracts/wolontariat/view.php?id={id}', 'umowy_wolontariat', 'rand'],
        'contract_dzielo'      => ['UDZ', 'Umowa o dzieło', '/contracts/dzielo/view.php?id={id}', 'umowy_dzielo', 'rand'],
        'contract_praca'       => ['UPR', 'Umowa o pracę', '/contracts/praca/view.php?id={id}', 'umowy_praca', 'rand'],
        'contract_powierzenie' => ['UPW', 'Umowa powierzenia zadania publicznego', '/contracts/powierzenie/view.php?id={id}', 'umowy_powierzenie', 'rand'],
        'contract_inne'        => ['UIN', 'Inna umowa', '/contracts/inne/view.php?id={id}', 'umowy_inne', 'rand'],
        'contract_szk'         => ['USZ', 'Umowa szkoleniowa (SZK)', '/contracts/szk/view.php?id={id}', 'umowy_szk', 'rand'],
        'print_doc'            => ['WYD', 'Wydruk dokumentu', '/', 'doc_prints', 'rand'],
        'gen_doc'              => ['DOG', 'Dokument z generatora wzorów', '/generator/view.php?id={id}', 'gen_documents', 'rand'],
    ];
    /** Litery kodu bez I i O (mylą się z 1 i 0 na wydruku). */
    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        db()->exec("CREATE TABLE IF NOT EXISTS doc_registry (
            id {$pk},
            code VARCHAR(40) NOT NULL,
            doc_number VARCHAR(30) NOT NULL DEFAULT '',
            source_type VARCHAR(30) NOT NULL,
            source_id INTEGER NOT NULL,
            sha256 VARCHAR(64) NOT NULL DEFAULT '',
            created_by INTEGER,
            created_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_doc_registry_code ON doc_registry(code)");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_doc_registry_src ON doc_registry(source_type, source_id)");
        // Odpowiednik papierowy: '' = nie rozstrzygnięto, none = brak papieru, label = etykieta (naklejka), manual = numer nanoszony ręcznie
        foreach (['paper_mode' => "VARCHAR(10) NOT NULL DEFAULT ''", 'paper_at' => 'VARCHAR(19)', 'paper_by' => 'INTEGER', 'paper_done_at' => 'VARCHAR(19)'] as $col => $def) {
            try { db()->exec("ALTER TABLE doc_registry ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) { /* istnieje */ }
        }
        db()->exec("CREATE TABLE IF NOT EXISTS doc_prints (
            id {$pk}, kind VARCHAR(40) NOT NULL, ref VARCHAR(120) NOT NULL DEFAULT '', title VARCHAR(255) NOT NULL DEFAULT '', open_path VARCHAR(255) NOT NULL DEFAULT '',
            created_by INTEGER, created_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_doc_prints_ref ON doc_prints(kind, ref)");
    }

    // ── Kod ───────────────────────────────────────────────────────────────────────

    private static function randomCode(string $prefix): string
    {
        $l = self::LETTERS;
        return $prefix . '/' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT) . $l[random_int(0, strlen($l) - 1)] . $l[random_int(0, strlen($l) - 1)];
    }

    /** Kolejny numer w roku w stylu KDOK/EODoK: PREFIKS/NNNN/ROK (np. HD/0012/2026). */
    private static function seqNumber(string $prefix): string
    {
        $year = date('Y');
        $max = 0;
        foreach (db_all("SELECT code FROM doc_registry WHERE code LIKE ?", [$prefix . '/%/' . $year]) as $r) {
            if (preg_match('~^' . preg_quote($prefix, '~') . '/(\d+)/' . $year . '$~', (string)$r['code'], $m)) $max = max($max, (int)$m[1]);
        }
        return sprintf('%s/%04d/%s', $prefix, $max + 1, $year);
    }

    /** Czy tekst ma format kodu rejestru (TYP/6 cyfr + 2 litery). */
    public static function isCode(string $c): bool
    {
        return (bool)preg_match('~^[A-Z]{2,5}/(?:\d{6}[A-Z]{2}|\d{4}/\d{4})$~', $c);
    }

    // ── Źródło (plik na dysku + dane) ────────────────────────────────────────────

    /** @return array{name:string,path:string,size:int,uploader:?int,at:string,exists:bool,superseded:bool}|null  null = brak rekordu źródłowego */
    private static function source(string $type, int $id): ?array
    {
        if ($type === 'print_doc') {
            $z = db_one("SELECT * FROM doc_prints WHERE id = ?", [$id]);
            if (!$z) return null;
            return ['name' => (string)$z['title'], 'path' => '', 'size' => 0, 'uploader' => $z['created_by'] !== null ? (int)$z['created_by'] : null, 'at' => (string)$z['created_at'], 'exists' => true, 'superseded' => false];
        }
        if (str_starts_with($type, 'contract_') || $type === 'gen_doc') {
            $isGen = $type === 'gen_doc';
            $table = self::SOURCES[$type][3] ?? '';
            if ($table === '' || !isset(self::SOURCES[$type])) return null;
            try { $z = db_one("SELECT * FROM {$table} WHERE id = ?", [$id]); } catch (\Throwable $e) { return null; }
            if (!$z) return null;
            $cancelled = !$isGen && in_array((string)($z['status'] ?? ''), ['anulowana', 'aneks'], true);
            return ['name' => (string)($isGen ? ($z['title'] ?? '') : ($z['numer_umowy'] ?? '')), 'path' => '', 'size' => 0, 'uploader' => null,
                    'at' => (string)($z['created_at'] ?? ''), 'exists' => true, 'superseded' => $cancelled,
                    'note' => $cancelled ? ((string)($z['status'] ?? '') === 'aneks' ? 'Umowa została zmieniona aneksem — obowiązuje wersja z aneksem.' : 'Umowa została anulowana.') : ''];
        }
        switch ($type) {
            case 'ezd_zalacznik':
                $z = db_one("SELECT * FROM ezd_zalaczniki WHERE id = ?", [$id]);
                if (!$z) return null;
                $p = defined('UPLOAD_DIR') ? UPLOAD_DIR . (defined('EZD_UPLOAD_SUBDIR') ? EZD_UPLOAD_SUBDIR : 'ezd/') . (int)$z['sprawa_id'] . '/' . $z['filename'] : '';   // stała z includes/ezd.php bywa niezaładowana (strony rejestru) — wtedy ta sama wartość wprost
                $sup = (bool)db_one("SELECT 1 AS x FROM ezd_zalaczniki WHERE prev_id = ? LIMIT 1", [$id]);
                return ['name' => (string)$z['original_name'], 'path' => $p, 'size' => (int)$z['file_size'], 'uploader' => $z['uploaded_by'] !== null ? (int)$z['uploaded_by'] : null,
                        'at' => (string)$z['uploaded_at'], 'exists' => $p !== '' && is_file($p), 'superseded' => $sup];
            case 'crm_case_file':
                $z = db_one("SELECT * FROM crm_case_files WHERE id = ?", [$id]);
                if (!$z) return null;
                $p = dirname(__DIR__, 3) . '/uploads/' . $z['stored_path'];
                return ['name' => (string)$z['original_name'], 'path' => $p, 'size' => (int)$z['file_size'], 'uploader' => $z['created_by'] !== null ? (int)$z['created_by'] : null,
                        'at' => (string)$z['created_at'], 'exists' => is_file($p), 'superseded' => false];
            case 'task_file':
                $z = db_one("SELECT * FROM task_files WHERE id = ?", [$id]);
                if (!$z) return null;
                $p = dirname(__DIR__, 3) . '/uploads/tasks/' . basename((string)$z['stored_name']);
                return ['name' => (string)$z['original_name'], 'path' => $p, 'size' => (int)$z['file_size'], 'uploader' => $z['uploaded_by'] !== null ? (int)$z['uploaded_by'] : null,
                        'at' => (string)$z['created_at'], 'exists' => is_file($p), 'superseded' => false];
            case 'ezd_rpw_scan':
                $z = db_one("SELECT * FROM ezd_rpw WHERE id = ?", [$id]);
                if (!$z) return null;
                if ((string)$z['scan_file'] === '') {   // brak skanu: pominięty przy nadawaniu; zarejestrowany wcześniej → przeniesiony do koszulki
                    if (!self::get('ezd_rpw_scan', $id)) return null;
                    return ['name' => (string)$z['scan_name'], 'path' => '', 'size' => (int)$z['scan_size'], 'uploader' => null, 'at' => (string)$z['created_at'], 'exists' => false,
                            'superseded' => true, 'note' => 'Skan został przeniesiony do koszulki — obowiązuje plik w koszulce (jego własny numer).'];
                }
                $p = defined('UPLOAD_DIR') ? UPLOAD_DIR . (defined('EZD_RPW_SUBDIR') ? EZD_RPW_SUBDIR : 'ezd/rpw/') . $id . '/' . $z['scan_file'] : '';
                return ['name' => (string)($z['scan_name'] ?: $z['scan_file']), 'path' => $p, 'size' => (int)$z['scan_size'], 'uploader' => $z['created_by'] !== null ? (int)$z['created_by'] : null,
                        'at' => (string)$z['created_at'], 'exists' => $p !== '' && is_file($p), 'superseded' => false];
            case 'helpdesk_attachment':
                $z = db_one("SELECT * FROM helpdesk_attachments WHERE id = ?", [$id]);
                if (!$z) return null;
                $p = (defined('UPLOAD_DIR') ? UPLOAD_DIR : dirname(__DIR__, 3) . '/uploads/') . $z['stored_path'];
                return ['name' => (string)$z['original_name'], 'path' => $p, 'size' => (int)$z['file_size'], 'uploader' => $z['uploaded_by'] !== null ? (int)$z['uploaded_by'] : null,
                        'at' => (string)$z['uploaded_at'], 'exists' => is_file($p), 'superseded' => false];
            case 'correspondence_file':
                $z = db_one("SELECT id, attachment, created_by, created_at FROM correspondence WHERE id = ?", [$id]);
                if (!$z || (string)$z['attachment'] === '') return null;
                $p = dirname(__DIR__, 3) . '/uploads/correspondence/' . basename((string)$z['attachment']);
                return ['name' => (string)$z['attachment'], 'path' => $p, 'size' => is_file($p) ? (int)filesize($p) : 0, 'uploader' => $z['created_by'] !== null ? (int)$z['created_by'] : null,
                        'at' => (string)$z['created_at'], 'exists' => is_file($p), 'superseded' => false];
            case 'rekr_file':
                $z = db_one("SELECT * FROM rekr_files WHERE id = ?", [$id]);
                if (!$z) return null;
                $p = (defined('UPLOAD_DIR') ? UPLOAD_DIR : dirname(__DIR__, 3) . '/uploads/') . $z['stored_path'];
                return ['name' => (string)$z['original_name'], 'path' => $p, 'size' => (int)$z['size'], 'uploader' => $z['uploaded_by'] !== null ? (int)$z['uploaded_by'] : null,
                        'at' => (string)$z['created_at'], 'exists' => is_file($p), 'superseded' => false];
        }
        return null;
    }

    // ── Nadawanie ─────────────────────────────────────────────────────────────────

    /** Wiersz rejestru źródła albo null (bez nadawania). */
    public static function get(string $type, int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM doc_registry WHERE source_type = ? AND source_id = ?", [$type, $id]);
    }

    /** Nadaje numer i kod plikowi (idempotentnie; ponowne wywołanie zwraca istniejący wpis). null — nieznany typ / brak rekordu źródłowego / błąd (upload NIGDY nie jest blokowany). */
    public static function assign(string $type, int $id): ?array
    {
        try {
            if (!isset(self::SOURCES[$type]) || $id <= 0) return null;
            if ($row = self::get($type, $id)) return $row;
            $src = self::source($type, $id);
            if (!$src) return null;
            $sha = ($src['exists'] && $src['path'] !== '' && $src['size'] <= 100 * 1024 * 1024) ? (string)@hash_file('sha256', $src['path']) : '';
            $now = date('Y-m-d H:i:s');
            for ($try = 0; $try < 8; $try++) {
                $seq = (self::SOURCES[$type][4] ?? 'rand') === 'seq';
                $code = $seq ? self::seqNumber(self::SOURCES[$type][0]) : self::randomCode(self::SOURCES[$type][0]);
                try {
                    $rid = db_insert('doc_registry', ['code' => $code, 'doc_number' => $seq ? $code : '', 'source_type' => $type, 'source_id' => $id, 'sha256' => $sha,
                        'created_by' => $src['uploader'], 'created_at' => $now]);
                    if (!$seq) db()->prepare("UPDATE doc_registry SET doc_number = ? WHERE id = ?")->execute([sprintf('DOK/%s/%06d', date('Y'), $rid), $rid]);
                    return self::get($type, $id);
                } catch (\PDOException $e) {
                    if ($r = self::get($type, $id)) return $r;   // wyścig: ktoś nadał w międzyczasie
                    // kolizja kodu → losuj ponownie
                }
            }
        } catch (\Throwable $e) {
            error_log('doc_registry assign ' . $type . '#' . $id . ': ' . $e->getMessage());
        }
        return null;
    }

    /** Wpisy dla listy id (dopisuje brakujące — pliki sprzed wprowadzenia rejestru). @return array<int,array> id źródła => wiersz */
    public static function forMany(string $type, array $ids): array
    {
        $out = [];
        try {
            self::migrate();
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (!$ids || !isset(self::SOURCES[$type])) return [];
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (db_all("SELECT * FROM doc_registry WHERE source_type = ? AND source_id IN ($in)", array_merge([$type], $ids)) as $r) $out[(int)$r['source_id']] = $r;
            foreach ($ids as $id) if (!isset($out[$id]) && ($r = self::assign($type, $id))) $out[$id] = $r;
        } catch (\Throwable $e) { error_log('doc_registry forMany: ' . $e->getMessage()); }
        return $out;
    }

    /** Dopisuje wszystkie pliki bez kodu (CLI / zadanie CRON). @return array<string,int> typ => liczba nowych */
    public static function backfill(int $limit = 5000): array
    {
        self::migrate();
        $res = [];
        foreach (self::SOURCES as $type => $def) {
            $res[$type] = 0;
            try {
                $rows = db_all("SELECT t.id FROM {$def[3]} t WHERE NOT EXISTS (SELECT 1 FROM doc_registry r WHERE r.source_type = ? AND r.source_id = t.id) ORDER BY t.id LIMIT " . (int)$limit, [$type]);
            } catch (\Throwable $e) { continue; }   // brak tabeli źródłowej (moduł niewdrożony)
            foreach ($rows as $r) if (self::assign($type, (int)$r['id'])) $res[$type]++;
        }
        return $res;
    }

    // ── Lista i wyszukiwanie (strona Rejestr dokumentów) ─────────────────────────

    /** Czy rodzaj ma numerację kolejną (HD/0001/2026) — bez losowego kodu i etykiety. */
    public static function isSeq(string $type): bool
    {
        return (self::SOURCES[$type][4] ?? 'rand') === 'seq';
    }

    /**
     * Wyszukiwanie w rejestrze. $f: q (numer/kod, fragment), type (klucz źródła), from/to (Y-m-d, data nadania), state (valid|void).
     * Stan liczony na żywo ze źródła; filtr stanu wymaga przeliczenia wszystkich pasujących (limit 5000), bez niego — tylko bieżącej strony.
     * @return array{rows:array,total:int,pages:int,page:int,per:int,counts:array<string,int>}
     */
    public static function search(array $f, int $page = 1, int $per = 50): array
    {
        self::migrate();
        $w = ['1=1']; $p = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') { $w[] = '(UPPER(code) LIKE ? OR UPPER(doc_number) LIKE ?)'; $like = '%' . strtoupper(str_replace(['%', '_'], ['', ''], $q)) . '%'; $p[] = $like; $p[] = $like; }
        $type = (string)($f['type'] ?? '');
        if ($type !== '' && isset(self::SOURCES[$type])) { $w[] = 'source_type = ?'; $p[] = $type; }
        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($f['from'] ?? ''))) { $w[] = 'created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($f['to'] ?? '')))   { $w[] = 'created_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
        $paper = (string)($f['paper'] ?? '');
        if ($paper === 'pending') { $w[] = "paper_mode = '' AND source_type NOT LIKE 'contract\\_%' ESCAPE '\\' AND source_type NOT IN ('print_doc','gen_doc','helpdesk_attachment','correspondence_file','rekr_file')"; }
        elseif (in_array($paper, ['none', 'label', 'manual'], true)) { $w[] = 'paper_mode = ?'; $p[] = $paper; }
        elseif ($paper === 'todo') { $w[] = "paper_mode IN ('label','manual') AND paper_done_at IS NULL"; }
        $where = implode(' AND ', $w);
        $state = in_array($f['state'] ?? '', ['valid', 'void'], true) ? (string)$f['state'] : '';
        $page = max(1, $page);

        $counts = [];
        foreach (db_all("SELECT source_type, COUNT(*) AS n FROM doc_registry GROUP BY source_type") as $r) $counts[(string)$r['source_type']] = (int)$r['n'];

        $decorate = function (array $r): array {
            $st = self::state($r);
            $r['state'] = $st['state']; $r['note'] = $st['note']; $r['file_name'] = $st['src']['name'] ?? '';
            $r['seq'] = self::isSeq((string)$r['source_type']);
            $r['label'] = self::SOURCES[$r['source_type']][1] ?? $r['source_type'];
            return $r;
        };

        if ($state === '') {
            $total = (int)(db_one("SELECT COUNT(*) AS n FROM doc_registry WHERE $where", $p)['n'] ?? 0);
            $rows = array_map($decorate, db_all("SELECT * FROM doc_registry WHERE $where ORDER BY id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per), $p));
        } else {
            $all = array_values(array_filter(array_map($decorate, db_all("SELECT * FROM doc_registry WHERE $where ORDER BY id DESC LIMIT 5000", $p)), fn($r) => $r['state'] === $state));
            $total = count($all);
            $rows = array_slice($all, ($page - 1) * $per, $per);
        }
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int)ceil($total / $per)), 'page' => $page, 'per' => $per, 'counts' => $counts];
    }

    // ── Adresy i QR ───────────────────────────────────────────────────────────────

    /** Adres z QR: publiczna weryfikacja kodu (z przyciskiem „Otwórz dokument online”). */
    public static function url(string $code): string
    {
        return rtrim(APP_URL, '/') . '/espis/?c=' . rawurlencode($code);
    }

    /** Adres otwarcia dokumentu online (wymaga logowania; uprawnienia sprawdza system źródłowy). */
    public static function openUrl(string $type, int $id): string
    {
        if ($type === 'print_doc') {
            $z = db_one("SELECT open_path FROM doc_prints WHERE id = ?", [$id]);
            $path = (string)($z['open_path'] ?? '');
            return rtrim(APP_URL, '/') . ($path !== '' && $path[0] === '/' ? $path : '/');
        }
        return rtrim(APP_URL, '/') . str_replace('{id}', (string)$id, self::SOURCES[$type][2] ?? '/');
    }

    /** Adres „przez kod” — przekierowanie po zalogowaniu do właściwego systemu: /docreg/?o=KOD. */
    public static function openByCodeUrl(string $code): string
    {
        return rtrim(APP_URL, '/') . '/docreg/?o=' . rawurlencode($code);
    }

    public static function find(string $code): ?array
    {
        $c = strtoupper(trim($code));
        if (!self::isCode($c)) return null;
        self::migrate();
        return db_one("SELECT * FROM doc_registry WHERE code = ?", [$c]);
    }

    /** Stan na żywo: valid | void; powód w 'note'. */
    public static function state(array $row): array
    {
        $src = self::source((string)$row['source_type'], (int)$row['source_id']);
        if (!$src) return ['state' => 'void', 'note' => 'Plik został usunięty z systemu.', 'src' => null];
        if ($src['superseded']) return ['state' => 'void', 'note' => $src['note'] ?? 'Plik został zastąpiony nowszą wersją.', 'src' => $src];
        if (!$src['exists']) return ['state' => 'void', 'note' => 'Plik nie jest już dostępny na serwerze.', 'src' => $src];
        return ['state' => 'valid', 'note' => '', 'src' => $src];
    }

    /** Dla publicznej weryfikacji (Espis::verify) — BEZ nazwy pliku i danych osobowych. */
    public static function verify(string $code): ?array
    {
        $row = self::find($code);
        if (!$row) return null;
        $st = self::state($row);
        $def = self::SOURCES[$row['source_type']] ?? ['', 'Plik w systemie', '', ''];
        return ['label' => $def[1], 'number' => (string)$row['doc_number'], 'state' => $st['state'], 'date' => substr((string)$row['created_at'], 0, 10),
                'note' => trim($st['note'] . ($row['sha256'] !== '' ? ' Skrót SHA-256 pliku z chwili wgrania: ' . substr((string)$row['sha256'], 0, 16) . '…' : '')),
                'open_url' => self::openByCodeUrl((string)$row['code'])];
    }

    /** PNG kodu QR do wiersza rejestru (lokalnie, bez serwisów zewnętrznych). null gdy brak biblioteki. */
    public static function qrPng(string $code): ?string
    {
        require_once dirname(__DIR__, 3) . '/includes/qr.php';
        $uri = qr_data_uri(self::url($code), 300);
        return $uri && str_contains($uri, ',') ? (string)base64_decode(substr($uri, strpos($uri, ',') + 1), true) : null;
    }

    // ── Kody w stopkach dokumentów (umowy, dokumenty z generatora) ───────────────────

    /** Kod Code128 jako PNG (bajty) — lokalnie, picqer/php-barcode-generator. null gdy biblioteka niedostępna. */
    public static function barcodePng(string $code, int $widthFactor = 2, int $height = 40): ?string
    {
        $er = error_reporting();
        error_reporting($er & ~E_DEPRECATED & ~E_USER_DEPRECATED);   // biblioteka emituje deprecacje pod PHP 8.5 — nie mogą uszkodzić pobieranego pliku
        ob_start();
        try {
            if (!class_exists(\Picqer\Barcode\BarcodeGeneratorPNG::class)) return null;
            $g = new \Picqer\Barcode\BarcodeGeneratorPNG();
            return $g->getBarcode($code, $g::TYPE_CODE_128, $widthFactor, $height);
        } catch (\Throwable $e) { return null; }
        finally { ob_end_clean(); error_reporting($er); }
    }

    /**
     * Dane do stopki dokumentu: nadaje numer i kod (idempotentnie) i zwraca ['code','doc_number','url','qr'=>PNG,'bar'=>PNG] albo null
     * (nieznany typ / brak rekordu / błąd — generowanie dokumentu NIGDY nie jest przez to blokowane).
     */
    public static function footerCodes(string $type, int $id): ?array
    {
        try {
            $row = self::assign($type, $id);
            if (!$row) return null;
            $code = (string)$row['code'];
            return ['code' => $code, 'doc_number' => (string)$row['doc_number'], 'url' => self::url($code),
                    'qr' => self::qrPng($code), 'bar' => self::barcodePng($code)];
        } catch (\Throwable $e) { error_log('doc_registry footerCodes ' . $type . '#' . $id . ': ' . $e->getMessage()); return null; }
    }

    /**
     * Kody dla WYDRUKU, który nie jest umową ani wgranym plikiem (pismo, oświadczenie, zaświadczenie…): jeden stały kod na parę
     * ($kind, $ref) — ponowny wydruk tego samego dokumentu ma ten sam kod. QR prowadzi do weryfikacji, „Otwórz” do $openPath.
     * @return array|null jak footerCodes()
     */
    public static function printCodes(string $kind, string $ref, string $title, string $openPath = ''): ?array
    {
        try {
            self::migrate();
            $kind = mb_substr($kind, 0, 40); $ref = mb_substr($ref, 0, 120);
            $row = db_one("SELECT id FROM doc_prints WHERE kind = ? AND ref = ?", [$kind, $ref]);
            $uid = function_exists('current_user') ? ((current_user()['id'] ?? null) ?: null) : null;
            if (!$row) {
                try { $id = db_insert('doc_prints', ['kind' => $kind, 'ref' => $ref, 'title' => mb_substr($title, 0, 255), 'open_path' => mb_substr($openPath, 0, 255), 'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s')]); }
                catch (\Throwable $e) { $row = db_one("SELECT id FROM doc_prints WHERE kind = ? AND ref = ?", [$kind, $ref]); $id = $row ? (int)$row['id'] : 0; }
            } else $id = (int)$row['id'];
            return $id > 0 ? self::footerCodes('print_doc', (int)$id) : null;
        } catch (\Throwable $e) { error_log('doc_registry printCodes: ' . $e->getMessage()); return null; }
    }

    /** Stopka HTML z kodami dla wydruku (patrz printCodes). '' gdy brak kodów. */
    public static function printFooterHtml(string $kind, string $ref, string $title, string $openPath = ''): string
    {
        $f = self::printCodes($kind, $ref, $title, $openPath);
        return $f ? self::renderFooterHtml($f) : '';
    }

    // ── Odpowiednik papierowy ─────────────────────────────────────────────────────

    public const PAPER = ['' => 'Do rozstrzygnięcia', 'none' => 'Brak odpowiednika papierowego', 'label' => 'Papier — etykieta (naklejka)', 'manual' => 'Papier — numer nanoszony ręcznie'];

    /** Zapisuje decyzję o papierze. Zwraca '' albo komunikat błędu. */
    public static function setPaper(string $code, string $mode, int $uid): string
    {
        if (!array_key_exists($mode, self::PAPER) || $mode === '') return 'Wybierz jedną z opcji.';
        $row = self::find($code);
        if (!$row) return 'Nie znaleziono kodu.';
        db()->prepare("UPDATE doc_registry SET paper_mode = ?, paper_at = ?, paper_by = ?, paper_done_at = NULL WHERE id = ?")->execute([$mode, date('Y-m-d H:i:s'), $uid ?: null, (int)$row['id']]);
        return '';
    }

    /** Oznacza, że etykietę wydrukowano / numer naniesiono ręcznie. */
    public static function paperDone(string $code): void
    {
        $row = self::find($code);
        if ($row) db()->prepare("UPDATE doc_registry SET paper_done_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
    }

    /** Ile pozycji czeka na decyzję o papierze (bez numerów kolejnych HD/KOR/REK — one nie mają etykiet). */
    public static function paperPending(): int
    {
        self::migrate();
        return (int)(db_one("SELECT COUNT(*) c FROM doc_registry WHERE paper_mode = '' AND source_type NOT LIKE 'contract\_%' ESCAPE '\\' AND source_type NOT IN ('print_doc','gen_doc','helpdesk_attachment','correspondence_file','rekr_file')")['c'] ?? 0);
    }

    /** Stopka HTML z kodami (obrazy inline data:) — do stron wydruku. '' gdy brak kodów. */
    public static function footerHtml(string $type, int $id): string
    {
        $f = self::footerCodes($type, $id);
        return $f ? self::renderFooterHtml($f) : '';
    }

    private static function renderFooterHtml(array $f): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $img = fn(?string $png, string $alt, string $style) => $png ? '<img alt="' . $e($alt) . '" style="' . $style . '" src="data:image/png;base64,' . base64_encode($png) . '">' : '';
        return '<div class="doc-codes" style="display:flex;align-items:center;gap:8pt;font-size:6.5pt;color:#555">'
             . $img($f['qr'], 'Kod QR — weryfikacja dokumentu online', 'height:26pt;width:26pt')
             . '<div>' . $img($f['bar'], 'Kod kreskowy ' . $f['code'], 'height:16pt;max-width:130pt;display:block')
             . '<span style="font-family:monospace">' . $e($f['code']) . '</span> · ' . $e($f['doc_number']) . '</div></div>';
    }

    // ── Etykiety do druku (70×37 mm) ───────────────────────────────────────────────

    /** Treść jednej etykiety (HTML dla mPDF): organizacja i data, rodzaj, Code128 na całą szerokość, kod i numer drukiem, QR. */
    public static function labelHtml(array $row): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $code = (string)$row['code'];
        $kind = mb_strtoupper(mb_strimwidth((string)(self::SOURCES[$row['source_type']][1] ?? 'Dokument'), 0, 34, '…'));
        $date = !empty($row['created_at']) ? date('d.m.Y', strtotime((string)$row['created_at'])) : '';
        return '<table style="width:100%;border-collapse:collapse;font-family:dejavusans">'
            . '<tr><td style="font-size:5.5pt;color:#444">' . $e(defined('ORG_NAME') ? ORG_NAME : '') . '</td><td style="font-size:5.5pt;color:#444;text-align:right">' . $e($date) . '</td></tr>'
            . '<tr><td colspan="2" style="font-size:6.5pt;font-weight:bold;padding-bottom:.6mm">' . $e($kind) . '</td></tr>'
            . '<tr><td style="vertical-align:top;width:66%">'
            . '<barcode code="' . $e($code) . '" type="C128B" size="0.60" height="1.05" />'
            . '<div style="font-size:9.5pt;font-family:dejavusansmono;font-weight:bold;padding-top:.8mm">' . $e($code) . '</div>'
            . '<div style="font-size:8pt;font-weight:bold">' . $e($row['doc_number']) . '</div></td>'
            . '<td style="vertical-align:top;text-align:center"><barcode code="' . $e(self::url($code)) . '" type="QR" class="barcode" size="0.54" error="M" disableborder="1" />'
            . '</td></tr></table>';
    }

    /**
     * PDF z etykietami. Jedna etykieta → strona 70×37 mm; kilka (lub $sheet) → arkusz A4 3×8 (70×37 mm, bez marginesów),
     * $start = numer pierwszego wolnego pola (1–24), żeby dokończyć częściowo zużyty arkusz. Zwraca bajty PDF albo null.
     */
    public static function labelsPdf(array $rows, bool $sheet = false, int $start = 1): ?string
    {
        if (!class_exists(\Mpdf\Mpdf::class)) { $a = dirname(__DIR__, 3) . '/vendor/autoload.php'; if (is_file($a)) require_once $a; }
        if (!class_exists(\Mpdf\Mpdf::class) || !$rows) return null;
        $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $er = error_reporting();
        error_reporting($er & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            if (!$sheet && count($rows) === 1) {
                $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => [70, 37], 'margin_left' => 2.5, 'margin_right' => 2.5, 'margin_top' => 2, 'margin_bottom' => 1.5, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
                $pdf->SetTitle('Etykieta ' . $rows[0]['code']);
                $pdf->WriteHTML(self::labelHtml($rows[0]));
            } else {
                $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
                $pdf->SetTitle('Arkusz etykiet');
                $cells = array_fill(0, max(0, min(23, $start - 1)), '');
                foreach ($rows as $r) $cells[] = self::labelHtml($r);
                $html = '';
                foreach (array_chunk($cells, 24) as $pi => $page) {
                    if ($pi > 0) $html .= '<pagebreak />';
                    // pozycjonowanie bezwzględne: komórka (kolumna, wiersz) → dokładnie 70×37 mm od lewego górnego rogu arkusza
                    foreach ($page as $n => $cell) {
                        if ($cell === '') continue;
                        $html .= '<div style="position:absolute;left:' . (($n % 3) * 70) . 'mm;top:' . (intdiv($n, 3) * 37) . 'mm;width:62mm;height:32mm;padding:2.5mm 4mm 0 4mm;overflow:hidden">' . $cell . '</div>';
                    }
                }
                $pdf->WriteHTML($html);
            }
            return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        } finally { error_reporting($er); }
    }

    // ── Interfejs ─────────────────────────────────────────────────────────────────

    /**
     * Znacznik w listach plików: numer + kod (klikalny → okno z QR i etykietą do druku). Pusty, gdy brak wpisu.
     * Okno obsługuje assets/js/docRegistry.js (jedno na stronę) — wywołaj docRegistryAssets() raz na dole listy.
     */
    public static function badge(?array $row): string
    {
        if (!$row) return '';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $base = rtrim(APP_URL, '/') . '/docreg/';
        $seq = (self::SOURCES[$row['source_type']][4] ?? 'rand') === 'seq';
        return '<a href="' . $e($base . '?view=' . rawurlencode((string)$row['code'])) . '" target="_blank" rel="noopener" class="docreg-badge" data-docreg-code="' . $e($row['code']) . '" data-docreg-number="' . $e($row['doc_number']) . '"'
            . ' data-docreg-qr="' . $e($base . '?qr=' . rawurlencode((string)$row['code'])) . '" data-docreg-label="' . ($seq ? '' : $e($base . '?label=' . rawurlencode((string)$row['code']))) . '"'
            . ' data-docreg-url="' . $e(self::url((string)$row['code'])) . '" data-docreg-paper="' . $e($row['paper_mode'] ?? '') . '" data-docreg-csrf="' . $e(function_exists('csrf_token') ? csrf_token() : '') . '" data-docreg-ask="' . ((in_array($row['source_type'], ['helpdesk_attachment', 'correspondence_file', 'rekr_file', 'print_doc', 'gen_doc'], true) || str_starts_with((string)$row['source_type'], 'contract_')) ? '0' : '1') . '" title="Numer i kod QR dokumentu (otwiera się w nowej karcie)">'
            . '<span aria-hidden="true">▦</span> ' . $e($seq ? $row['doc_number'] : $row['code']) . '<span class="visually-hidden"> — numer ' . $e($row['doc_number']) . ', pokaż kod QR</span></a>';
    }

    /** Okno z QR + style + skrypt (raz na stronę, POZA zakładkami — modal Bootstrap). */
    public static function assets(bool $withModal = true): string
    {
        static $done = false;
        if ($done) return '';
        $done = true;
        $style = <<<'HTML'
<style>
.docreg-badge{display:inline-block;text-decoration:none;font:600 .68rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;background:#eef4ff;color:#0b3d91;border:1px solid #9db7e8;border-radius:3px;padding:.1rem .4rem;cursor:pointer}
.docreg-badge:hover{background:#dce8ff}.docreg-badge:focus-visible{outline:3px solid #ff9800;outline-offset:2px}
</style>
HTML;
        if (!$withModal) return $style;   // fragmenty ładowane AJAX: sam styl, znacznik działa jako zwykły link
        return $style . <<<'HTML'
<div class="modal fade" id="docregModal" tabindex="-1" aria-labelledby="docregTitle" aria-hidden="true"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title fs-6" id="docregTitle">Identyfikator dokumentu</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
  <div class="modal-body text-center">
    <img id="docregQr" alt="Kod QR do weryfikacji i otwarcia dokumentu online" width="200" height="200" class="mb-2">
    <div class="small text-muted">Numer</div><div id="docregNumber" class="fw-semibold mb-1"></div>
    <div id="docregCodeWrap"><div class="small text-muted">Kod (Code128)</div><code id="docregCode" class="fs-6 d-block mb-2"></code></div>
    <p class="small text-muted mb-0">Kod QR otwiera stronę weryfikacji; po zalogowaniu — dokument online.</p>
    <div id="docregPaper" class="text-start border-top mt-3 pt-2" hidden>
      <div class="small fw-semibold mb-1" id="docregPaperQ">Czy dokument ma odpowiednik papierowy?</div>
      <div class="d-grid gap-1" role="group" aria-labelledby="docregPaperQ">
        <button type="button" class="btn btn-sm btn-outline-secondary text-start" data-paper="none">Nie — tylko elektroniczny</button>
        <button type="button" class="btn btn-sm btn-outline-secondary text-start" data-paper="label">Tak — drukuję etykietę</button>
        <button type="button" class="btn btn-sm btn-outline-secondary text-start" data-paper="manual">Tak — numer nanoszę ręcznie</button>
      </div>
      <div class="small mt-1" id="docregPaperMsg" role="status"></div>
    </div>
  </div>
  <div class="modal-footer justify-content-between"><a id="docregLabel" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Etykieta do druku (PDF)</a><a id="docregOpen" class="btn btn-sm btn-primary" target="_blank" rel="noopener">Strona weryfikacji</a></div>
</div></div></div>
<script>
document.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('.docreg-badge');if(!b)return;
  var m=document.getElementById('docregModal');if(!m||!window.bootstrap)return;ev.preventDefault();ev.stopPropagation();
  document.getElementById('docregQr').src=b.dataset.docregQr;document.getElementById('docregNumber').textContent=b.dataset.docregNumber;
  document.getElementById('docregCode').textContent=b.dataset.docregCode;var lb=document.getElementById('docregLabel');lb.hidden=!b.dataset.docregLabel;lb.href=b.dataset.docregLabel||'#';
  document.getElementById('docregCodeWrap').hidden=b.dataset.docregCode===b.dataset.docregNumber;document.getElementById('docregOpen').href=b.dataset.docregUrl;
  var pp=document.getElementById('docregPaper');pp.hidden=b.dataset.docregAsk!=='1';window._docregBtn=b;paperMark(b.dataset.docregPaper||'');
  window.bootstrap.Modal.getOrCreateInstance(m).show();},true);
function paperMark(mode){document.querySelectorAll('#docregPaper [data-paper]').forEach(function(x){var on=x.dataset.paper===mode;x.classList.toggle('btn-primary',on);x.classList.toggle('btn-outline-secondary',!on);});
  var msg=document.getElementById('docregPaperMsg'),b=window._docregBtn;if(!msg||!b)return;
  msg.textContent=mode===''?'Nie rozstrzygnięto — wybierz jedną z opcji.':mode==='manual'?'Nanieś ręcznie numer: '+b.dataset.docregNumber+(b.dataset.docregCode!==b.dataset.docregNumber?' / '+b.dataset.docregCode:''):mode==='label'?'Wydrukuj etykietę (przycisk poniżej).':'Zapisano: brak odpowiednika papierowego.';}
document.addEventListener('click',function(ev){var x=ev.target.closest&&ev.target.closest('#docregPaper [data-paper]');if(!x||!window._docregBtn)return;var b=window._docregBtn;
  var fd=new FormData();fd.append('mode',x.dataset.paper);fd.append('_csrf',b.dataset.docregCsrf||'');
  fetch(b.dataset.docregQr.replace(/\?qr=.*/,'?paper='+encodeURIComponent(b.dataset.docregCode)),{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
   .then(function(r){return r.json();}).then(function(j){if(j.ok){b.dataset.docregPaper=j.mode;paperMark(j.mode);if(j.mode==='label'&&b.dataset.docregLabel)window.open(b.dataset.docregLabel,'_blank');}else{document.getElementById('docregPaperMsg').textContent=j.error||'Nie udało się zapisać.';}})
   .catch(function(){document.getElementById('docregPaperMsg').textContent='Nie udało się zapisać.';});});
</script>
HTML;
    }
}
