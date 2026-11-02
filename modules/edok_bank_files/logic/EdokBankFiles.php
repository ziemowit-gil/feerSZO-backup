<?php
/**
 * modules/edok_bank_files/logic/EdokBankFiles.php — PDF-y z banku (wyciągi, potwierdzenia przelewów) dołączane do transakcji EODoK (edok_bank_tx).
 *
 * Wgrany PDF jest zapisywany w oryginale (UPLOAD_DIR/edok_bank/<sha256>.pdf, duplikat rozpoznajemy po sumie kontrolnej), parsowany
 * (BankPdfParser) i od razu dopasowywany:
 *   • wyciąg → każda operacja z listy trafia do swojej transakcji: po identyfikatorze operacji (= numer operacji z MT940), a gdy go brak — po
 *     rachunku, kwocie, kierunku i dacie (±3 dni, tylko gdy trafia dokładnie jedna transakcja); jeden wyciąg może dołączyć do wielu transakcji;
 *   • potwierdzenie przelewu → jedna transakcja (wypływ): kwota + rachunek kontrahenta + data + tytuł; przy remisie lub słabym dopasowaniu PDF
 *     zostaje „niedopasowany” i użytkownik wskazuje transakcję ręcznie (candidates()).
 * Transakcje z MT940 mogą być wgrane PO PDF-ach — rematchPending() (wołane na końcu edok_bank_import) dopasowuje wtedy zaległe pliki.
 */
