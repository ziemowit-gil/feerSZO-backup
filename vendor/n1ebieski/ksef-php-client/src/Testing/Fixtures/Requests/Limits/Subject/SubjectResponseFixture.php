<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Limits\Subject;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class SubjectResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'enrollment' => [
            'maxEnrollments' => 0
        ],
        'certificate' => [
            'maxCertificates' => 0
        ]
    ];
}
