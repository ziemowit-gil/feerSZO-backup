<?php
/**
 * karty30/ti/ext/_boot.php — wspólny start stron modułu Materiały zewnętrzne.
 *
 * PHP utrzymuje w żądaniu JEDNĄ sesję, a SZO ma trzy niezależne (aplikacja,
 * panel kursanta, panel prowadzącego). Moduł ma działać we wszystkich, więc
 * o tym, którą otworzyć, decyduje ciasteczko obecne w żądaniu — kolejność:
 * prowadzący → kursant → aplikacja. Otwarcie dwóch naraz jest niemożliwe,
 * a próba kończy się cichym rozjazdem sesji (patrz komentarz w dydaktyk/imp.php).
 */

$__ext_root = dirname(dirname(dirname(__DIR__)));
require_once $__ext_root . '/config.php';
require_once $__ext_root . '/includes/db.php';
require_once $__ext_root . '/includes/functions.php';

if (isset($_COOKIE['k30_dydaktyk'])) {
    require_once __DIR__ . '/../dydaktyk/auth.php';
} elseif (isset($_COOKIE['k30_student'])) {
    require_once __DIR__ . '/../kursant/auth.php';
} else {
    require_once $__ext_root . '/includes/auth.php';
    require_once $__ext_root . '/includes/karty30.php';
}

require_once $__ext_root . '/includes/ext_access.php';

ext_migrate();

$EXT_SUBJECT = ext_subject();
$EXT_MANAGE  = ext_can_manage($EXT_SUBJECT);

/** Strona tylko dla zalogowanych — bez podmiotu nie ma czego pokazywać. */
function ext_require_subject(?array $subject): array
{
    if ($subject) return $subject;
    header('Location: ' . rtrim(APP_URL, '/') . '/karty30/ti/login.php');
    exit;
}

/** Strona tylko dla pracownika (wgrywanie, uprawnienia, dziennik). */
function ext_require_manager(?array $subject): array
{
    $s = ext_require_subject($subject);
    if (!ext_can_manage($s)) {
        http_response_code(403);
        exit('Ta część modułu jest dostępna dla pracowników.');
    }
    return $s;
}

/** Odpowiedź JSON dla wywołań AJAX (upload w kawałkach). */
function ext_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Zakończenie żądania pliku komunikatem — bez layoutu, bo tu leci strumień. */
function ext_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

/** Dokąd wraca „Wróć do panelu" — zależnie od tego, kto patrzy. */
function ext_back_url(?array $subject): string
{
    if (isset($_COOKIE['k30_dydaktyk'])) return '../dydaktyk/index.php?tab=pulpit';
    if (isset($_COOKIE['k30_student']))  return '../kursant/index.php?tab=dane';
    return '../index.php';
}
