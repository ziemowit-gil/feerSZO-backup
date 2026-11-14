<?php
declare(strict_types=1);

/**
 * modules/ezdStorage/lib/EzdSpMigrator.php — migrator plików EZD z SharePointa do chmury Nextcloud (konto ezdmigracja)
 * i na FTP ze ścieżkami jak w bibliotece SharePoint (polecenie 2026-11-14: „zrób też migrator EZD z sharepointa
 * zachowując ścieżki”).
 *
 * Bierze pliki EZD, które mają kopię na SharePoincie (ezd_zalaczniki.sp_drive_id / sp_item_id — dawna automatyczna
 * kopia i „Otwórz w Office Online”). Ścieżka = miejsce pliku w bibliotece (parentReference.path + name) względem folderu
 * bazowego SZO (sp_base_folder): {folder EZD w chmurze}/{ta sama ścieżka} i {katalog FTP}/{ta sama ścieżka}.
 *  - Pliku nie ma na dysku serwera → odtwarzamy go z SharePointa.
 *  - Treść na SharePoincie inna niż na dysku (dysk to wersja, którą widzi EZD) → pod tę samą ścieżkę idzie plik z dysku;
 *    treść z SharePointa zostaje w wersjach pliku w Nextcloud.
 *  - Kilka plików EZD z tym samym elementem SharePointa (wersje o tej samej nazwie) → z SharePointa idzie najnowszy,
 *    starsze wysyła zwykła kopia z dysku pod nazwą z [id].
 *  - Wcześniejsza kopia z dysku pod inną ścieżką (np. koszulka zmieniła tytuł po wysłaniu na SharePoint) — usuwana,
 *    żeby plik nie leżał w dwóch miejscach.
 * Uruchamia: agent CRON ezd_store_sync (przed dosyłaniem z dysku), przycisk na ekranie kopii, cli/ezdSpMigrate.php.
 * Ustawienie ezd_store_sp_mode = off wyłącza migrację. SharePoint niedostępny (token, sieć, uprawnienia) = przerwa 5 min,
 * a w przerwie pliki czekające na migrację idą zwykłą kopią z dysku — po wyłączeniu Microsoft 365 nic nie utknie.
 */
require_once __DIR__ . '/EzdStorage.php';

/** SharePoint / Graph niedostępny jako całość (token, sieć, uprawnienia) — przerwa w migracji. */
final class EzdSpUnavailable extends RuntimeException {}

interface EzdSpSource
{
    /** Metadane elementu (driveItem: name, size, parentReference.path) albo null, gdy go nie ma. */
    public function item(string $drive, string $id): ?array;

    /** Zapisuje treść elementu do pliku; zwraca liczbę bajtów. */
    public function download(string $drive, string $id, string $toFile): int;
}

/** Źródło: Microsoft Graph (includes/m365.php). */
final class EzdSpGraphSource implements EzdSpSource
{
    private ?M365Graph $g = null;

    public static function configured(): bool
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/m365.php';
            return (new M365Graph())->is_configured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function g(): M365Graph
    {
        if ($this->g) return $this->g;
        require_once dirname(__DIR__, 3) . '/includes/m365.php';
        $g = new M365Graph();
        if (!$g->is_configured()) throw new EzdSpUnavailable('Microsoft 365 (Graph) nie jest skonfigurowany.');
        return $this->g = $g;
    }

    public function item(string $drive, string $id): ?array
    {
        try {
            return $this->g()->sp_item_by_id($drive, $id);
        } catch (EzdSpUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EzdSpUnavailable('SharePoint: ' . $e->getMessage());
        }
    }

    public function download(string $drive, string $id, string $toFile): int
    {
        $data = $this->g()->sp_download_file($drive, $id);
        if (file_put_contents($toFile, $data) === false) throw new RuntimeException('Nie można zapisać pliku tymczasowego.');
        return strlen($data);
    }
}

final class EzdSpMigrator
{
    public const K_MODE = 'ezd_store_sp_mode';   // '' = działa, 'off' = wyłączona

    private static ?EzdSpSource $src = null;

