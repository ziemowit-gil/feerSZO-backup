<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingCopy.php — kopiowanie poczty z Microsoft 365 na skrzynkę dhosting (w tle, CRON).
 *
 * Zadanie (dhosting_copy_jobs) powstaje przy zakładaniu skrzynki / nadawaniu nowego hasła (admin albo użytkownik), bo tylko
 * wtedy znamy hasło do skrzynki dhosting — albo gdy użytkownik sam je poda. Hasło trzymamy zaszyfrowane (fcEncrypt) wyłącznie
 * do końca kopiowania: po zakończeniu, anulowaniu albo ostatecznym błędzie jest kasowane.
 *
 * Przebieg (agent cron/agents/dhosting_copy.php co 5 min, porcja ~4 min):
 *  1. plan: foldery z Graph (rekurencyjnie, bez Skrzynki nadawczej, Historii konwersacji, Problemów z synchronizacją…)
 *     → foldery IMAP (Odebrane → INBOX, Wysłane/Szkice/Usunięte/Wiadomości-śmieci/Archiwum → foldery specjalne serwera
 *     wg SPECIAL-USE albo nazw; reszta z tą samą hierarchią), brakujące tworzymy;
 *  2. wiadomości folderami, najstarsze najpierw: MIME z Graph → APPEND na dhosting (flagi \Seen, \Flagged, \Draft, data odbioru);
 *     każda skopiowana trafia do dhosting_copy_items (skrzynka + identyfikator niezmienny Graph) — wznowienie ani ponowne
 *     kopiowanie tej samej skrzynki (np. dosłanie nowych wiadomości) nie robi duplikatów; nieudane próbuje kolejne zadanie.
 *  3. Niedostępność (Microsoft, dhosting, IMAP, także odrzucone logowanie) → status „czeka”, ponowienie co 6 h
 *     (polecenie 2026-11-13), najdłużej 7 dni bez postępu — potem błąd i skasowanie hasła.
 *  4. Kopia przyrostowa (polecenie 2026-11-13): źródłem jest WYŁĄCZNIE Microsoft 365, a nowa skrzynka jest już w użyciu —
 *     niczego w niej nie zmieniamy ani nie usuwamy, tylko dopisujemy brakujące wiadomości. Przed pobraniem MIME porównujemy:
 *     rejestr dhosting_copy_items (co już skopiowaliśmy — także gdy użytkownik potem to przeniósł lub usunął) i nagłówek
 *     Message-ID w folderze docelowym (IMAP EXAMINE + UID SEARCH, tylko odczyt). Po zakończonym zadaniu hasło zostaje
 *     (zaszyfrowane) do SYNC_DAYS dni po dacie przełączenia, a CRON co SYNC_HOURS h zakłada zadanie „incremental”, które
 *     pobiera z Graph tylko wiadomości odebrane od ostatniej kopii (z zakładką 1 dnia; duplikaty odsiewa porównanie).
 *     Po końcu okna hasło jest kasowane. Stan „zmigrowane” = jest zakończone zadanie (everDone).
 */
require_once dirname(__DIR__) . '/lib/DhostingConfig.php';
require_once dirname(__DIR__) . '/lib/DhostingImap.php';
require_once dirname(__DIR__) . '/lib/DhostingGraphMail.php';
require_once dirname(__DIR__) . '/lib/DhostingHelpdesk.php';

final class DhostingCopy
{
    public const RETRY_HOURS = 6;
    public const MAX_ATTEMPTS = 28;            // 28 × 6 h = 7 dni bez postępu
    public const SYNC_HOURS = 3;               // co ile godzin dosyłamy nowe wiadomości z Microsoft 365
    public const SYNC_DAYS = 7;                // ile dni po dacie przełączenia (bez daty: po zakończeniu kopii) dosyłamy
    private const OVERLAP = 86400;             // zakładka kopii przyrostowej (duplikaty odsiewa porównanie)
    private const PASS_CTX = 'dhosting_copy_jobs.password_enc';
    public const ACTIVE = ['queued', 'running', 'waiting'];
    public const LABELS = [
        'queued' => 'w kolejce', 'running' => 'kopiuje', 'waiting' => 'czeka na ponowienie',
        'done' => 'zakończone', 'error' => 'błąd', 'cancelled' => 'anulowane',
    ];

    /** Podmiana wysyłek w testach: fn($to, $name, $subject, $html, $text): bool oraz fn($phone, $message): string (dostawca, '' = nie wyszło). */
    public static ?\Closure $sendMail = null;
    public static ?\Closure $sendSms = null;

