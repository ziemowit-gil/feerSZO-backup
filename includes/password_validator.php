<?php
/**
 * PasswordValidator — walidacja, ocena siły i haszowanie haseł.
 *
 * Reguły:
 *  - minimum 8 znaków
 *  - przynajmniej 1 wielka litera
 *  - przynajmniej 1 mała litera
 *  - przynajmniej 1 cyfra
 *
 * Siła hasła:
 *  - weak   — nie spełnia reguł lub ma < 8 znaków
 *  - medium — spełnia reguły, ale ma < 12 znaków
 *  - strong — spełnia reguły i ma >= 12 znaków
 */

if (defined('PASSWORD_VALIDATOR_LOADED')) return;
define('PASSWORD_VALIDATOR_LOADED', true);

class PasswordValidator
{
    /**
     * Waliduje hasło i zwraca wynik w postaci tablicy.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public static function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Hasło musi zawierać co najmniej jedną wielką literę.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Hasło musi zawierać co najmniej jedną małą literę.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Hasło musi zawierać co najmniej jedną cyfrę.';
        }

        return [
            'ok'     => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Ocenia siłę hasła.
     *
     * @return string 'weak'|'medium'|'strong'
     */
    public static function strength(string $password): string
    {
        $result = self::validate($password);

        if (!$result['ok']) {
            return 'weak';
        }

        if (strlen($password) >= 12) {
            return 'strong';
        }

        return 'medium';
    }

    /**
     * Haszuje hasło algorytmem BCRYPT.
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Weryfikuje hasło względem hasha.
     */
    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
