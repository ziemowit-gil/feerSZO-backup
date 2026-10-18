<?php
declare(strict_types=1);
/** Wyjątki Słownika Centralnego — komunikaty są dla użytkownika (po polsku). */

class DictionaryException extends \RuntimeException {}

/** Błędne dane wejściowe; $errors = [pole => komunikat]. */
final class DictionaryValidationException extends DictionaryException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

/** Słownik zabezpieczony / reguła rodzaju systemowego / brak uprawnień. */
final class DictionaryLockedException extends DictionaryException {}

/** Nie znaleziono słownika albo wpisu. */
final class DictionaryNotFoundException extends DictionaryException {}

/** Ktoś zmienił wpis w międzyczasie (blokada optymistyczna po kolumnie version). */
final class DictionaryConflictException extends DictionaryException {}
