<?php
declare(strict_types=1);

/**
 * modules/ezdStorage/lib/EzdStorage.php — kopie dokumentów EZD na serwerze FTP i w chmurze Nextcloud
 * (polecenie 2026-11-14: „przepiszmy zapisywanie dokumentów na sharepoint na przypisywanie na FTP i NC, a jak niedostępne
 * tylko na nc na użytkowniku ezdmigracja”). Zastępuje automatyczną kopię na SharePoint (ezd_sp_sync_attachment).
 *
 * Oryginał zostaje na dysku serwera (uploads/ezd/<koszulka>/<plik>) — z niego czyta całe EZD. Po każdym zapisie pliku
 * (wgranie, nowa wersja, podpis, zaświadczenie, Spinacz, szablon, skan z dziennika podawczego…) push() wysyła kopię:
 *   - do Nextcloud: konto techniczne „ezdmigracja” (WebDAV, hasło aplikacji zaszyfrowane w settings), folder {root}/…;
 *   - na FTP, gdy administrator go skonfigurował (Ustawienia Wirtualnego biurka → Kopie dokumentów).
 * Niedostępny cel (sieć, czas, 5xx) = przerwa w próbach na 5 min i wpis w dzienniku zdarzeń; zapis w EZD idzie dalej
 * bez komunikatu dla użytkownika, a zaległe kopie dosyła agent CRON ezd_store_sync (cron/agents/ezd_store_sync.php).
 * Gdzie leży każda kopia — tabela ezd_store_files (ścieżka w chmurze i na FTP, skrót SHA-256). Gdy na dysku zabraknie
 * pliku, ensureLocal() odtwarza go z chmury albo z FTP (ezd/serve.php).
 *
 * Konto w chmurze istnieje tylko tam (bez rekordu w users): createAccount() zakłada je przez OCS na koncie API SZO
 * (SsoConfig::cloud(), administrator), bierze hasło aplikacji i zapisuje je wyłącznie zaszyfrowane (fcEncrypt).
 */

require_once __DIR__ . '/EzdFtp.php';
require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';   // + CloudApi, fieldCrypto

/** Błędy formularza ustawień FTP przypisane do pól. */
final class EzdStorageInvalid extends InvalidArgumentException
{
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

final class EzdStorage
{
    public const NC_UID     = 'ezdmigracja';
    public const NC_DISPLAY = 'EZD – kopie dokumentów (konto techniczne)';
    public const NC_ROOT    = 'EZD';
    public const FTP_DIR    = '/ezd';
    public const PAUSE      = 300;      // s przerwy po niedostępności celu
    public const MAX_TRIES  = 5;        // tyle prób dosyłania jednego pliku przez CRON (potem tylko --wszystkie / przycisk)

    // Klucze w tabeli settings
    public const K_FTP_ON      = 'ezd_store_ftp_enabled';
    public const K_FTP_HOST    = 'ezd_store_ftp_host';
    public const K_FTP_PORT    = 'ezd_store_ftp_port';
    public const K_FTP_USER    = 'ezd_store_ftp_user';
    public const K_FTP_PASS    = 'ezd_store_ftp_pass';      // fcEncrypt(…, 'settings.ezd_store_ftp_pass')
    public const K_FTP_DIR     = 'ezd_store_ftp_dir';
    public const K_FTP_TLS     = 'ezd_store_ftp_tls';
    public const K_FTP_PASSIVE = 'ezd_store_ftp_passive';
    public const K_NC_USER     = 'ezd_store_nc_user';
    public const K_NC_PASS     = 'ezd_store_nc_pass';       // fcEncrypt(…, 'settings.ezd_store_nc_pass') — hasło aplikacji
    public const K_NC_ROOT     = 'ezd_store_nc_root';

    private static ?CloudApi $nc = null;
    private static ?EzdFtp $ftp = null;
    private static bool $held = false;

    // ── Schemat ──────────────────────────────────────────────────────────────────────────────

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ezd_store_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS ezd_store_files (
            zal_id        INTEGER PRIMARY KEY REFERENCES ezd_zalaczniki(id) ON DELETE CASCADE,
            rel_path      TEXT NOT NULL DEFAULT '',
            sha256        TEXT NOT NULL DEFAULT '',
            size          INTEGER NOT NULL DEFAULT 0,
            nc_path       TEXT NOT NULL DEFAULT '',
            nc_at         TEXT,
            nc_error      TEXT NOT NULL DEFAULT '',
            ftp_path      TEXT NOT NULL DEFAULT '',
            ftp_at        TEXT,
            ftp_error     TEXT NOT NULL DEFAULT '',
            local_missing INTEGER NOT NULL DEFAULT 0,
            tries         INTEGER NOT NULL DEFAULT 0,
            sp_path       TEXT NOT NULL DEFAULT '',
            sp_at         TEXT,
            sp_error      TEXT NOT NULL DEFAULT '',
            sp_tries      INTEGER NOT NULL DEFAULT 0,
            updated_at    TEXT
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ezd_store_rel ON ezd_store_files(rel_path)");
    }

