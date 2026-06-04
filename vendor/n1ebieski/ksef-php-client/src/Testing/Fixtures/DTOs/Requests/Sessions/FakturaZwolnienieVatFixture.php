<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\DTOs\Requests\Sessions;

final class FakturaZwolnienieVatFixture extends AbstractFakturaFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'naglowek' => [
            'wariantFormularza' => 'FA (3)',
            'systemInfo' => 'TEST',
        ],
        'podmiot1' => [
            'daneIdentyfikacyjne' => [
                'nip' => '5555555555',
                'nazwa' => 'Test Seller',
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => '02-786 Warszawa',
            ],
        ],
        'podmiot2' => [
            'daneIdentyfikacyjne' => [
                'idGroup' => [
                    'nip' => '3561236543',
                ],
                'nazwa' => 'EggPlants Sp. j.',
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => 'Warzywna 33B, 44-234 Dzięciołów',
            ],
        ],
        'fa' => [
            'kodWaluty' => 'PLN',
            'p_1' => '2025-11-07',
            'p_1M' => 'Warszawa',
            'p_2' => '11/FV/2025',
            'p_6Group' => [
                'p_6' => '2025-11-07',
            ],
            'p_13_7' => '100.0',
            'p_15' => '100.0',
            'rodzajFaktury' => 'VAT',
            'faWiersz' => [
                [
                    'nrWierszaFa' => 1,
                    'p_7' => 'Usługa informatyczna',
                    'p_8A' => 'szt.',
                    'p_8B' => '1.0',
                    'p_9A' => '100.0',
                    'p_11' => '100.0',
                    'p_12' => 'zw',
                ],
            ],
            'platnosc' => [
                'terminPlatnosci' => [
                    [
                        'termin' => '2025-11-14',
                    ],
                ],
                'platnoscGroup' => [
                    'formaPlatnosci' => '6',
                ],
            ],
            'adnotacje' => [
                'zwolnienie' => [
                    'p_19Group' => [
                        'p_19ABCGroup' => [
                            'p_19A' => 'art. 43. ust. 1 pkt 29 lit. a Ustawa o VAT',
                        ],
                        'p_19' => '1',
                    ],
                ],
            ],
        ],
    ];
}