    /** Do testów: podmiana źródła; null = Microsoft Graph. */
    public static function setSource(?EzdSpSource $s): void
    {
        self::$src = $s;
    }

    private static function src(): EzdSpSource
    {
        return self::$src ??= new EzdSpGraphSource();
    }

    public static function enabled(): bool
    {
        return EzdStorage::setting(self::K_MODE) !== 'off';
    }

    public static function setEnabled(bool $on): void
    {
        org_setting_set(self::K_MODE, $on ? '' : 'off');
        if ($on) EzdStorage::resume('sp');
        EzdStorage::log('ezd.store.sp_mode', ['enabled' => $on]);
    }

    /** Microsoft 365 skonfigurowany (bez łączenia z Graph). */
    public static function sourceConfigured(): bool
    {
        return self::$src && !(self::$src instanceof EzdSpGraphSource) ? true : EzdSpGraphSource::configured();
    }

    /** Migracja działa teraz: włączona, jest cel kopii, SharePoint bez przerwy, Microsoft 365 skonfigurowany. */
    public static function active(): bool
    {
        return self::enabled() && (EzdStorage::ncConfigured() || EzdStorage::ftpConfigured()) && !EzdStorage::paused('sp')
            && self::sourceConfigured();
    }

    /** Warunek SQL (aliasy z = ezd_zalaczniki, s = ezd_store_files): plik czeka na migrację z SharePointa. */
    public static function awaitingSql(): string
    {
        return "COALESCE(z.sp_item_id,'') <> '' AND COALESCE(z.sp_drive_id,'') <> '' AND s.sp_at IS NULL AND COALESCE(s.sp_tries,0) < " . EzdStorage::MAX_TRIES;
    }

    public static function base(): string
    {
        return trim(EzdStorage::setting('sp_base_folder'), "/ \t");
    }

    /** Ścieżka elementu w bibliotece: pełna (od korzenia) i względna (bez folderu bazowego SZO, gdy w nim leży). */
    public static function paths(array $item): array
    {
        $parent = (string)($item['parentReference']['path'] ?? '');
        $folder = preg_match('~root:(.*)$~', $parent, $m) ? $m[1] : '';
        if (preg_match('/%[0-9A-Fa-f]{2}/', $folder)) $folder = rawurldecode($folder);   // Graph podaje ścieżkę zakodowaną
        $clean = fn(string $p) => implode('/', array_filter(explode('/', str_replace(["\r", "\n", "\0", '\\'], '', $p)),
                                                             fn($s) => $s !== '' && $s !== '.' && $s !== '..'));
        $full = $clean($folder . '/' . (string)($item['name'] ?? ''));
        $base = self::base();
        $rel = $base !== '' && str_starts_with($full . '/', $base . '/') ? ltrim(substr($full, strlen($base)), '/') : $full;
        return ['full' => $full, 'rel' => $rel];
    }

    /** Pliki czekające na migrację (najstarsze najpierw). */
    public static function candidates(int $limit, bool $all = false): array
    {
        EzdStorage::migrate();
        $cond = $all ? "COALESCE(z.sp_item_id,'') <> '' AND COALESCE(z.sp_drive_id,'') <> '' AND s.sp_at IS NULL" : self::awaitingSql();
        return db_all("SELECT z.* FROM ezd_zalaczniki z LEFT JOIN ezd_store_files s ON s.zal_id = z.id WHERE {$cond} ORDER BY z.id LIMIT " . max(1, $limit));
    }

