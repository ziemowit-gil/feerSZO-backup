<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\Requests\Invoices\Query\Metadata;

use N1ebieski\KSEFClient\Testing\Fixtures\Requests\AbstractResponseFixture;

final class MetadataResponseFixture extends AbstractResponseFixture
{
    public int $statusCode = 200;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'hasMore' => false,
        'isTruncated' => false,
        'invoices' => [
            [
                'ksefNumber' => '5555555555-20250828-010080615740-E4',
                'invoiceNumber' => 'FA/KUDYO1a7dddfe-610e-4843-84ba-6b887e35266e',
                'issueDate' => '2025-08-27',
                'invoicingDate' => '2025-08-28T09:22:13.388+00:00',
                'acquisitionDate' => '2025-08-28T09:22:56.388+00:00',
                'permanentStorageDate' => '2025-08-28T09:23:01.388+00:00',
                'seller' => [
                    'nip' => '5555555555',
                    'name' => 'Test Company 1',
                ],
                'buyer' => [
                    'identifier' => [
                        'type' => 'Nip',
                        'value' => '7352765225',
                    ],
                    'name' => 'Test Company 4',
                ],
                'netAmount' => 35260.63,
                'grossAmount' => 43370.57,
                'vatAmount' => 8109.94,
                'currency' => 'PLN',
                'invoicingMode' => 'Offline',
                'invoiceType' => 'Vat',
                'formCode' => [
                    'systemCode' => 'FA (3)',
                    'schemaVersion' => '1-0E',
                    'value' => 'FA',
                ],
                'isSelfInvoicing' => false,
                'hasAttachment' => false,
                'invoiceHash' => 'mkht+3m5trnfxlTYhq3QFn74LkEO69MFNlsMAkCDSPA=',
                'thirdSubjects' => [],
            ],
            [
                'ksefNumber' => '5555555555-20250828-010080615740-E4',
                'invoiceNumber' => '5265877635-20250925-010020A0A242-0A',
                'issueDate' => '2025-08-28',
                'invoicingDate' => '2025-08-28T10:23:13.388+00:00',
                'acquisitionDate' => '2025-08-28T10:23:56.388+00:00',
                'permanentStorageDate' => '2025-08-28T10:24:01.388+00:00',
                'seller' => [
                    'nip' => '5555555555',
                    'name' => 'Test Company 1',
                ],
                'buyer' => [
                    'identifier' => [
                        'type' => 'Nip',
                        'value' => '3225081610',
                    ],
                    'name' => 'Test Company 2',
                ],
                'netAmount' => 35260.63,
                'grossAmount' => 43370.57,
                'vatAmount' => 8109.94,
                'currency' => 'PLN',
                'invoicingMode' => 'Online',
                'invoiceType' => 'Vat',
                'formCode' => [
                    'systemCode' => 'FA (3)',
                    'schemaVersion' => '1-0E',
                    'value' => 'FA',
                ],
                'isSelfInvoicing' => false,
                'hasAttachment' => true,
                'invoiceHash' => 'o+nMBU8n8TAhy6EjbcdYdHSZVbUspqmCKqOPLhy3zIQ=',
                'thirdSubjects' => [
                    [
                        'identifier' => [
                            'type' => 'InternalId',
                            'value' => '5555555555-12345',
                        ],
                        'name' => 'Wystawca faktury',
                        'role' => 4,
                    ]
                ],
            ],
        ],
    ];
}