    // ── Ustawienia ───────────────────────────────────────────────────────────────────────────

    /** Wartość z settings czytana wprost (org_setting() trzyma pamięć podręczną na całe żądanie). */
    public static function setting(string $key, string $default = ''): string
    {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $v = (string)($r['value'] ?? '');
        return $v !== '' ? $v : $default;
    }

    private static function set(string $key, string $value): void
    {
        org_setting_set($key, $value);
    }

    private static function secret(string $key): string
    {
        $enc = self::setting($key);
        if ($enc === '') return '';
        try { return (string)(fcDecrypt($enc, 'settings.' . $key) ?? ''); } catch (\Throwable $e) { return ''; }
    }

    /** Ustawienia FTP bez hasła (do widoku). */
    public static function ftpSettings(): array
    {
        return [
            'enabled'  => self::setting(self::K_FTP_ON) === '1',
            'host'     => self::setting(self::K_FTP_HOST),
            'port'     => (int)self::setting(self::K_FTP_PORT, '21'),
            'user'     => self::setting(self::K_FTP_USER),
            'has_pass' => self::setting(self::K_FTP_PASS) !== '',
            'dir'      => self::setting(self::K_FTP_DIR, self::FTP_DIR),
            'tls'      => self::setting(self::K_FTP_TLS, 'explicit'),
            'passive'  => self::setting(self::K_FTP_PASSIVE, '1') !== '0',
        ];
    }

    public static function ftpConfigured(): bool
    {
        return self::setting(self::K_FTP_ON) === '1' && self::setting(self::K_FTP_HOST) !== '' && self::setting(self::K_FTP_USER) !== '';
    }

    public static function ncUser(): string
    {
        return self::setting(self::K_NC_USER);
    }

    public static function ncRoot(): string
    {
        $v = trim(self::setting(self::K_NC_ROOT), "/ \t");
        return $v !== '' ? $v : self::NC_ROOT;
    }

    public static function ncConfigured(): bool
    {
        try { $url = SsoConfig::cloudUrl(); } catch (\Throwable $e) { return false; }
        return $url !== '' && self::ncUser() !== '' && self::setting(self::K_NC_PASS) !== '';
    }

    /** Łącznik WebDAV na koncie ezdmigracja (właściciel kopii). */
    public static function nc(): CloudApi
    {
        if (self::$nc) return self::$nc;
        if (!self::ncConfigured()) throw new CloudApiException('Konto chmury na kopie EZD nie jest skonfigurowane.');
        return self::$nc = new CloudApi(SsoConfig::cloudUrl(), self::ncUser(), self::secret(self::K_NC_PASS), SsoConfig::cloudKind());
    }

    public static function ftp(?string $dir = null): EzdFtp
    {
        if ($dir === null && self::$ftp) return self::$ftp;
        $s = self::ftpSettings();
        $f = new EzdFtp($s['host'], $s['port'], $s['user'], self::secret(self::K_FTP_PASS), $dir ?? $s['dir'], $s['tls'], $s['passive']);
        if ($dir === null) self::$ftp = $f;
        return $f;
    }

    /** Do testów: podmiana łączników; null = z ustawień. */
    public static function setNc(?CloudApi $api): void { self::$nc = $api; }
    public static function setFtp(?EzdFtp $ftp): void { self::$ftp = $ftp; }

    /** Po zmianie ustawień — następne wywołanie zbuduje łączniki od nowa. */
    public static function reset(): void
    {
        self::$nc = null;
        self::$ftp = null;
    }

    /**
     * Wstrzymuje kopie na czas operacji z plikami tymczasowymi (Spinacz wgrywa oryginały, które zaraz usuwa) —
     * push() w tym czasie nic nie wysyła.
     */
    public static function hold(bool $on): void
    {
        self::$held = $on;
    }

    // ── Przerwa po niedostępności ────────────────────────────────────────────────────────────

    public static function pausedUntil(string $target): int
    {
        return (int)self::setting('ezd_store_' . $target . '_down_until', '0');
    }

    public static function paused(string $target): bool
    {
        return self::pausedUntil($target) > time();
    }

