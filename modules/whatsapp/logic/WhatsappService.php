<?php
/**
 * modules/whatsapp/logic/WhatsappService.php — wysyłka szablonów WhatsApp Business (kanał osobny od SMS; SMS-y zostają przy SerwerSMS).
 *
 * Wysyłka idzie WYŁĄCZNIE szablonami zatwierdzonymi przez Meta (tabela whatsapp_templates: klucz w SZO → nazwa szablonu u dostawcy,
 * język, treść z {{1}} {{2}}…). Szablon „marketing” wymaga zgody odbiorcy (opts['consent']) — bez niej wysyłka jest odrzucana.
 * Gdy bramka zawiedzie (albo WhatsApp jest wyłączony) i szablon ma sms_fallback=1, ta sama treść idzie zwykłym SMS-em przez łańcuch
 * dostawców SMS (rodzaj 'whatsapp_fallback' — nadawca wg Ustawień SMS). Każda próba trafia do whatsapp_messages (numer tylko końcówką).
 *
 * Ustawienia (settings): whatsapp_enabled, whatsapp_api_url, whatsapp_auth_header, whatsapp_auth_prefix, whatsapp_api_token,
 * whatsapp_body_template, whatsapp_ok_path, whatsapp_id_path — opis w lib/WhatsappHttpGateway.php.
 */
