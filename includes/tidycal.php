<?php
/**
 * includes/tidycal.php — Integracja z TidyCal (rezerwacja terminów szkoleń).
 *
 * Model: organizacja konfiguruje JEDEN token API TidyCal (Personal Access
 * Token) w panelu admina. Wolontariusze/kursanci rezerwują termin szkolenia
 * z panelu lub z portalu — kreator pobiera typy szkoleń i wolne terminy przez
 * REST API (https://tidycal.com/api), a rezerwacja powstaje przez
 * POST /booking-types/{id}/bookings. Każda rezerwacja jest logowana lokalnie
 * (tabela tidycal_bookings) i potwierdzana e-mailem.
 *
 * Gdy typ szkolenia ma wymagane pytania lub API zwróci błąd — kreator
 * degraduje się do osadzonej (iframe) hostowanej strony TidyCal.
 *
 * Ustawienia (tabela settings, klucze):
 *   tidycal_enabled        '1'/'0'  — master switch (module_enabled)
 *   tidycal_api_key        token Bearer
 *   tidycal_account_slug   nazwa konta (do budowy publicznych URL-i)
 *   tidycal_account_name   nazwa konta (podgląd w adminie)
 *   tidycal_types_cache    JSON listy typów szkoleń (po teście połączenia)
 *   tidycal_exposed_types  JSON listy ID typów udostępnionych użytkownikom
 *   tidycal_intro          tekst wprowadzający na stronie rezerwacji
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ── Self-migracja: seed master switch + tabela logu rezerwacji ────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!db_one("SELECT 1 FROM settings WHERE key_='tidycal_enabled'")) {
            db()->prepare("INSERT INTO settings (key_, value) VALUES ('tidycal_enabled','1')")->execute();
        }
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS tidycal_bookings (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id            INTEGER REFERENCES users(id) ON DELETE SET NULL,
            booking_type_id    INTEGER NOT NULL,
            booking_type_title TEXT    NOT NULL DEFAULT '',
            tidycal_booking_id INTEGER,
            starts_at          TEXT,
            ends_at            TEXT,
            timezone           TEXT    NOT NULL DEFAULT 'Europe/Warsaw',
            name               TEXT    NOT NULL DEFAULT '',
            email              TEXT,
            status             TEXT    NOT NULL DEFAULT 'booked',
            meeting_url        TEXT,
            source             TEXT    NOT NULL DEFAULT 'panel',
            created_at         DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_tc_user ON tidycal_bookings(user_id)");
    } catch (\Throwable $e) {}
})();

/**
 * Klient REST API TidyCal (Bearer token).
 * Wzorowany na includes/furgonetka.php (stream_context + request/get/post).
 */
class TidyCal
{
    const BASE = 'https://tidycal.com/api';

    private string $token;

    public function __construct()
    {
        $this->token = trim(org_setting('tidycal_api_key'));
    }

