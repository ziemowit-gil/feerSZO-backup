<?php
/**
 * includes/ti_online.php — warstwa „nauki online" panelu kursanta TI.
 *
 * Łączy dwie integracje, obie skonfigurowane przez admina:
 *   1. Konta Microsoft 365 w OSOBNYM tenancie szkoleniowym (klucze settings m365t_*).
 *      Reużywa klasy M365Graph (includes/m365.php) z własnym zestawem creds.
 *   2. Linki do nadchodzących szkoleń: ręczne (k30_ti_meetings) + Zoom (Zoom API).
 *
 * Kotwicą kursanta jest k30_ti_student_accounts (kolumny ms_* dodane w karty30_migrate()).
 * Wszystkie wywołania zewnętrzne są w try/catch — operacje zwracają ['ok'=>bool,'msg'=>string,...].
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; // org_setting()
require_once __DIR__ . '/m365.php';      // M365Graph, m365_save_setting()
require_once __DIR__ . '/zoom.php';      // ZoomAPI, zoom_enabled()

// ── Ustawienia tenanta szkoleniowego ──────────────────────────────────────────

/** Ustawienie konfiguracji M365 dla Kart 30 (klucze settings k30_m365_*). */
function ti_m365_setting(string $key, string $default = ''): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['k30_m365_' . $key]);
        return $r['value'] ?? $default;
    } catch (\Throwable $e) { return $default; }
}

/**
 * Instancja M365Graph dla Zajęć TI — korzysta z konfiguracji ustawionej już w
 * „Dydaktyka 3 → M365" (karty30/admin/m365.php, klucze k30_m365_*). Tryb „własny"
 * = oddzielne creds; tryb domyślny = główny tenant organizacji (m365_*).
 * Dzięki temu nie duplikujemy konfiguracji w panelu Nauki online.
 */
function m365_training(): M365Graph {
    if ((bool)ti_m365_setting('use_own_tenant')) {
        return new M365Graph([
            'tenant_id'     => ti_m365_setting('tenant_id'),
            'client_id'     => ti_m365_setting('client_id'),
            'client_secret' => ti_m365_setting('client_secret'),
            'domain'        => ti_m365_setting('domain'),
        ]);
    }
    $domainOverride = ti_m365_setting('domain');
    return new M365Graph([
        'tenant_id'     => m365_setting('m365_tenant_id'),
        'client_id'     => m365_setting('m365_graph_client_id'),
        'client_secret' => m365_setting('m365_graph_client_secret'),
        'domain'        => $domainOverride ?: m365_setting('m365_domain'),
    ]);
}

/** Czy konta MS można tworzyć — konfiguracja M365 (Dydaktyka 3 → M365) jest kompletna. */
function ti_ms_enabled(): bool {
    return m365_training()->is_configured();
}

/**
 * Czy kursant obsługuje konta Microsoft 365 SAM, ze swojego panelu (zakładanie
 * i kasowanie z zakładki „Szkolenia online"). Wyłącznik jest tutaj, w jednym
 * miejscu: pyta o niego zarówno zakładka „Szkolenia online", jak i endpoint
 * ti_online_api.php, więc nie da się obejść interfejsu żądaniem wprost.
 *
 * Nie dotyczy administracji (karty30/ti/*) — tam provisioning działa jak dotąd,
 * niezależnie od tego przełącznika.
 */
function ti_student_selfservice_enabled(): bool {
    return true;
}

// ── Stan kursanta ─────────────────────────────────────────────────────────────

/** Zwraca rekord konta kursanta wraz z danymi beneficjenta. */
function ti_student_row(int $studentId): ?array {
    return db_one(
        "SELECT a.*, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone
         FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id=a.client_id
         WHERE a.id=?",
        [$studentId]
    );
}

/** Stan integracji online dla front-endu / panelu admina. */
function ti_student_online_state(int $studentId): array {
    $r = ti_student_row($studentId);
    if (!$r) return ['exists' => false];
    return [
        'exists'         => true,
        'ms_enabled'     => ti_ms_enabled(),
        'ms_upn'         => $r['ms_upn'] ?? '',
        'ms_active'      => !empty($r['ms_user_id']),
    ];
}

// ── Konto Microsoft 365 (tenant szkoleniowy) ──────────────────────────────────

/**
 * Tworzy konto MS w tenancie szkoleniowym dla kursanta. Limit: 1 konto / kursant.
 * Wysyła dane dostępowe e-mailem (na k30_clients.email) i SMS-em (na k30_clients.phone).
 * Zwraca ['ok','msg','upn'?,'password'?].
 */
/**
 * Jeśli kursant nie ma konta MS w systemie, a w tenancie istnieje już konto
 * o „naturalnym" loginie imie.nazwisko (utworzone poza systemem) — zwraca ten UPN.
 * Chroni przed utworzeniem duplikatu/kolizją i nadpisaniem. null = brak takiego konta.
 */
