<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Contracts;

use FeerSzo\Hybrid\Dto\AccessibilityProfileDto;

/**
 * Granica komunikacji z domeną TI (Tyfloinformatyka).
 *
 * Ma dokładnie dwie implementacje:
 *   - InternalTyfloProfileClient — wywołanie bezpośrednie (TI w tym samym procesie).
 *   - HttpTyfloProfileClient     — wywołanie REST (TI jako osobny serwis).
 * Wołający (np. Dydaktyka) zna WYŁĄCZNIE ten interfejs — nie wie i nie musi
 * wiedzieć, gdzie faktycznie działa TI.
 */
interface TyfloProfileClientInterface
{
    /**
     * Zwraca profil dostępności uczestnika. Gdy profil nie istnieje, zwraca
     * pusty AccessibilityProfileDto (nie null) — brak profilu to prawidłowy,
     * "domyślny" stan, nie błąd.
     */
    public function getProfile(int $clientId): AccessibilityProfileDto;
}
