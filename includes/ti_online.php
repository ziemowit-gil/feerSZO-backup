<?php
/**
 * includes/ti_online.php — warstwa „nauki online" panelu kursanta TI.
 *
 * Łączy trzy integracje, wszystkie skonfigurowane przez admina:
 *   1. Konta Microsoft 365 w OSOBNYM tenancie szkoleniowym (klucze settings m365t_*).
 *      Reużywa klasy M365Graph (includes/m365.php) z własnym zestawem creds.
 *   2. Konta Moodle (reużywa MoodleAPI z includes/moodle.php). Loginem do Moodle
 *      jest UPN konta MS szkoleniowego — spójna tożsamość MS↔Moodle.
 *   3. Linki do nadchodzących szkoleń: ręczne (k30_ti_meetings) + Teams (kalendarz
 *      tenanta szkoleniowego, Graph) + Zoom (Zoom API).
 *
 * Kotwicą kursanta jest k30_ti_student_accounts (kolumny ms_* oraz moodle_* dodane w karty30_migrate()).
 * Wszystkie wywołania zewnętrzne są w try/catch — operacje zwracają ['ok'=>bool,'msg'=>string,...].
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; // org_setting()
require_once __DIR__ . '/m365.php';      // M365Graph, m365_save_setting()
require_once __DIR__ . '/moodle.php';    // MoodleAPI, moodle_setting()
require_once __DIR__ . '/zoom.php';      // ZoomAPI, zoom_enabled()

// ── Ustawienia tenanta szkoleniowego ──────────────────────────────────────────

/** Czy moduł kont MS (tenant szkoleniowy) jest włączony i ma komplet creds. */
function ti_ms_enabled(): bool {
    return org_setting('m365t_enabled') === '1'
        && org_setting('m365t_tenant_id') !== ''
        && org_setting('m365t_client_id') !== ''
        && org_setting('m365t_client_secret') !== '';
}

/** Instancja M365Graph wskazująca na tenant szkoleniowy. */
function m365_training(): M365Graph {
    return new M365Graph([
        'tenant_id'     => org_setting('m365t_tenant_id'),
        'client_id'     => org_setting('m365t_client_id'),
        'client_secret' => org_setting('m365t_client_secret'),
        'domain'        => org_setting('m365t_domain') ?: 'onmicrosoft.com',
    ]);
}

/** Czy provisioning Moodle jest możliwy (URL + token ustawione). */
function ti_moodle_enabled(): bool {
    return moodle_setting('url') !== '' && moodle_setting('token') !== '';
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
        'moodle_enabled' => ti_moodle_enabled(),
        'ms_upn'         => $r['ms_upn'] ?? '',
        'ms_active'      => !empty($r['ms_user_id']),
        'moodle_login'   => $r['moodle_username'] ?? '',
        'moodle_active'  => !empty($r['moodle_user_id']),
        'moodle_url'     => rtrim(moodle_setting('url'), '/'),
    ];
}

// ── Konto Microsoft 365 (tenant szkoleniowy) ──────────────────────────────────

/**
 * Tworzy konto MS w tenancie szkoleniowym dla kursanta. Limit: 1 konto / kursant.
 * Wysyła dane dostępowe e-mailem (na k30_clients.email) i SMS-em (na k30_clients.phone).
 * Zwraca ['ok','msg','upn'?,'password'?].
 */
