<?php
/**
 * modules/sms_delivery/logic/sendlyDelivery.php — potwierdzanie doręczeń Sendly i ponowna wysyłka przez innego operatora
 * (funkcjonalność współdzielona — działa dla każdego SMS-a wysłanego przez Sendly, z łańcucha i z „preferowanego” dostawcy).
 *
 * Problem: Sendly przyjmuje SMS (HTTP 2xx + message_id), a o niedoręczeniu informuje dopiero webhookiem (DELIVERED / ERROR);
 * API nie ma zapytania o status. Dla systemu SMS był „wysłany”, więc fallback na smsapi nigdy nie ruszał — hasła i kody
 * nie docierały.
 *
 * Rozwiązanie:
 *   • po przyjęciu przez Sendly zapisujemy message_id + numer + treść (ZASZYFROWANA, AES-256-CBC kluczem z APP_KEY;
 *     w treści bywają hasła i kody) w sms_sendly_track;
 *   • webhook api/sendly_webhook.php?k=<klucz> (adres do wklejenia w panelu Sendly: Bramka SMS → Ustawienia usługi →
 *     raporty doręczeń): DELIVERED → koniec, treść kasowana; ERROR → od razu ten sam SMS przez innego operatora;
 *   • CRON (agent sms_sendly_watch, co 2 min): SMS bez potwierdzenia po sms_sendly_confirm_minutes (domyślnie 4) idzie
 *     ponownie przez innego operatora — tylko gdy webhook działa (dostał coś w ostatnich 7 dniach), inaczej każdy SMS
 *     poszedłby dwa razy;
 *   • treść kasowana po doręczeniu / ponownej wysyłce / 24 h.
 */

const SENDLY_TRACK_TTL_HOURS = 24;

function sendlyDeliveryMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS sms_sendly_track (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id   TEXT    NOT NULL,
        phone        TEXT    NOT NULL,
        message_enc  TEXT    NOT NULL DEFAULT '',
        kind         TEXT    NOT NULL DEFAULT '',
        status       TEXT    NOT NULL DEFAULT 'sent',   -- sent | delivered | error | resent | resend_failed | expired
        resent_via   TEXT    NOT NULL DEFAULT '',
        error        TEXT    NOT NULL DEFAULT '',
        created_at   TEXT    NOT NULL,
        updated_at   TEXT    NOT NULL
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_sms_sendly_track_mid ON sms_sendly_track(message_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS ix_sms_sendly_track_status ON sms_sendly_track(status, created_at)");
}

function sendlyEncrypt(string $plain): string
{
    $key = substr(hash('sha256', 'sendly-track|' . APP_KEY, true), 0, 32);
    $iv = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}

function sendlyDecrypt(string $enc): ?string
{
    if ($enc === '') return null;
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= 16) return null;
    $key = substr(hash('sha256', 'sendly-track|' . APP_KEY, true), 0, 32);
    $dec = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $dec === false ? null : $dec;
}

/** message_id z odpowiedzi Sendly (różne kształty: message_id / id / data.message_id / messages[0].message_id). */
function sendlyMessageId(string $resp): string
{
    $d = json_decode($resp, true);
    if (!is_array($d)) return '';
    foreach ([$d, $d['data'] ?? null, $d['messages'][0] ?? null, $d['data']['messages'][0] ?? null] as $x) {
        if (!is_array($x)) continue;
        foreach (['message_id', 'messageId', 'id'] as $k) {
            if (isset($x[$k]) && is_scalar($x[$k]) && (string)$x[$k] !== '') return (string)$x[$k];
        }
    }
    return '';
}