    /** Przerwa w próbach dla celu (nc, ftp, sp) po niedostępności + jeden wpis w dzienniku na przerwę. */
    public static function pause(string $target, string $why): void
    {
        $was = self::paused($target);
        self::set('ezd_store_' . $target . '_down_until', (string)(time() + self::PAUSE));
        if (!$was) self::log('ezd.store.unavailable', ['target' => $target, 'until' => date('Y-m-d H:i:s', time() + self::PAUSE), 'error' => $why]);
    }

    public static function resume(string $target): void
    {
        if (self::setting('ezd_store_' . $target . '_down_until') !== '') self::set('ezd_store_' . $target . '_down_until', '');
    }

    // ── Ścieżki ──────────────────────────────────────────────────────────────────────────────

    public static function localPath(array $z): string
    {
        return UPLOAD_DIR . (defined('EZD_UPLOAD_SUBDIR') ? EZD_UPLOAD_SUBDIR : 'ezd/') . (int)$z['sprawa_id'] . '/' . $z['filename'];
    }

    private static function clean(string $s, string $fallback): string
    {
        $s = str_replace(['"', '*', ':', '<', '>', '?', '\\', '|', '/', "\r", "\n", "\0"], '-', $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s), " ./-");
        return $s !== '' ? $s : $fallback;
    }

    /**
     * Ścieżka kopii względem katalogu bazowego — ten sam układ co dawniej na SharePoincie (polecenie „migrator EZD
     * z sharepointa zachowując ścieżki”), więc pliki przeniesione z SharePointa i nowe kopie leżą razem:
     * cases/{symbol JRWA}/{tytuł koszulki - data (znak)}/{nazwa pliku} (folder: ezd_sprawa_sp_folder).
     * Kilka plików o tej samej nazwie w koszulce (wersje): zwykłą nazwę ma najnowszy, starsze — „nazwa [id].rozszerzenie”.
     */
    public static function relPath(array $z): string
    {
        $folder = function_exists('ezd_sprawa_sp_folder') ? (string)(ezd_sprawa_sp_folder((int)$z['sprawa_id']) ?? '') : '';
        if ($folder === '') $folder = 'cases/bez-koszulki/' . (int)$z['sprawa_id'];
        $orig = (string)($z['original_name'] ?: $z['filename']);
        $ext = (string)pathinfo($orig, PATHINFO_EXTENSION);   // wielkość liter jak w nazwie (tak zapisywał SharePoint)
        $base = self::clean($ext !== '' ? mb_substr($orig, 0, -(mb_strlen($ext) + 1)) : $orig, 'plik');
        $base = mb_substr($base, 0, 180);
        $newer = db_one("SELECT 1 FROM ezd_zalaczniki WHERE sprawa_id=? AND original_name=? AND id>? LIMIT 1",
                        [(int)$z['sprawa_id'], (string)$z['original_name'], (int)$z['id']]);
        if ($newer) $base .= ' [' . (int)$z['id'] . ']';
        return $folder . '/' . $base . ($ext !== '' ? '.' . self::clean($ext, 'bin') : '');
    }

