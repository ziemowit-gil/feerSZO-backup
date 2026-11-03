<?php
/**
 * modules/mail_footer/lib/mailFooter.php — zarządzanie własną pocztą (panel ms365webpanel)
 * i wymóg ustawienia własnej stopki.
 *
 * Zewnętrzny panel https://ms365webpanel.feer.org.pl/ pozwala każdej osobie zarządzać WYŁĄCZNIE własną
 * skrzynką (aliasy, hasło, 2FA, stopka, autoresponder, przekierowanie). SZO nie ma jak sprawdzić, czy
 * stopka tam została ustawiona, więc wymóg opiera się na potwierdzeniu osoby (tabela mail_footer_confirm).
 *
 * Ustawienie (tabela settings): mail_footer_mode = off | remind | require (domyślnie remind).
 *   off     — nic nie wymuszamy (tekst o zarządzaniu pocztą i tak jest na stronie panel/poczta.php),
 *   remind  — na pulpicie stały pas „Ustaw swoją stopkę” do czasu potwierdzenia,
 *   require — pulpit przenosi na panel/poczta.php?wymagane=1, dopóki osoba nie potwierdzi.
 * Dotyczy tylko osób ze skrzynką Microsoft 365. Administratorzy i impersonacja są zwolnieni (dostają pas).
 */

const MAIL_FOOTER_BASE  = 'https://ms365webpanel.feer.org.pl/';
const MAIL_FOOTER_MODES = [
    'off'     => 'Wyłączone',
    'remind'  => 'Przypomnienie (pas na pulpicie)',
    'require' => 'Wymagane (blokuje pulpit do potwierdzenia)',
];

/** Zadania dostępne w panelu poczty: slug => [etykieta, ikona, opis]. Adres: MAIL_FOOTER_BASE . slug. */
function mailFooterTasks(): array {
    return [
        'stopka'         => ['Stopka',          'bi-pen',                'Podpis, który dopisuje się pod Twoimi wiadomościami. Zacznij od niego.'],
        'aliasy'         => ['Aliasy',          'bi-at',                 'Dodatkowe adresy e-mail, które trafiają do Twojej skrzynki.'],
        'haslo'          => ['Hasło',           'bi-key',                'Zmiana hasła do konta Microsoft 365.'],
        '2fa'            => ['Weryfikacja 2FA', 'bi-shield-lock',        'Wybierasz, jak potwierdzasz logowanie.'],
        'autoresponder'  => ['Autoresponder',   'bi-reply',              'Automatyczna odpowiedź, gdy jesteś poza biurem.'],
        'przekierowanie' => ['Przekierowanie',  'bi-arrow-right-square', 'Poczta, która ma trafiać na inny adres.'],
    ];
}

function mailFooterUrl(string $slug = ''): string {
    return MAIL_FOOTER_BASE . ltrim($slug, '/');
}

function mailFooterMode(): string {
    $mode = function_exists('org_setting') ? org_setting('mail_footer_mode') : '';
    return isset(MAIL_FOOTER_MODES[$mode]) ? $mode : 'remind';
}

function mailFooterSaveMode(string $mode): void {
    org_setting_set('mail_footer_mode', isset(MAIL_FOOTER_MODES[$mode]) ? $mode : 'remind');
}

function mailFooterMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS mail_footer_confirm (
            user_id      INTEGER PRIMARY KEY,
            confirmed_at DATETIME NOT NULL
        )");
    } catch (\Throwable $e) {}
}

/** Czy osoba ma skrzynkę Microsoft 365 (login M365 albo powiązane logowanie przez Microsoft). */
function mailFooterHasMailbox(array $user): bool {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) return false;
    try {
        $r = db_one("SELECT m365_login, microsoft_id FROM users WHERE id=?", [$uid]);
    } catch (\Throwable $e) { return false; }
    return trim((string)($r['m365_login'] ?? '')) !== '' || trim((string)($r['microsoft_id'] ?? '')) !== '';
}

function mailFooterConfirmedAt(int $uid): ?string {
    mailFooterMigrate();
    try {
        return db_one("SELECT confirmed_at FROM mail_footer_confirm WHERE user_id=?", [$uid])['confirmed_at'] ?? null;
    } catch (\Throwable $e) { return null; }
}

function mailFooterConfirm(int $uid): void {
    mailFooterMigrate();
    db()->prepare("INSERT OR REPLACE INTO mail_footer_confirm (user_id, confirmed_at) VALUES (?, ?)")
        ->execute([$uid, date('Y-m-d H:i:s')]);
}

function mailFooterConfirmedCount(): int {
    mailFooterMigrate();
    try { return (int)(db_one("SELECT COUNT(*) AS c FROM mail_footer_confirm")['c'] ?? 0); } catch (\Throwable $e) { return 0; }
}

/** ['mode', 'applies', 'confirmed', 'confirmedAt', 'pending'] — pending = wymóg dotyczy osoby i nie został spełniony. */
function mailFooterStatus(array $user): array {
    $uid  = (int)($user['id'] ?? 0);
    $mode = mailFooterMode();
    $out  = ['mode' => $mode, 'applies' => false, 'confirmed' => false, 'confirmedAt' => null, 'pending' => false];
    if ($uid <= 0 || ($user['email'] ?? '') === 'serwis@local') return $out;
    $out['applies']     = mailFooterHasMailbox($user);
    $out['confirmedAt'] = $out['applies'] ? mailFooterConfirmedAt($uid) : null;
    $out['confirmed']   = $out['confirmedAt'] !== null;
    $out['pending']     = $out['applies'] && !$out['confirmed'] && $mode !== 'off';
    return $out;
}

/**
 * Wymóg na pulpicie: w trybie „require” przenosi na panel/poczta.php, gdy stopka nie została potwierdzona.
 * Wołać PRZED wysłaniem nagłówków strony. Przekierowuje tylko zwykłe wejścia GET; administratorzy
 * i impersonacja są zwolnieni (widzą pas). Zwraca status (do pasa) albo null, gdy nic do pokazania.
 */
function mailFooterEnforce(array $user): ?array {
    if (PHP_SAPI === 'cli') return null;
    $st = mailFooterStatus($user);
    if (!$st['pending']) return null;
    $exempt = (function_exists('is_admin') && is_admin()) || !empty($_SESSION['_admin_original']);
    $plainGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && !isset($_GET['_ajax']) && !isset($_GET['ajax']);
    if ($st['mode'] === 'require' && !$exempt && $plainGet && !headers_sent()) {
        header('Location: ' . APP_URL . '/panel/poczta.php?wymagane=1');
        exit;
    }
    return $st;
}
