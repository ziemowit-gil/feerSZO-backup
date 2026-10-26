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
    /** Źródła: typ => [prefiks kodu, etykieta, adres otwarcia ({id}), tabela]. */
    public const SOURCES = [
        'ezd_zalacznik' => ['EZD', 'Plik w koszulce EZD', '/ezd/serve.php?id={id}', 'ezd_zalaczniki'],
        'crm_case_file' => ['CRM', 'Plik sprawy CRM', '/crm/cases/download.php?id={id}', 'crm_case_files'],
        'task_file'     => ['ZAD', 'Plik zadania', '/tasks/api/file.php?id={id}', 'task_files'],
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
    }

    // ── Kod ───────────────────────────────────────────────────────────────────────

    private static function randomCode(string $prefix): string
    {
        $l = self::LETTERS;
        return $prefix . '/' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT) . $l[random_int(0, strlen($l) - 1)] . $l[random_int(0, strlen($l) - 1)];
    }

    /** Czy tekst ma format kodu rejestru (TYP/6 cyfr + 2 litery). */
    public static function isCode(string $c): bool
    {
        return (bool)preg_match('~^[A-Z]{2,5}/\d{6}[A-Z]{2}$~', $c);
    }

    // ── Źródło (plik na dysku + dane) ────────────────────────────────────────────

    /** @return array{name:string,path:string,size:int,uploader:?int,at:string,exists:bool,superseded:bool}|null  null = brak rekordu źródłowego */
    private static function source(string $type, int $id): ?array
    {
        switch ($type) {
            case 'ezd_zalacznik':
                $z = db_one("SELECT * FROM ezd_zalaczniki WHERE id = ?", [$id]);
                if (!$z) return null;
                $p = (defined('UPLOAD_DIR') && defined('EZD_UPLOAD_SUBDIR')) ? UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'] : '';
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
            $sha = ($src['exists'] && $src['size'] <= 100 * 1024 * 1024) ? (string)@hash_file('sha256', $src['path']) : '';
            $now = date('Y-m-d H:i:s');
            for ($try = 0; $try < 8; $try++) {
                $code = self::randomCode(self::SOURCES[$type][0]);
                try {
                    $rid = db_insert('doc_registry', ['code' => $code, 'doc_number' => '', 'source_type' => $type, 'source_id' => $id, 'sha256' => $sha,
                        'created_by' => $src['uploader'], 'created_at' => $now]);
                    db()->prepare("UPDATE doc_registry SET doc_number = ? WHERE id = ?")->execute([sprintf('DOK/%s/%06d', date('Y'), $rid), $rid]);
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

    // ── Adresy i QR ───────────────────────────────────────────────────────────────

    /** Adres z QR: publiczna weryfikacja kodu (z przyciskiem „Otwórz dokument online”). */
    public static function url(string $code): string
    {
        return rtrim(APP_URL, '/') . '/espis/?c=' . rawurlencode($code);
    }

    /** Adres otwarcia dokumentu online (wymaga logowania; uprawnienia sprawdza system źródłowy). */
    public static function openUrl(string $type, int $id): string
    {
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
        if ($src['superseded']) return ['state' => 'void', 'note' => 'Plik został zastąpiony nowszą wersją.', 'src' => $src];
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
        return '<button type="button" class="docreg-badge" data-docreg-code="' . $e($row['code']) . '" data-docreg-number="' . $e($row['doc_number']) . '"'
            . ' data-docreg-qr="' . $e($base . '?qr=' . rawurlencode((string)$row['code'])) . '" data-docreg-label="' . $e($base . '?label=' . rawurlencode((string)$row['code'])) . '"'
            . ' data-docreg-url="' . $e(self::url((string)$row['code'])) . '" aria-haspopup="dialog" title="Numer i kod QR dokumentu">'
            . '<span aria-hidden="true">▦</span> ' . $e($row['code']) . '<span class="visually-hidden"> — numer ' . $e($row['doc_number']) . ', pokaż kod QR</span></button>';
    }

    /** Okno z QR + style + skrypt (raz na stronę, POZA zakładkami — modal Bootstrap). */
    public static function assets(): string
    {
        static $done = false;
        if ($done) return '';
        $done = true;
        return <<<'HTML'
<style>
.docreg-badge{font:600 .68rem/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;background:#eef4ff;color:#0b3d91;border:1px solid #9db7e8;border-radius:3px;padding:.1rem .4rem;cursor:pointer}
.docreg-badge:hover{background:#dce8ff}.docreg-badge:focus-visible{outline:3px solid #ff9800;outline-offset:2px}
</style>
<div class="modal fade" id="docregModal" tabindex="-1" aria-labelledby="docregTitle" aria-hidden="true"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title fs-6" id="docregTitle">Identyfikator dokumentu</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
  <div class="modal-body text-center">
    <img id="docregQr" alt="Kod QR do weryfikacji i otwarcia dokumentu online" width="200" height="200" class="mb-2">
    <div class="small text-muted">Numer</div><div id="docregNumber" class="fw-semibold mb-1"></div>
    <div class="small text-muted">Kod (Code128)</div><code id="docregCode" class="fs-6 d-block mb-2"></code>
    <p class="small text-muted mb-0">Kod QR otwiera stronę weryfikacji; po zalogowaniu — dokument online.</p>
  </div>
  <div class="modal-footer justify-content-between"><a id="docregLabel" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Etykieta do druku (PDF)</a><a id="docregOpen" class="btn btn-sm btn-primary" target="_blank" rel="noopener">Strona weryfikacji</a></div>
</div></div></div>
<script>
document.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('.docreg-badge');if(!b)return;ev.preventDefault();ev.stopPropagation();
  var m=document.getElementById('docregModal');if(!m||!window.bootstrap){window.open(b.dataset.docregLabel,'_blank','noopener');return;}
  document.getElementById('docregQr').src=b.dataset.docregQr;document.getElementById('docregNumber').textContent=b.dataset.docregNumber;
  document.getElementById('docregCode').textContent=b.dataset.docregCode;document.getElementById('docregLabel').href=b.dataset.docregLabel;document.getElementById('docregOpen').href=b.dataset.docregUrl;
  window.bootstrap.Modal.getOrCreateInstance(m).show();},true);
</script>
HTML;
    }
}
