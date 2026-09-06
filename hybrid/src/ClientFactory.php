<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid;

use FeerSzo\Hybrid\Config\DriverConfig;
use FeerSzo\Hybrid\Contracts\SzoQualityReportClientInterface;
use FeerSzo\Hybrid\Contracts\TyfloProfileClientInterface;
use FeerSzo\Hybrid\Szo\HttpSzoQualityReportClient;
use FeerSzo\Hybrid\Szo\InternalSzoQualityReportClient;
use FeerSzo\Hybrid\Ti\HttpTyfloProfileClient;
use FeerSzo\Hybrid\Ti\InternalTyfloProfileClient;

/**
 * Jedyne miejsce, które decyduje "local czy http" — na podstawie
 * DriverConfig (zmienne środowiskowe). Wołający (kontrolery/serwisy
 * Dydaktyki) proszą fabrykę o interfejs i nie wiedzą, którą implementację
 * dostali.
 */
final class ClientFactory
{
    public static function tyfloProfileClient(): TyfloProfileClientInterface
    {
        if (DriverConfig::isHttp(DriverConfig::tiDriver())) {
            return new HttpTyfloProfileClient(DriverConfig::tiBaseUrl(), DriverConfig::tiApiKey());
        }
        return new InternalTyfloProfileClient();
    }

    public static function szoQualityReportClient(): SzoQualityReportClientInterface
    {
        if (DriverConfig::isHttp(DriverConfig::szoDriver())) {
            return new HttpSzoQualityReportClient(DriverConfig::szoBaseUrl(), DriverConfig::szoApiKey());
        }
        return new InternalSzoQualityReportClient();
    }
}