    public function is_configured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Wykonuje uwierzytelnione żądanie do API TidyCal.
     * @throws RuntimeException przy błędzie HTTP / API.
     */
    private function request(string $method, string $path, array $data = [], array $query = []): array
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('Brak tokenu API TidyCal — skonfiguruj integrację.');
        }

        $url = self::BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = "Authorization: Bearer {$this->token}\r\nAccept: application/json\r\n";
        $content = '';
        if (strtoupper($method) !== 'GET' && !empty($data)) {
            $content  = json_encode($data);
            $headers .= "Content-Type: application/json\r\n";
        }

        $ctx_options = [
            'method'        => strtoupper($method),
            'header'        => $headers,
            'ignore_errors' => true,
            'timeout'       => 20,
        ];
        if ($content !== '') {
            $ctx_options['content'] = $content;
        }

        $ctx  = stream_context_create(['http' => $ctx_options]);
        $resp = @file_get_contents($url, false, $ctx);

        // Status HTTP z nagłówków odpowiedzi (zgodnie z PHP 8.4+).
        $hdrs = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: [])
            : ($http_response_header ?? []);
        $status = 0;
        if (isset($hdrs[0]) && preg_match('#\s(\d{3})\s#', $hdrs[0], $m)) {
            $status = (int)$m[1];
        }

        if ($resp === false || $resp === '' || $resp === null) {
            if ($status >= 200 && $status < 300) return ['_ok' => true];
            throw new RuntimeException('Brak odpowiedzi z TidyCal API (HTTP ' . $status . ').');
        }

        $arr = json_decode($resp, true);
        if (!is_array($arr)) {
            throw new RuntimeException('Nieprawidłowa odpowiedź z TidyCal API: ' . substr($resp, 0, 200));
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Token TidyCal odrzucony (HTTP ' . $status . '). Sprawdź klucz API.');
        }
        if ($status >= 400 || isset($arr['error']) || (isset($arr['message']) && $status >= 400)) {
            $msg = $arr['message'] ?? $arr['error'] ?? ('HTTP ' . $status);
            if (!empty($arr['errors']) && is_array($arr['errors'])) {
                $flat = [];
                foreach ($arr['errors'] as $e) { $flat[] = is_array($e) ? implode(' ', $e) : (string)$e; }
                $msg .= ' — ' . implode('; ', $flat);
            }
            throw new RuntimeException('TidyCal API: ' . $msg);
        }

        return $arr;
    }

    private function get(string $path, array $query = []): array { return $this->request('GET', $path, [], $query); }
    private function post(string $path, array $data = []): array { return $this->request('POST', $path, $data); }

    /** Dane zalogowanego konta (GET /me). */
    public function me(): array { return $this->get('/me'); }

    /** Lista typów szkoleń/spotkań (GET /booking-types). Zwraca tablicę pozycji. */
    public function bookingTypes(): array
    {
        $r = $this->get('/booking-types');
        return $r['data'] ?? (isset($r[0]) ? $r : []);
    }

    /**
     * Wolne terminy dla typu w przedziale dat (GET /booking-types/{id}/timeslots).
     * @param string $from Data ISO (np. 2026-06-20T00:00:00Z)
     * @param string $to   Data ISO
     */
    public function timeslots(int $typeId, string $from, string $to): array
    {
        $r = $this->get("/booking-types/{$typeId}/timeslots", [
            'starts_at' => $from,
            'ends_at'   => $to,
        ]);
        return $r['data'] ?? (isset($r[0]) ? $r : []);
    }

    /**
     * Tworzy rezerwację (POST /booking-types/{id}/bookings).
     * @param array $payload starts_at, name, email, timezone (+ ewentualne odpowiedzi)
     */
    public function createBooking(int $typeId, array $payload): array
    {
        return $this->post("/booking-types/{$typeId}/bookings", $payload);
    }
}

// ── Helpery integracji ────────────────────────────────────────────────────────

/** Czy moduł rezerwacji szkoleń jest aktywny i skonfigurowany. */
function tidycal_enabled(): bool
{
    return module_enabled('tidycal_enabled') && trim(org_setting('tidycal_api_key')) !== '';
}

/** Cache listy typów szkoleń (zapisany po teście połączenia). */
function tidycal_types_cache(): array
{
    $raw = org_setting('tidycal_types_cache');
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}

/** Zapisz cache typów (znormalizowane pola). */
function tidycal_set_types_cache(array $types): void
{
    $slug = trim(org_setting('tidycal_account_slug'));
    $norm = [];
    foreach ($types as $t) {
        $id = (int)($t['id'] ?? 0);
        if ($id <= 0) continue;
        $type_slug = (string)($t['url_slug'] ?? $t['slug'] ?? '');
        $public    = (string)($t['url'] ?? '');
        if ($public === '' && $slug !== '' && $type_slug !== '') {
            $public = 'https://tidycal.com/' . $slug . '/' . $type_slug;
        } elseif ($public === '' && $type_slug !== '') {
            $public = 'https://tidycal.com/' . $type_slug;
        }
        $norm[] = [
            'id'          => $id,
            'title'       => (string)($t['title'] ?? ('Szkolenie #' . $id)),
            'description' => (string)($t['description'] ?? ''),
            'duration'    => (int)($t['duration_minutes'] ?? 0),
            'url_slug'    => $type_slug,
            'public_url'  => $public,
        ];
    }
    org_setting_set('tidycal_types_cache', json_encode($norm, JSON_UNESCAPED_UNICODE));
}

/** ID typów udostępnionych użytkownikom (przecięcie z cache). */
function tidycal_exposed_ids(): array
{
    $raw = org_setting('tidycal_exposed_types');
    $ids = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    return array_values(array_filter(array_map('intval', (array)$ids)));
}

/** Typy szkoleń udostępnione użytkownikom (z cache, w kolejności cache). */
function tidycal_exposed_types(): array
{
    $exposed = tidycal_exposed_ids();
    if (!$exposed) return [];
    $out = [];
    foreach (tidycal_types_cache() as $t) {
        if (in_array((int)$t['id'], $exposed, true)) $out[] = $t;
    }
    return $out;
}