require_once dirname(__DIR__) . '/lib/WhatsappHttpGateway.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class WhatsappService
{
    public const CATEGORIES = ['transactional' => 'Transakcyjny (informacja o usłudze)', 'marketing' => 'Marketingowy (wymaga zgody)'];
    public const BODY_MAX = 1024;   // limit treści szablonu WhatsApp

    // ── ustawienia ──────────────────────────────────────────────────────────────────────────────────────────────────
    public static function setting(string $key): string
    {
        return (string)(db_one("SELECT value FROM settings WHERE key_=?", [$key])['value'] ?? '');
    }

    public static function settingSet(string $key, string $value): void
    {
        if (db_one("SELECT 1 FROM settings WHERE key_=?", [$key])) {
            db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
        } else {
            db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $value]);
        }
    }

    public static function isEnabled(): bool { return self::setting('whatsapp_enabled') === '1'; }

    public static function gateway(): WhatsappHttpGateway
    {
        return new WhatsappHttpGateway([
            'url' => self::setting('whatsapp_api_url'),
            'auth_header' => self::setting('whatsapp_auth_header') ?: 'Authorization',
            'auth_prefix' => self::setting('whatsapp_auth_prefix') !== '' ? self::setting('whatsapp_auth_prefix') : 'Bearer ',
            'token' => self::setting('whatsapp_api_token'),
            'body_template' => self::setting('whatsapp_body_template'),
            'ok_path' => self::setting('whatsapp_ok_path'),
            'id_path' => self::setting('whatsapp_id_path'),
        ]);
    }

    // ── schemat ─────────────────────────────────────────────────────────────────────────────────────────────────────
    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tpl_key TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            provider_name TEXT NOT NULL,
            language TEXT NOT NULL DEFAULT 'pl',
            body TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'transactional',
            sms_fallback INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tpl_key TEXT NOT NULL,
            number_tail TEXT,
            channel TEXT NOT NULL,
            status TEXT NOT NULL,
            provider_msg_id TEXT,
            error TEXT,
            user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whatsapp_messages_created ON whatsapp_messages(created_at)");
    }

    // ── szablony ────────────────────────────────────────────────────────────────────────────────────────────────────
    /** @return list<array<string,mixed>> */
    public static function templates(): array
    {
        self::migrate();
        return db_all("SELECT * FROM whatsapp_templates ORDER BY label COLLATE NOCASE");
    }

    public static function template(string $key): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM whatsapp_templates WHERE tpl_key=?", [$key]);
    }

    /** Ile parametrów wymaga treść: największy numer {{n}}. */
    public static function paramCount(string $body): int
    {
        return preg_match_all('/\{\{(\d+)\}\}/', $body, $m) ? max(array_map('intval', $m[1])) : 0;
    }

    /** Podstawia {{1}}, {{2}}… wartościami (brakujące → InvalidArgumentException). */
    public static function render(string $body, array $params): string
    {
        $need = self::paramCount($body);
        $params = array_values(array_map('strval', $params));
        if (count($params) < $need) throw new \InvalidArgumentException('Szablon wymaga ' . $need . ' parametrów, podano ' . count($params) . '.');
        return (string)preg_replace_callback('/\{\{(\d+)\}\}/', static fn($m) => $params[(int)$m[1] - 1] ?? '', $body);
    }

    /** Dodaje albo aktualizuje szablon po kluczu. @throws \InvalidArgumentException przy błędnych danych */
    public static function saveTemplate(array $d): int
    {
        self::migrate();
        $key = trim((string)($d['tpl_key'] ?? ''));
        $label = trim((string)($d['label'] ?? ''));
        $prov = trim((string)($d['provider_name'] ?? ''));
        $lang = trim((string)($d['language'] ?? 'pl')) ?: 'pl';
        $body = trim((string)($d['body'] ?? ''));
        $cat = (string)($d['category'] ?? 'transactional');
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) throw new \InvalidArgumentException('Klucz szablonu: 2–40 znaków a–z, 0–9, _.');
        if ($label === '') throw new \InvalidArgumentException('Podaj nazwę szablonu.');
        if ($prov === '' || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $prov)) throw new \InvalidArgumentException('Nazwa szablonu u dostawcy: do 80 znaków, litery, cyfry, _ . -');
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $lang)) throw new \InvalidArgumentException('Język w formacie pl albo pl_PL.');
        if ($body === '' || mb_strlen($body) > self::BODY_MAX) throw new \InvalidArgumentException('Treść szablonu: 1–' . self::BODY_MAX . ' znaków.');
        if (!isset(self::CATEGORIES[$cat])) throw new \InvalidArgumentException('Nieznana kategoria szablonu.');
        $args = [$label, $prov, $lang, $body, $cat, !empty($d['sms_fallback']) ? 1 : 0, !empty($d['active']) ? 1 : 0];
        if ($ex = db_one("SELECT id FROM whatsapp_templates WHERE tpl_key=?", [$key])) {
            db()->prepare("UPDATE whatsapp_templates SET label=?, provider_name=?, language=?, body=?, category=?, sms_fallback=?, active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([...$args, (int)$ex['id']]);
            return (int)$ex['id'];
        }
        db()->prepare("INSERT INTO whatsapp_templates (label, provider_name, language, body, category, sms_fallback, active, tpl_key) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([...$args, $key]);
        return (int)db()->lastInsertId();
    }

    public static function deleteTemplate(int $id): void
    {
        self::migrate();
        db()->prepare("DELETE FROM whatsapp_templates WHERE id=?")->execute([$id]);
    }

    // ── numery ──────────────────────────────────────────────────────────────────────────────────────────────────────
    /** Numer do E.164 bez plusa: polski 9-cyfrowy → 48…, „+”/„00” + kierunkowy zostaje; null = nie wygląda na numer. */
    public static function toE164(string $raw): ?string
    {
        $compact = preg_replace('/[^\d+]/', '', trim($raw));
        if ($compact === '') return null;
        $intl = str_starts_with($compact, '+') || str_starts_with($compact, '00');
        $d = preg_replace('/\D/', '', $compact);
        if (str_starts_with($d, '00')) $d = substr($d, 2);
        if (!$intl && strlen($d) === 9) return '48' . $d;
        if (strlen($d) >= 8 && strlen($d) <= 15 && ($intl || (strlen($d) === 11 && str_starts_with($d, '48')))) return $d;
        return null;
    }

    private static function tail(string $e164): string { return '***' . substr($e164, -3); }

    // ── wysyłka ─────────────────────────────────────────────────────────────────────────────────────────────────────
    /**
     * Wysyła szablon. $opts: consent (bool, zgoda odbiorcy — wymagana dla szablonu marketingowego), smsFallback (?bool — nadpisuje
     * flagę szablonu), userId (?int, do dziennika).
     *
     * @param list<string|int|float> $params
     * @return array{channel:string,id:string,provider:string,error:string} channel: 'whatsapp' | 'sms' (zapasowy)
     * @throws \InvalidArgumentException zły szablon / numer / liczba parametrów; \DomainException brak zgody (marketing);
     *         \RuntimeException wysyłka nieudana (i zapasowy SMS też, jeśli był dozwolony)
     */
    public static function send(string $phone, string $tplKey, array $params = [], array $opts = []): array
    {
        self::migrate();
        $uid = $opts['userId'] ?? (PHP_SAPI === 'cli' ? 0 : null);
        $tpl = self::template($tplKey);
        if (!$tpl || !(int)$tpl['active']) throw new \InvalidArgumentException('Szablon WhatsApp „' . $tplKey . '” nie istnieje albo jest nieaktywny.');
        $to = self::toE164($phone);
        if ($to === null) throw new \InvalidArgumentException('Numer telefonu nie wygląda na poprawny.');
        $text = self::render((string)$tpl['body'], $params);   // sprawdza też liczbę parametrów

        if ($tpl['category'] === 'marketing' && empty($opts['consent'])) {
            self::log($tplKey, $to, 'none', 'refused_consent', '', 'Szablon marketingowy bez zgody odbiorcy.', $uid);
            throw new \DomainException('Szablon marketingowy wymaga zgody odbiorcy na komunikację przez WhatsApp.');
        }
        $fallback = ($opts['smsFallback'] ?? null) !== null ? (bool)$opts['smsFallback'] : (bool)(int)$tpl['sms_fallback'];

        $waError = null;
        $gw = self::gateway();
        if (!self::isEnabled()) $waError = 'WhatsApp jest wyłączony w ustawieniach.';
        elseif (!$gw->isConfigured()) $waError = 'Bramka WhatsApp nie jest skonfigurowana.';
        else {
            try {
                $r = $gw->send($to, (string)$tpl['provider_name'], (string)$tpl['language'], array_map('strval', array_values($params)), $text);
                self::log($tplKey, $to, 'whatsapp', 'sent', $r['id'], null, $uid);
                audit_log('whatsapp.sent', ['tpl' => $tplKey, 'to' => self::tail($to)], $uid);
                return ['channel' => 'whatsapp', 'id' => $r['id'], 'provider' => 'whatsapp', 'error' => ''];
            } catch (\Throwable $e) {
                $waError = $e->getMessage();
            }
        }

        if (!$fallback) {
            self::log($tplKey, $to, 'none', 'failed', '', $waError, $uid);
            audit_log('whatsapp.failed', ['tpl' => $tplKey, 'to' => self::tail($to), 'error' => mb_substr((string)$waError, 0, 200)], $uid);
            throw new \RuntimeException('Wysyłka WhatsApp nieudana: ' . $waError);
        }

        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        try {
            $provider = sms_send($to, $text, 'whatsapp_fallback');   // literał rodzaju — wymaga go cli/smsKindsLint.php
        } catch (\Throwable $e) {
            $msg = 'WhatsApp: ' . $waError . ' | SMS zapasowy: ' . $e->getMessage();
            self::log($tplKey, $to, 'none', 'failed', '', $msg, $uid);
            audit_log('whatsapp.failed', ['tpl' => $tplKey, 'to' => self::tail($to), 'error' => mb_substr($msg, 0, 200)], $uid);
            throw new \RuntimeException('Wysyłka nieudana — WhatsApp i zapasowy SMS. ' . $msg);
        }
        self::log($tplKey, $to, 'sms', 'fallback_sms', '', $waError, $uid);
        audit_log('whatsapp.fallback', ['tpl' => $tplKey, 'to' => self::tail($to), 'via' => $provider], $uid);
        return ['channel' => 'sms', 'id' => '', 'provider' => (string)$provider, 'error' => (string)$waError];
    }

    private static function log(string $tpl, string $e164, string $channel, string $status, string $msgId, ?string $error, ?int $uid): void
    {
        db()->prepare("INSERT INTO whatsapp_messages (tpl_key, number_tail, channel, status, provider_msg_id, error, user_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$tpl, self::tail($e164), $channel, $status, $msgId !== '' ? $msgId : null, $error !== null ? mb_substr($error, 0, 500) : null, $uid ?: null]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 30): array
    {
        self::migrate();
        return db_all("SELECT m.*, u.name AS user_name FROM whatsapp_messages m LEFT JOIN users u ON u.id = m.user_id ORDER BY m.id DESC LIMIT " . max(1, min(200, $limit)));
    }
}
