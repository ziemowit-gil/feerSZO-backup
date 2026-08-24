<?php
/**
 * includes/crm_sender.php — konta, z których CRM może wysyłać pocztę.
 *
 * Do wyboru są trzy rodzaje nadawcy:
 *   system  — konto systemowe (m365_send_from_email); tak leciało dotąd wszystko,
 *   me      — skrzynka Microsoft 365 zalogowanego użytkownika (kopia trafia
 *             do jego „Elementów wysłanych"),
 *   mbox:ID — skrzynka współdzielona z modułu Poczta, do której użytkownik ma
 *             dostęp (np. fundacja@feer.org.pl).
 *
 * Wybór można zapamiętać jako domyślny — ustawienie jest PER UŻYTKOWNIK
 * (tabela user_prefs), bo każdy pisze z innej skrzynki.
 *
 * UWAGA: wysyłka „jako skrzynka" idzie przez Graph w trybie aplikacyjnym, więc
 * zadziała tylko dla skrzynek w tym samym tenancie M365. Adres spoza tenanta
 * (np. prywatny) zostanie odrzucony przez Graph — dlatego listy nie budujemy
 * z dowolnych adresów, tylko ze skrzynek znanych systemowi.
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
 * @return array [['key'=>'system|me|mbox:ID', 'email'=>..., 'label'=>..., 'hint'=>...], …]
 */
function crm_sender_accounts(?array $user = null): array {
    $u   = $user ?? current_user();
    $out = [];

    require_once __DIR__ . '/mail_queue.php';
    $sys = trim((string)_mail_setting('m365_send_from_email'));
    $out[] = [
        'key'   => 'system',
        'email' => $sys,
        'label' => 'Konto systemowe' . ($sys !== '' ? ' — ' . $sys : ''),
        'hint'  => 'Domyślny nadawca systemu; odpowiedzi trafią na tę skrzynkę.',
    ];

    $m365 = function_exists('_mail_m365_configured') && _mail_m365_configured();

    if ($m365 && !empty($u['microsoft_id']) && !empty($u['email'])) {
        $out[] = [
            'key'   => 'me',
            'email' => trim((string)$u['email']),
            'label' => 'Moja skrzynka — ' . trim((string)$u['email']),
            'hint'  => 'Wiadomość wyjdzie z Twojej skrzynki M365 i zostanie w „Elementach wysłanych".',
        ];
    }

    // Skrzynki współdzielone z modułu Poczta — tylko te, do których user ma dostęp
    try {
        require_once __DIR__ . '/poczta_acl.php';
        foreach (poczta_mailboxes_for_user() as $mb) {
            $addr = trim((string)($mb['mailbox'] ?? ''));
            if ($addr === '' || strcasecmp($addr, (string)($u['email'] ?? '')) === 0) continue;
            $out[] = [
                'key'   => 'mbox:' . (int)$mb['id'],
                'email' => $addr,
                'label' => 'Skrzynka — ' . $addr,
                'hint'  => 'Wysyłka jako skrzynka współdzielona (wymaga uprawnienia aplikacji w M365).',
            ];
        }
    } catch (\Throwable $e) {}

    return $out;
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
        // Konto systemowe adresu nie nadpisuje — resztę wysyłamy „jako" wskazana skrzynka
        return $a['key'] === 'system' ? '' : (string)$a['email'];
    }
    return '';   // nieznany albo niedostępny wybór → bezpieczny fallback
}
