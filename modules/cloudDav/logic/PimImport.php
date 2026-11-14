<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/GraphPim.php';
require_once dirname(__DIR__) . '/lib/PimIcal.php';
require_once dirname(__DIR__) . '/lib/PimVcard.php';
require_once dirname(__DIR__) . '/lib/NcPim.php';
require_once dirname(__DIR__, 2) . '/sso_logto/logic/SsoAccounts.php';
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';

/**
 * modules/cloudDav/logic/PimImport.php — kalendarze i kontakty z Microsoft 365 do chmury Nextcloud (polecenie 2026-11-14:
 * „zrób po API graph import kalendarzy i kontaktów z MS365”). Microsoft 365 wyłączamy 20.11.2026, więc zadanie ma dwie fazy:
 *
 *   1) export — Graph (app-only, Calendars.Read, Contacts.Read) → pliki .ics (jeden na kalendarz) i .vcf, zaszyfrowane fcEncrypt
 *      w uploads/pim_m365/{losowy katalog}/ (własny .htaccess „deny all”). Kopia przetrwa wyłączenie Microsoft 365, więc import
 *      można powtórzyć później, np. w produkcyjnej chmurze w grudniu;
 *   2) import — do chmury po stronie serwera (NcPim: occ calendar:import i szoNcPim.php), bez haseł użytkowników: kalendarze jako
 *      nowe kalendarze „Outlook: {nazwa}” (domyślny: „Kalendarz z Outlooka”, uri outlook, outlook-2…), kontakty do nowej książki
 *      „Kontakty z Outlooka” (uri outlook). Ponowny import nadpisuje te same wydarzenia i karty (UID), niczego nie dubluje.
 *
 * Konto w chmurze: sso_accounts po adresie konta Microsoft albo po users.m365_login → e-mail. Brak konta w chmurze = kopia czeka
 * (status waiting, ponowienie co RETRY_HOURS), import ruszy sam, gdy konto powstanie. Niedostępność Microsoftu albo chmury → waiting.
 * Agent cron/agents/cloud_pim.php co 5 min, porcja ~4 min, stan w state_json (wznawianie bez powtórek).
 */
