<?php
/**
 * modules/first_run/lib/firstRun.php — kreator pierwszego logowania osoby (SZO i Panel współpracownika).
 *
 * Funkcjonalność współdzielona: firstRunGate() wołają includes/header.php (SZO) i
 * panel/includes/header_panel.php (Panel współpracownika). Kreator: modules/first_run/index.php.
 *
 * Kroki (każdy pokazywany tylko, gdy ma sens dla danej osoby):
 *   phone   — numer telefonu + weryfikacja kodem SMS (gdy SMS włączone),
 *   m365    — konto Microsoft 365: połączenie logowania (SSO), skrzynka, Outlook na komputer/telefon,
 *   profile — profil w Katalogu współpracowników (wymagane pola z modules/directory_profile, jeśli jest),
 *   stopka  — stanowisko i telefon do stopki e-mail (gdy osoba ma skrzynkę M365).
 *
 * Stan: users.first_run_done_at (NULL = kreator się pokaże). Przy dodaniu kolumny istniejącym
 * kontom wpisujemy datę — kreator widzą tylko NOWE osoby; administrator może go wymusić ponownie.
 * Ustawienie first_run_mode: on (domyślnie) | off.
 */

const FIRST_RUN_URL = '/modules/first_run/index.php';

function firstRunMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("ALTER TABLE users ADD COLUMN first_run_done_at DATETIME DEFAULT NULL");
        // kolumna właśnie powstała — dotychczasowe konta nie dostają kreatora
        db()->exec("UPDATE users SET first_run_done_at = datetime('now') WHERE first_run_done_at IS NULL");
    } catch (\Throwable $e) { /* już jest */ }
}

function firstRunEnabled(): bool {
    return (function_exists('org_setting') ? org_setting('first_run_mode') : '') !== 'off';
}

/** Ścieżka powrotu po kreatorze — tylko lokalna. */
function firstRunSafeNext(string $next, string $fallback): string {
    return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, FIRST_RUN_URL)) ? $next : $fallback;
}

/**
 * Przekierowuje do kreatora, jeśli osoba go nie ukończyła. Wołać przed wysłaniem treści.
 * Pomija: CLI, nie-GET, AJAX, podgląd roli/impersonację, „Później” w tej sesji, serwis@local,
 * strony logowania/API/zgody/Tożsamości i sam kreator.
 */
