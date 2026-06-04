<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Requests\Certificates\Retrieve;

use N1ebieski\KSEFClient\Contracts\BodyInterface;
use N1ebieski\KSEFClient\Requests\AbstractRequest;
use N1ebieski\KSEFClient\Support\Concerns\HasToBody;
use N1ebieski\KSEFClient\Validator\Rules\Array\MaxRule;
use N1ebieski\KSEFClient\Validator\Rules\Array\MinRule;
use N1ebieski\KSEFClient\Validator\Validator;
use N1ebieski\KSEFClient\ValueObjects\CertificateSerialNumber;

final class RetrieveRequest extends AbstractRequest implements BodyInterface
{
    use HasToBody;

    /**
     * @var array<int, CertificateSerialNumber> $certificateSerialNumbers
     */
    public readonly array $certificateSerialNumbers;

    /**
     * @param array<int, CertificateSerialNumber> $certificateSerialNumbers
     */
    public function __construct(array $certificateSerialNumbers)
    {
        Validator::validate([
            'certificateSerialNumbers' => $certificateSerialNumbers
        ], [
            'certificateSerialNumbers' => [
                new MinRule(1),
                new MaxRule(10)
            ]
        ]);

        $this->certificateSerialNumbers = $certificateSerialNumbers;
    }
}
