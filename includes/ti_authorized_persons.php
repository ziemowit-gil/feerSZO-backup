<?php
/**
 * Osoby upoważnione przez pełnoletniego kursanta TI do wglądu w jego panel
 * (tabela k30_ti_authorized_persons, schemat w includes/karty30.php).
 *
 * Wspólne reguły dla dwóch miejsc:
 *  - kierownik/administrator: karty30/ti/dydaktyk/konta.php (zakładka „Upoważnieni”),
 *  - sam kursant: karty30/ti/kursant/index.php?tab=upowaznieni.
 *
 * Zasady:
 *  - upoważniać może TYLKO pełnoletni kursant (is_minor=0) z aktywnym kontem,
 *  - login generowany z imienia i nazwiska + sufiks „-up”, unikalny w obu tabelach
 *    (osoby upoważnione i konta kursantów),
 *  - hasło generowane, zapisywane wyłącznie jako hash; jawne hasło wraca tylko
 *    w wyniku funkcji — wywołujący pokazuje je RAZ (jak kierownik w konta.php),
 *  - „zablokuj” = is_active=0 bez revoked_at (można odblokować),
 *    „odwołaj” = is_active=0 + revoked_at (koniec upoważnienia),
 *  - limit TI_AUTHP_MAX_ACTIVE aktywnych osób na kursanta (egzekwowany dla kursanta;
 *    kierownik może go pominąć parametrem).
 *
 * Funkcje nie wysyłają żadnych wiadomości — powiadomienia zostają po stronie wywołującego.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ti_messages.php'; // ti_account_log() — dziennik konta kursanta

/** Maksymalna liczba jednocześnie aktywnych osób upoważnionych na jednego kursanta. */
const TI_AUTHP_MAX_ACTIVE = 3;

/** Bazowy login z imienia i nazwiska: pierwsza litera imienia + kropka + nazwisko, bez polskich znaków. */
function ti_authp_login_base(string $name): string {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
    $parts = preg_split('/\s+/', trim(strtr($name, $map))) ?: [''];
    $login = count($parts) >= 2 ? ($parts[0][0] . '.' . end($parts)) : $parts[0];
    $login = preg_replace('/[^a-z0-9._-]/', '', strtolower($login));
    if ($login === '' || $login === '.') $login = 'osoba';
    return $login . '-up';
}

/** Unikalny login osoby upoważnionej: 4 cyfry + „u” (TiLoginFormat). $name zostaje dla zgodności wywołań. */
function ti_authp_unique_login(string $name): string {
    // Nowy format (TiLoginFormat): 4 cyfry + „u”
    require_once dirname(__DIR__) . '/modules/ti_login/logic/TiLoginFormat.php';
    return TiLoginFormat::numberLogin(TiLoginFormat::AUTHP);
}

/**
 * Hasło osoby upoważnionej: słowo + 4 cyfry + 2 wielkie litery (bez mylących się I/O),
 * np. „Rower4821KM” — łatwe do przepisania z kartki, ale znacznie trudniejsze do
 * odgadnięcia niż dawne „słowo + 3 cyfry”.
 */
function ti_authp_gen_pass(): string {
    $w = ['Kotek','Rower','Zamek','Kwiat','Statek','Zegar','Obraz','Lampa','Mosty','Sokol','Klucz','Gwiazda'];
    $l = 'ABCDEFGHJKLMNPRSTUWXYZ';
    return $w[random_int(0, count($w) - 1)] . random_int(1000, 9999)
         . $l[random_int(0, strlen($l) - 1)] . $l[random_int(0, strlen($l) - 1)];
}

/** Konto kursanta, dla którego można prowadzić upoważnienia (aktywne, pełnoletnie) albo null. */
function ti_authp_eligible_account(int $student_account_id, bool $require_active = true): ?array {
    if ($student_account_id <= 0) return null;
    $acc = db_one(
        "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
         JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?",
        [$student_account_id]
    );
    if (!$acc || !empty($acc['is_minor'])) return null;
    if ($require_active && empty($acc['is_active'])) return null;
    return $acc;
}

/** Lista upoważnień kursanta (najnowsze na górze). */
function ti_authp_list(int $student_account_id): array {
    return db_all(
        "SELECT * FROM k30_ti_authorized_persons WHERE student_account_id=? ORDER BY created_at DESC, id DESC",
        [$student_account_id]
    );
}

/** Liczba aktywnych upoważnień kursanta. */
function ti_authp_active_count(int $student_account_id): int {
    $r = db_one(
        "SELECT COUNT(*) AS c FROM k30_ti_authorized_persons WHERE student_account_id=? AND is_active=1",
        [$student_account_id]
    );
    return (int)($r['c'] ?? 0);
}

/** Upoważnienie należące do danego kursanta albo null (ochrona przed operacją na cudzym rekordzie). */
function ti_authp_get_owned(int $id, int $student_account_id): ?array {
    if ($id <= 0 || $student_account_id <= 0) return null;
    return db_one(
        "SELECT * FROM k30_ti_authorized_persons WHERE id=? AND student_account_id=?",
        [$id, $student_account_id]
    ) ?: null;
}

/**
 * Tworzy upoważnienie.
 *
 * $data: name (wymagane), reason (wymagane), email (opcjonalnie), notes (opcjonalnie).
 * $opts: enforce_limit (bool, domyślnie true), require_active (bool, domyślnie true —
 *        kierownik może dodać upoważnienie także do nieaktywnego konta).
 *
 * Zwraca ['ok'=>true, 'id', 'login', 'pass', 'name', 'student_name', 'email']
 * albo ['ok'=>false, 'error'=>komunikat po polsku].
 */
