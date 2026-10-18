<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/DeclarationStatus.php — statusy oświadczenia prowadzącego (MSUES 2.0).
 *
 * Obieg: wersja robocza → oczekuje na weryfikację → zatwierdzone pod audyt; z weryfikacji można odrzucić
 * (odrzucone wraca do edycji jako robocze). Zatwierdzenie nowszej wersji oznacza poprzednią zatwierdzoną
 * jako „zastąpione” — historia wersji zostaje, ważne jest zawsze najnowsze zatwierdzone.
 */
enum DeclarationStatus: string
{
    case Draft      = 'draft';
    case Pending    = 'pending';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Wersja robocza',
            self::Pending    => 'Oczekuje na weryfikację',
            self::Approved   => 'Zatwierdzone pod audyt',
            self::Rejected   => 'Odrzucone',
            self::Superseded => 'Zastąpione nowszą wersją',
        };
    }

    /** Klasa odznaki Bootstrapa. */
    public function badge(): string
    {
        return match ($this) {
            self::Draft      => 'secondary',
            self::Pending    => 'warning',
            self::Approved   => 'success',
            self::Rejected   => 'danger',
            self::Superseded => 'light',
        };
    }

    /** Treść oświadczenia można zmieniać (robocze i odrzucone). */
    public function editable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Dozwolone przejścia ręczne; $staff = kierownik / zastępca / administrator panelu. */
    public function canTransitionTo(self $to, bool $staff): bool
    {
        return match ([$this, $to]) {
            [self::Draft, self::Pending], [self::Rejected, self::Pending] => true,
            [self::Pending, self::Draft]                                   => true,   // wycofanie do poprawy
            [self::Pending, self::Approved], [self::Pending, self::Rejected] => $staff,
            default => false,
        };
    }

    /** @return array<string,string> kod => etykieta */
    public static function options(): array
    {
        $o = [];
        foreach (self::cases() as $c) $o[$c->value] = $c->label();
        return $o;
    }
}
