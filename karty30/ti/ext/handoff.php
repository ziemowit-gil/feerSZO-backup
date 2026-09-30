<?php
/**
 * karty30/ti/ext/handoff.php — wejście do Biblioteki materiałów z nowego panelu
 * kursanta (kursantApp, logowanie tokenem API). Moduł ext działa na sesji PHP
 * k30_student (jak klasyczny panel), więc nowy panel pobiera z API jednorazowy
 * bilet (action=ext_handoff, 60 s, w bazie tylko skrót), a ta strona zamienia go
 * na sesję kursanta i przekierowuje do Biblioteki. Impersonacja admina zostaje
 * impersonacją (student_impersonate), a nie zwykłym logowaniem.
 */
$root = dirname(__DIR__, 3);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/karty30.php';
require_once $root . '/karty30/ti/kursant/auth.php';

$fail = function (string $msg): void {
    http_response_code(403);
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Biblioteka materiałów</title>'
       . '<body style="font:15px/1.5 system-ui,sans-serif;max-width:520px;margin:60px auto;padding:0 16px">'
       . '<h1 style="font-size:19px">Nie udało się otworzyć Biblioteki materiałów</h1><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p>'
       . '<p><a href="index.php?as=student">Zaloguj się do Biblioteki</a> albo wróć do panelu i spróbuj ponownie.</p></body></html>';
    exit;
};

$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $t)) $fail('Nieprawidłowy link.');
try {
    $h = db_one("SELECT * FROM k30_ti_ext_handoff WHERE token_hash=? AND used_at IS NULL AND expires_at > datetime('now')", [hash('sha256', $t)]);
} catch (\Throwable $e) { $h = null; }
if (!$h) $fail('Link wygasł albo został już użyty.');

// Jednorazowość: oznacz przed założeniem sesji (warunek w UPDATE chroni przed wyścigiem)
$st = db()->prepare("UPDATE k30_ti_ext_handoff SET used_at=datetime('now') WHERE id=? AND used_at IS NULL");
$st->execute([(int)$h['id']]);
if ($st->rowCount() !== 1) $fail('Link został już użyty.');

$acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1", [(int)$h['account_id']]);
if (!$acc || !empty($acc['child_access_blocked'])) $fail('Konto kursanta jest nieaktywne albo zablokowane.');

if ($h['role'] === 'impersonation' && (int)$h['actor_id'] > 0) {
    student_impersonate($acc, (int)$h['actor_id'], (string)$h['actor_name']);
} else {
    student_login_user($acc, 'password', 'z nowego panelu (Biblioteka materiałów)');
}
session_write_close();
header('Location: index.php?as=student');
exit;
