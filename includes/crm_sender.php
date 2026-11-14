<?php
/**
 * includes/crm_sender.php — konta, z których CRM może wysyłać pocztę.
 *
 * Do wyboru: Konto Fundacji (SMTP z ustawień) i własna skrzynka dPoczta (SMTP, hasło do poczty podawane
 * w CRM → Moja skrzynka). Microsoft 365 (Graph, skrzynki współdzielone) usunięty 14.11.2026 — opis przy
 * crm_sender_accounts().
 *
 * Wybór można zapamiętać jako domyślny — ustawienie jest PER UŻYTKOWNIK
 * (tabela user_prefs), bo każdy pisze z innej skrzynki.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS user_prefs (
            user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            pref_key TEXT    NOT NULL,
            value    TEXT    NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, pref_key)
        )");
    } catch (\Throwable $e) {}
})();

/** Preferencja użytkownika (dowolna, nie tylko nadawca). */
function user_pref(string $key, string $default = '', ?int $user_id = null): string {
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    if ($uid <= 0) return $default;
    try {
        $r = db_one("SELECT value FROM user_prefs WHERE user_id=? AND pref_key=?", [$uid, $key]);
        return $r ? (string)$r['value'] : $default;
    } catch (\Throwable $e) { return $default; }
}

function user_pref_set(string $key, string $value, ?int $user_id = null): void {
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    if ($uid <= 0) return;
    try {
        db()->prepare("INSERT INTO user_prefs (user_id, pref_key, value) VALUES (?,?,?)
                       ON CONFLICT(user_id, pref_key) DO UPDATE SET value=excluded.value")
            ->execute([$uid, $key, $value]);
    } catch (\Throwable $e) {
        error_log('[user_pref_set] ' . $e->getMessage());
    }
}

/**
 * Konta nadawcy dostępne dla bieżącego użytkownika.
 *
 * Od 14.11.2026 bez Microsoft 365 (poczta Fundacji przeniesiona na dPoczta):
 *   system — Konto Fundacji: SMTP z Ustawienia → Poczta (mail_queue: transport 'smtp', bez Graph),
 *   me     — własna skrzynka dPoczta przez SMTP (modules/userSmtp); wymaga podania hasła do poczty
 *            (crm/moja_skrzynka.php), po każdej zmianie hasła — ponownie. 'ready' = hasło zapisane i aktualne.
 * Skrzynki współdzielone wysyłane przez Graph (mbox:ID) usunięte.
 *
 * @return array [['key'=>'system|me', 'email'=>..., 'label'=>..., 'hint'=>..., 'ready'=>bool], …]
 */
function crm_sender_accounts(?array $user = null): array {
    $u   = $user ?? current_user();
    $out = [];

    require_once __DIR__ . '/mail_queue.php';
    $sys = trim((string)(_mail_setting('smtp_from_email') ?: _mail_setting('notify_from_email') ?: _mail_from()));
    $out[] = [
        'key'   => 'system',
        'email' => $sys,
        'label' => 'Konto Fundacji' . ($sys !== '' ? ' — ' . $sys : ''),
        'hint'  => 'Domyślny nadawca korespondencji organizacji (serwer SMTP z Ustawienia → Poczta); odpowiedzi trafią na tę skrzynkę.',
        'ready' => true,
    ];

    if ($u) {
        require_once dirname(__DIR__) . '/modules/userSmtp/lib/UserSmtp.php';
        $box = UserSmtp::mailboxOf($u);
        if ($box !== '') {
            $ready = UserSmtp::ready((int)$u['id']);
            $out[] = [
                'key'   => 'me',
                'email' => $box,
                'label' => 'Moja skrzynka — ' . $box . ($ready ? '' : ' (najpierw podaj hasło do poczty)'),
                'hint'  => $ready
                    ? 'Wiadomość wyjdzie z Twojej skrzynki przez serwer poczty Fundacji (dPoczta).'
                    : 'Aby pisać z własnej skrzynki, podaj raz hasło do poczty w CRM → Moja skrzynka. Po każdej zmianie hasła trzeba je podać ponownie.',
                'ready' => $ready,
            ];
        }
    }

    return $out;
}

/**
 * Podpowiedź pod listą nadawców: gdy własna skrzynka wymaga podania hasła do poczty — odnośnik do CRM → Moja skrzynka.
 * Pusty tekst, gdy nie ma czego podawać. Tekst zwykły (nie tylko kolor) + odnośnik z opisową nazwą (WCAG 1.4.1, 2.4.4).
 */
function crm_sender_mailbox_hint(array $accounts, string $id = ''): string {
    foreach ($accounts as $a) {
        if (($a['key'] ?? '') !== 'me' || !empty($a['ready'])) continue;
        return '<div class="form-text small"' . ($id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
             . 'Pisanie z własnej skrzynki wymaga hasła do poczty: '
             . '<a href="' . htmlspecialchars(crm_sender_mailbox_url(), ENT_QUOTES, 'UTF-8') . '">podaj hasło do poczty (Moja skrzynka)</a>.'
             . ' Po każdej zmianie hasła trzeba je podać ponownie.</div>';
    }
    return '';
}

/** Adres strony, na której podaje się hasło do własnej skrzynki. */
function crm_sender_mailbox_url(): string {
    return APP_URL . '/crm/moja_skrzynka.php';
}

/**
 * Sprawdza, czy z wybranego konta da się teraz wysłać. Zwraca komunikat błędu dla użytkownika albo '' (OK).
 * Wysyłka z „Mojej skrzynki” bez aktualnego hasła NIE przechodzi po cichu na Konto Fundacji.
 */
function crm_sender_problem(string $key, ?array $user = null): string {
    if ($key !== 'me') return '';
    foreach (crm_sender_accounts($user) as $a) {
        if ($a['key'] !== 'me') continue;
        return $a['ready'] ? '' : 'Aby wysłać z własnej skrzynki, podaj hasło do poczty w CRM → Moja skrzynka (' . crm_sender_mailbox_url() . '). Po każdej zmianie hasła trzeba je podać ponownie.';
    }
    return 'Twoje konto nie ma skrzynki pocztowej Fundacji — wybierz Konto Fundacji.';
}

/** Domyślne konto nadawcy użytkownika (klucz), z odsiewem nieaktualnych wpisów. */
function crm_sender_default(?array $accounts = null): string {
    $accounts = $accounts ?? crm_sender_accounts();
    $saved    = user_pref('crm_send_as', 'system');
    foreach ($accounts as $a) if ($a['key'] === $saved) return $saved;
    return 'system';
}

/**
 * Zamienia wybór z formularza na adres nadawcy dla mail_queue_add().
 * Pusty wynik = nadawca systemowy (mail_queue użyje swojego ustawienia).
 */
function crm_sender_email(string $key, ?array $user = null): string {
    if ($key === '' || $key === 'system') return '';

    foreach (crm_sender_accounts($user) as $a) {
        if ($a['key'] !== $key) continue;
        // Konto systemowe adresu nie nadpisuje; własna skrzynka tylko z aktualnym hasłem (crm_sender_problem())
        return ($a['key'] === 'system' || empty($a['ready'])) ? '' : (string)$a['email'];
    }
    return '';   // nieznany albo niedostępny wybór → bezpieczny fallback
}
