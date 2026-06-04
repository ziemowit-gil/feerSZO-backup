<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Auth;

enum SubjectIdentifierType: string
{
    case CertificateSubject = 'certificateSubject';

    case CertificateFingerprint = 'certificateFingerprint';
}