/** Pojedynczy udostępniony typ po ID lub null. */
function tidycal_exposed_type(int $id): ?array
{
    foreach (tidycal_exposed_types() as $t) {
        if ((int)$t['id'] === $id) return $t;
    }
    return null;
}

/** Zapisz rezerwację w lokalnym logu. Zwraca ID wiersza. */
function tidycal_log_booking(array $row): int
{
    return (int)db_insert('tidycal_bookings', [
        'user_id'            => $row['user_id'] ?? null,
        'booking_type_id'    => (int)($row['booking_type_id'] ?? 0),
        'booking_type_title' => (string)($row['booking_type_title'] ?? ''),
        'tidycal_booking_id' => $row['tidycal_booking_id'] ?? null,
        'starts_at'          => $row['starts_at'] ?? null,
        'ends_at'            => $row['ends_at'] ?? null,
        'timezone'           => (string)($row['timezone'] ?? 'Europe/Warsaw'),
        'name'               => (string)($row['name'] ?? ''),
        'email'              => $row['email'] ?? null,
        'status'             => (string)($row['status'] ?? 'booked'),
        'meeting_url'        => $row['meeting_url'] ?? null,
        'source'             => (string)($row['source'] ?? 'panel'),
    ]);
}

/** Rezerwacje danego użytkownika (najnowsze pierwsze). */
function tidycal_user_bookings(int $uid): array
{
    if ($uid <= 0) return [];
    try {
        return db_all("SELECT * FROM tidycal_bookings WHERE user_id=? ORDER BY datetime(starts_at) DESC, id DESC", [$uid]);
    } catch (\Throwable $e) { return []; }
}

/** Wszystkie rezerwacje (dla admina). */
function tidycal_all_bookings(int $limit = 200): array
{
    try {
        return db_all(
            "SELECT b.*, u.name AS user_name, u.email AS user_email
             FROM tidycal_bookings b LEFT JOIN users u ON u.id=b.user_id
             ORDER BY b.id DESC LIMIT " . (int)$limit
        );
    } catch (\Throwable $e) { return []; }
}

/** Sformatuj termin ISO na czytelną postać w danej strefie. */
function tidycal_fmt_dt(?string $iso, string $tz = 'Europe/Warsaw'): string
{
    if (!$iso) return '—';
    try {
        $dt = new DateTime($iso);
        $dt->setTimezone(new DateTimeZone($tz ?: 'Europe/Warsaw'));
        return $dt->format('Y-m-d H:i');
    } catch (\Throwable $e) {
        return (string)$iso;
    }
}

// ── Obsługa AJAX / POST kreatora rezerwacji (wspólna dla panelu i portalu) ─────

/**
 * Obsługuje AJAX pobrania wolnych terminów. Wywołać NA POCZĄTKU strony,
 * przed jakimkolwiek wyjściem HTML — przy trafieniu kończy żądanie (exit).
 */