function ti_ms_provision(int $studentId): array {
    if (!ti_ms_enabled()) return ['ok' => false, 'msg' => 'Moduł kont Microsoft nie jest skonfigurowany.'];
    $r = ti_student_row($studentId);
    if (!$r) return ['ok' => false, 'msg' => 'Konto kursanta nie istnieje.'];
    if (!empty($r['ms_user_id'])) return ['ok' => false, 'msg' => 'Konto Microsoft już istnieje (' . $r['ms_upn'] . ').'];

    $name = trim($r['client_name'] ?: $r['login']);

    try {
        $graph = m365_training();
        $upn   = $graph->unique_login($name);
        $pass  = M365Graph::generate_password();
        $created = $graph->create_user($upn, $name, $pass, true); // enabled=true
        $userId  = $created['id'] ?? '';
        if ($userId === '') return ['ok' => false, 'msg' => 'Graph nie zwrócił identyfikatora konta.'];

        // Opcjonalna licencja
        $sku = org_setting('m365t_default_sku');
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

// ── Konto Moodle ───────────────────────────────────────────────────────────────

/**
 * Tworzy (lub dowiązuje istniejące) konto Moodle dla kursanta.
 * Loginem/e-mailem jest UPN konta MS szkoleniowego. Zwraca ['ok','msg','login'?,'url'?].
 */
function ti_moodle_provision(int $studentId): array {
    if (!ti_moodle_enabled()) return ['ok' => false, 'msg' => 'Integracja Moodle nie jest skonfigurowana.'];
    $r = ti_student_row($studentId);
    if (!$r) return ['ok' => false, 'msg' => 'Konto kursanta nie istnieje.'];
    if (!empty($r['moodle_user_id'])) {
        return ['ok' => true, 'msg' => 'Konto Moodle już istnieje.', 'login' => $r['moodle_username'], 'url' => rtrim(moodle_setting('url'), '/')];
    }
    $upn = trim((string)$r['ms_upn']);
    if ($upn === '') return ['ok' => false, 'msg' => 'Najpierw utwórz konto Microsoft — jego login posłuży jako login Moodle.'];

    $name = trim($r['client_name'] ?: $r['login']);
    try {
        $api = new MoodleAPI();
        $mu  = $api->find_user($upn);
        if ($mu) {
            $moodleId = (int)$mu['id'];
            $login    = $mu['username'] ?? $upn;
        } else {
            $pass     = bin2hex(random_bytes(6)) . 'Aa1!';
            $moodleId = $api->create_user($name, $upn, $pass);
            $login    = strtolower(preg_replace('/[^a-z0-9._-]/', '', $upn));
        }
        if (!$moodleId) return ['ok' => false, 'msg' => 'Moodle nie zwrócił identyfikatora użytkownika.'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd tworzenia konta Moodle: ' . $e->getMessage()];
    }

    db_update('k30_ti_student_accounts', [
        'moodle_user_id'    => $moodleId,
        'moodle_username'   => $login,
        'moodle_created_at' => date('Y-m-d H:i:s'),
        'updated_at'        => date('Y-m-d H:i:s'),
    ], $studentId);

    return ['ok' => true, 'msg' => 'Konto Moodle gotowe.', 'login' => $login, 'url' => rtrim(moodle_setting('url'), '/')];
}

/**
 * Ustawia własne hasło kursanta na platformie Moodle.
 * Wymaga istniejącego konta Moodle (moodle_user_id). Zwraca ['ok','msg'].
 * Złożoność wstępnie sprawdzamy lokalnie (domyślna polityka Moodle), a ostatecznie
 * waliduje sam Moodle — jego ewentualny błąd przekazujemy kursantowi.
 */
function ti_moodle_set_password(int $studentId, string $password): array {
    if (!ti_moodle_enabled()) return ['ok' => false, 'msg' => 'Integracja Moodle nie jest skonfigurowana.'];
    $r = ti_student_row($studentId);
    if (!$r) return ['ok' => false, 'msg' => 'Konto kursanta nie istnieje.'];
    if (empty($r['moodle_user_id'])) return ['ok' => false, 'msg' => 'Najpierw utwórz konto na platformie e-learningowej.'];

    $err = ti_moodle_password_problem($password);
    if ($err !== '') return ['ok' => false, 'msg' => $err];

    try {
        $api = new MoodleAPI();
        $api->update_user((int)$r['moodle_user_id'], ['password' => $password]);
    } catch (\Throwable $e) {
        // Najczęściej: niezgodność z polityką haseł skonfigurowaną w Moodle.
        return ['ok' => false, 'msg' => 'Moodle odrzucił hasło: ' . $e->getMessage()];
    }

    return ['ok' => true, 'msg' => 'Hasło do platformy e-learningowej zostało zmienione.'];
}

/** Sprawdza hasło wg domyślnej polityki Moodle. Zwraca '' gdy OK, inaczej komunikat. */
function ti_moodle_password_problem(string $p): string {
    if (mb_strlen($p) < 8)            return 'Hasło musi mieć co najmniej 8 znaków.';
    if (!preg_match('/[a-z]/', $p))   return 'Hasło musi zawierać małą literę.';
    if (!preg_match('/[A-Z]/', $p))   return 'Hasło musi zawierać wielką literę.';
    if (!preg_match('/[0-9]/', $p))   return 'Hasło musi zawierać cyfrę.';
    if (!preg_match('/[^a-zA-Z0-9]/', $p)) return 'Hasło musi zawierać znak specjalny (np. ! @ # ?).';
    return '';
}

// ── Nadchodzące szkolenia (agregacja trzech źródeł) ────────────────────────────

/**
 * Scalona, posortowana lista nadchodzących szkoleń online.
 * Każdy element: ['title','platform'=>zoom|teams|other,'join_url','starts_at'].
 */
function ti_upcoming_meetings(): array {
    $items = [];

    // (a) Ręczne wpisy admina
    try {
        $rows = db_all(
            "SELECT title, platform, join_url, starts_at FROM k30_ti_meetings
             WHERE is_active=1 AND (starts_at IS NULL OR starts_at >= datetime('now','-1 hour'))
             ORDER BY starts_at"
        );
        foreach ($rows as $m) {
            if (!$m['join_url']) continue;
            $items[] = [
                'title'     => $m['title'] ?: 'Szkolenie',
                'platform'  => $m['platform'] ?: 'other',
                'join_url'  => $m['join_url'],
                'starts_at' => $m['starts_at'] ?: '',
            ];
        }
    } catch (\Throwable $e) { /* ignoruj */ }

    // (b) Teams — kalendarz tenanta szkoleniowego
    $calUser = org_setting('m365t_meetings_user');
    if (ti_ms_enabled() && $calUser !== '') {
        try {
            $start = date('Y-m-d\TH:i:s');
            $end   = date('Y-m-d\TH:i:s', strtotime('+30 days'));
            foreach (m365_training()->get_online_calendar_events($calUser, $start, $end) as $ev) {
                $items[] = [
                    'title'     => $ev['subject'],
                    'platform'  => 'teams',
                    'join_url'  => $ev['join_url'],
                    'starts_at' => $ev['start'] ? date('Y-m-d H:i:s', strtotime($ev['start'])) : '',
                ];
            }
        } catch (\Throwable $e) { /* ignoruj */ }
    }

    // (c) Zoom
    if (zoom_enabled()) {
        try {
            foreach ((new ZoomAPI())->upcoming_meetings() as $m) {
                $items[] = [
                    'title'     => $m['title'],
                    'platform'  => 'zoom',
                    'join_url'  => $m['join_url'],
                    'starts_at' => $m['start'] ? date('Y-m-d H:i:s', strtotime($m['start'])) : '',
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
