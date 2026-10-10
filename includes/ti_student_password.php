<?php
/**
 * includes/ti_student_password.php — reguły silnego hasła dla kont kursantów TI.
 * Min. 12 znaków, mała i wielka litera, cyfra, znak specjalny; hasło nie może zawierać loginu.
 */

/** Zwraca komunikat błędu albo null, gdy hasło spełnia reguły. */
function ti_student_password_error(string $pass, string $login = ''): ?string {
    if (mb_strlen($pass) < 12) return 'Hasło musi mieć co najmniej 12 znaków.';
    if (!preg_match('/[a-zęóąśłżźćń]/u', $pass)) return 'Hasło musi zawierać małą literę.';
    if (!preg_match('/[A-ZĘÓĄŚŁŻŹĆŃ]/u', $pass)) return 'Hasło musi zawierać wielką literę.';
    if (!preg_match('/[0-9]/', $pass)) return 'Hasło musi zawierać cyfrę.';
    if (!preg_match('/[^\p{L}\p{N}]/u', $pass)) return 'Hasło musi zawierać znak specjalny (np. ! ? # $ %).';
    if ($login !== '' && mb_stripos($pass, $login) !== false) return 'Hasło nie może zawierać loginu.';
    return null;
}

/** Tekst reguł do wyświetlenia przy polu hasła. */
function ti_student_password_hint(): string {
    return 'Min. 12 znaków, wielka i mała litera, cyfra, znak specjalny. Bez loginu.';
}