function firstRunGate(?array $user): void {
    if (!$user || PHP_SAPI === 'cli' || headers_sent()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['_ajax']) || isset($_GET['ajax'])) return;
    if (!empty($_SESSION['first_run_skip']) || !empty($_SESSION['_admin_original'])) return;
    if (($user['email'] ?? '') === 'serwis@local') return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    // + strony otwierane z kreatora w nowej karcie (profil, konto M365, stopka)
    foreach (['/modules/first_run/', '/auth/', '/api/', '/user/first_login_consent', '/user/verify_reset', '/tozsamosc/',
              '/profile_edit.php', '/panel/m365.php', '/modules/stopka/'] as $p) {
        if (str_contains($path, $p)) return;
    }
    if (!firstRunEnabled()) return;
    firstRunMigrate();
    $row = db_one("SELECT first_run_done_at FROM users WHERE id=?", [(int)$user['id']]);
    if (!$row || !empty($row['first_run_done_at'])) return;
    header('Location: ' . APP_URL . FIRST_RUN_URL . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

/**
 * Jedna kolejka pierwszego logowania (jedyne miejsce, które ją ustala):
 *   1. zgoda na dokumentową formę umów (/user/first_login_consent.php),
 *   2. kreator pierwszych kroków (firstRunGate),
 *   3. oświadczenie o ochronie danych (/panel/gdpr_statement.php) — tylko w Panelu ($gdpr = true).
 * Wołają ją includes/header.php (SZO: zgoda + kreator), panel/includes/header_panel.php (kreator + RODO)
 * i panel/launcher.php (wszystko). $consent / $gdpr wyłączają kroki, których dany ekran nie wymaga.
 */
function firstLoginGate(?array $user, bool $consent = true, bool $gdpr = false): void {
    if (!$user || PHP_SAPI === 'cli') return;
    if (($user['email'] ?? '') === 'serwis@local') return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $uid  = (int)$user['id'];

    if ($consent) {
        $skip = false;
        foreach (['/user/first_login_consent.php', '/user/verify_reset.php', '/auth/', '/admin/api/', '/api/'] as $p) {
            if (str_contains($path, $p)) { $skip = true; break; }
        }
        if (!$skip) {
            require_once dirname(__DIR__, 3) . '/includes/cpc.php';
            cpc_migrate();
            $row = db_one("SELECT document_form_consent, consent_text_version_hash FROM users WHERE id=?", [$uid]);
            if ($row && ((int)($row['document_form_consent'] ?? 0) !== 1
                         || ($row['consent_text_version_hash'] ?? '') !== consent_current_hash())) {
                header('Location: ' . APP_URL . '/user/first_login_consent.php');
                exit;
            }
        }
    }

    firstRunGate($user);

    if ($gdpr && !str_ends_with($path, '/panel/gdpr_statement.php')) {
        try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_signed_at DATETIME"); } catch (\Throwable $e) {}
        try { db()->exec("ALTER TABLE users ADD COLUMN gdpr_statement_ip TEXT"); } catch (\Throwable $e) {}
        $row = db_one("SELECT gdpr_statement_signed_at FROM users WHERE id=?", [$uid]);
        if (empty($row['gdpr_statement_signed_at'])) {
            header('Location: ' . APP_URL . '/panel/gdpr_statement.php');
            exit;
        }
    }
}

function firstRunFinish(int $userId): void {
    firstRunMigrate();
    db_exec("UPDATE users SET first_run_done_at = datetime('now') WHERE id=?", [$userId]);
}

/** Wymusza kreator ponownie: jednej osobie ($userId) albo wszystkim (null). Zwraca liczbę kont. */
function firstRunReset(?int $userId = null): int {
    firstRunMigrate();
    $st = $userId
        ? db()->prepare("UPDATE users SET first_run_done_at = NULL WHERE id=?")
        : db()->prepare("UPDATE users SET first_run_done_at = NULL WHERE email <> 'serwis@local'");
    $st->execute($userId ? [$userId] : []);
    return $st->rowCount();
}

/** Adres skrzynki M365 osoby albo ''. */
function firstRunMailbox(array $u): string {
    $login = trim((string)($u['m365_login'] ?? ''));
    if ($login !== '') return $login;
    return !empty($u['microsoft_id']) ? (string)($u['email'] ?? '') : '';
}

/**
 * Kroki kreatora dla osoby: [klucz => ['title','short','done'(true|false|null=bez oceny)]], tylko pasujące.
 * $panel — osoba korzysta z Panelu współpracownika (inna ścieżka edycji profilu).
 */
function firstRunSteps(array $user, bool $panel): array {
    $u = db_one("SELECT * FROM users WHERE id=?", [(int)$user['id']]) ?: $user;
    $root = dirname(__DIR__, 3);
    $steps = [];

    $sms = false;
    try { require_once $root . '/includes/sms.php'; $sms = sms_is_enabled(); } catch (\Throwable $e) {}
    if ($sms) {
        $steps['phone'] = ['title' => 'Telefon', 'short' => 'Numer komórkowy potwierdzony kodem SMS',
            'done' => !empty($u['phone_verified_at'])];
    }

    $mailbox = firstRunMailbox($u);
    $msAvail = function_exists('ms_login_available') && ms_login_available();
    if ($msAvail || $mailbox !== '') {
        $steps['m365'] = ['title' => 'Microsoft 365 i Outlook', 'short' => 'Logowanie przez Microsoft i poczta',
            'done' => $msAvail ? !empty($u['microsoft_id']) : null];
    }

    $profDone = null;
    $lib = $root . '/modules/directory_profile/lib/directoryProfile.php';
    if (is_file($lib)) {
        try {
            require_once $lib;
            require_once $root . '/includes/directory.php';
            $st = directoryProfileStatus((int)$u['id']);
            if ($st['total'] > 0) $profDone = $st['complete'];
        } catch (\Throwable $e) {}
    }
    $steps['profile'] = ['title' => 'Profil w katalogu', 'short' => 'Zdjęcie, opis i dane kontaktowe dla współpracowników',
        'done' => $profDone, 'url' => APP_URL . ($panel ? '/panel/profile_edit.php' : '/directory/profile_edit.php')];

    if ($mailbox !== '' && is_file($root . '/modules/stopka/logic/stopka.php')) {
        require_once $root . '/modules/stopka/logic/stopka.php';
        stopkaMigrate();
        $p = db_one("SELECT job_title FROM stopka_profile WHERE user_id=?", [(int)$u['id']]);
        $steps['stopka'] = ['title' => 'Stopka e-mail', 'short' => 'Stanowisko i telefon pod Twoimi wiadomościami',
            'done' => trim((string)($p['job_title'] ?? '')) !== ''];
    }
    return $steps;
}