require_once dirname(__DIR__) . '/lib/BankPdfParser.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class EdokBankFiles
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const KINDS = ['wyciag' => 'wyciąg', 'potwierdzenie' => 'potwierdzenie przelewu', 'inny' => 'inny dokument'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS edok_bank_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sha256 TEXT NOT NULL UNIQUE,
            orig_name TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL DEFAULT 'inny',
            meta TEXT,
            error TEXT,
            uploaded_by INTEGER,
            uploaded_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS edok_bank_tx_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tx_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            matched_how TEXT NOT NULL DEFAULT '',
            matched_by INTEGER,
            created_at TEXT NOT NULL,
            UNIQUE(tx_id, file_id)
        )");
        // ręcznie odpięte pary — automat ich nie przywraca (inaczej odpięcie znikałoby przy kolejnym imporcie)
        $pdo->exec("CREATE TABLE IF NOT EXISTS edok_bank_file_rejects (tx_id INTEGER NOT NULL, file_id INTEGER NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY (tx_id, file_id))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_edok_bank_tx_files_tx ON edok_bank_tx_files(tx_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_edok_bank_tx_files_file ON edok_bank_tx_files(file_id)");
    }

    public static function dir(): string
    {
        $d = rtrim(UPLOAD_DIR, '/') . '/edok_bank/';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public static function path(array $file): string { return self::dir() . $file['sha256'] . '.pdf'; }

    public static function meta(array $file): array
    {
        $m = json_decode((string)($file['meta'] ?? ''), true);
        return is_array($m) ? $m : [];
    }

    // ── wgrywanie ───────────────────────────────────────────────────────────────────────────────────────────────────
    /**
     * Zapisuje PDF i dopasowuje go do transakcji.
     * @return array{id:int,status:string,kind:string,links:int,note:string} status: 'new' | 'duplicate'
     * @throws \InvalidArgumentException plik nie jest PDF-em / jest za duży
     */
    public static function store(string $tmpPath, string $origName, int $userId): array
    {
        self::migrate();
        $bytes = (string)@file_get_contents($tmpPath);
        if ($bytes === '' || !str_starts_with(ltrim($bytes), '%PDF')) throw new \InvalidArgumentException('„' . $origName . '” nie jest plikiem PDF.');
        if (strlen($bytes) > self::MAX_BYTES) throw new \InvalidArgumentException('„' . $origName . '” jest za duży (max ' . (self::MAX_BYTES >> 20) . ' MB).');
        $sha = hash('sha256', $bytes);
        if ($ex = db_one("SELECT id, kind FROM edok_bank_files WHERE sha256=?", [$sha])) {
            return ['id' => (int)$ex['id'], 'status' => 'duplicate', 'kind' => (string)$ex['kind'], 'links' => 0, 'note' => 'Ten plik był już wgrany.'];
        }
        $meta = ['kind' => 'inny']; $err = null;
        try {
            $meta = BankPdfParser::parse(BankPdfParser::text($bytes));
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        if (($meta['kind'] ?? 'inny') === 'inny' && $err === null) $err = 'Nie rozpoznano układu dokumentu (brak warstwy tekstowej albo inny typ) — dopasuj ręcznie.';
        if (@file_put_contents(self::dir() . $sha . '.pdf', $bytes) === false) throw new \RuntimeException('Nie udało się zapisać pliku na serwerze.');
        $id = db_insert('edok_bank_files', [
            'sha256' => $sha, 'orig_name' => mb_substr(preg_replace('/[^\p{L}\p{N}._ \-()]/u', '_', basename($origName)), 0, 150), 'size' => strlen($bytes),
            'kind' => (string)($meta['kind'] ?? 'inny'), 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error' => $err, 'uploaded_by' => $userId ?: null, 'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
        $links = self::autoMatch($id, $userId);
        audit_log('edok.bank_pdf', ['file' => $id, 'kind' => $meta['kind'] ?? 'inny', 'links' => $links], $userId ?: (PHP_SAPI === 'cli' ? 0 : null));
        return ['id' => $id, 'status' => 'new', 'kind' => (string)($meta['kind'] ?? 'inny'), 'links' => $links, 'note' => $err ?? ''];
    }

    // ── dopasowanie ─────────────────────────────────────────────────────────────────────────────────────────────────
    private static function accSql(string $col = 'account_nrb'): string
    {
        return "substr(replace(replace(upper({$col}),'PL',''),' ',''), -26)";
    }

    /** Liczba nowych powiązań dodanych dla pliku. */
    public static function autoMatch(int $fileId, ?int $userId = null): int
    {
        self::migrate();
        $f = db_one("SELECT * FROM edok_bank_files WHERE id=?", [$fileId]);
        if (!$f) return 0;
        $meta = self::meta($f);
        return match ($f['kind']) {
            'wyciag' => self::matchStatement($f, $meta, $userId),
            'potwierdzenie' => self::matchConfirmation($f, $meta, $userId),
            default => 0,
        };
    }

    private static function matchStatement(array $f, array $meta, ?int $userId): int
    {
        $acc = preg_replace('/\D/', '', (string)($meta['account'] ?? ''));   // tylko cyfry — trafia do SQL
        $ops = (array)($meta['ops'] ?? []);
        $added = 0; $matched = 0;
        foreach ($ops as $o) {
            $abs = abs((float)$o['kwota']); $znak = (float)$o['kwota'] < 0 ? 'D' : 'C';
            $accSql = $acc !== '' ? " AND (account_nrb='' OR " . self::accSql() . " = '{$acc}')" : '';
            $tx = null;
            $id = (string)$o['id'];
            // 1) identyfikator operacji (MT940 przechowuje końcówkę 16 znaków)
            $rows = db_all("SELECT id FROM edok_bank_tx WHERE numer_operacji<>'' AND length(numer_operacji)>=12 AND znak=? AND ABS(kwota-?)<0.005{$accSql}
                              AND (numer_operacji=? OR ? LIKE '%'||numer_operacji OR numer_operacji LIKE '%'||?)", [$znak, $abs, $id, $id, $id]);
            if (count($rows) === 1) { $tx = (int)$rows[0]['id']; $how = 'op_id'; }
            // 2) rachunek + kwota + kierunek + data (±3 dni) — tylko gdy jednoznacznie
            if ($tx === null && !empty($o['date'])) {
                $rows = db_all("SELECT id FROM edok_bank_tx WHERE znak=? AND ABS(kwota-?)<0.005{$accSql}
                                  AND data_waluty BETWEEN date(?, '-3 days') AND date(?, '+3 days')", [$znak, $abs, $o['date'], $o['date']]);
                if (count($rows) === 1) { $tx = (int)$rows[0]['id']; $how = 'kwota_data'; }
            }
            if ($tx === null) continue;
            $matched++;
            $added += self::link($tx, (int)$f['id'], $how, $userId) ? 1 : 0;
        }
        $meta['ops_total'] = count($ops); $meta['ops_matched'] = $matched;
        db()->prepare("UPDATE edok_bank_files SET meta=? WHERE id=?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$f['id']]);
        return $added;
    }

    private static function matchConfirmation(array $f, array $meta, ?int $userId): int
    {
        if (db_one("SELECT 1 FROM edok_bank_tx_files WHERE file_id=?", [(int)$f['id']])) return 0;   // już dopasowane (także ręcznie)
        $c = self::candidates($f, 5);
        if (!$c) return 0;
        $top = $c[0];
        $second = $c[1]['score'] ?? -99;
        $ok = ($top['score'] >= 7 && $top['score'] - $second >= 2) || ($top['score'] >= 5 && count($c) === 1);
        if (!$ok) return 0;
        return self::link((int)$top['tx']['id'], (int)$f['id'], 'potwierdzenie', $userId) ? 1 : 0;
    }

    /**
     * Kandydaci dla potwierdzenia przelewu: transakcje-wypływy z tą samą kwotą (i datą ±5 dni), punktowane.
     * @return list<array{tx:array,score:int,reasons:list<string>}> malejąco wg punktów
     */
    public static function candidates(array $file, int $limit = 3): array
    {
        $m = self::meta($file);
        if (($file['kind'] ?? '') !== 'potwierdzenie' || !isset($m['kwota'])) return [];
        $sql = "SELECT * FROM edok_bank_tx WHERE znak='D' AND ABS(kwota-?)<0.005";
        $p = [(float)$m['kwota']];
        if (!empty($m['data'])) { $sql .= " AND data_waluty BETWEEN date(?, '-5 days') AND date(?, '+5 days')"; array_push($p, $m['data'], $m['data']); }
        if (!empty($m['account_from'])) { $sql .= " AND (account_nrb='' OR " . self::accSql() . " = ?)"; $p[] = $m['account_from']; }
        $out = [];
        $conf = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string)($m['tytul'] ?? '') . ' ' . ($m['nazwa'] ?? '')));
        foreach (db_all($sql, $p) as $tx) {
            if (db_one("SELECT 1 FROM edok_bank_file_rejects WHERE tx_id=? AND file_id=?", [(int)$tx['id'], (int)($file['id'] ?? 0)])) continue;
            $score = 3; $why = ['kwota'];
            if (!empty($m['account_to']) && BankPdfParser::nrb((string)$tx['kontrahent_konto']) === $m['account_to']) { $score += 4; $why[] = 'rachunek kontrahenta'; }
            if (!empty($m['data']) && $tx['data_waluty'] === $m['data']) { $score += 1; $why[] = 'data'; }
            $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper((string)$tx['tytul'])), fn($w) => mb_strlen($w) >= 4);
            if ($words) {
                $hit = 0; foreach ($words as $w) if (str_contains($conf, preg_replace('/[^A-Z0-9]/', '', $w))) $hit++;
                if ($hit / count($words) >= 0.6) { $score += 2; $why[] = 'tytuł'; }
            }
            $idt = (string)($m['id_transakcji'] ?? '');
            if ($idt !== '' && (str_contains((string)$tx['referencja'], ltrim($idt, '0')) || str_contains((string)$tx['numer_operacji'], ltrim($idt, '0')) || str_contains((string)$tx['tytul'], ltrim($idt, '0')))) { $score += 2; $why[] = 'identyfikator'; }
            $out[] = ['tx' => $tx, 'score' => $score, 'reasons' => $why];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /** Dopasowuje zaległe pliki (po wgraniu nowych transakcji). @return array{files:int,links:int} */
    public static function rematchPending(): array
    {
        self::migrate();
        $files = 0; $links = 0;
        foreach (db_all("SELECT * FROM edok_bank_files WHERE kind IN ('wyciag','potwierdzenie')") as $f) {
            $meta = self::meta($f);
            $pending = $f['kind'] === 'wyciag' ? ((int)($meta['ops_matched'] ?? 0) < count((array)($meta['ops'] ?? [])))
                                               : !db_one("SELECT 1 FROM edok_bank_tx_files WHERE file_id=?", [(int)$f['id']]);
            if (!$pending) continue;
            $files++;
            $links += self::autoMatch((int)$f['id']);
        }
        return ['files' => $files, 'links' => $links];
    }

    // ── powiązania ──────────────────────────────────────────────────────────────────────────────────────────────────
    /** @return bool true = dodano nowe powiązanie */
    public static function link(int $txId, int $fileId, string $how, ?int $userId = null): bool
    {
        self::migrate();
        if (!db_one("SELECT 1 FROM edok_bank_tx WHERE id=?", [$txId]) || !db_one("SELECT 1 FROM edok_bank_files WHERE id=?", [$fileId])) return false;
        if (db_one("SELECT 1 FROM edok_bank_tx_files WHERE tx_id=? AND file_id=?", [$txId, $fileId])) return false;
        if ($how === 'reczne') db()->prepare("DELETE FROM edok_bank_file_rejects WHERE tx_id=? AND file_id=?")->execute([$txId, $fileId]);
        elseif (db_one("SELECT 1 FROM edok_bank_file_rejects WHERE tx_id=? AND file_id=?", [$txId, $fileId])) return false;   // automat nie przywraca ręcznie odpiętego
        db()->prepare("INSERT INTO edok_bank_tx_files (tx_id, file_id, matched_how, matched_by, created_at) VALUES (?,?,?,?,?)")
            ->execute([$txId, $fileId, $how, $userId ?: null, date('Y-m-d H:i:s')]);
        return true;
    }

    public static function unlink(int $txId, int $fileId): void
    {
        self::migrate();
        db()->prepare("DELETE FROM edok_bank_tx_files WHERE tx_id=? AND file_id=?")->execute([$txId, $fileId]);
        db()->prepare("INSERT OR IGNORE INTO edok_bank_file_rejects (tx_id, file_id, created_at) VALUES (?,?,?)")->execute([$txId, $fileId, date('Y-m-d H:i:s')]);
        // wyciąg odpięty od transakcji nie może być od razu przypięty z powrotem przez rematch — liczymy dopasowane operacje od nowa
        if ($f = db_one("SELECT * FROM edok_bank_files WHERE id=?", [$fileId])) {
            $meta = self::meta($f);
            if (isset($meta['ops_matched'])) {
                $meta['ops_matched'] = max(0, (int)$meta['ops_matched'] - 1);
                db()->prepare("UPDATE edok_bank_files SET meta=? WHERE id=?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $fileId]);
            }
        }
    }

    /** Usuwa plik z dysku, bazy i powiązań. */
    public static function delete(int $fileId): void
    {
        self::migrate();
        if ($f = db_one("SELECT * FROM edok_bank_files WHERE id=?", [$fileId])) @unlink(self::path($f));
        db()->prepare("DELETE FROM edok_bank_tx_files WHERE file_id=?")->execute([$fileId]);
        db()->prepare("DELETE FROM edok_bank_file_rejects WHERE file_id=?")->execute([$fileId]);
        db()->prepare("DELETE FROM edok_bank_files WHERE id=?")->execute([$fileId]);
    }

    /** @param list<int> $txIds @return array<int,list<array>> tx_id => pliki */
    public static function forTxs(array $txIds): array
    {
        self::migrate();
        $txIds = array_values(array_unique(array_map('intval', $txIds)));
        if (!$txIds) return [];
        $ph = implode(',', array_fill(0, count($txIds), '?'));
        $out = [];
        foreach (db_all("SELECT l.tx_id, l.matched_how, f.id, f.kind, f.orig_name, f.meta FROM edok_bank_tx_files l JOIN edok_bank_files f ON f.id=l.file_id
                          WHERE l.tx_id IN ({$ph}) ORDER BY f.kind, f.id", $txIds) as $r) {
            $meta = json_decode((string)$r['meta'], true) ?: [];
            $r['label'] = $r['kind'] === 'wyciag' ? 'wyciąg ' . ($meta['statement_no'] ?? '') : ($r['kind'] === 'potwierdzenie' ? 'potwierdzenie' : 'PDF');
            $out[(int)$r['tx_id']][] = $r;
        }
        return $out;
    }

    /** Pliki bez żadnej transakcji (potwierdzenia/inne) oraz wyciągi z niedopasowanymi operacjami. @return list<array> */
    public static function pending(): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT f.*, (SELECT COUNT(*) FROM edok_bank_tx_files l WHERE l.file_id=f.id) AS links FROM edok_bank_files f ORDER BY f.id DESC LIMIT 200") as $f) {
            $meta = self::meta($f);
            if ($f['kind'] === 'wyciag') {
                $total = count((array)($meta['ops'] ?? []));
                $f['unmatched_ops'] = max(0, $total - (int)($meta['ops_matched'] ?? 0));
                if ($f['unmatched_ops'] === 0) continue;
            } elseif ((int)$f['links'] > 0) {
                continue;
            }
            $f['meta_arr'] = $meta;
            $out[] = $f;
        }
        return $out;
    }
}
