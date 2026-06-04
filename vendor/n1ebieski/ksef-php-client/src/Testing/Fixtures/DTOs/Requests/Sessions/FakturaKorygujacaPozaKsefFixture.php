<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\DTOs\Requests\Sessions;

final class FakturaKorygujacaPozaKsefFixture extends AbstractFakturaFixture
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
                    'nip' => '1326578964',
                ],
                'nazwa' => 'Zrób to sam Sp. z o. o. ',
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => 'Druciana 43 lok. 23, 01-003 Warszawa',
            ],
        ],
        'fa' => [
            'kodWaluty' => 'PLN',
            'p_1' => '2025-11-10',
            'p_1M' => 'Warszawa',
            'p_2' => '1/FK/2025',
            'p_6Group' => [
                'p_6' => '2025-11-10',
            ],
            'p_13_1Group' => [
                'p_13_1' => '-10.0',
                'p_14_1' => '-2.3',
            ],
            'p_15' => '-12.3',
            'rodzajFaktury' => 'KOR',
            'korektaGroup' => [
                'przyczynaKorekty' => 'Uznany rabat.',
                'typKorekty' => '2',
                'daneFaKorygowanej' => [
                    [
                        'dataWystFaKorygowanej' => '2025-11-05',
                        'nrFaKorygowanej' => '10/FV/2025',
                        'nrKSeFGroup' => [
                            'nrKSeFN' => '1',
                        ],
                    ],
                ],
            ],
            'faWiersz' => [
                [
                    'nrWierszaFa' => 1,
                    'p_7' => 'Usługa informatyczna',
                    'p_8A' => 'szt.',
                    'p_8B' => 1.0,
                    'p_9A' => '90.0',
                    'p_11' => '90.0',
                    'p_12' => '23',
                ],
                [
                    'nrWierszaFa' => 1,
                    'p_7' => 'Usługa informatyczna',
                    'p_8A' => 'szt.',
                    'p_8B' => '1.0',
                    'p_9A' => '100.0',
                    'p_11' => '100.0',
                    'p_12' => '23',
                    'stanPrzed' => '1',
                ],
            ],
            'platnosc' => [
                'zaplataGroup' => [
                    'zaplacono' => '1',
                    'dataZaplaty' => '2022-01-27',
                ],
            ],
        ],
    ];
}
