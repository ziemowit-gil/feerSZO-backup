<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects;

use N1ebieski\KSEFClient\Contracts\EnumInterface;
use N1ebieski\KSEFClient\Support\Concerns\HasEquals;

enum Mode: string implements EnumInterface
{
    use HasEquals;

    case Test = 'test';

    case Demo = 'demo';

    case Production = 'production';

    public function getClientAppInvoiceUrl(): Url
    {
        return match ($this) {
            self::Test => Url::from('https://qr-test.ksef.mf.gov.pl/invoice'),
            self::Demo => Url::from('https://qr-demo.ksef.mf.gov.pl/invoice'),
            self::Production => Url::from('https://qr.ksef.mf.gov.pl/invoice'),
        };
    }

    public function getClientAppCertificateUrl(): Url
    {
        return match ($this) {
            self::Test => Url::from('https://qr-test.ksef.mf.gov.pl/certificate'),
            self::Demo => Url::from('https://qr-demo.ksef.mf.gov.pl/certificate'),
            self::Production => Url::from('https://qr.ksef.mf.gov.pl/certificate'),
        };
    }

    public function getApiUrl(): ApiUrl
    {
        return match ($this) {
            self::Test => ApiUrl::from('https://api-test.ksef.mf.gov.pl/v2'),
            self::Demo => ApiUrl::from('https://api-demo.ksef.mf.gov.pl/v2'),
            self::Production => ApiUrl::from('https://api.ksef.mf.gov.pl/v2'),
        };
    }

    public function getLatarniaApiUrl(): ApiUrl
    {
        return match ($this) {
            self::Test, self::Demo => ApiUrl::from('https://api-latarnia-test.ksef.mf.gov.pl'),
            self::Production => ApiUrl::from('https://api-latarnia.ksef.mf.gov.pl'),
        };
    }
}