    public static function row(int $zalId): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM ezd_store_files WHERE zal_id=?", [$zalId]) ?: null;
    }

    /** Zapis pól wpisu kopii (upsert). */
    public static function save(int $zalId, array $f): void
    {
        $f['updated_at'] = date('Y-m-d H:i:s');
        $cols = array_keys($f);
        $sql = "INSERT INTO ezd_store_files (zal_id," . implode(',', $cols) . ") VALUES (?" . str_repeat(',?', count($cols)) . ")
                ON CONFLICT(zal_id) DO UPDATE SET " . implode(',', array_map(fn($c) => "$c=excluded.$c", $cols));
        db()->prepare($sql)->execute(array_merge([$zalId], array_values($f)));
    }

    // ── Zapis kopii ──────────────────────────────────────────────────────────────────────────

    /**
     * Wysyła kopię załącznika do chmury i na FTP (każdy cel osobno; ten sam plik bez zmian nie idzie drugi raz).
     * Nigdy nie rzuca wyjątku. Wynik celu: ok | error | paused (przerwa po niedostępności) | off (nieskonfigurowany) | held.
     * @return array{nc:string, ftp:string, error:string}
     */
    public static function push(int $zalId, bool $force = false): array
    {
        $res = ['nc' => 'off', 'ftp' => 'off', 'error' => ''];
        try {
            if (self::$held) return ['nc' => 'held', 'ftp' => 'held', 'error' => ''];
            $useNc = self::ncConfigured();
            $useFtp = self::ftpConfigured();
            if (!$useNc && !$useFtp) return $res;
            self::migrate();
            $z = db_one("SELECT * FROM ezd_zalaczniki WHERE id=?", [$zalId]);
            if (!$z) return ['nc' => 'error', 'ftp' => 'error', 'error' => 'Nie ma takiego pliku w EZD.'];
            $row = self::row($zalId) ?? [];
            $local = self::localPath($z);
            if (!is_file($local)) {
                self::save($zalId, ['local_missing' => 1]);
                self::log('ezd.store.error', ['zal_id' => $zalId, 'target' => 'dysk', 'error' => 'Brak pliku na dysku serwera.']);
                return ['nc' => 'error', 'ftp' => 'error', 'error' => 'Brak pliku na dysku serwera.'];
            }
            $sha = (string)hash_file('sha256', $local);
            $same = ($row['sha256'] ?? '') === $sha;
            $rel = (string)(($row['rel_path'] ?? '') ?: self::relPath($z));
            $skip = [];
            if (!$force && $same && !empty($row['nc_at'])) $skip[] = 'nc';
            if (!$force && $same && !empty($row['ftp_at'])) $skip[] = 'ftp';
            [$r, $u, $failed] = self::sendCopies($zalId, $rel, $local, $skip);
            $upd = ['rel_path' => $rel, 'sha256' => $sha, 'size' => (int)filesize($local), 'local_missing' => 0] + $u;
            if (!$same) {   // nowa treść: stara kopia już nieaktualna, dopóki nowa nie dojdzie
                if ($r['nc'] !== 'ok') $upd['nc_at'] = null;
                if ($r['ftp'] !== 'ok') $upd['ftp_at'] = null;
            }
            $upd['tries'] = $failed ? (int)($row['tries'] ?? 0) + 1 : (in_array('paused', $r, true) ? (int)($row['tries'] ?? 0) : 0);
            self::save($zalId, $upd);
            if (($r['nc'] === 'ok' && !in_array('nc', $skip, true)) || ($r['ftp'] === 'ok' && !in_array('ftp', $skip, true))) {
                self::releaseOlder($zalId, $rel);
            }
            $res = $r + ['error' => trim(($upd['nc_error'] ?? '') . ' ' . ($upd['ftp_error'] ?? ''))];
        } catch (\Throwable $e) {
            error_log('[ezd_store] push ' . $zalId . ': ' . $e->getMessage());
            $res['error'] = $e->getMessage();
        }
        return $res;
    }

    /**
     * Wysyła plik pod ścieżkę względną do chmury i na FTP (cele skonfigurowane i bez przerwy; $skip — cele, które już
     * mają kopię). Niedostępny cel = przerwa i wpis w dzienniku. Nie rzuca wyjątków.
     * @return array{0: array{nc:string, ftp:string}, 1: array, 2: bool}  [wynik celów, pola do ezd_store_files, był błąd]
     */
    public static function sendCopies(int $zalId, string $rel, string $file, array $skip = []): array
    {
        $res = ['nc' => 'off', 'ftp' => 'off'];
        $upd = [];
        $failed = false;
        $targets = [
            'nc'  => [self::ncConfigured(), function () use ($rel, $file): string {
                $path = self::ncRoot() . '/' . $rel;
                self::nc()->ownMkdirs(dirname($path));
                self::nc()->ownPut($path, $file);
                return $path;
            }],
            'ftp' => [self::ftpConfigured(), function () use ($rel, $file): string {
                $ftp = self::ftp();
                $ftp->put($rel, $file);
                return $ftp->remotePath($rel);
            }],
        ];
        foreach ($targets as $t => [$on, $send]) {
            if (!$on) continue;
            if (in_array($t, $skip, true)) { $res[$t] = 'ok'; continue; }
            if (self::paused($t)) { $res[$t] = 'paused'; continue; }
            try {
                $upd += [$t . '_path' => $send(), $t . '_at' => date('Y-m-d H:i:s'), $t . '_error' => ''];
                $res[$t] = 'ok';
            } catch (\Throwable $e) {
                $failed = true;
                $res[$t] = 'error';
                $upd[$t . '_error'] = mb_substr($e->getMessage(), 0, 500);
                $down = $t === 'nc' ? $e instanceof CloudUnavailable : ($e instanceof EzdFtpException && $e->unavailable);
                if ($down) self::pause($t, $e->getMessage());
                else self::log('ezd.store.error', ['zal_id' => $zalId, 'target' => $t, 'error' => $e->getMessage()]);
            }
        }
        return [$res, $upd, $failed];
    }

    /**
     * Pod ścieżką $rel leży teraz plik $zalId — starsze pliki o tej samej ścieżce (wcześniejsze wersje o tej samej nazwie)
     * tracą wpis kopii, więc CRON wyśle je ponownie pod nazwą z [id] (relPath()).
     */
    public static function releaseOlder(int $zalId, string $rel): void
    {
        db()->prepare("UPDATE ezd_store_files SET rel_path='', nc_path='', nc_at=NULL, ftp_path='', ftp_at=NULL, updated_at=?
                       WHERE rel_path=? AND zal_id<?")->execute([date('Y-m-d H:i:s'), $rel, $zalId]);
    }

    /**
     * Ścieżka pliku na dysku; gdy go brakuje, a jest kopia — odtwarza go z chmury (najpierw) albo z FTP.
     * Zwraca null, gdy pliku nie ma i nie da się go odtworzyć.
     */
    public static function ensureLocal(array $z): ?string
    {
        $path = self::localPath($z);
        if (is_file($path)) return $path;
        try {
            $row = self::row((int)$z['id']);
            if (!$row) return null;
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $tmp = $path . '.restore-' . bin2hex(random_bytes(4));
            $sources = [];
            if (!empty($row['nc_at']) && $row['nc_path'] !== '' && self::ncConfigured()) {
                $sources['nc'] = fn() => self::nc()->ownDownload($row['nc_path'], $tmp);
            }
            if (!empty($row['ftp_at']) && $row['ftp_path'] !== '' && self::setting(self::K_FTP_HOST) !== '') {
                $abs = str_starts_with($row['ftp_path'], '/');
                $sources['ftp'] = fn() => self::ftp($abs ? '/' : '')->get(ltrim($row['ftp_path'], '/'), $tmp);
            }
            foreach ($sources as $target => $fetch) {
                try {
                    $fetch();
                    if ($row['sha256'] !== '' && !hash_equals($row['sha256'], (string)hash_file('sha256', $tmp))) {
                        throw new RuntimeException('Kopia różni się od zapisanej (SHA-256).');
                    }
                    if (!@rename($tmp, $path)) throw new RuntimeException('Nie można zapisać pliku na dysku.');
                    self::save((int)$z['id'], ['local_missing' => 0]);
                    self::log('ezd.store.restored', ['zal_id' => (int)$z['id'], 'from' => $target]);
                    return $path;
                } catch (\Throwable $e) {
                    @unlink($tmp);
                    self::log('ezd.store.error', ['zal_id' => (int)$z['id'], 'target' => $target, 'error' => 'Odtworzenie: ' . $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[ezd_store] restore ' . ($z['id'] ?? '?') . ': ' . $e->getMessage());
        }
        return null;
    }

    /** Usuwa kopie pliku (chmura: do kosza konta, FTP: DELE) i wpis o nich. Nigdy nie rzuca wyjątku. */
    public static function forget(int $zalId): void
    {
        try {
            $row = self::row($zalId);
            if (!$row) return;
            if (!empty($row['nc_at'])) self::dropCopy('nc', (string)$row['nc_path'], $zalId);
            if (!empty($row['ftp_at'])) self::dropCopy('ftp', (string)$row['ftp_path'], $zalId);
            db()->prepare("DELETE FROM ezd_store_files WHERE zal_id=?")->execute([$zalId]);
        } catch (\Throwable $e) {
            error_log('[ezd_store] forget ' . $zalId . ': ' . $e->getMessage());
        }
    }

    /**
     * Usuwa jedną kopię (chmura: do kosza konta, FTP: DELE), chyba że pod tą ścieżką leży też kopia innego pliku
     * (np. przeniesiona z SharePointa) — wtedy zostaje. Błąd = wpis w dzienniku, bez wyjątku.
     */
    public static function dropCopy(string $target, string $path, int $zalId): void
    {
        if ($path === '' || !in_array($target, ['nc', 'ftp'], true)) return;
        if (db_one("SELECT 1 FROM ezd_store_files WHERE {$target}_path=? AND {$target}_at IS NOT NULL AND zal_id<>? LIMIT 1", [$path, $zalId])) return;
        try {
            if ($target === 'nc' && self::ncConfigured()) self::nc()->ownDelete($path);
            if ($target === 'ftp' && self::setting(self::K_FTP_HOST) !== '') {
                self::ftp(str_starts_with($path, '/') ? '/' : '')->delete(ltrim($path, '/'));
            }
        } catch (\Throwable $e) {
            self::log('ezd.store.error', ['zal_id' => $zalId, 'target' => $target, 'error' => 'Usunięcie: ' . $e->getMessage()]);
        }
    }

    // ── Dosyłanie zaległych (CRON, przycisk, migracja istniejących plików) ─────────────────────

    /**
     * Id plików bez kopii w skonfigurowanych celach (najnowsze najpierw). Pomija cele w przerwie oraz — gdy działa
     * migracja z SharePointa — pliki, które przeniesie ona ze ścieżką z biblioteki (EzdSpMigrator).
     */
    public static function pending(int $limit, bool $all = false): array
    {
        self::migrate();
        $need = [];
        if (self::ncConfigured() && !self::paused('nc')) $need[] = 's.nc_at IS NULL';
        if (self::ftpConfigured() && !self::paused('ftp')) $need[] = 's.ftp_at IS NULL';
        if (!$need) return [];
        require_once __DIR__ . '/EzdSpMigrator.php';
        $sql = "SELECT z.id FROM ezd_zalaczniki z LEFT JOIN ezd_store_files s ON s.zal_id = z.id
                WHERE (" . implode(' OR ', $need) . ")"
             . ($all ? '' : " AND COALESCE(s.local_missing,0) = 0 AND COALESCE(s.tries,0) < " . self::MAX_TRIES)
             . (EzdSpMigrator::active() ? " AND NOT (" . EzdSpMigrator::awaitingSql() . ")" : '')
             . " ORDER BY z.id DESC LIMIT " . max(1, $limit);
        return array_map('intval', array_column(db_all($sql), 'id'));
    }

    /**
     * Porcja dosyłania: do $limit plików albo $seconds sekund. Kończy wcześniej, gdy oba cele są w przerwie.
     * @return array{files:int, nc:int, ftp:int, errors:int, left:int}
     */
    public static function syncBatch(int $limit = 200, int $seconds = 240, bool $all = false): array
    {
        $out = ['files' => 0, 'nc' => 0, 'ftp' => 0, 'errors' => 0, 'left' => 0];
        $stop = time() + $seconds;
        foreach (self::pending($limit, $all) as $id) {
            if (time() >= $stop) break;
            $r = self::push($id);
            $out['files']++;
            if ($r['nc'] === 'ok') $out['nc']++;
            if ($r['ftp'] === 'ok') $out['ftp']++;
            if ($r['nc'] === 'error' || $r['ftp'] === 'error') $out['errors']++;
            $ncDone = !self::ncConfigured() || self::paused('nc');
            $ftpDone = !self::ftpConfigured() || self::paused('ftp');
            if ($ncDone && $ftpDone) break;
        }
        $out['left'] = count(self::pending(100000, $all));
        return $out;
    }

    /** Liczby do ekranu ustawień. */
    public static function stats(): array
    {
        self::migrate();
        $total = (int)(db_one("SELECT COUNT(*) c FROM ezd_zalaczniki")['c'] ?? 0);
        $s = db_one("SELECT SUM(CASE WHEN nc_at IS NOT NULL THEN 1 ELSE 0 END) nc,
                            SUM(CASE WHEN ftp_at IS NOT NULL THEN 1 ELSE 0 END) ftp,
                            SUM(CASE WHEN nc_at IS NULL AND nc_error <> '' THEN 1 ELSE 0 END) nc_err,
                            SUM(CASE WHEN ftp_at IS NULL AND ftp_error <> '' THEN 1 ELSE 0 END) ftp_err,
                            SUM(local_missing) missing
                     FROM ezd_store_files") ?: [];
        $errors = db_all("SELECT s.zal_id, s.nc_error, s.ftp_error, s.updated_at, s.local_missing, z.original_name, z.sprawa_id
                          FROM ezd_store_files s LEFT JOIN ezd_zalaczniki z ON z.id = s.zal_id
                          WHERE (s.nc_at IS NULL AND s.nc_error <> '') OR (s.ftp_at IS NULL AND s.ftp_error <> '') OR s.local_missing = 1
                          ORDER BY s.updated_at DESC LIMIT 10");
        return ['total' => $total, 'nc' => (int)($s['nc'] ?? 0), 'ftp' => (int)($s['ftp'] ?? 0), 'nc_err' => (int)($s['nc_err'] ?? 0),
                'ftp_err' => (int)($s['ftp_err'] ?? 0), 'missing' => (int)($s['missing'] ?? 0), 'errors' => $errors];
    }

    // ── Konto w chmurze ──────────────────────────────────────────────────────────────────────

    private static function password(): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 40; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
        return $p;
    }

    /** Hasło aplikacji z logowania hasłem konta (Nextcloud: /ocs/v2.php/core/getapppassword); '' gdy się nie da. */
    private static function appPassword(string $uid, string $pass): string
    {
        $ch = curl_init(rtrim(SsoConfig::cloudUrl(), '/') . '/ocs/v2.php/core/getapppassword?format=json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $uid . ':' . $pass, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
                                CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json']]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($raw === false || $code !== 200) return '';
        $j = json_decode((string)$raw, true);
        return is_array($j) ? (string)($j['ocs']['data']['apppassword'] ?? '') : '';
    }

    /**
     * Zakłada (albo przejmuje, nadając nowe hasło) konto techniczne w chmurze i przełącza na nie kopie EZD.
     * Hasło: losowe, zamienione na hasło aplikacji; w SZO tylko zaszyfrowane, nigdzie nie wyświetlane.
     * @return array{ok:bool, msg:string}
     */
    public static function createAccount(string $uid = self::NC_UID): array
    {
        $uid = strtolower(trim($uid));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $uid)) return ['ok' => false, 'msg' => 'Login konta: 3–64 znaki, małe litery, cyfry, . _ -'];
        if (!SsoConfig::cloudConfigured()) return ['ok' => false, 'msg' => 'Chmura plików nie jest skonfigurowana (adres i konto API w /ssoadmin).'];

        if (self::ncUser() === $uid && self::ncConfigured()) {
            try {
                self::reset();
                self::nc()->ownMkdirs(self::ncRoot());
                return ['ok' => true, 'msg' => 'Konto ' . $uid . ' już działa (zapis do folderu ' . self::ncRoot() . ' przyjęty). Nic nie zmieniono.'];
            } catch (\Throwable $e) {
                // hasło nie działa — nadajemy nowe niżej
            }
        }

        $pass = self::password();
        try {
            $admin = SsoConfig::cloud();
            if ($admin->userExists($uid)) {
                $admin->setPassword($uid, $pass);
                $how = 'istniało — nadano nowe hasło';
            } else {
                $admin->createUser($uid, $pass, self::NC_DISPLAY);
                $how = 'założono';
            }
            // Nazwa i e-mail prywatne — konto nie podpowiada się przy udostępnianiu (Nextcloud 21+; błąd nie przerywa).
            foreach (['displaynameScope', 'emailScope', 'phoneScope', 'addressScope', 'websiteScope', 'twitterScope'] as $k) {
                try { $admin->ocs('PUT', 'users/' . rawurlencode($uid), ['key' => $k, 'value' => 'v2-private']); } catch (\Throwable $e) {}
            }
        } catch (\Throwable $e) {
            $hint = ($e instanceof CloudApiException && in_array($e->http, [401, 403], true))
                ? ' Konto API SZO musi być administratorem chmury (php cli/seedCloudTechAccount.php --status).' : '';
            return ['ok' => false, 'msg' => 'Nie udało się założyć konta ' . $uid . ': ' . $e->getMessage() . $hint];
        }

        $app = self::appPassword($uid, $pass);
        $secret = $app !== '' ? $app : $pass;
        try {
            (new CloudApi(SsoConfig::cloudUrl(), $uid, $secret, SsoConfig::cloudKind()))->ownMkdirs(self::ncRoot());
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => 'Konto ' . $uid . ' ' . $how . ', ale zapis pliku nie działa: ' . $e->getMessage() . ' Ustawienia SZO bez zmian.'];
        }
        self::set(self::K_NC_USER, $uid);
        self::set(self::K_NC_PASS, (string)fcEncrypt($secret, 'settings.' . self::K_NC_PASS));
        self::reset();
        self::resume('nc');
        self::log('ezd.store.nc_account', ['uid' => $uid, 'action' => $how, 'app_password' => $app !== '']);
        return ['ok' => true, 'msg' => 'Konto ' . $uid . ' w chmurze: ' . $how . '. Kopie EZD idą do folderu ' . self::ncRoot() . ' na tym koncie. '
            . ($app !== '' ? 'Hasło aplikacji' : 'Hasło konta (chmura nie wydała hasła aplikacji)') . ' zapisane w SZO zaszyfrowane, nigdzie go nie wyświetlamy.'];
    }

    /** Odłącza konto (SZO przestaje wysyłać kopie do chmury). Nie rusza konta ani plików w chmurze. */
    public static function forgetAccount(): void
    {
        self::set(self::K_NC_USER, '');
        self::set(self::K_NC_PASS, '');
        self::reset();
        self::log('ezd.store.nc_account', ['action' => 'odłączono']);
    }

    /** Test konta w chmurze: zapis, odczyt i usunięcie pliku testowego. */
    public static function testNc(): string
    {
        $api = self::nc();
        $tmp = tempnam(sys_get_temp_dir(), 'ezdnc');
        $back = $tmp . '.b';
        $body = 'Test zapisu SZO ' . date('c') . "\n";
        file_put_contents($tmp, $body);
        $path = self::ncRoot() . '/.szo-test-' . bin2hex(random_bytes(4)) . '.txt';
        try {
            $api->ownMkdirs(self::ncRoot());
            $api->ownPut($path, $tmp);
            $api->ownDownload($path, $back);
            if ((string)@file_get_contents($back) !== $body) throw new RuntimeException('Plik testowy wrócił z chmury zmieniony.');
            $api->ownDelete($path);
        } finally {
            @unlink($tmp);
            @unlink($back);
        }
        self::resume('nc');
        return 'Chmura ' . SsoConfig::cloudUrl() . ', konto ' . self::ncUser() . ': zapis, odczyt i usunięcie pliku w folderze ' . self::ncRoot() . ' działają.';
    }

    public static function testFtp(): string
    {
        if (self::setting(self::K_FTP_HOST) === '') throw new RuntimeException('Najpierw wpisz i zapisz adres serwera FTP.');
        self::reset();
        $msg = self::ftp()->test();
        self::resume('ftp');
        return $msg;
    }

    /**
     * Zapis ustawień FTP z formularza. Puste hasło = bez zmiany (chyba że zaznaczono „usuń hasło”).
     * @throws EzdStorageInvalid z błędami przypisanymi do pól (host, port, user, pass, dir)
     */
    public static function saveFtp(array $in): void
    {
        $on = !empty($in['enabled']);
        $host = trim((string)($in['host'] ?? ''));
        $port = (int)($in['port'] ?? 0);
        $user = trim((string)($in['user'] ?? ''));
        $dir = trim((string)($in['dir'] ?? ''));
        $tls = (string)($in['tls'] ?? 'explicit');
        $pass = (string)($in['pass'] ?? '');
        if (!isset(EzdFtp::TLS_MODES[$tls])) $tls = 'explicit';
        if ($port <= 0) $port = $tls === 'implicit' ? 990 : 21;
        if ($dir === '') $dir = self::FTP_DIR;

        $err = [];
        if ($host !== '' && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$|^\d{1,3}(?:\.\d{1,3}){3}$|^[0-9a-f:]+$/i', $host)) {
            $err['host'] = 'Podaj samą nazwę serwera albo adres IP — bez ftp://, portu i ścieżki (np. ftp.example.pl).';
        } elseif ($on && $host === '') {
            $err['host'] = 'Aby włączyć kopie na FTP, podaj adres serwera.';
        }
        if ($port > 65535) $err['port'] = 'Port to liczba od 1 do 65535.';
        if ($on && $user === '') $err['user'] = 'Aby włączyć kopie na FTP, podaj użytkownika.';
        if (preg_match('~(^|/)\.\.(/|$)|[\r\n\0]~', $dir)) $err['dir'] = 'Katalog nie może zawierać „..” ani znaków sterujących.';
        if ($on && $pass === '' && (!empty($in['pass_clear']) || self::setting(self::K_FTP_PASS) === '')) {
            $err['pass'] = 'Aby włączyć kopie na FTP, podaj hasło użytkownika FTP.';
        }
        if ($err) throw new EzdStorageInvalid($err);

        $before = self::ftpSettings();
        self::set(self::K_FTP_ON, $on ? '1' : '0');
        self::set(self::K_FTP_HOST, $host);
        self::set(self::K_FTP_PORT, (string)$port);
        self::set(self::K_FTP_USER, $user);
        self::set(self::K_FTP_DIR, $dir);
        self::set(self::K_FTP_TLS, $tls);
        self::set(self::K_FTP_PASSIVE, !empty($in['passive']) ? '1' : '0');
        $passChanged = false;
        if (!empty($in['pass_clear'])) { self::set(self::K_FTP_PASS, ''); $passChanged = true; }
        elseif ($pass !== '') { self::set(self::K_FTP_PASS, (string)fcEncrypt($pass, 'settings.' . self::K_FTP_PASS)); $passChanged = true; }
        self::reset();
        self::resume('ftp');
        $after = self::ftpSettings();
        $changed = array_keys(array_filter($after, fn($v, $k) => $k !== 'has_pass' && $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        if ($passChanged) $changed[] = 'hasło';
        self::log('ezd.store.ftp_settings', ['zmiany' => $changed, 'enabled' => $on]);
    }

    // ── Dziennik ─────────────────────────────────────────────────────────────────────────────

    /** Wpis w dzienniku zdarzeń (audit_logs) i w logu PHP. Bez haseł — komunikaty cURL/chmury ich nie zawierają. */
    public static function log(string $action, array $details): void
    {
        error_log('[ezd_store] ' . $action . ' ' . json_encode($details, JSON_UNESCAPED_UNICODE));
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log($action, $details);
        } catch (\Throwable $e) {}
    }
}
