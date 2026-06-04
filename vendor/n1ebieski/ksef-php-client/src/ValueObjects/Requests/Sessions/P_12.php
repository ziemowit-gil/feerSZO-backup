<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Contracts\EnumInterface;

enum P_12: string implements EnumInterface
{
    case Tax23 = '23';

    case Tax22 = '22';

    case Tax8 = '8';

    case Tax7 = '7';

    case Tax5 = '5';

    case Tax4 = '4';

    case Tax3 = '3';

    /**
     * Stawka 0% w przypadku sprzedaży towarów i świadczenia usług na terytorium kraju (z wyłączeniem WDT i eksportu)
     */
    case Tax0Kr = '0 KR';

    /**
     * Stawka 0% w przypadku wewnątrzwspólnotowej dostawy towarów (WDT)
     */
    case Tax0Wdt = '0 WDT';

    /**
     * Stawka 0% w przypadku eksportu towarów
     */
    case Tax0Ex = '0 EX';

    case Zw = 'zw';

    case Oo = 'oo';

    /**
     * niepodlegające opodatkowaniu- dostawy towarów oraz świadczenia usług poza terytorium kraju,
     * z wyłączeniem transakcji, o których mowa w art. 100 ust. 1 pkt 4 ustawy oraz OSS
     */
    case NpI = 'np I';

    /**
     * niepodlegajace opodatkowaniu na terytorium kraju, świadczenie usług o których mowa w art. 100 ust. 1 pkt 4 ustawy
     */
    case NpII = 'np II';
}