final class PimImport
{
    public const RETRY_HOURS = 6;
    public const MAX_ATTEMPTS = 28;
    public const KEEP_DAYS = 120;              // kopia (pliki) po udanym imporcie
    public const SERIES_PAST_YEARS = 3;        // okno wystąpień serii do wyjątków i EXDATE
    public const SERIES_FUTURE_YEARS = 3;
    public const ADDRESSBOOK_URI = 'outlook';
    public const ACTIVE = ['queued', 'running', 'waiting'];
    public const LABELS = ['queued' => 'w kolejce', 'running' => 'w toku', 'waiting' => 'czeka', 'done' => 'gotowe', 'error' => 'błąd', 'cancelled' => 'anulowane'];
    private const CTX = 'pim_jobs.file';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('cloud_pim_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS pim_jobs (
            id INTEGER PRIMARY KEY,
            upn VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '',
            cloud_uid VARCHAR(128) NOT NULL DEFAULT '',
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'queued',
            phase VARCHAR(12) NOT NULL DEFAULT 'export',
            dir VARCHAR(64) NOT NULL DEFAULT '',
            state_json TEXT NULL,
            calendars INTEGER NOT NULL DEFAULT 0,
            events INTEGER NOT NULL DEFAULT 0,
            contacts INTEGER NOT NULL DEFAULT 0,
            imported_events INTEGER NOT NULL DEFAULT 0,
            imported_contacts INTEGER NOT NULL DEFAULT 0,
            failed INTEGER NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            next_run_at VARCHAR(19) NULL,
            locked_until VARCHAR(19) NULL,
            exported_at VARCHAR(19) NULL,
            imported_at VARCHAR(19) NULL,
            finished_at VARCHAR(19) NULL,
            updated_at VARCHAR(19) NULL,
            last_error TEXT NULL,
            log TEXT NULL)");
        try { db()->exec("CREATE UNIQUE INDEX idx_pim_jobs_upn ON pim_jobs(upn)"); } catch (\Throwable $e) {}
        try { db()->exec("CREATE INDEX idx_pim_jobs_status ON pim_jobs(status, next_run_at)"); } catch (\Throwable $e) {}
    }

    /**
     * Wyłącznik (settings.cloud_pim_enabled = '1'). Domyślnie wyłączone: agent nic nie robi, karta dla użytkowników się nie pokazuje.
     * Włączenie oznacza zapis do chmury na produkcji (occ), więc robi je administrator na /kalendarz-kontakty/m365 po próbie na jednym koncie.
     * Jednorazowa próba bez włączania: php cli/cloudPimImport.php --upn … --run --force.
     */
    public static function enabled(): bool
    {
        return (function_exists('org_setting') && org_setting('cloud_pim_enabled') === '1') || (defined('PIM_FORCE') && PIM_FORCE);
    }

    public static function exportAvailable(): bool { return GraphPim::configured(); }
    public static function importAvailable(): bool { return SsoConfig::cloudConfigured() && NcPim::available(); }

    /** Nowe zadanie albo ponowienie istniejącego dla konta Microsoft (od kopii; stare pliki zastąpi nowa kopia). */
    public static function queue(string $upn, string $email, string $by): int
    {
        self::migrate();
        $upn = strtolower(trim($upn));
        if (!filter_var($upn, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Nieprawidłowy adres konta Microsoft: ' . $upn);
        $now = date('Y-m-d H:i:s');
        $old = db_one("SELECT * FROM pim_jobs WHERE upn=?", [$upn]);
        if ($old) {
            if (in_array($old['status'], ['queued', 'running'], true)) return (int)$old['id'];
            db_exec("UPDATE pim_jobs SET status='queued', phase='export', state_json=NULL, attempts=0, next_run_at=?, locked_until=NULL, last_error=NULL,
                     email=CASE WHEN ?<>'' THEN ? ELSE email END, created_by=?, updated_at=? WHERE id=?", [$now, $email, strtolower($email), $by, $now, (int)$old['id']]);
            return (int)$old['id'];
        }
        db_exec("INSERT INTO pim_jobs (upn, email, created_by, created_at, status, phase, dir, next_run_at, updated_at) VALUES (?,?,?,?, 'queued', 'export', ?, ?, ?)",
                [$upn, strtolower($email), $by, $now, bin2hex(random_bytes(12)), $now, $now]);
        return (int)db()->lastInsertId();
    }

    /**
     * Zadania dla wszystkich kont Microsoft 365 z adresem (kopia przed wyłączeniem), bez ruszania trwających i zakończonych.
     * $accounts — lista adresów (z M365Graph::list_all_users) albo null = pobierz. Zwraca liczbę nowych zadań.
     */
    public static function queueAll(string $by, ?array $accounts = null): int
    {
        self::migrate();
        if ($accounts === null) {
            $accounts = [];
            foreach ((new M365Graph())->list_all_users() as $u) {
                $upn = strtolower((string)($u['userPrincipalName'] ?? ''));
                if ($upn !== '' && !str_contains($upn, '#ext#') && ($u['accountEnabled'] ?? true) !== false) $accounts[] = $upn;
            }
        }
        $n = 0;
        foreach (array_unique($accounts) as $upn) {
            if (db_one("SELECT 1 FROM pim_jobs WHERE upn=?", [strtolower((string)$upn)])) continue;
            try { self::queue((string)$upn, '', $by); $n++; } catch (\Throwable $e) {}
        }
        return $n;
    }

    public static function job(int $id): ?array { self::migrate(); return db_one("SELECT * FROM pim_jobs WHERE id=?", [$id]) ?: null; }
    public static function forUpn(string $upn): ?array { self::migrate(); return db_one("SELECT * FROM pim_jobs WHERE upn=?", [strtolower(trim($upn))]) ?: null; }
    public static function jobs(int $limit = 500): array { self::migrate(); return db_all("SELECT * FROM pim_jobs ORDER BY CASE status WHEN 'running' THEN 0 WHEN 'error' THEN 1 WHEN 'waiting' THEN 2 WHEN 'queued' THEN 3 ELSE 4 END, updated_at DESC LIMIT " . (int)$limit); }

    /** @return array<string,int> liczba zadań wg statusu */
    public static function counts(): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT status, COUNT(*) AS n FROM pim_jobs GROUP BY status") as $r) $out[(string)$r['status']] = (int)$r['n'];
        return $out;
    }

    public static function cancel(int $id): bool
    {
        self::migrate();
        db_exec("UPDATE pim_jobs SET status='cancelled', locked_until=NULL, updated_at=? WHERE id=? AND status IN ('queued','running','waiting')", [date('Y-m-d H:i:s'), $id]);
        return (bool)db_one("SELECT 1 FROM pim_jobs WHERE id=? AND status='cancelled'", [$id]);
    }

    /** Ponów sam import z istniejącej kopii (np. po założeniu konta w chmurze albo w nowej chmurze). */
    public static function reimport(int $id, string $by): bool
    {
        $j = self::job($id);
        if (!$j || empty($j['exported_at'])) return false;
        $st = json_decode((string)$j['state_json'], true) ?: [];
        unset($st['imp']);
        db_exec("UPDATE pim_jobs SET status='queued', phase='import', state_json=?, attempts=0, next_run_at=?, locked_until=NULL, last_error=NULL, created_by=?, updated_at=? WHERE id=?",
                [json_encode($st, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $by, date('Y-m-d H:i:s'), $id]);
        return true;
    }

    /** Agent: zadania do wykonania w budżecie sekund. @return list<string> */
    public static function runDue(int $budget = 240): array
    {
        self::migrate();
        if (!self::enabled()) return [];
        $deadline = time() + $budget;
        $lines = [];
        self::cleanup();
        self::promoteWaiting();
        while (time() < $deadline - 10) {
            $now = date('Y-m-d H:i:s');
            $j = db_one("SELECT id FROM pim_jobs WHERE status IN ('queued','waiting','running') AND (next_run_at IS NULL OR next_run_at<=?)
                         AND (locked_until IS NULL OR locked_until<?) ORDER BY CASE phase WHEN 'export' THEN 0 ELSE 1 END, next_run_at, id LIMIT 1", [$now, $now]);
            if (!$j) break;
            $lines[] = self::runJob((int)$j['id'], $deadline);
        }
        return $lines;
    }

    /**
     * Gotowa kopia czeka na konto w chmurze (status waiting, faza import): gdy konto już jest, import rusza w tym przebiegu,
     * zamiast czekać RETRY_HOURS. Same odczyty bazy, bez Microsoftu i chmury.
     */
    public static function promoteWaiting(): int
    {
        $n = 0;
        $now = date('Y-m-d H:i:s');
        foreach (db_all("SELECT * FROM pim_jobs WHERE status='waiting' AND phase='import' AND exported_at IS NOT NULL AND next_run_at>?", [$now]) as $j) {
            if (self::cloudUid($j) === '') continue;
            db_exec("UPDATE pim_jobs SET next_run_at=?, updated_at=? WHERE id=?", [$now, $now, (int)$j['id']]);
            $n++;
        }
        return $n;
    }

    public static function runJob(int $id, int $deadline): string
    {
        $job = self::job($id);
        if (!$job) return "#{$id}: brak zadania";
        db_exec("UPDATE pim_jobs SET status='running', locked_until=?, updated_at=? WHERE id=?", [date('Y-m-d H:i:s', $deadline + 300), date('Y-m-d H:i:s'), $id]);
        $st = json_decode((string)$job['state_json'], true) ?: [];
        try {
            if ($job['phase'] === 'export') {
                if (!self::exportAvailable()) return self::wait($id, $job, false, 'Microsoft 365 nie jest skonfigurowany w SZO.');
                $done = self::export($job, $st, $deadline);
                if (!$done) return self::progress($id, $st, "#{$id} {$job['upn']}: kopia w toku");
                $job = self::job($id);
                $st = json_decode((string)$job['state_json'], true) ?: [];
            }
            if (!self::importAvailable()) return self::wait($id, $job, false, 'Chmura nie jest dostępna na tym serwerze: ' . (NcPim::diagnose() ?: 'brak konfiguracji chmury') . ' Kopia jest gotowa.');
            $uid = self::cloudUid($job);
            if ($uid === '') return self::wait($id, $job, false, 'Brak konta w chmurze dla ' . $job['upn'] . '. Import ruszy, gdy konto powstanie. Kopia jest gotowa.');
            if ($uid !== $job['cloud_uid']) db_exec("UPDATE pim_jobs SET cloud_uid=? WHERE id=?", [$uid, $id]);
            $done = self::import($job, $uid, $st, $deadline);
            if (!$done) return self::progress($id, $st, "#{$id} {$job['upn']}: import w toku");
            db_exec("UPDATE pim_jobs SET status='done', phase='done', state_json=?, imported_at=?, finished_at=?, locked_until=NULL, next_run_at=NULL, last_error=NULL, updated_at=? WHERE id=?",
                    [json_encode($st, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            self::audit('cloud.pim.done', $job, "kalendarze {$job['calendars']}, wydarzenia {$st['imp']['events']}, kontakty {$st['imp']['contacts']} → {$uid}");
            return "#{$id} {$job['upn']}: gotowe ({$uid})";
        } catch (PimGraphGone $e) {
            return self::finish($id, 'error', 'Nie ma takiego konta w Microsoft 365 albo nie ma ono skrzynki (' . $e->getMessage() . ').');
        } catch (PimGraphDenied $e) {
            return self::finish($id, 'error', $e->getMessage());
        } catch (PimGraphUnavailable $e) {
            return self::wait($id, self::job($id) ?? $job, false, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[cloud_pim] #' . $id . ': ' . $e->getMessage());
            return self::wait(self::saveState($id, $st), self::job($id) ?? $job, false, $e->getMessage());
        }
    }

    // ── faza 1: kopia z Microsoft 365 ────────────────────────────────────────

    /** true = kopia kompletna (pliki zaszyfrowane, phase=import). */
    private static function export(array $job, array &$st, int $deadline): bool
    {
        $g = new GraphPim();
        $upn = (string)$job['upn'];
        $dir = self::dir($job);
        if (!isset($st['cals'])) {
            $cals = [];
            $all = $g->calendars($upn);
            // właściciel = właściciel kalendarza domyślnego (adres skrzynki bywa inny niż UPN); kalendarze udostępnione przez innych
            // zostają u ich właścicieli, nie kopiujemy ich drugi raz
            $self = '';
            foreach ($all as $c) if ($c['default']) { $self = $c['owner']; break; }
            foreach ($all as $c) {
                if ($self !== '' && $c['owner'] !== '' && $c['owner'] !== $self && $c['owner'] !== $upn) continue;
                $cals[] = $c + ['n' => count($cals) + 1, 'events' => 0, 'done' => false, 'next' => null];
            }
            usort($cals, fn($a, $b) => [!$a['default'], $a['n']] <=> [!$b['default'], $b['n']]);
            foreach ($cals as $k => &$c) $c['n'] = $k + 1;
            unset($c);
            $st = ['cals' => $cals, 'ab' => ['folders' => null, 'fi' => 0, 'next' => null, 'count' => 0, 'done' => false]];
            foreach (glob($dir . '*') ?: [] as $f) @unlink($f);   // poprzednia kopia tej osoby
            self::saveState((int)$job['id'], $st);
        }
        $tz = new \DateTimeZone(PimIcal::TZ);
        $winFrom = (new \DateTimeImmutable('now', $tz))->modify('-' . self::SERIES_PAST_YEARS . ' years');
        $winTo = (new \DateTimeImmutable('now', $tz))->modify('+' . self::SERIES_FUTURE_YEARS . ' years');
        foreach ($st['cals'] as &$c) {
            if ($c['done']) continue;
            $part = $dir . 'cal-' . $c['n'] . '.part';
            do {
                $page = $g->events($upn, $c['id'], $c['next']);
                $buf = '';
                foreach ($page['items'] as $ev) {
                    $inst = [];
                    if (($ev['type'] ?? '') === 'seriesMaster' && !empty($ev['recurrence'])) {
                        $s = PimIcal::dt((array)$ev['start']);
                        $from = $s > $winFrom ? $s : $winFrom;
                        $inst = $g->instances($upn, (string)$ev['id'], $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
                                              $winTo->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'));
                        $buf .= PimIcal::events($ev, $inst, $from, $winTo);
                    } else {
                        $buf .= PimIcal::events($ev);
                    }
                    $c['events']++;
                }
                if ($buf !== '') file_put_contents($part, $buf, FILE_APPEND | LOCK_EX);
                $c['next'] = $page['next'];
                self::saveState((int)$job['id'], $st);
                if ($c['next'] && time() > $deadline - 20) return false;
            } while ($c['next']);
            $name = $c['default'] ? 'Kalendarz z Outlooka' : 'Outlook: ' . ($c['name'] !== '' ? $c['name'] : 'kalendarz ' . $c['n']);
            $ics = PimIcal::calendar($name, is_file($part) ? (string)file_get_contents($part) : '');
            self::writeEnc($dir . 'cal-' . $c['n'] . '.ics.enc', $ics);
            @unlink($part);
            $c['done'] = true;
            $c['name_nc'] = $name;
            self::saveState((int)$job['id'], $st);
            if (time() > $deadline - 20) return false;
        }
        unset($c);
        $ab = &$st['ab'];
        if (!$ab['done']) {
            if ($ab['folders'] === null) { $ab['folders'] = $g->contactFolders($upn); self::saveState((int)$job['id'], $st); }
            $part = $dir . 'contacts.part';
            while ($ab['fi'] < count($ab['folders'])) {
                $f = $ab['folders'][$ab['fi']];
                $page = $g->contacts($upn, (string)$f['id'], $ab['next']);
                $buf = '';
                foreach ($page['items'] as $ct) { $buf .= PimVcard::card($ct, (string)$f['name']); $ab['count']++; }
                if ($buf !== '') file_put_contents($part, $buf, FILE_APPEND | LOCK_EX);
                $ab['next'] = $page['next'];
                if (!$ab['next']) $ab['fi']++;
                self::saveState((int)$job['id'], $st);
                if (time() > $deadline - 20) return false;
            }
            if ($ab['count'] > 0) self::writeEnc($dir . 'contacts.vcf.enc', (string)file_get_contents($part));
            @unlink($part);
            $ab['done'] = true;
        }
        unset($ab);
        $events = array_sum(array_column($st['cals'], 'events'));
        db_exec("UPDATE pim_jobs SET phase='import', state_json=?, calendars=?, events=?, contacts=?, exported_at=?, attempts=0, updated_at=? WHERE id=?",
                [json_encode($st, JSON_UNESCAPED_UNICODE), count($st['cals']), $events, (int)$st['ab']['count'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$job['id']]);
        self::audit('cloud.pim.export', $job, 'kalendarze ' . count($st['cals']) . ", wydarzenia {$events}, kontakty " . (int)$st['ab']['count']);
        return true;
    }

    // ── faza 2: import do chmury ─────────────────────────────────────────────

    private static function import(array $job, string $uid, array &$st, int $deadline): bool
    {
        $dir = self::dir($job);
        $st['imp'] = ($st['imp'] ?? []) + ['cal' => 0, 'events' => 0, 'contacts' => 0, 'ab' => false, 'notes' => []];
        $imp = &$st['imp'];
        foreach ($st['cals'] ?? [] as $i => $c) {
            if ($i < $imp['cal']) continue;
            $enc = $dir . 'cal-' . $c['n'] . '.ics.enc';
            if ((int)$c['events'] > 0 && is_file($enc)) {
                $uri = $c['n'] === 1 ? 'outlook' : 'outlook-' . $c['n'];
                NcPim::ensureCalendar($uid, $uri, (string)($c['name_nc'] ?? 'Kalendarz z Outlooka'), self::color((string)($c['color'] ?? '')));
                $tmp = self::plainTemp($dir, $enc);
                try { $r = NcPim::importIcs($uid, $uri, $tmp); } finally { @unlink($tmp); }
                $imp['events'] += (int)$c['events'];
                $imp['notes'][] = $uri . ': ' . mb_substr(trim((string)$r['summary']), 0, 300);
            }
            $imp['cal'] = $i + 1;
            self::saveState((int)$job['id'], $st);
            if (time() > $deadline - 30) return false;
        }
        if (!$imp['ab']) {
            $enc = $dir . 'contacts.vcf.enc';
            if (is_file($enc)) {
                NcPim::ensureAddressbook($uid, self::ADDRESSBOOK_URI, 'Kontakty z Outlooka');
                $tmp = self::plainTemp($dir, $enc);
                try { $r = NcPim::importVcards($uid, self::ADDRESSBOOK_URI, $tmp); } finally { @unlink($tmp); }
                $imp['contacts'] = $r['created'] + $r['updated'];
                db_exec("UPDATE pim_jobs SET failed=? WHERE id=?", [(int)$r['failed'], (int)$job['id']]);
                if ($r['errors']) $imp['notes'][] = 'kontakty: ' . implode('; ', $r['errors']);
            }
            $imp['ab'] = true;
        }
        db_exec("UPDATE pim_jobs SET imported_events=?, imported_contacts=?, log=? WHERE id=?",
                [(int)$imp['events'], (int)$imp['contacts'], mb_substr(implode("\n", $imp['notes']), 0, 4000), (int)$job['id']]);
        unset($imp);
        return true;
    }

    // ── pomocnicze ───────────────────────────────────────────────────────────

    /** Login w chmurze dla konta Microsoft: sso_accounts po adresie, po users.m365_login albo po e-mailu z zadania. */
    public static function cloudUid(array $job): string
    {
        SsoAccounts::migrate();
        foreach (array_filter([(string)$job['upn'], (string)$job['email']]) as $mail) {
            $r = db_one("SELECT cloud_uid FROM sso_accounts WHERE email=? AND COALESCE(cloud_uid,'')<>''", [strtolower($mail)]);
            if ($r) return (string)$r['cloud_uid'];
        }
        $u = db_one("SELECT email FROM users WHERE LOWER(COALESCE(m365_login,''))=? AND COALESCE(email,'')<>'' LIMIT 1", [strtolower((string)$job['upn'])]);
        if ($u) {
            $r = db_one("SELECT cloud_uid FROM sso_accounts WHERE email=? AND COALESCE(cloud_uid,'')<>''", [strtolower((string)$u['email'])]);
            if ($r) return (string)$r['cloud_uid'];
        }
        return '';
    }

    /** Konta Microsoft osoby (do strony /kalendarz-kontakty): users.m365_login i adres z domeny organizacji. @return list<string> */
    public static function upnsFor(array $user): array
    {
        $out = [];
        $l = strtolower(trim((string)($user['m365_login'] ?? '')));
        if ($l !== '' && str_contains($l, '@')) $out[] = $l;
        $e = strtolower(trim((string)($user['email'] ?? '')));
        $dom = strtolower(trim((string)(function_exists('m365_setting') ? m365_setting('m365_domain') : ''))) ?: 'feer.org.pl';
        if ($e !== '' && str_ends_with($e, '@' . $dom)) $out[] = $e;
        return array_values(array_unique($out));
    }

    public static function storeDir(): string
    {
        $d = rtrim(UPLOAD_DIR, '/') . '/pim_m365/';
        if (!is_dir($d)) @mkdir($d, 0700, true);
        if (!is_file($d . '.htaccess')) @file_put_contents($d . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        if (!is_file($d . 'index.html')) @file_put_contents($d . 'index.html', '');
        return $d;
    }

    private static function dir(array $job): string
    {
        $name = preg_match('/^[a-f0-9]{24}$/', (string)$job['dir']) ? (string)$job['dir'] : substr(sha1((string)$job['upn']), 0, 24);
        $d = self::storeDir() . $name . '/';
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    private static function writeEnc(string $file, string $plain): void
    {
        $enc = fcEncrypt($plain, self::CTX);
        if ($enc === null || $enc === $plain) throw new RuntimeException('Szyfrowanie kopii nie działa (klucz field_crypto).');
        if (file_put_contents($file, $enc, LOCK_EX) === false) throw new RuntimeException('Nie udało się zapisać kopii w ' . $file);
        @chmod($file, 0600);
    }

    /** Odszyfrowany plik tymczasowy do importu (usuwany zaraz po imporcie). */
    private static function plainTemp(string $dir, string $enc): string
    {
        $plain = fcDecrypt((string)file_get_contents($enc), self::CTX);
        if ($plain === null || $plain === '') throw new RuntimeException('Nie udało się odczytać kopii ' . basename($enc));
        $tmp = $dir . 'tmp-' . bin2hex(random_bytes(6)) . (str_contains($enc, '.vcf') ? '.vcf' : '.ics');
        file_put_contents($tmp, $plain, LOCK_EX);
        @chmod($tmp, 0600);
        return $tmp;
    }

    /** Kopia jako tekst (do pobrania przez administratora). @return array<string,string> nazwa → treść */
    public static function exportFiles(array $job): array
    {
        $out = [];
        foreach (glob(self::dir($job) . '*.enc') ?: [] as $f) {
            $p = fcDecrypt((string)file_get_contents($f), self::CTX);
            if ($p) $out[substr(basename($f), 0, -4)] = $p;
        }
        return $out;
    }

    /** Kolor Outlooka (#rrggbb) albo domyślny niebieski. */
    private static function color(string $c): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? $c : '#0082c9';
    }

    /** Usuwa kopie KEEP_DAYS dni po udanym imporcie i katalogi anulowanych zadań. */
    public static function cleanup(): int
    {
        $n = 0;
        $limit = date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400);
        foreach (db_all("SELECT * FROM pim_jobs WHERE (status='done' AND imported_at<?) OR (status='cancelled' AND updated_at<?)", [$limit, $limit]) as $j) {
            $d = self::storeDir() . (string)$j['dir'] . '/';
            if ((string)$j['dir'] === '' || !is_dir($d)) continue;
            foreach (glob($d . '*') ?: [] as $f) @unlink($f);
            @rmdir($d);
            $n++;
        }
        return $n;
    }

    private static function saveState(int $id, array $st): int
    {
        db_exec("UPDATE pim_jobs SET state_json=?, updated_at=? WHERE id=?", [json_encode($st, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $id]);
        return $id;
    }

    private static function progress(int $id, array $st, string $line): string
    {
        db_exec("UPDATE pim_jobs SET status='running', state_json=?, locked_until=NULL, next_run_at=?, attempts=0, updated_at=? WHERE id=?",
                [json_encode($st, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        return $line;
    }

    private static function wait(int $id, array $job, bool $progress, string $msg): string
    {
        $att = (int)$job['attempts'] + 1;
        if ($att > self::MAX_ATTEMPTS) return self::finish($id, 'error', $msg . ' (ponawiane przez 7 dni bez skutku).');
        db_exec("UPDATE pim_jobs SET status='waiting', attempts=?, next_run_at=?, locked_until=NULL, last_error=?, updated_at=? WHERE id=?",
                [$att, date('Y-m-d H:i:s', time() + self::RETRY_HOURS * 3600), mb_substr($msg, 0, 1000), date('Y-m-d H:i:s'), $id]);
        return "#{$id}: czeka ({$msg})";
    }

    private static function finish(int $id, string $status, string $msg): string
    {
        db_exec("UPDATE pim_jobs SET status=?, locked_until=NULL, next_run_at=NULL, finished_at=?, last_error=?, updated_at=? WHERE id=?",
                [$status, date('Y-m-d H:i:s'), mb_substr($msg, 0, 1000), date('Y-m-d H:i:s'), $id]);
        $job = self::job($id);
        if ($job) self::audit('cloud.pim.' . $status, $job, $msg);
        return "#{$id}: {$status} ({$msg})";
    }

    private static function audit(string $action, array $job, string $details): void
    {
        if (function_exists('audit_log')) {
            try { audit_log($action, 'pim_jobs', (int)$job['id'], ['upn' => (string)$job['upn'], 'details' => $details]); } catch (\Throwable $e) {}
        }
    }
}