function tidycal_handle_slots_ajax(): void
{
    if (($_GET['_ajax'] ?? '') !== 'tc_slots') return;
    header('Content-Type: application/json; charset=utf-8');

    $typeId = (int)($_GET['type'] ?? 0);
    if (!tidycal_exposed_type($typeId)) {
        echo json_encode(['ok' => false, 'error' => 'Nieznany typ szkolenia.']); exit;
    }
    $fromTs = strtotime((string)($_GET['from'] ?? ''));
    $toTs   = strtotime((string)($_GET['to'] ?? ''));
    if (!$fromTs || !$toTs || $toTs < $fromTs) {
        echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy zakres dat.']); exit;
    }
    try {
        $api   = new TidyCal();
        $slots = $api->timeslots($typeId, gmdate('Y-m-d\TH:i:s\Z', $fromTs), gmdate('Y-m-d\TH:i:s\Z', $toTs));
        $out   = [];
        foreach ($slots as $s) {
            if (empty($s['starts_at'])) continue;
            $out[] = ['starts_at' => (string)$s['starts_at'], 'ends_at' => (string)($s['ends_at'] ?? '')];
        }
        echo json_encode(['ok' => true, 'slots' => $out]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Obsługuje POST rezerwacji. Wywołać przed nagłówkiem.
 * $ctx: ['user_id'=>int|null, 'self_url'=>string, 'source'=>'panel'|'portal',
 *        'default_name'=>string, 'default_email'=>string]
 * Po przetworzeniu ustawia flash i przekierowuje na self_url (exit).
 */
function tidycal_handle_booking_post(array $ctx): void
{
    if (!isset($_POST['_tc_book'])) return;
    csrf_check();

    $self   = (string)($ctx['self_url'] ?? '');
    $typeId = (int)($_POST['type'] ?? 0);
    $type   = tidycal_exposed_type($typeId);
    if (!$type) {
        flash_set('danger', 'Nieznany typ szkolenia.');
        header('Location: ' . $self); exit;
    }

    $starts = trim($_POST['starts_at'] ?? '');
    $name   = trim($_POST['name'] ?? '')  ?: (string)($ctx['default_name'] ?? '');
    $email  = trim($_POST['email'] ?? '') ?: (string)($ctx['default_email'] ?? '');
    $tz     = trim($_POST['timezone'] ?? '') ?: 'Europe/Warsaw';

    if ($starts === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('danger', 'Uzupełnij termin, imię oraz poprawny adres e-mail.');
        header('Location: ' . $self); exit;
    }

    try {
        $api  = new TidyCal();
        $book = $api->createBooking($typeId, [
            'starts_at' => $starts,
            'name'      => $name,
            'email'     => $email,
            'timezone'  => $tz,
        ]);
    } catch (\Throwable $e) {
        flash_set('danger', 'Rezerwacja przez API nie powiodła się: ' . $e->getMessage()
            . ' Skorzystaj z rezerwacji bezpośrednio na stronie TidyCal poniżej.');
        $sep = (strpos($self, '?') !== false) ? '&' : '?';
        header('Location: ' . $self . $sep . 'fallback=' . $typeId); exit;
    }

    $ends = $book['ends_at'] ?? null;
    $bid  = (int)($book['id'] ?? 0) ?: null;
    $meet = $book['meeting_url'] ?? ($book['meeting_url_id'] ?? null);

    tidycal_log_booking([
        'user_id'            => $ctx['user_id'] ?? null,
        'booking_type_id'    => $typeId,
        'booking_type_title' => $type['title'],
        'tidycal_booking_id' => $bid,
        'starts_at'          => $starts,
        'ends_at'            => $ends,
        'timezone'           => $tz,
        'name'               => $name,
        'email'              => $email,
        'status'             => 'booked',
        'meeting_url'        => $meet,
        'source'             => (string)($ctx['source'] ?? 'panel'),
    ]);

    tidycal_send_confirmation($email, $name, $type['title'], $starts, $tz, $meet);

    flash_set('success', 'Zarezerwowano termin szkolenia: „' . $type['title'] . '" na '
        . tidycal_fmt_dt($starts, $tz) . '. Potwierdzenie wysłano e-mailem.');
    header('Location: ' . $self); exit;
}

/** Wyślij e-mail z potwierdzeniem rezerwacji (best-effort). */
function tidycal_send_confirmation(string $email, string $name, string $title, string $starts, string $tz, ?string $meet): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $when = tidycal_fmt_dt($starts, $tz);
    $nameH = h($name); $titleH = h($title); $whenH = h($when); $tzH = h($tz);
    $meetBtn = '';
    if ($meet) {
        $meetH = h($meet);
        $meetBtn = '<div style="text-align:center;margin:18px 0">'
            . '<a href="' . $meetH . '" style="background:#7c3aed;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600">Dołącz do spotkania →</a></div>';
    }
    $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#7C3AED,#C084FC);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">📅 Potwierdzenie rezerwacji szkolenia — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$nameH}</strong>!</p>
  <p>Twój termin szkolenia został zarezerwowany:</p>
  <div style="background:#f5f3ff;border-left:4px solid #7C3AED;border-radius:4px;padding:14px;margin:14px 0">
    <div><strong>Szkolenie:</strong> {$titleH}</div>
    <div><strong>Termin:</strong> {$whenH} ({$tzH})</div>
  </div>
  {$meetBtn}
  <p style="font-size:.9em;color:#6c757d">Osobne potwierdzenie i przypomnienia wyśle również system TidyCal.</p>
</div></body></html>
HTML;
    try {
        require_once __DIR__ . '/approval.php';
        if (function_exists('approval_send_email')) {
            approval_send_email($email, 'Potwierdzenie rezerwacji szkolenia — ' . $org, $body);
        }
    } catch (\Throwable $e) {}
}