function ti_authp_create(array $data, int $student_account_id, string $added_by_name, array $opts = []): array {
    $acc = ti_authp_eligible_account($student_account_id, (bool)($opts['require_active'] ?? true));
    if (!$acc) {
        return ['ok' => false, 'error' => 'Upoważnienia obsługiwane tylko dla pełnoletnich kursantów z aktywnym kontem.'];
    }
    $name   = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($data['name'] ?? ''))), 0, 100);
    $email  = mb_substr(trim((string)($data['email']  ?? '')), 0, 150);
    $notes  = mb_substr(trim((string)($data['notes']  ?? '')), 0, 300);
    $reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 400);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Podaj imię i nazwisko osoby upoważnionej.', 'field' => 'name'];
    }
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Podaj powód upoważnienia.', 'field' => 'reason'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Adres e-mail jest nieprawidłowy. Popraw go albo zostaw pole puste.', 'field' => 'email'];
    }
    if (($opts['enforce_limit'] ?? true) && ti_authp_active_count($student_account_id) >= TI_AUTHP_MAX_ACTIVE) {
        return ['ok' => false, 'error' => 'Możesz mieć najwyżej ' . TI_AUTHP_MAX_ACTIVE
            . ' aktywne osoby upoważnione. Zablokuj albo odwołaj jedną z nich, aby dodać nową.', 'field' => 'limit'];
    }

    $login = ti_authp_unique_login($name);
    $pass  = ti_authp_gen_pass();
    $id = db_insert('k30_ti_authorized_persons', [
        'student_account_id' => $student_account_id,
        'name'          => $name,
        'login'         => $login,
        'email'         => $email,
        'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
        'notes'         => $notes,
        'added_by_name' => mb_substr($added_by_name, 0, 120),
        'reason'        => $reason,
    ]);
    return [
        'ok'           => true,
        'id'           => (int)$id,
        'login'        => $login,
        'pass'         => $pass,
        'name'         => $name,
        'email'        => $email,
        'notes'        => $notes,
        'reason'       => $reason,
        'student_name' => (string)$acc['client_name'],
        'client_id'    => (int)$acc['client_id'],
    ];
}

/**
 * Blokuje (is_active=0, bez odwołania) albo odblokowuje upoważnienie kursanta.
 * Odwołanego upoważnienia nie da się odblokować tą funkcją.
 * Zwraca ['ok'=>bool, 'error'?, 'row'?].
 */
function ti_authp_set_blocked(int $id, int $student_account_id, bool $blocked, bool $enforce_limit = true): array {
    if (!ti_authp_eligible_account($student_account_id)) {
        return ['ok' => false, 'error' => 'Upoważnienia obsługiwane tylko dla pełnoletnich kursantów z aktywnym kontem.'];
    }
    $ap = ti_authp_get_owned($id, $student_account_id);
    if (!$ap) return ['ok' => false, 'error' => 'Nie znaleziono upoważnienia.'];
    if (!empty($ap['revoked_at'])) {
        return ['ok' => false, 'error' => 'To upoważnienie zostało odwołane i nie można go odblokować. Dodaj osobę ponownie.'];
    }
    if (!$blocked && empty($ap['is_active']) && $enforce_limit
        && ti_authp_active_count($student_account_id) >= TI_AUTHP_MAX_ACTIVE) {
        return ['ok' => false, 'error' => 'Możesz mieć najwyżej ' . TI_AUTHP_MAX_ACTIVE
            . ' aktywne osoby upoważnione. Zablokuj albo odwołaj inną osobę, aby odblokować tę.'];
    }
    db()->prepare("UPDATE k30_ti_authorized_persons SET is_active=? WHERE id=? AND student_account_id=?")
       ->execute([$blocked ? 0 : 1, $id, $student_account_id]);
    return ['ok' => true, 'row' => $ap];
}

/**
 * Odwołuje upoważnienie (is_active=0, revoked_at=teraz, revoked_by_name).
 * Zwraca ['ok'=>bool, 'error'?, 'row'?].
 */
function ti_authp_revoke(int $id, int $student_account_id, string $revoked_by_name): array {
    $ap = ti_authp_get_owned($id, $student_account_id);
    if (!$ap) return ['ok' => false, 'error' => 'Nie znaleziono upoważnienia.'];
    if (!empty($ap['revoked_at'])) return ['ok' => false, 'error' => 'To upoważnienie jest już odwołane.'];
    db()->prepare(
        "UPDATE k30_ti_authorized_persons SET is_active=0, revoked_at=datetime('now'), revoked_by_name=?
         WHERE id=? AND student_account_id=?"
    )->execute([mb_substr($revoked_by_name, 0, 120), $id, $student_account_id]);
    return ['ok' => true, 'row' => $ap];
}

/**
 * Przywraca dostęp po odwołaniu (tylko kierownik — konta.php): is_active=1, czyści revoked_at.
 */
function ti_authp_restore(int $id, int $student_account_id): array {
    $ap = ti_authp_get_owned($id, $student_account_id);
    if (!$ap) return ['ok' => false, 'error' => 'Nie znaleziono upoważnienia.'];
    db()->prepare(
        "UPDATE k30_ti_authorized_persons SET is_active=1, revoked_at=NULL, revoked_by_name=''
         WHERE id=? AND student_account_id=?"
    )->execute([$id, $student_account_id]);
    return ['ok' => true, 'row' => $ap];
}