/** Zapamiętuje SMS przyjęty przez Sendly do potwierdzenia doręczenia. Nigdy nie przerywa wysyłki. */
function sendlyTrack(string $messageId, string $phone, string $message, string $kind): void
{
    if ($messageId === '') return;
    try {
        sendlyDeliveryMigrate();
        $now = date('Y-m-d H:i:s');
        db_exec("INSERT OR IGNORE INTO sms_sendly_track (message_id, phone, message_enc, kind, status, created_at, updated_at)
                 VALUES (?,?,?,?, 'sent', ?, ?)", [$messageId, $phone, sendlyEncrypt($message), $kind, $now, $now]);
    } catch (\Throwable $e) {
        error_log('[SMS] sendly track: ' . $e->getMessage());
    }
}

/** Klucz w adresie webhooka (tworzony przy pierwszym użyciu). */
function sendlyWebhookKey(): string
{
    $k = (string)org_setting('sms_sendly_webhook_key');
    if ($k === '') {
        $k = bin2hex(random_bytes(16));
        org_setting_set('sms_sendly_webhook_key', $k);
    }
    return $k;
}

function sendlyWebhookUrl(): string
{
    return rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/api/sendly_webhook.php?k=' . sendlyWebhookKey();
}

/** Czy webhook Sendly działa (przyszło coś w ostatnich 7 dniach) — warunek ponownej wysyłki po czasie. */
function sendlyWebhookAlive(): bool
{
    $t = (string)org_setting('sms_sendly_webhook_last');
    return $t !== '' && strtotime($t) > time() - 7 * 86400;
}

/**
 * Dostawcy do ponownej wysyłki SMS-a niedoręczonego przez Sendly: łańcuch systemowy + smsapi, bez Sendly,
 * tylko z kompletną konfiguracją.
 */
function sendlyFallbackProviders(): array
{
    $out = [];
    foreach (array_merge(sms_provider_chain(), ['smsapi', 'serwersms', 'twilio', 'httprequest']) as $p) {
        if ($p !== 'sendly' && !in_array($p, $out, true) && sms_provider_ready($p)) $out[] = $p;
    }
    return $out;
}

/** Wysyła ponownie SMS z wiersza śledzenia przez innego operatora; zapisuje wynik i kasuje treść. */
function sendlyResend(array $row, string $reason): bool
{
    $msg = sendlyDecrypt((string)$row['message_enc']);
    if ($msg === null) {
        db_exec("UPDATE sms_sendly_track SET status='resend_failed', error=?, message_enc='', updated_at=datetime('now') WHERE id=?",
            ['brak treści do ponownej wysyłki', (int)$row['id']]);
        return false;
    }
    $errors = ['sendly' => $reason];
    foreach (sendlyFallbackProviders() as $p) {
        try {
            _sms_dispatch($p, (string)$row['phone'], $msg, (string)$row['kind']);
            db_exec("UPDATE sms_sendly_track SET status='resent', resent_via=?, error=?, message_enc='', updated_at=datetime('now') WHERE id=?",
                [$p, mb_substr($reason, 0, 300), (int)$row['id']]);
            sms_log_event('sendly_undelivered', (string)$row['phone'], $errors, $p, (string)$row['kind']);
            return true;
        } catch (\Throwable $e) {
            $errors[$p] = $e->getMessage();
        }
    }
    db_exec("UPDATE sms_sendly_track SET status='resend_failed', error=?, message_enc='', updated_at=datetime('now') WHERE id=?",
        [mb_substr(implode(' | ', array_map('sms_scrub', $errors)), 0, 500), (int)$row['id']]);
    sms_log_event('failed', (string)$row['phone'], $errors, '', (string)$row['kind']);
    return false;
}

/**
 * Raport doręczenia z webhooka Sendly. Zwraca opis do odpowiedzi (diagnostyka); nieznany message_id też kończy się
 * sukcesem, żeby Sendly nie ponawiał.
 */
function sendlyHandleReport(string $messageId, string $status, string $detail = ''): string
{
    sendlyDeliveryMigrate();
    org_setting_set('sms_sendly_webhook_last', date('Y-m-d H:i:s'));
    $row = db_one("SELECT * FROM sms_sendly_track WHERE message_id = ?", [$messageId]);
    if (!$row) return 'unknown';
    $status = strtoupper(trim($status));
    if ($status === 'DELIVERED') {
        if ($row['status'] === 'sent') db_exec("UPDATE sms_sendly_track SET status='delivered', message_enc='', updated_at=datetime('now') WHERE id=?", [(int)$row['id']]);
        return 'delivered';
    }
    if (in_array($status, ['ERROR', 'FAILED', 'UNDELIVERED', 'REJECTED', 'EXPIRED'], true)) {
        if ($row['status'] !== 'sent') return 'already';
        db_exec("UPDATE sms_sendly_track SET status='error', updated_at=datetime('now') WHERE id=?", [(int)$row['id']]);
        return sendlyResend($row, 'Sendly: niedoręczony (' . $status . ($detail !== '' ? ' — ' . $detail : '') . ')') ? 'resent' : 'resend_failed';
    }
    return 'ignored';
}

/**
 * CRON: SMS-y Sendly bez potwierdzenia po N minutach → ponowna wysyłka (tylko przy działającym webhooku);
 * treść starsza niż 24 h jest kasowana. Zwraca [ponownie wysłane, nieudane, wygaszone].
 */
function sendlyWatch(): array
{
    sendlyDeliveryMigrate();
    $resent = 0; $failed = 0;
    if (sendlyWebhookAlive()) {
        $min = max(2, (int)(org_setting('sms_sendly_confirm_minutes') ?: 4));
        $rows = db_all("SELECT * FROM sms_sendly_track WHERE status='sent' AND created_at <= ? AND created_at >= ? ORDER BY id LIMIT 50",
            [date('Y-m-d H:i:s', time() - $min * 60), date('Y-m-d H:i:s', time() - 3 * 3600)]);
        foreach ($rows as $r) {
            sendlyResend($r, 'Sendly: brak potwierdzenia doręczenia po ' . $min . ' min') ? $resent++ : $failed++;
        }
    }
    $cut = date('Y-m-d H:i:s', time() - SENDLY_TRACK_TTL_HOURS * 3600);
    $expired = (int)(db_one("SELECT COUNT(*) n FROM sms_sendly_track WHERE status='sent' AND created_at < ?", [$cut])['n'] ?? 0);
    db_exec("UPDATE sms_sendly_track SET status='expired', message_enc='', updated_at=datetime('now') WHERE status='sent' AND created_at < ?", [$cut]);
    db_exec("UPDATE sms_sendly_track SET message_enc='' WHERE message_enc != '' AND created_at < ?", [$cut]);
    db_exec("DELETE FROM sms_sendly_track WHERE created_at < ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
    return [$resent, $failed, $expired];
}

/** Statystyka do Ustawień SMS: ostatnie 7 dni wg statusu. */
function sendlyStats(): array
{
    sendlyDeliveryMigrate();
    $out = [];
    foreach (db_all("SELECT status, COUNT(*) n FROM sms_sendly_track WHERE created_at >= ? GROUP BY status", [date('Y-m-d H:i:s', time() - 7 * 86400)]) as $r) {
        $out[$r['status']] = (int)$r['n'];
    }
    return $out;
}
