<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests;

enum XmlNamespace: string
{
    case Auth = 'http://ksef.mf.gov.pl/auth/token/2.0';

    case Ds = 'http://www.w3.org/2000/09/xmldsig#';

    case Xades = 'http://uri.etsi.org/01903/v1.3.2#';

    case Xsi = 'http://www.w3.org/2001/XMLSchema-instance';

    case Fa3 = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    case FaRr1 = 'http://crd.gov.pl/wzor/2026/03/06/14189/';
}