    /**
     * Przenosi jeden plik. Wynik: ok | older (starsza wersja — pójdzie z dysku) | missing (nie ma go na SharePoincie)
     * | error (błąd tego pliku) | paused (cel kopii w przerwie). SharePoint niedostępny → EzdSpUnavailable.
     */
    public static function migrateOne(array $z): string
    {
        $id = (int)$z['id'];
        $now = date('Y-m-d H:i:s');
        $row = EzdStorage::row($id) ?? [];
        if (db_one("SELECT 1 FROM ezd_zalaczniki WHERE sp_drive_id=? AND sp_item_id=? AND id>? LIMIT 1", [$z['sp_drive_id'], $z['sp_item_id'], $id])) {
            EzdStorage::save($id, ['sp_at' => $now, 'sp_error' => '', 'sp_path' => '']);
            return 'older';
        }
        $item = self::src()->item((string)$z['sp_drive_id'], (string)$z['sp_item_id']);
        if (!$item || empty($item['name']) || isset($item['folder'])) {
            EzdStorage::save($id, ['sp_at' => $now, 'sp_error' => 'Nie ma tego pliku na SharePoincie — kopia pójdzie z dysku.']);
            EzdStorage::log('ezd.store.sp_missing', ['zal_id' => $id]);
            return 'missing';
        }
        $p = self::paths($item);
        if ($p['rel'] === '') {
            EzdStorage::save($id, ['sp_at' => $now, 'sp_error' => 'Nie udało się ustalić ścieżki na SharePoincie.']);
            return 'missing';
        }
        $tmp = (string)tempnam(sys_get_temp_dir(), 'ezdsp');
        try {
            try {
                self::src()->download((string)$z['sp_drive_id'], (string)$z['sp_item_id'], $tmp);
            } catch (EzdSpUnavailable $e) {
                throw $e;
            } catch (\Throwable $e) {
                EzdStorage::save($id, ['sp_error' => mb_substr('Pobranie z SharePointa: ' . $e->getMessage(), 0, 500), 'sp_tries' => (int)($row['sp_tries'] ?? 0) + 1]);
                EzdStorage::log('ezd.store.error', ['zal_id' => $id, 'target' => 'sharepoint', 'error' => $e->getMessage()]);
                return 'error';
            }
            $sha = (string)hash_file('sha256', $tmp);

            // Brak pliku na dysku serwera → odtwarzamy go z SharePointa.
            $local = EzdStorage::localPath($z);
            $restored = false;
            if (!is_file($local)) {
                if (!is_dir(dirname($local))) @mkdir(dirname($local), 0755, true);
                if (@copy($tmp, $local)) {
                    $restored = true;
                    EzdStorage::log('ezd.store.restored', ['zal_id' => $id, 'from' => 'sharepoint']);
                }
            }

            // Ponowna próba tego samego pliku: cele, które już mają tę treść pod tą ścieżką, pomijamy.
            $skip = [];
            if (($row['rel_path'] ?? '') === $p['rel'] && ($row['sha256'] ?? '') === $sha) {
                if (!empty($row['nc_at'])) $skip[] = 'nc';
                if (!empty($row['ftp_at'])) $skip[] = 'ftp';
            }
            [$r, $u, $failed] = EzdStorage::sendCopies($id, $p['rel'], $tmp, $skip);
            $allOk = !in_array('error', $r, true) && !in_array('paused', $r, true);
            $upd = ['rel_path' => $p['rel'], 'sha256' => $sha, 'size' => (int)filesize($tmp), 'sp_path' => $p['full']] + $u;
            if ($restored) $upd['local_missing'] = 0;
            foreach (['nc', 'ftp'] as $t) {
                // cel z błędem / w przerwie: dotychczasowa kopia (inna ścieżka albo treść) przestaje się liczyć
                if (in_array($r[$t], ['error', 'paused'], true)) $upd[$t . '_at'] = null;
            }
            if ($allOk) {
                $upd += ['sp_at' => $now, 'sp_error' => '', 'sp_tries' => 0];
            } else {
                $upd['sp_error'] = mb_substr(trim(($u['nc_error'] ?? '') . ' ' . ($u['ftp_error'] ?? '')) ?: 'Cel kopii w przerwie.', 0, 500);
                if ($failed) $upd['sp_tries'] = (int)($row['sp_tries'] ?? 0) + 1;
            }
            EzdStorage::save($id, $upd);

            // Wcześniejsza kopia z dysku pod inną ścieżką — usuwamy (plik ma leżeć tam, gdzie był na SharePoincie).
            foreach (['nc', 'ftp'] as $t) {
                $old = (string)($row[$t . '_path'] ?? '');
                if (isset($u[$t . '_path']) && $old !== '' && !empty($row[$t . '_at']) && $old !== $u[$t . '_path']) EzdStorage::dropCopy($t, $old, $id);
            }
            if (!$allOk) return $failed ? 'error' : 'paused';
            EzdStorage::releaseOlder($id, $p['rel']);
            // Na dysku inna treść niż na SharePoincie → pod tę samą ścieżkę idzie plik z dysku (wersja, którą widzi EZD).
            if (!$restored && is_file($local) && !hash_equals($sha, (string)hash_file('sha256', $local))) EzdStorage::push($id);
            return 'ok';
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Porcja migracji: do $limit plików albo $seconds sekund. SharePoint niedostępny → przerwa 5 min i koniec porcji.
     * @return array{files:int, ok:int, older:int, missing:int, errors:int, left:int, stopped:string}
     */
    public static function run(int $limit = 200, int $seconds = 240, bool $all = false): array
    {
        $out = ['files' => 0, 'ok' => 0, 'older' => 0, 'missing' => 0, 'errors' => 0, 'left' => 0, 'stopped' => ''];
        if (!self::enabled()) { $out['stopped'] = 'Migracja z SharePointa jest wyłączona.'; return $out; }
        if (!EzdStorage::ncConfigured() && !EzdStorage::ftpConfigured()) { $out['stopped'] = 'Brak celu kopii (chmura / FTP).'; return $out; }
        if (!self::sourceConfigured()) { $out['stopped'] = 'Microsoft 365 nie jest skonfigurowany.'; return $out; }
        if ($all) EzdStorage::resume('sp');
        if (EzdStorage::paused('sp')) { $out['stopped'] = 'SharePoint niedostępny — przerwa do ' . date('H:i', EzdStorage::pausedUntil('sp')) . '.'; return $out; }
        $stop = time() + $seconds;
        foreach (self::candidates($limit, $all) as $z) {
            if (time() >= $stop) break;
            try {
                $st = self::migrateOne($z);
            } catch (EzdSpUnavailable $e) {
                EzdStorage::pause('sp', $e->getMessage());
                $out['stopped'] = $e->getMessage();
                break;
            } catch (\Throwable $e) {
                error_log('[ezd_store] sp ' . $z['id'] . ': ' . $e->getMessage());
                $st = 'error';
            }
            $out['files']++;
            if (isset($out[$st])) $out[$st]++;
            elseif ($st === 'error') $out['errors']++;
            if ($st === 'paused') { $out['stopped'] = 'Cel kopii w przerwie.'; break; }
        }
        $out['left'] = count(self::candidates(100000, $all));
        return $out;
    }

    /** Liczby do ekranu i CLI. */
    public static function stats(): array
    {
        EzdStorage::migrate();
        $all = (int)(db_one("SELECT COUNT(*) c FROM ezd_zalaczniki WHERE COALESCE(sp_item_id,'') <> '' AND COALESCE(sp_drive_id,'') <> ''")['c'] ?? 0);
        $s = db_one("SELECT SUM(CASE WHEN s.sp_at IS NOT NULL AND s.sp_path <> '' THEN 1 ELSE 0 END) moved,
                            SUM(CASE WHEN s.sp_at IS NOT NULL AND s.sp_path = '' AND s.sp_error = '' THEN 1 ELSE 0 END) older,
                            SUM(CASE WHEN s.sp_at IS NOT NULL AND s.sp_error <> '' THEN 1 ELSE 0 END) missing,
                            SUM(CASE WHEN s.sp_at IS NULL AND s.sp_error <> '' THEN 1 ELSE 0 END) errors
                     FROM ezd_zalaczniki z JOIN ezd_store_files s ON s.zal_id = z.id
                     WHERE COALESCE(z.sp_item_id,'') <> '' AND COALESCE(z.sp_drive_id,'') <> ''") ?: [];
        return ['all' => $all, 'moved' => (int)($s['moved'] ?? 0), 'older' => (int)($s['older'] ?? 0), 'missing' => (int)($s['missing'] ?? 0),
                'errors' => (int)($s['errors'] ?? 0), 'left' => count(self::candidates(100000)), 'enabled' => self::enabled(),
                'configured' => self::sourceConfigured(), 'paused' => EzdStorage::paused('sp') ? EzdStorage::pausedUntil('sp') : 0];
    }
}
