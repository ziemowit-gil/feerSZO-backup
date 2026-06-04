<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Auth\KsefToken;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class KsefTokenResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 202;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'referenceNumber' => '20250514-AU-2DFC46C000-3AC6D5877F-D4',
        'authenticationToken' => [
            'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ0b2tlbi10eXBlIjoiT3BlcmF0aW9uVG9rZW4iLCJvcGVyYXRpb24tcmVmZXJlbmNlLW51bWJlciI6IjIwMjUwNTE0LUFVLTJERkM0NkMwMDAtM0FDNkQ1ODc3Ri1ENCIsImV4cCI6MTc0NzIzMTcxOSwiaWF0IjoxNzQ3MjI5MDE5LCJpc3MiOiJrc2VmLWFwaS10aSIsImF1ZCI6ImtzZWYtYXBpLXRpIn0.rtRcV2mR9SiuJwpQaQHsbAXvvVsdNKG4DJsdiJctIeU',
            'validUntil' => '2025-07-11T12:23:56.0154302+00:00'
        ]
    ];
}