function ti_ms_external_upn(int $studentId): ?string {
    if (!ti_ms_enabled()) return null;
    $r = ti_student_row($studentId);
    if (!$r || !empty($r['ms_user_id'])) return null; // mamy już własne konto — nie sprawdzamy
    $name = trim($r['client_name'] ?: $r['login']);
    if ($name === '') return null;
    try {
        $graph = m365_training();
        $login = $graph->natural_login($name);
        return $graph->login_exists($login) ? $login : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function ti_ms_provision(int $studentId): array {
    if (!ti_ms_enabled()) return ['ok' => false, 'msg' => 'Moduł kont Microsoft nie jest skonfigurowany.'];
    $r = ti_student_row($studentId);
    if (!$r) return ['ok' => false, 'msg' => 'Konto kursanta nie istnieje.'];
    if (!empty($r['ms_user_id'])) return ['ok' => false, 'msg' => 'Konto Microsoft już istnieje (' . $r['ms_upn'] . ').'];

    // Konto o loginie imie.nazwisko istnieje już w tenancie (spoza systemu) —
    // nie tworzymy nowego, by nie zrobić duplikatu / nie nadpisać istniejącego.
    $external = ti_ms_external_upn($studentId);
    if ($external !== null) {
        return ['ok' => false, 'msg' => 'Konto Microsoft o loginie ' . $external
            . ' już istnieje (utworzone poza systemem). Zaloguj się nim — nie tworzymy nowego.'];
    }

    $name = trim($r['client_name'] ?: $r['login']);

    try {
        $graph = m365_training();
        $upn   = $graph->unique_login($name);
        $pass  = M365Graph::generate_password();
        $created = $graph->create_user($upn, $name, $pass, true); // enabled=true
        $userId  = $created['id'] ?? '';
        if ($userId === '') return ['ok' => false, 'msg' => 'Graph nie zwrócił identyfikatora konta.'];

        // Opcjonalna licencja
        $sku = ti_m365_setting('default_sku');
        if ($sku !== '') {
            try { $graph->assign_license($userId, $sku); } catch (\Throwable $e) { /* licencja nieobowiązkowa */ }
        }
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd tworzenia konta Microsoft: ' . $e->getMessage()];
    }

    db_update('k30_ti_student_accounts', [
        'ms_user_id'    => $userId,
        'ms_upn'        => $upn,
        'ms_created_at' => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ], $studentId);

    ti_send_ms_credentials($r, $upn, $pass);

    return ['ok' => true, 'msg' => 'Konto Microsoft utworzone.', 'upn' => $upn, 'password' => $pass];
}

/** Usuwa konto MS kursanta z tenanta szkoleniowego i czyści powiązania. */
function ti_ms_delete(int $studentId): array {
    $r = ti_student_row($studentId);
    if (!$r) return ['ok' => false, 'msg' => 'Konto kursanta nie istnieje.'];
    if (empty($r['ms_user_id'])) return ['ok' => false, 'msg' => 'Brak konta Microsoft do usunięcia.'];

    try {
        m365_training()->delete_user($r['ms_user_id']);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd usuwania konta Microsoft: ' . $e->getMessage()];
    }

    db_update('k30_ti_student_accounts', [
        'ms_user_id'    => '',
        'ms_upn'        => '',
        'ms_created_at' => null,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], $studentId);

    return ['ok' => true, 'msg' => 'Konto Microsoft usunięte.'];
}

/** Wysyła kursantowi dane dostępowe do konta MS (e-mail + SMS). */
function ti_send_ms_credentials(array $student, string $upn, string $pass): void {
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';

    // E-mail (na adres beneficjenta)
    $email = trim((string)($student['client_email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (!function_exists('mail_queue_add')) { @require_once __DIR__ . '/mail_queue.php'; }
        if (function_exists('mail_queue_add')) {
            $name = htmlspecialchars($student['client_name'] ?? '', ENT_QUOTES);
            $html = "<p>Cześć {$name},</p>"
                  . "<p>Utworzono dla Ciebie konto szkoleniowe Microsoft 365 w {$org}. Tego loginu używasz także do platformy e-learningowej.</p>"
                  . "<table style='border-collapse:collapse;font-size:14px'>"
                  . "<tr><td style='padding:4px 12px;color:#555'>Login</td><td style='padding:4px 12px'><code>" . htmlspecialchars($upn, ENT_QUOTES) . "</code></td></tr>"
                  . "<tr><td style='padding:4px 12px;color:#555'>Hasło tymczasowe</td><td style='padding:4px 12px'><code>" . htmlspecialchars($pass, ENT_QUOTES) . "</code></td></tr>"
                  . "</table>"
                  . "<p style='color:#888;font-size:12px;margin-top:16px'>Przy pierwszym logowaniu konieczna jest zmiana hasła. Nie udostępniaj tych danych osobom trzecim.</p>";
            try { mail_queue_add($email, $student['client_name'] ?? '', "Konto szkoleniowe Microsoft 365 — {$org}", $html, '', 'ti_ms', (int)$student['id'], '', true); }
            catch (\Throwable $e) { /* wysyłka nie może blokować operacji */ }
        }
    }

    // SMS (na numer beneficjenta)
    $phone = trim((string)($student['client_phone'] ?? ''));
    if ($phone !== '') {
        if (!function_exists('sms_send')) { @require_once __DIR__ . '/sms.php'; }
        if (function_exists('sms_is_enabled') && sms_is_enabled()) {
            try { sms_send($phone, "{$org} - konto szkoleniowe MS. Login: {$upn}, haslo: {$pass}"); }
            catch (\Throwable $e) { /* SMS opcjonalny */ }
        }
    }
}

// ── Nadchodzące szkolenia (agregacja dwóch źródeł) ─────────────────────────────

/**
 * Scalona, posortowana lista nadchodzących szkoleń online.
 * Każdy element: ['title','platform'=>zoom|other,'join_url','starts_at'].
 */
function ti_upcoming_meetings(?int $clientId = null): array {
    $items = [];

    // (a) Ręczne wpisy admina — przypisane do grupy (course_id) lub wspólne (NULL).
    //     Dla kursanta pokazujemy tylko jego grupy + wspólne.
    try {
        $sql = "SELECT m.title, m.platform, m.join_url, m.starts_at, m.course_id, c.name AS course_name
                FROM k30_ti_meetings m
                LEFT JOIN k30_ti_courses c ON c.id = m.course_id
                WHERE m.is_active=1 AND (m.starts_at IS NULL OR m.starts_at >= datetime('now','-1 hour'))";
        $params = [];
        if ($clientId !== null) {
            $sql .= " AND (m.course_id IS NULL OR m.course_id IN (
                          SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'))";
            $params[] = $clientId;
        }
        $sql .= " ORDER BY m.starts_at";
        foreach (db_all($sql, $params) as $m) {
            if (!$m['join_url']) continue;
            $items[] = [
                'title'       => $m['title'] ?: 'Szkolenie',
                'platform'    => $m['platform'] ?: 'other',
                'join_url'    => $m['join_url'],
                'starts_at'   => $m['starts_at'] ?: '',
                'course_id'   => $m['course_id'] ? (int)$m['course_id'] : null,
                'course_name' => $m['course_name'] ?: null,
            ];
        }
    } catch (\Throwable $e) { /* ignoruj */ }

    // (b) Stałe linki per kurs/kursant.
    //     Gdy clientId podany: enrollment.zoom_meeting_url > course.default_meeting_url.
    //     Platforma wykrywana z URL-a (zoom.us → zoom, reszta → other).
    $ti_perm_detect_plat = static function(string $url): string {
        if (str_contains($url, 'zoom.us')) return 'zoom';
        return 'other';
    };
    try {
        if ($clientId !== null) {
            // Dla konkretnego kursanta — bierz enrollment-level link, fallback do kursu
            $rows = db_all(
                "SELECT c.id AS course_id, c.name AS course_name,
                        CASE WHEN e.zoom_meeting_url != '' THEN e.zoom_meeting_url
                             ELSE c.default_meeting_url END AS link_url
                 FROM k30_ti_courses c
                 JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
                 WHERE c.status='active'
                   AND (e.zoom_meeting_url != '' OR c.default_meeting_url != '')",
                [$clientId]
            );
        } else {
            // Widok ogólny (admin) — tylko linki na poziomie kursu
            $rows = db_all(
                "SELECT c.id AS course_id, c.name AS course_name, c.default_meeting_url AS link_url
                 FROM k30_ti_courses c
                 WHERE c.default_meeting_url != '' AND c.status='active'",
                []
            );
        }
        foreach ($rows as $row) {
            $url = (string)($row['link_url'] ?? '');
            if ($url === '') continue;
            $items[] = [
                'title'       => $row['course_name'],
                'platform'    => $ti_perm_detect_plat($url),
                'join_url'    => $url,
                'starts_at'   => '',
                'course_id'   => (int)$row['course_id'],
                'course_name' => $row['course_name'],
            ];
        }
    } catch (\Throwable $e) { /* ignoruj */ }

    // (c) Zoom API — spotkania z datą (typ 1/2); per-kursowe stałe linki (typ 3) już w (b).
    //     Pomijamy join_url, które już dodaliśmy z DB, żeby uniknąć duplikatów.
    if (zoom_enabled()) {
        $db_zoom_urls = array_column(
            array_filter($items, fn($i) => $i['platform'] === 'zoom'),
            'join_url'
        );
        try {
            foreach ((new ZoomAPI())->upcoming_meetings() as $m) {
                if (in_array($m['join_url'], $db_zoom_urls, true)) continue;
                $items[] = [
                    'title'       => $m['title'],
                    'platform'    => 'zoom',
                    'join_url'    => $m['join_url'],
                    'starts_at'   => $m['start'] ? date('Y-m-d H:i:s', strtotime($m['start'])) : '',
                    'course_id'   => null,
                    'course_name' => null,
                ];
            }
        } catch (\Throwable $e) { /* ignoruj */ }
    }

    // Sortuj wg startu (puste daty na koniec)
    usort($items, function ($a, $b) {
        $sa = $a['starts_at'] ?: '9999';
        $sb = $b['starts_at'] ?: '9999';
        return strcmp($sa, $sb);
    });

    return $items;
}
