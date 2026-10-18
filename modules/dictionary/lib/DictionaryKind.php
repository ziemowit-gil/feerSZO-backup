<?php
declare(strict_types=1);
/**
 * Rodzaj słownika. Od rodzaju zależą reguły edycji (DictionaryService):
 *   Static   — lista stała, zmieniana rzadko (np. formy prawne);
 *   Dynamic  — rośnie w trakcie pracy (np. instytucje, dostawcy);
 *   System   — klucze wpisów są użyte w kodzie: kodu wpisu nie można zmienić ani wpisu usunąć,
 *              wolno zmieniać etykiety, tłumaczenia, atrybuty, kolejność i aktywność;
 *   Business — słownik biznesowy prowadzony przez użytkowników (domyślny).
 * Niezależnie od rodzaju flaga is_protected blokuje KAŻDĄ edycję, dopóki administrator jej nie zdejmie.
 */
enum DictionaryKind: string
{
    case Static   = 'static';
    case Dynamic  = 'dynamic';
    case System   = 'system';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Static   => 'Statyczny',
            self::Dynamic  => 'Dynamiczny',
            self::System   => 'Systemowy',
            self::Business => 'Biznesowy',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Static   => 'bi-lock',
            self::Dynamic  => 'bi-arrow-repeat',
            self::System   => 'bi-cpu',
            self::Business => 'bi-briefcase',
        };
    }

    /** Czy kody wpisów są zamrożone (użyte w kodzie). */
    public function codesFrozen(): bool
    {
        return $this === self::System;
    }

    /** @return array<string,string> wartość => etykieta (do <select>) */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $c) $out[$c->value] = $c->label();
        return $out;
    }
}