    /**
     * Po zakończonym pełnym przenoszeniu (polecenie 2026-11-13): e-mail i SMS „poczta przeniesiona — możesz się zalogować”:
     * adres i login, hasło „to samo co do nowej skrzynki” (bez samego hasła), poczta w przeglądarce, ile przeniesiono, ile niepełnych
     * i nieprzeniesionych (wykaz na stronie migracji), do kiedy dosyłamy nowe. Bez nazwy dostawcy. E-mail: nowa skrzynka + adres konta SZO;
     * SMS: komórka z konta SZO / rejestru / umowy (rodzaj access_data, bez omijania ciszy nocnej). Zwraca opis do notatki Helpdesku.
     */
    public static function notifyDone(array $job, array $c, ?string $keep): string
    {
        $box = strtolower(trim((string)$job['mailbox']));
        $uid = (int)($job['user_id'] ?? 0);
        $user = $uid > 0 ? (db_one("SELECT name, email FROM users WHERE id=?", [$uid]) ?: []) : [];
        $name = trim((string)($user['name'] ?? ''));
        $web = DhostingConfig::webmail();
        $url = (defined('APP_URL') ? rtrim((string)APP_URL, '/') : '') . '/migracjapoczty';
        $list = self::failedFor($box, 5000);
        $nFail = count(array_filter($list, fn($r) => (int)$r['result'] === 0));
        $nPart = count($list) - $nFail;
        $n = (int)$c['copied'];
        $lines = ['Adres i login: ' . $box,
                  'Hasło: to samo, którego używasz do nowej skrzynki (z SMS-a albo ustawione na stronie migracji).'];
        if ($web !== '') $lines[] = 'Poczta w przeglądarce: ' . $web;
        $lines[] = 'Przeniesiono ' . $n . ' ' . ($n === 1 ? 'wiadomość' : 'wiadomości') . '.';
        if ($nPart || $nFail) {
            $lines[] = 'Nie wszystko przeszło w całości: ' . implode(', ', array_filter([$nPart ? 'niepełne — ' . $nPart : '', $nFail ? 'nieprzeniesione — ' . $nFail : '']))
                     . '. Wykaz jest na stronie migracji, a oryginały zostały w Microsoft 365.';
        }
        if ($keep) $lines[] = 'Nowe wiadomości, które jeszcze trafią do Microsoft 365, dosyłamy automatycznie do ' . date('d.m.Y H:i', (int)strtotime($keep)) . '.';
        $title = 'Twoja poczta jest przeniesiona — możesz się zalogować';
        $done = [];

        if ($uid > 0) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/notifications.php';
                if (function_exists('notif_migrate')) notif_migrate();
                if (function_exists('notif_create')) { notif_create($uid, 'system', $title, implode(' ', $lines), $url); $done[] = 'SZO'; }
            } catch (\Throwable $e) { error_log('[dhosting] notif done ' . $box . ': ' . $e->getMessage()); }
        }

        $to = [$box];
        $ue = strtolower(trim((string)($user['email'] ?? '')));
        if ($ue !== '' && filter_var($ue, FILTER_VALIDATE_EMAIL) && !in_array($ue, $to, true)) $to[] = $ue;
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<p>Dzień dobry' . ($name !== '' ? ', ' . $e($name) : '') . ',</p>'
              . '<p>przenieśliśmy Twoją pocztę z Microsoft 365 do nowej skrzynki. <strong>Możesz się już do niej zalogować.</strong> Twój adres e-mail się nie zmienia.</p><ul>';
        foreach ($lines as $l) {
            $html .= '<li>' . ($web !== '' && str_starts_with($l, 'Poczta w przeglądarce: ')
                ? 'Poczta w przeglądarce: <a href="' . $e($web) . '">' . $e($web) . '</a>' : $e($l)) . '</li>';
        }
        $html .= '</ul><p>Ustawienia poczty w telefonie i komputerze oraz szczegóły przenoszenia: <a href="' . $e($url) . '">' . $e($url) . '</a></p>'
               . '<p style="color:#555">Nikt z Fundacji nie poprosi Cię o hasło — jeśli ktoś o nie prosi, to próba oszustwa.</p>';
        $text = 'Przenieśliśmy Twoją pocztę z Microsoft 365 do nowej skrzynki. Możesz się już do niej zalogować.' . "\n\n" . implode("\n", $lines)
              . "\n\nUstawienia i szczegóły: " . $url . "\nNikt z Fundacji nie poprosi Cię o hasło.";
        $sent = 0;
        try {
            $mail = self::$sendMail ?? (function (string $to, string $nm, string $subj, string $h, string $t): bool {
                require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                return (bool)mail_queue_add($to, $nm, $subj, $h, $t, 'dhosting_copy_done');
            });
            foreach ($to as $addr) { if ($mail($addr, $name, $title, $html, $text)) $sent++; }
        } catch (\Throwable $ex) { error_log('[dhosting] e-mail done ' . $box . ': ' . $ex->getMessage()); }
        $done[] = $sent ? 'e-mail' . ($sent > 1 ? ' (' . $sent . ')' : '') : 'e-mail nie wyszedł';

        try {
            require_once __DIR__ . '/DhostingMigrator.php';
            $phone = DhostingMigrator::phoneForMailbox($box, $uid ?: null);
            if ($phone === '') {
                $done[] = 'SMS: brak numeru komórkowego';
            } else {
                $msg = 'FEER: poczta ' . $box . ' przeniesiona, mozesz sie logowac swoim haslem.'
                     . ($web !== '' ? ' Poczta: ' . $web : '') . ($nPart || $nFail ? ' Nie wszystko przeszlo, wykaz: ' . $url : '');
                $sms = self::$sendSms ?? fn(string $p, string $m): string => (string)sms_send($p, function_exists('sms_ascii') ? sms_ascii($m) : $m, 'access_data');
                $prov = $sms($phone, $msg);
                $done[] = $prov === '' ? 'SMS nie wyszedł' : ($prov === 'odlozony' ? 'SMS po ciszy nocnej' : 'SMS') . ' (' . DhostingMigrator::maskPhone($phone) . ')';
            }
        } catch (\Throwable $ex) { $done[] = 'SMS: błąd'; error_log('[dhosting] SMS done ' . $box . ': ' . $ex->getMessage()); }
        return implode(', ', $done);
    }

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('dhosting_copy_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_copy_jobs (
            id INTEGER PRIMARY KEY,
            mailbox VARCHAR(190) NOT NULL,
            source VARCHAR(190) NOT NULL,
            user_id INTEGER NULL,
            via VARCHAR(10) NOT NULL DEFAULT '',
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'queued',
            password_enc TEXT NULL,
            state_json TEXT NULL,
            total_items INTEGER NOT NULL DEFAULT 0,
            copied_items INTEGER NOT NULL DEFAULT 0,
            failed_items INTEGER NOT NULL DEFAULT 0,
            bytes INTEGER NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            next_run_at VARCHAR(19) NULL,
            locked_until VARCHAR(19) NULL,
            started_at VARCHAR(19) NULL,
            finished_at VARCHAR(19) NULL,
            updated_at VARCHAR(19) NULL,
            last_error TEXT NULL)");
        try { db()->exec("CREATE INDEX idx_dhcopy_mailbox ON dhosting_copy_jobs(mailbox)"); } catch (\Throwable $e) {}
        try { db()->exec("CREATE INDEX idx_dhcopy_status ON dhosting_copy_jobs(status, next_run_at)"); } catch (\Throwable $e) {}
        // kind: full (pełne przejście) | incremental (dosyłanie nowych); keep_until: do kiedy trzymamy hasło na dosyłanie
        try { db()->exec("ALTER TABLE dhosting_copy_jobs ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'full'"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE dhosting_copy_jobs ADD COLUMN keep_until VARCHAR(19) NULL"); } catch (\Throwable $e) {}
        // need_password: serwer nowej poczty odrzucił zapisane hasło (np. zmienione po założeniu) — czekamy na aktualne
        try { db()->exec("ALTER TABLE dhosting_copy_jobs ADD COLUMN need_password INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        // result: 1 = skopiowana, 2 = zniknęła w trakcie (pomijamy), 0 = nieudana (np. za duża) — ponawia dopiero inne zadanie,
        // 3 = przeniesiona częściowo (odtworzona bez pełnej treści albo bez załączników) — jest na serwerze, ale w wykazie
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_copy_items (
            mailbox VARCHAR(190) NOT NULL,
            msg_id VARCHAR(255) NOT NULL,
            job_id INTEGER NOT NULL,
            result INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (mailbox, msg_id))");
        // wykaz nieudanych (polecenie 2026-11-13): gdzie była, kiedy przyszła, temat i powód — tylko dla result=0
        foreach (['folder VARCHAR(255) NULL', 'subject TEXT NULL', 'received_at VARCHAR(19) NULL', 'reason TEXT NULL', 'failed_at VARCHAR(19) NULL'] as $col) {
            try { db()->exec("ALTER TABLE dhosting_copy_items ADD COLUMN {$col}"); } catch (\Throwable $e) {}
        }
    }

    /**
     * Wykaz wiadomości skrzynki przeniesionych niecałkowicie: result 0 = nie przeniesiona, 3 = przeniesiona częściowo (bez pełnej
     * treści albo bez załączników — odtworzona przez buildMime). Stan teraz: późniejsza udana próba zdejmuje nieudaną z wykazu.
     * [['result','folder','subject','received_at','reason','failed_at'], …] — najstarsze najpierw; wiersze sprzed 13.11.2026 bez szczegółów.
     */
    public static function failedFor(string $mailbox, int $limit = 500): array
    {
        self::migrate();
        return db_all("SELECT result, folder, subject, received_at, reason, failed_at FROM dhosting_copy_items WHERE mailbox=? AND result IN (0, 3)
                       ORDER BY received_at IS NULL, received_at, msg_id LIMIT " . max(1, min(5000, $limit)), [strtolower(trim($mailbox))]);
    }

    /** Czy kopiowanie jest możliwe (serwer IMAP dhosting — domyślnie imap.dpoczta.pl — i skonfigurowany Microsoft 365). */
    public static function available(): bool
    {
        return DhostingConfig::imapAddress() !== '' && DhostingGraphMail::configured();
    }

    // ── Kolejka ────────────────────────────────────────────────────────────────────────────────

    /** Dodaje zadanie (albo odświeża aktywne dla tej skrzynki nowym hasłem). $source = id albo login konta Microsoft. */
    public static function queue(string $mailbox, string $source, string $password, ?int $userId, string $via, string $by): int
    {
        self::migrate();
        $mailbox = strtolower($mailbox);
        $now = date('Y-m-d H:i:s');
        $enc = (string)fcEncrypt($password, self::PASS_CTX);
        $active = self::activeFor($mailbox);
        if ($active) {
            db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=?, source=?, status='queued', attempts=0, need_password=0, next_run_at=?, last_error=NULL, updated_at=?,
                           state_json=CASE WHEN kind='incremental' THEN NULL ELSE state_json END, kind='full' WHERE id=?")
                ->execute([$enc, $source, $now, $now, (int)$active['id']]);
            return (int)$active['id'];
        }
        db()->prepare("INSERT INTO dhosting_copy_jobs (mailbox, source, user_id, via, created_by, created_at, status, password_enc, next_run_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?)")
            ->execute([$mailbox, $source, $userId, $via, $by, $now, $enc, $now, $now]);
        return (int)db()->lastInsertId();
    }

    /**
     * SZO ustawiło skrzynce nowe hasło (administrator, umowa, samoobsługa): aktywne kopiowanie i okno dosyłania dostają je od razu,
     * a zadanie czekające na hasło rusza w najbliższym przebiegu. Bez tego zadanie próbowało starym hasłem co 6 h aż do błędu.
     * Zwraca liczbę zaktualizowanych zadań.
     */
    public static function passwordChanged(string $mailbox, string $password): int
    {
        self::migrate();
        $mailbox = strtolower($mailbox);
        $enc = (string)fcEncrypt($password, self::PASS_CTX);
        $now = date('Y-m-d H:i:s');
        $a = db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=?, need_password=0, attempts=0,
                               last_error=CASE WHEN need_password=1 THEN NULL ELSE last_error END,
                               next_run_at=CASE WHEN status='waiting' THEN ? ELSE next_run_at END,
                               status=CASE WHEN status='waiting' THEN 'queued' ELSE status END, updated_at=?
                            WHERE mailbox=? AND status IN ('queued','running','waiting')");
        $a->execute([$enc, $now, $now, $mailbox]);
        $b = db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=?, updated_at=? WHERE mailbox=? AND status='done' AND password_enc IS NOT NULL AND keep_until > ?");
        $b->execute([$enc, $now, $mailbox, $now]);
        return $a->rowCount() + $b->rowCount();
    }

    /** Stan skrzynki na serwerze poczty (pola status/IMAP z getMailboxInformation) — do opisu błędu dla administratora; '' gdy się nie da. */
    public static function mailboxDiag(string $mailbox): string
    {
        try {
            $info = DhostingConfig::api()->getMailboxInformation($mailbox);
        } catch (\Throwable $e) {
            return 'nie udało się odczytać stanu skrzynki (' . mb_substr($e->getMessage(), 0, 120) . ')';
        }
        $out = [];
        array_walk_recursive($info, function ($v, $k) use (&$out) {
            if (count($out) < 8 && is_scalar($v) && preg_match('/status|imap|active|enabled/i', (string)$k)) {
                $out[] = $k . '=' . (is_bool($v) ? ($v ? 'tak' : 'nie') : mb_substr((string)$v, 0, 40));
            }
        });
        return implode(', ', $out);
    }

    public static function activeFor(string $mailbox): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE mailbox=? AND status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1", [strtolower($mailbox)]);
        return $r ?: null;
    }

    public static function latestFor(string $mailbox): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE mailbox=? ORDER BY id DESC LIMIT 1", [strtolower($mailbox)]);
        return $r ?: null;
    }

    /** Najnowsze zadanie pełnego kopiowania (bez automatycznego dosyłania) — stan kopii na stronie użytkownika. */
    public static function mainFor(string $mailbox): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE mailbox=? AND kind<>'incremental' ORDER BY id DESC LIMIT 1", [strtolower($mailbox)]);
        return $r ?: null;
    }

    /** Konto zmigrowane: jest zakończone zadanie kopiowania. */
    public static function everDone(string $mailbox): bool
    {
        self::migrate();
        return (bool)db_one("SELECT id FROM dhosting_copy_jobs WHERE mailbox=? AND status='done' LIMIT 1", [strtolower($mailbox)]);
    }

    /** Dosyłanie nowych wiadomości: active (hasło trzymane), until (do kiedy), last (ostatnie zakończone dosłanie albo null). */
    public static function syncInfo(string $mailbox): array
    {
        self::migrate();
        $mailbox = strtolower($mailbox);
        $now = date('Y-m-d H:i:s');
        $k = db_one("SELECT keep_until FROM dhosting_copy_jobs WHERE mailbox=? AND password_enc IS NOT NULL AND keep_until > ? ORDER BY id DESC LIMIT 1", [$mailbox, $now]);
        $last = db_one("SELECT id, status, finished_at, state_json FROM dhosting_copy_jobs WHERE mailbox=? AND kind='incremental' AND status='done' ORDER BY id DESC LIMIT 1", [$mailbox]);
        $added = $last ? (int)((json_decode((string)$last['state_json'], true) ?: [])['appended'] ?? 0) : 0;
        return ['active' => (bool)$k, 'until' => (string)($k['keep_until'] ?? ''), 'last' => $last ? ['at' => (string)$last['finished_at'], 'added' => $added] : null];
    }

    /** Do kiedy trzymać hasło na dosyłanie: SYNC_DAYS dni po dacie przełączenia (bez daty — od teraz); null = już nie. */
    public static function keepUntil(): ?string
    {
        $sw = class_exists('DhostingConfig') ? DhostingConfig::switchDate() : null;
        $end = ($sw ?: time()) + self::SYNC_DAYS * 86400;
        return $end > time() ? date('Y-m-d H:i:s', $end) : null;
    }

    /**
     * CRON: zakłada zadania dosyłania dla skrzynek, których ostatnie zadanie zakończyło się ponad SYNC_HOURS h temu
     * i wciąż trzyma hasło (okno dosyłania); po końcu okna kasuje hasła. Hasło przechodzi na nowe zadanie (stare je traci).
     */
    public static function scheduleSync(): array
    {
        self::migrate();
        $now = date('Y-m-d H:i:s');
        $log = [];
        db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=NULL WHERE status='done' AND password_enc IS NOT NULL AND (keep_until IS NULL OR keep_until <= ?)")
            ->execute([$now]);
        $cut = date('Y-m-d H:i:s', time() - self::SYNC_HOURS * 3600);
        $rows = db_all("SELECT * FROM dhosting_copy_jobs WHERE status='done' AND password_enc IS NOT NULL AND keep_until > ? AND finished_at <= ? ORDER BY id", [$now, $cut]);
        foreach ($rows as $j) {
            $newer = db_one("SELECT id FROM dhosting_copy_jobs WHERE mailbox=? AND id>? LIMIT 1", [$j['mailbox'], (int)$j['id']]);
            if ($newer) {   // nowsze zadanie ma już własne hasło — to stare tylko czyścimy
                db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=NULL WHERE id=?")->execute([(int)$j['id']]);
                continue;
            }
            db()->prepare("INSERT INTO dhosting_copy_jobs (mailbox, source, user_id, via, created_by, created_at, status, password_enc, next_run_at, updated_at, kind, keep_until)
                           VALUES (?, ?, ?, 'auto', 'dosyłanie nowych wiadomości', ?, 'queued', ?, ?, ?, 'incremental', ?)")
                ->execute([$j['mailbox'], $j['source'], $j['user_id'], $now, $j['password_enc'], $now, $now, $j['keep_until']]);
            $nid = (int)db()->lastInsertId();
            db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=NULL WHERE id=?")->execute([(int)$j['id']]);
            $log[] = "[SYNC] {$j['mailbox']}: dosyłanie nowych wiadomości (#{$nid})";
        }
        return $log;
    }

    /** Zadania do panelu admina (najnowsze najpierw), bez haseł. */
    public static function jobs(int $limit = 200): array
    {
        self::migrate();
        $st = db()->prepare("SELECT id, mailbox, source, user_id, via, kind, created_by, created_at, status, total_items, copied_items, failed_items, bytes,
                                    attempts, need_password, next_run_at, started_at, finished_at, updated_at, last_error
                             FROM dhosting_copy_jobs ORDER BY CASE WHEN status IN ('queued','running','waiting') THEN 0 ELSE 1 END, id DESC LIMIT " . max(1, $limit));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function job(int $id): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE id=?", [$id]);
        return $r ?: null;
    }

    public static function cancel(int $id): void
    {
        self::migrate();
        $q = db()->prepare("UPDATE dhosting_copy_jobs SET status='cancelled', password_enc=NULL, finished_at=?, updated_at=? WHERE id=? AND status IN ('queued','running','waiting')");
        $q->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        if ($q->rowCount() > 0 && ($job = self::job($id))) self::helpdesk($job, 'Anulowano kopiowanie poczty (zadanie #' . $id . ').');
    }

    /** Zdarzenie kopiowania w zgłoszeniu Helpdesku skrzynki (DhostingHelpdesk; nigdy nie przerywa kopiowania). */
    private static function helpdesk(array $job, string $note): void
    {
        $src = (string)($job['source'] ?? '');
        DhostingHelpdesk::event((string)$job['mailbox'], $note,
            ['user_id' => (int)($job['user_id'] ?? 0), 'login' => str_contains($src, '@') ? $src : '']);
    }

    /** „Ponów teraz”: zadanie czekające wraca do kolejki najbliższego przebiegu CRON. */
    public static function runSoon(int $id): void
    {
        self::migrate();
        db()->prepare("UPDATE dhosting_copy_jobs SET status='queued', next_run_at=?, updated_at=? WHERE id=? AND status IN ('queued','waiting')")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    }

    public const NO_PASS = 'Brak zapisanego hasła do tej skrzynki (minęło okno dosyłania albo kopiowanie anulowano) — użyj „Ponów z nowym hasłem” albo poproś osobę, żeby podała hasło na stronie migracji.';

    /**
     * „Ponów przenoszenie poczty” (administrator): nowe pełne przejście dla skrzynki — kopiuje tylko brakujące wiadomości
     * (rejestr + Message-ID w nowej skrzynce; niczego tam nie nadpisuje). $password: nowe hasło ustawione właśnie przez
     * administratora; null = zaszyfrowane hasło zapisane przy wcześniejszym zadaniu (przechodzi na nowe zadanie).
     * Aktywne zadanie tej skrzynki — tylko przyspieszamy je. Zwraca id zadania; brak hasła → wyjątek NO_PASS.
     */
    public static function retry(string $mailbox, string $by, ?string $password = null): int
    {
        self::migrate();
        $mailbox = strtolower(trim($mailbox));
        $last = self::latestFor($mailbox);
        if (!$last) throw new RuntimeException('Ta skrzynka nie była jeszcze kopiowana.');
        $uid = $last['user_id'] !== null ? (int)$last['user_id'] : null;
        if ($password !== null) {
            $id = self::queue($mailbox, (string)$last['source'], $password, $uid, 'admin', $by);
        } elseif ($active = self::activeFor($mailbox)) {
            self::runSoon((int)$active['id']);
            return (int)$active['id'];
        } else {
            $src = db_one("SELECT id, password_enc, keep_until FROM dhosting_copy_jobs WHERE mailbox=? AND password_enc IS NOT NULL ORDER BY id DESC LIMIT 1", [$mailbox]);
            if (!$src) throw new RuntimeException(self::NO_PASS);
            $now = date('Y-m-d H:i:s');
            db()->prepare("INSERT INTO dhosting_copy_jobs (mailbox, source, user_id, via, created_by, created_at, status, password_enc, next_run_at, updated_at, kind, keep_until)
                           VALUES (?, ?, ?, 'admin', ?, ?, 'queued', ?, ?, ?, 'full', ?)")
                ->execute([$mailbox, $last['source'], $uid, $by, $now, $src['password_enc'], $now, $now, $src['keep_until']]);
            $id = (int)db()->lastInsertId();
            db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=NULL WHERE id=?")->execute([(int)$src['id']]);
        }
        self::helpdesk(['mailbox' => $mailbox, 'source' => $last['source'], 'user_id' => $uid],
            'Administrator (' . $by . ') ponowił przenoszenie poczty (zadanie #' . $id . ')' . ($password !== null ? ' z nowym hasłem.' : '.'));
        return $id;
    }

    /** Skrzynki z zapisanym (zaszyfrowanym) hasłem — „Ponów przenoszenie” bez zmiany hasła. mailbox => true. */
    public static function storedPasswordBoxes(): array
    {
        self::migrate();
        return array_flip(array_column(db_all("SELECT DISTINCT mailbox FROM dhosting_copy_jobs WHERE password_enc IS NOT NULL"), 'mailbox'));
    }

    public static function percent(array $job): int
    {
        $t = (int)$job['total_items'];
        if ($job['status'] === 'done') return 100;
        return $t > 0 ? min(99, (int)floor(100 * (int)$job['copied_items'] / $t)) : 0;
    }

    // ── Przebieg (CRON) ────────────────────────────────────────────────────────────────────────

    /** Przetwarza zadania, których czas nadszedł, aż do $budget sekund. Zwraca linie dziennika. */
    public static function runDue(int $budget = 240): array
    {
        self::migrate();
        $deadline = time() + $budget;
        $log = [];
        if (!self::available()) return ['[SKIP] Kopiowanie poczty nieskonfigurowane (serwer IMAP dhosting albo Microsoft 365).'];
        try { $log = self::scheduleSync(); } catch (\Throwable $e) { $log[] = '[ERROR] dosyłanie: ' . $e->getMessage(); }
        $now = date('Y-m-d H:i:s');
        $due = db()->prepare("SELECT id FROM dhosting_copy_jobs WHERE status IN ('queued','running','waiting')
                              AND (next_run_at IS NULL OR next_run_at <= ?) AND (locked_until IS NULL OR locked_until < ?) ORDER BY id");
        $due->execute([$now, $now]);
        foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (time() >= $deadline - 10) break;
            $log[] = self::runJob((int)$id, $deadline);
        }
        return $log ?: ['[SKIP] Brak zadań kopiowania do wykonania.'];
    }

    public static function runJob(int $id, int $deadline): string
    {
        $now = date('Y-m-d H:i:s');
        $lock = db()->prepare("UPDATE dhosting_copy_jobs SET locked_until=?, status='running', started_at=COALESCE(started_at, ?), updated_at=?
                               WHERE id=? AND (locked_until IS NULL OR locked_until < ?) AND status IN ('queued','running','waiting')");
        $lock->execute([date('Y-m-d H:i:s', $deadline + 300), $now, $now, $id, $now]);
        if ($lock->rowCount() !== 1) return "[SKIP] #{$id} zajęte albo nieaktywne";

        $job = self::job($id);
        $state = json_decode((string)($job['state_json'] ?? ''), true) ?: [];
        $copiedBefore = (int)$job['copied_items'];
        $c = ['copied' => (int)$job['copied_items'], 'failed' => (int)$job['failed_items'], 'bytes' => (int)$job['bytes'], 'total' => (int)$job['total_items']];
        $imap = null;
        try {
            $pass = (string)fcDecrypt((string)$job['password_enc'], self::PASS_CTX);
            if ($pass === '') throw new RuntimeException('Brak hasła do nowej skrzynki — uruchom kopiowanie ponownie z hasłem.');
            $graph = new DhostingGraphMail();
            $imap = new DhostingImap(DhostingConfig::imapAddress());
            $imap->connect();
            $imap->login((string)$job['mailbox'], $pass);
            if (empty($state['folders'])) {
                $state = self::plan($graph, $imap, (string)$job['source']);
                if (($job['kind'] ?? 'full') === 'incremental') self::sinceFromPrevious($job, $state);
                $c['total'] = array_sum(array_column($state['folders'], 'total'));
                self::save($id, $state, $c, null);
            }
            $finished = self::copyFolders($id, (string)$job['mailbox'], $graph, $imap, (string)$job['source'], $state, $c, $deadline);
            $imap->logout();
            if ($finished) {
                $keep = self::keepUntil();   // okno dosyłania: hasło zostaje (zaszyfrowane), po oknie kasuje je scheduleSync()
                db()->prepare("UPDATE dhosting_copy_jobs SET status='done', password_enc=CASE WHEN ? IS NULL THEN NULL ELSE password_enc END, keep_until=?,
                               finished_at=?, locked_until=NULL, next_run_at=NULL,
                               attempts=0, state_json=?, copied_items=?, failed_items=?, bytes=?, updated_at=?, last_error=? WHERE id=?")
                    ->execute([$keep, $keep, date('Y-m-d H:i:s'), json_encode($state, JSON_UNESCAPED_UNICODE), $c['copied'], $c['failed'], $c['bytes'], date('Y-m-d H:i:s'),
                               $c['failed'] ? $c['failed'] . ' wiadomości nie dało się skopiować (np. za duże dla serwera).' : null, $id]);
                try { if (function_exists('audit_log')) audit_log('dhosting.copy_done', ['mailbox' => $job['mailbox'], 'copied' => $c['copied'], 'failed' => $c['failed']], 0); } catch (\Throwable $e) {}
                $added = (int)($state['appended'] ?? 0);
                $present = (int)($state['present'] ?? 0);
                $rebuilt = (int)($state['rebuilt'] ?? 0);
                $partialN = (int)($state['partial'] ?? 0);
                $failList = '';
                if ($c['failed'] > 0 || (int)($state['partial'] ?? 0) > 0) {
                    $fl = self::failedFor((string)$job['mailbox'], 11);
                    foreach (array_slice($fl, 0, 10) as $fr) {
                        $failList .= "\n– " . ((int)$fr['result'] === 3 ? '[niepełna] ' : '') . ($fr['received_at'] ? substr((string)$fr['received_at'], 0, 10) . ' · ' : '')
                                   . ($fr['folder'] ?: '?') . ' · ' . ($fr['subject'] !== null && $fr['subject'] !== '' ? $fr['subject'] : '(bez tematu)');
                    }
                    if ($failList !== '') $failList = "\nNieudane i niepełne (pełny wykaz: /migracjaadmin#kopiowanie):" . $failList . (count($fl) > 10 ? "\n– …" : '');
                }
                $notice = '';
                if (($job['kind'] ?? 'full') !== 'incremental') {
                    try { $notice = self::notifyDone($job, $c, $keep); } catch (\Throwable $e) { $notice = 'błąd: ' . $e->getMessage(); }
                }
                if (($job['kind'] ?? 'full') !== 'incremental') {
                    self::helpdesk($job, 'Kopiowanie poczty zakończone: skopiowano ' . $c['copied'] . ' wiadomości (nowo dopisanych: ' . $added
                        . ($present ? ', już były w nowej skrzynce: ' . $present : '')
                        . ($rebuilt ? ', odtworzonych z danych, bo Microsoft nie oddał oryginału: ' . $rebuilt : '')
                        . ($partialN ? ', w tym niepełnych (bez całej treści albo bez załączników): ' . $partialN : '') . ')'
                        . ($c['failed'] ? ', nie udało się ' . $c['failed'] . ' (np. za duże dla serwera)' : '') . '.'
                        . ($keep ? ' Nowe wiadomości z Microsoft 365 dosyłamy automatycznie co ' . self::SYNC_HOURS . ' h do ' . substr($keep, 0, 16) . '.' : '')
                        . ($notice !== '' ? ' Powiadomienie „możesz się zalogować”: ' . $notice . '.' : '') . $failList);
                } elseif ($added > 0 || $c['failed'] > 0) {
                    self::helpdesk($job, 'Dosłano nowe wiadomości z Microsoft 365: ' . $added . ($c['failed'] ? ', nie udało się ' . $c['failed'] : '') . '.' . $failList);
                }
                return "[OK] #{$id} {$job['mailbox']}: zakończone, skopiowano {$c['copied']}, błędów {$c['failed']}";
            }
            db()->prepare("UPDATE dhosting_copy_jobs SET status='queued', locked_until=NULL, next_run_at=?, attempts=0, last_error=NULL, updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            return "[OK] #{$id} {$job['mailbox']}: {$c['copied']}/{$c['total']} — ciąg dalszy w następnym przebiegu";
        } catch (\Throwable $e) {
            if ($imap) { try { $imap->logout(); } catch (\Throwable $e2) {} }
            self::save($id, $state, $c, null);
            $attempts = ($c['copied'] > $copiedBefore ? 0 : (int)$job['attempts']) + 1;
            $msg = mb_substr($e->getMessage(), 0, 500);
            if ($attempts >= self::MAX_ATTEMPTS) {
                db()->prepare("UPDATE dhosting_copy_jobs SET status='error', password_enc=NULL, attempts=?, locked_until=NULL, next_run_at=NULL, finished_at=?, last_error=?, updated_at=? WHERE id=?")
                    ->execute([$attempts, date('Y-m-d H:i:s'), 'Przerwano po 7 dniach prób: ' . $msg, date('Y-m-d H:i:s'), $id]);
                self::helpdesk($job, (($job['kind'] ?? 'full') === 'incremental' ? 'Dosyłanie nowych wiadomości przerwane' : 'Kopiowanie poczty przerwane')
                    . ' po 7 dniach prób (skopiowano ' . $c['copied'] . ' z ' . $c['total'] . '). Ostatni błąd: ' . $msg);
                return "[ERROR] #{$id} {$job['mailbox']}: koniec prób — {$msg}";
            }
            $next = date('Y-m-d H:i:s', time() + self::RETRY_HOURS * 3600);
            $needPw = $e instanceof DhostingImapAuthFailed ? 1 : 0;
            if ($needPw) {
                // To nie jest chwilowa niedostępność: skrzynka ma inne hasło niż zapisane (zmienione po założeniu albo zmiana nie weszła).
                // Ponawiamy dalej co 6 h (gdyby serwer wprowadzał zmianę z opóźnieniem), ale strona użytkownika prosi o aktualne hasło.
                $diag = self::mailboxDiag((string)$job['mailbox']);
                $msg = 'Serwer nowej poczty nie przyjął zapisanego hasła do skrzynki (' . mb_substr($e->getMessage(), 0, 160) . ').'
                     . ($diag !== '' ? ' Stan skrzynki na serwerze: ' . $diag . '.' : '')
                     . ' Potrzebne aktualne hasło: osoba podaje je na /migracjapoczty albo administrator ustawia nowe (z kopiowaniem).';
            }
            db()->prepare("UPDATE dhosting_copy_jobs SET status='waiting', attempts=?, need_password=?, locked_until=NULL, next_run_at=?, last_error=?, updated_at=? WHERE id=?")
                ->execute([$attempts, $needPw, $next, $msg, date('Y-m-d H:i:s'), $id]);
            // tylko pierwsza nieudana próba z rzędu — bez notatki co 6 h
            if ($attempts === 1) self::helpdesk($job, 'Kopiowanie poczty czeka: ' . $msg . ' Ponowienie co ' . self::RETRY_HOURS . ' h (najbliższe ' . $next . ').');
            return "[WAIT] #{$id} {$job['mailbox']}: {$msg} — ponowienie {$next}";
        }
    }

    /**
     * Kopia przyrostowa: dla każdego folderu „od kiedy” = ostatnia data odbioru z poprzedniego zakończonego zadania tej skrzynki
     * (ten sam folder Graph) minus zakładka; folder nieznany wcześniej — od zakończenia poprzedniego zadania minus zakładka.
     * Liczba wiadomości nieznana z góry (total 0 → postęp z tego, co przejdzie).
     */
    private static function sinceFromPrevious(array $job, array &$state): void
    {
        $prev = db_one("SELECT state_json, finished_at, started_at FROM dhosting_copy_jobs WHERE mailbox=? AND status='done' AND id<? ORDER BY id DESC LIMIT 1",
            [$job['mailbox'], (int)$job['id']]);
        if (!$prev) return;   // brak poprzedniej kopii — pełne przejście
        $last = [];
        foreach ((json_decode((string)$prev['state_json'], true) ?: [])['folders'] ?? [] as $pf) {
            if (!empty($pf['last'])) $last[(string)$pf['id']] = (string)$pf['last'];
        }
        $fallback = (int)strtotime((string)($prev['started_at'] ?: $prev['finished_at']));
        foreach ($state['folders'] as &$f) {
            $t = isset($last[$f['id']]) ? (int)strtotime($last[$f['id']]) : $fallback;
            $f['since'] = max(0, $t - self::OVERLAP);
            $f['total'] = 0;
            $f['done'] = false;
        }
        unset($f);
        $state['incremental'] = true;
    }

    /** Plan folderów: Graph → IMAP; brakujące foldery tworzy. */
    private static function plan(DhostingGraphMail $graph, DhostingImap $imap, string $source): array
    {
        $gFolders = $graph->folders($source);
        $existing = $imap->listFolders();
        $d = $imap->delimiter() ?: '/';
        $byName = [];
        $byAttr = [];
        foreach ($existing as $f) {
            $byName[mb_strtolower($f['name'])] = $f['name'];
            foreach ($f['attrs'] as $a) $byAttr[strtolower($a)] = $f['name'];
        }
        $specialMap = [
            'sentitems'    => ['\\sent', ['Sent', 'Sent Items', 'Sent Messages', 'Wysłane'], 'Sent'],
            'drafts'       => ['\\drafts', ['Drafts', 'Szkice'], 'Drafts'],
            'deleteditems' => ['\\trash', ['Trash', 'Deleted Items', 'Deleted Messages', 'Kosz'], 'Trash'],
            'junkemail'    => ['\\junk', ['Junk', 'Spam', 'Junk E-mail'], 'Junk'],
            'archive'      => ['\\archive', ['Archive', 'Archiwum'], 'Archive'],
        ];
        $targets = [];   // id Graph → nazwa UTF-8 na serwerze
        $out = [];
        foreach ($gFolders as $f) {
            $wk = $f['special'];
            $leaf = null; $parentName = null;   // tylko foldery użytkownika (do awaryjnej nazwy przy odrzuceniu przez serwer)
            if ($wk === 'inbox') {
                $name = 'INBOX';
            } elseif ($wk !== null && isset($specialMap[$wk])) {
                [$attr, $names, $default] = $specialMap[$wk];
                $name = $byAttr[$attr] ?? null;
                foreach ($names as $n) { if ($name === null && isset($byName[mb_strtolower($n)])) $name = $byName[mb_strtolower($n)]; }
                $name ??= $default;
            } else {
                $leaf = DhostingImap::leafName((string)$f['name'], $d);   // separator serwera i „/” → „_”
                $parentPath = array_slice($f['path'], 0, -1);
                if ($parentPath) {
                    // rodzic jest wcześniej na liście (przejście w głąb) — szukamy go po ścieżce
                    foreach ($out as $o) { if ($o['path'] === $parentPath) { $parentName = $o['target']; break; } }
                }
                $name = $parentName !== null ? $parentName . $d . $leaf : $leaf;
            }
            if ($name === 'INBOX' || isset($byName[mb_strtolower($name)])) {
                $raw = $name === 'INBOX' ? 'INBOX' : DhostingImap::encodeName($byName[mb_strtolower($name)]);
            } else {
                try {
                    $raw = $imap->create($name);
                } catch (DhostingImapCommandFailed $e) {
                    // serwer nie przyjął nazwy (niedozwolone znaki) — jeden folder nie może zatrzymać kopiowania całej skrzynki
                    if ($leaf === null || !DhostingImap::isBadName($e)) throw $e;
                    $strict = DhostingImap::strictLeafName($leaf);
                    $alt = $parentName !== null ? $parentName . $d . $strict : $strict;
                    if ($alt === $name) throw $e;
                    error_log('dhosting copy: folder „' . $name . '” odrzucony (' . $e->getMessage() . '), używam „' . $alt . '”');
                    $name = $alt;
                    $raw = isset($byName[mb_strtolower($name)]) ? DhostingImap::encodeName($byName[mb_strtolower($name)]) : $imap->create($name);
                }
                $byName[mb_strtolower($name)] = $name;
            }
            $out[] = ['id' => $f['id'], 'path' => $f['path'], 'special' => $wk, 'target' => $name, 'imap' => $raw,
                      'total' => (int)$f['total'], 'next' => '', 'done' => (int)$f['total'] === 0];
        }
        return ['folders' => $out, 'planned_at' => date('Y-m-d H:i:s')];
    }

    /** Kopiuje folder po folderze do $deadline. true = wszystko skopiowane. */
    private static function copyFolders(int $id, string $mailbox, DhostingGraphMail $graph, DhostingImap $imap, string $source, array &$state, array &$c, int $deadline): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dhm');
        $seen = db()->prepare("SELECT job_id, result FROM dhosting_copy_items WHERE mailbox=? AND msg_id=?");
        $ins  = db()->prepare("INSERT INTO dhosting_copy_items (mailbox, msg_id, job_id, result) VALUES (?, ?, ?, ?)");
        $upd  = db()->prepare("UPDATE dhosting_copy_items SET job_id=?, result=? WHERE mailbox=? AND msg_id=?");
        $mark = function (string $mid, int $result) use ($ins, $upd, $mailbox, $id): void {
            try { $ins->execute([$mailbox, $mid, $id, $result]); }
            catch (\Throwable $e) { $upd->execute([$id, $result, $mailbox, $mid]); }
        };
        // Czy ta wiadomość jest już gdzieś na nowym serwerze (dowolny folder, po Message-ID) — np. przeniesiona wcześniej ręcznie
        // albo leży w innym folderze. Pełne przeszukanie tylko dla wiadomości, które inaczej trafiłyby do wykazu nieudanych.
        $serverFolders = null;
        $onServer = function (array $m) use ($imap, &$state, &$serverFolders): ?string {
            $imid = trim((string)($m['internetMessageId'] ?? ''));
            if ($imid === '' || !empty($state['noSearch'])) return null;
            try { $serverFolders ??= array_values(array_filter($imap->listFolders(), fn($x) => !array_intersect(array_map('strtolower', $x['attrs']), ['\\noselect', '\\nonexistent']))); }
            catch (DhostingImapCommandFailed $e) { return null; }
            foreach ($serverFolders as $sf) {
                try { if ($imap->hasMessageId((string)$sf['raw'], $imid)) return (string)$sf['name']; }
                catch (DhostingImapCommandFailed $e) { continue; }
            }
            return null;
        };
        // nieudana wiadomość: najpierw sprawdzamy serwer; jest → liczymy jako przeniesioną (true), nie ma → rejestr + szczegóły
        // do wykazu nieudanych (folder z Outlooka, data, temat, powód), zwraca false
        $det  = db()->prepare("UPDATE dhosting_copy_items SET folder=?, subject=?, received_at=?, reason=?, failed_at=? WHERE mailbox=? AND msg_id=?");
        $details = function (string $mid, array $m, array $f, string $reason) use ($det, $mailbox): void {
            $rcv = (string)($m['receivedDateTime'] ?? $m['sentDateTime'] ?? '');
            try {
                $det->execute([mb_substr(implode(' / ', (array)($f['path'] ?? [])), 0, 255), mb_substr((string)($m['subject'] ?? ''), 0, 500),
                               $rcv !== '' && ($t = strtotime($rcv)) ? date('Y-m-d H:i:s', $t) : null, mb_substr($reason, 0, 500), date('Y-m-d H:i:s'), $mailbox, $mid]);
            } catch (\Throwable $e) { error_log('dhosting copy: wykaz nieudanych — ' . $e->getMessage()); }
        };
        $fail = function (string $mid, array $m, array $f, string $reason, bool $checked = false) use ($mark, $details, $onServer, &$state): bool {
            if (!$checked && ($where = $onServer($m)) !== null) {
                $mark($mid, 1);
                $state['present'] = (int)($state['present'] ?? 0) + 1;
                $state['foundElsewhere'] = (int)($state['foundElsewhere'] ?? 0) + 1;
                return true;
            }
            $mark($mid, 0);
            $details($mid, $m, $f, $reason);
            return false;
        };
        try {
            foreach ($state['folders'] as $fi => &$f) {
                if (!empty($f['done'])) continue;
                $resetOnce = false;
                while (true) {
                    if (time() >= $deadline) { self::save($id, $state, $c, null); return false; }
                    $cur = (string)$f['next'];
                    try {
                        $page = $graph->messagesPage($source, (string)$f['id'], $cur, !empty($f['since']) ? (int)$f['since'] : null);
                    } catch (DhostingGraphGone $e) {
                        $f['done'] = true; break;                         // folder usunięty w trakcie
                    } catch (DhostingGraphUnavailable | DhostingGraphDenied $e) {
                        throw $e;
                    } catch (DhostingGraphException $e) {
                        if ($cur === '' || $resetOnce) throw $e;           // przeterminowany nextLink — od początku folderu (duplikaty odsiewa rejestr)
                        $f['next'] = ''; $resetOnce = true; continue;
                    }
                    foreach ($page['items'] as $m) {
                        if (time() >= $deadline) { $f['next'] = $cur; self::save($id, $state, $c, null); return false; }
                        $mid = (string)$m['id'];
                        $rcv = (string)($m['receivedDateTime'] ?? '');
                        if ($rcv !== '' && strcmp($rcv, (string)($f['last'] ?? '')) > 0) $f['last'] = $rcv;   // do następnej kopii przyrostowej
                        $seen->execute([$mailbox, $mid]);
                        $prev = $seen->fetch(PDO::FETCH_ASSOC) ?: null;
                        $seen->closeCursor();
                        if ($prev) {
                            $mine = (int)$prev['job_id'] === $id;
                            if (in_array((int)$prev['result'], [1, 3], true) && !$mine) { $c['copied']++; continue; }   // przeniesiona wcześniej innym zadaniem — jest na serwerze
                            if ((int)$prev['result'] !== 0 || $mine) continue;                          // już policzona w tym zadaniu / zniknęła
                        }
                        // Porównanie z nową skrzynką (już w użyciu): ta sama wiadomość jest w folderze docelowym → nie dopisujemy.
                        $imid = (string)($m['internetMessageId'] ?? '');
                        if ($imid !== '' && empty($state['noSearch'])) {
                            try {
                                if ($imap->hasMessageId((string)$f['imap'], $imid)) {
                                    $mark($mid, 1); $c['copied']++; $state['present'] = (int)($state['present'] ?? 0) + 1;
                                    continue;
                                }
                            } catch (DhostingImapCommandFailed $e) {
                                $state['noSearch'] = true;   // serwer nie szuka po nagłówku — zostaje rejestr
                            }
                        }
                        $rebuilt = '';   // '' = oryginał; full / partial / noattach = odtworzona (DhostingGraphMail::buildMime, lastRebuild)
                        try {
                            $c['bytes'] += $graph->downloadMime($source, $mid, $tmp);
                        } catch (DhostingGraphGone $e) {
                            $mark($mid, 2); continue;        // wiadomość zniknęła w trakcie
                        } catch (DhostingGraphUnconvertible $e) {
                            // Graph nie oddaje oryginału tej wiadomości (stały błąd) — odtwarzamy ją z danych, coraz prostszymi próbami
                            // (treść HTML → tekst → sam początek); gdy nic nie wyjdzie — wykaz nieudanych, kopiowanie idzie dalej
                            try {
                                $c['bytes'] += $graph->buildMime($source, $mid, $tmp);
                                $rebuilt = $graph->lastRebuild ?: 'full';
                            } catch (DhostingGraphGone $e2) {
                                $mark($mid, 2); continue;
                            } catch (DhostingGraphUnavailable | DhostingGraphDenied $e2) {
                                throw $e2;
                            } catch (\Throwable $e2) {
                                error_log('dhosting copy: ' . $mailbox . ' — wiadomości ' . $mid . ' nie da się odtworzyć: ' . $e2->getMessage());
                                if ($fail($mid, $m, $f, 'Microsoft 365 nie oddał oryginału, a odtworzenie z treści się nie udało: ' . $e2->getMessage())) $c['copied']++;
                                else $c['failed']++;
                                continue;
                            }
                        }
                        $flags = [];
                        if (!empty($m['isRead'])) $flags[] = '\\Seen';
                        if (($m['flag']['flagStatus'] ?? '') === 'flagged') $flags[] = '\\Flagged';
                        if (!empty($m['isDraft'])) $flags[] = '\\Draft';
                        $ts = strtotime((string)($m['receivedDateTime'] ?? $m['sentDateTime'] ?? '')) ?: null;
                        try {
                            $imap->appendFile((string)$f['imap'], $tmp, $flags, $ts);
                        } catch (DhostingImapCommandFailed $e) {
                            // serwer nie przyjął (np. za duża): jest już w innym folderze? → przeniesiona; inaczej próba bez załączników
                            $why = 'Nowy serwer nie przyjął wiadomości: ' . $e->getMessage();
                            if ($onServer($m) !== null) {
                                $mark($mid, 1); $c['copied']++;
                                $state['present'] = (int)($state['present'] ?? 0) + 1;
                                $state['foundElsewhere'] = (int)($state['foundElsewhere'] ?? 0) + 1;
                                continue;
                            }
                            try {
                                $c['bytes'] += $graph->buildMime($source, $mid, $tmp, false);
                                $rebuilt = $graph->lastRebuild === 'partial' ? 'partial' : 'noattach';
                                $imap->appendFile((string)$f['imap'], $tmp, $flags, $ts);
                            } catch (DhostingGraphGone $e2) {
                                $mark($mid, 2); continue;
                            } catch (DhostingGraphUnavailable | DhostingGraphDenied $e2) {
                                throw $e2;
                            } catch (\Throwable $e2) {
                                $fail($mid, $m, $f, $why . ($e2 instanceof DhostingImapCommandFailed ? ' Wersji bez załączników też nie przyjął.'
                                    : ' Wersji bez załączników nie udało się przygotować: ' . $e2->getMessage()), true);
                                $c['failed']++;
                                continue;
                            }
                        }
                        $c['copied']++;
                        $state['appended'] = (int)($state['appended'] ?? 0) + 1;
                        if ($rebuilt !== '') $state['rebuilt'] = (int)($state['rebuilt'] ?? 0) + 1;
                        if ($rebuilt === 'partial' || $rebuilt === 'noattach') {   // jest na serwerze, ale niepełna — do wykazu
                            $mark($mid, 3);
                            $state['partial'] = (int)($state['partial'] ?? 0) + 1;
                            $details($mid, $m, $f, $rebuilt === 'partial'
                                ? 'Przeniesiona częściowo: Microsoft 365 nie oddał pełnej treści, jest jej początek (pełna wiadomość została w Microsoft 365).'
                                : 'Przeniesiona bez załączników: była za duża dla nowego serwera albo załączników nie dało się pobrać; zostały w Microsoft 365.');
                        } else {
                            $mark($mid, 1);
                        }
                        if (($c['copied'] + $c['failed']) % 25 === 0) self::save($id, $state, $c, null);
                    }
                    $f['next'] = (string)$page['next'];
                    if ($f['next'] === '') { $f['done'] = true; self::save($id, $state, $c, null); break; }
                }
            }
            unset($f);
            return true;
        } finally {
            @unlink($tmp);
        }
    }

    private static function save(int $id, array $state, array $c, ?string $err): void
    {
        db()->prepare("UPDATE dhosting_copy_jobs SET state_json=?, total_items=?, copied_items=?, failed_items=?, bytes=?, updated_at=? WHERE id=?")
            ->execute([$state ? json_encode($state, JSON_UNESCAPED_UNICODE) : null, max($c['total'], $c['copied'] + $c['failed']), $c['copied'], $c['failed'], $c['bytes'], date('Y-m-d H:i:s'), $id]);
    }

    /** Sprawdzenie hasła do skrzynki dhosting (samoobsługa). true = OK, null = serwer niedostępny, false = złe hasło. */
    public static function checkPassword(string $mailbox, string $password): ?bool
    {
        $imap = new DhostingImap(DhostingConfig::imapAddress(), 15);
        try {
            $imap->connect();
            $imap->login($mailbox, $password);
            $imap->logout();
            return true;
        } catch (DhostingImapAuthFailed $e) {
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
