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
// auth.php i karty30.php ładujemy ZAWSZE, niezależnie od warstwy sesji: dają
// auth_start() (bez niej flash_html() wywala stronę), csrf_*() oraz helpery
// kursów. Same z siebie nie startują sesji — robi to dopiero wywołanie funkcji.
// Panel kursanta ich nie ładuje, więc bez tego moduł działał tylko dla
// prowadzącego i pracownika.
require_once $__ext_root . '/includes/auth.php';
require_once $__ext_root . '/includes/karty30.php';

/**
 * Wybór sesji. Samo ciasteczko nie wystarczy: na jednym komputerze potrafią leżeć
 * ciasteczka wszystkich trzech paneli (ktoś sprawdzał panel prowadzącego, potem
 * zalogował się jako kursant), a ciasteczko zostaje także wtedy, gdy sesja po
 * stronie serwera dawno wygasła. Trzymanie się pierwszego napotkanego ciasteczka
 * kończyło się tym, że zalogowany kursant był odsyłany do logowania.
 *
 * Dlatego próbujemy po kolei: jeśli w danej sesji nie ma nikogo, zamykamy ją
 * i sięgamy po następną. PHP utrzymuje w żądaniu jedną sesję naraz, ale po
 * session_write_close() wolno otworzyć kolejną — i tylko tak się to da zrobić.
 */
/** Identyfikator sesji danej warstwy, wprost z ciasteczka (albo null). */
$__ext_sid = function (string $kind): ?string {
    if ($kind === 'dyd')     return $_COOKIE['k30_dydaktyk'] ?? null;
    if ($kind === 'student') return $_COOKIE['k30_student']  ?? null;
    foreach ($_COOKIE as $k => $v) {          // sesja aplikacji: umowy_<organizacja>
        if (str_starts_with($k, 'umowy_')) return (string)$v;
    }
    return null;
};

// Kolejność ma znaczenie techniczne, nie tylko porządkowe: panel prowadzącego
// i aplikacja trzymają sesje w BAZIE (własny save handler), a panel kursanta
// w plikach. Handler instaluje się na całe żądanie, więc gdyby najpierw ruszył
// panel prowadzącego, kolejna próba czytałaby sesję kursanta z bazy — i zawsze
// znajdowała pustkę. Zaczynamy więc od warstwy, która niczego nie przestawia.
$__ext_order = ['student', 'dyd', 'szo'];

// Link z panelu mówi wprost, skąd przyszedł użytkownik — wtedy nie zgadujemy
// wcale (istotne, gdy ktoś ma w przeglądarce ciasteczka obu paneli naraz).
$__ext_as = (string)($_GET['as'] ?? '');
if (in_array($__ext_as, $__ext_order, true)) {
    array_unshift($__ext_order, $__ext_as);
    $__ext_order = array_values(array_unique($__ext_order));
}

// Próbujemy tylko tych warstw, które w ogóle przysłały ciasteczko.
$__ext_try = array_values(array_filter($__ext_order, fn($k) => $__ext_sid($k) !== null));

$EXT_SUBJECT   = null;
$EXT_LAYER     = null;   // student | dyd | szo — czyja sesja się otworzyła
$__ext_started = false;
foreach ($__ext_try as $__ext_kind) {
    // Po session_write_close() PHP PAMIĘTA poprzedni identyfikator i użyłby go
    // ponownie, ignorując ciasteczko następnej warstwy — dlatego ustawiamy go
    // ręcznie przed każdą próbą. Bez tego druga próba czyta pustą sesję.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Poprzednia próba mogła zostawić bazodanowy magazyn sesji — wracamy do
        // natywnego, żeby kolejna warstwa czytała stamtąd, skąd naprawdę pisze.
        if ($__ext_started && class_exists('SessionHandler')) {
            session_set_save_handler(new \SessionHandler(), true);
        }
        $__sid = $__ext_sid($__ext_kind);
        if ($__sid !== null && preg_match('/^[A-Za-z0-9,\-]{16,128}$/', $__sid)) {
            session_id($__sid);
        }
    }

    if ($__ext_kind === 'dyd') {
        require_once __DIR__ . '/../dydaktyk/auth.php';
    } elseif ($__ext_kind === 'student') {
        require_once __DIR__ . '/../kursant/auth.php';
    }   // 'szo' nie ma czego dołączać — auth.php jest wyżej

    require_once $__ext_root . '/includes/ext_access.php';

    // Pytamy WPROST o tę jedną warstwę — ext_subject() zaczyna od panelu
    // prowadzącego i sama zajęłaby sesję, której właśnie nie chcemy.
    $EXT_SUBJECT = ext_subject_layer($__ext_kind);
    if ($EXT_SUBJECT) { $EXT_LAYER = $__ext_kind; break; }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();              // zwolnij miejsce dla kolejnej próby
        $_SESSION      = [];
        $__ext_started = true;
    }
}

require_once $__ext_root . '/includes/ext_access.php';

ext_migrate();

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

/**
 * Dokąd prowadzi powrót do panelu i wylogowanie. Decyduje warstwa sesji, w której
 * użytkownik NAPRAWDĘ jest ($EXT_LAYER ustawiane przy jej otwarciu), a nie samo
 * ciasteczko — stare ciasteczko po cudzym logowaniu wysyłałoby go do panelu, do
 * którego nie ma wstępu, a wylogowanie kończyłoby się na niewłaściwej sesji.
 */
function ext_back_url(?string $layer): string
{
    return match ($layer) {
        'dyd'     => '../dydaktyk/index.php?tab=pulpit',
        'student' => '../kursant/index.php?tab=dane',
        default   => '../index.php',
    };
}

/** Adres wylogowania właściwej sesji. */
function ext_logout_url(?string $layer): string
{
    return match ($layer) {
        'dyd'     => '../dydaktyk/logout.php',
        'student' => '../kursant/index.php?logout=1',
        default   => rtrim(APP_URL, '/') . '/auth/logout.php',
    };
}
