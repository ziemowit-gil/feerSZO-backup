<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\DTOs\Requests\Sessions\FakturaRR;

final class FakturaSprzedazyTowaruRolniczegoFixture extends AbstractFakturaFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'naglowek' => [
            'wariantFormularza' => 'FA_RR (1)',
            'systemInfo' => 'KSEF-PHP-Client'
        ],
        'podmiot1' => [
            'daneIdentyfikacyjne' => [
                'nip' => '1111111111',
                'nazwa' => 'Rolinik Dostawca'
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => '30-549 Stara Wieś',
            ]
        ],
        'podmiot2' => [
            'daneIdentyfikacyjne' => [
                'nip' => '9999999999',
                'nazwa' => 'Rolnik Nabywca'
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => 'Ulica 1/2, 11-111 Nowa Wieś',
            ]
        ],
        'fakturaRR' => [
            'kodWaluty' => 'PLN',
            'p_4A' => '2026-02-19',
            'p_4B' => '2026-02-19',
            'p_4C' => '1/02/2026',
            'p_11_1Group' => [
                'p_11_1' => '63.25',
                'p_11_2' => '4.14'
            ],
            'p_12_1Group' => [
                'p_12_1' => '67.39',
                'p_12_2' => 'sześćdziesiąt siedem złotych trzydzieści dziewięć groszy',
            ],
            'rodzajFaktury' => 'VAT_RR',
            'fakturaRRWiersz' => [
                [
                    'nrWierszaFa' => 1,
                    'p_5' => 'kartofle',
                    'p_6A' => 'kg',
                    'p_6B' => '10.50',
                    'p_6C' => 'wersja deluxe',
                    'p_7' => '5.50',
                    'p_8' => '57.75',
                    'p_9' => '6.5',
                    'p_10' => '3.75',
                    'p_11' => '61.50'
                ],
                [
                    'nrWierszaFa' => 2,
                    'p_5' => 'marchew',
                    'p_6A' => 'kg',
                    'p_6B' => '1',
                    'p_6C' => 'wersja deluxe',
                    'p_7' => '5.50',
                    'p_8' => '5.50',
                    'p_9' => '7',
                    'p_10' => '0.39',
                    'p_11' => '5.89'
                ],
            ],
            'platnosc' => [
                'platnoscGroup' => [
                    'formaPlatnosci' => '1'
                ],
                'rachunekBankowy1' => [
                    [
                        'nrRBGroup' => [
                            'nrRB' => '11111111111111111111111111',
                        ],
                        'nazwaBanku' => 'Bank 1'
                    ],
                ],
                'rachunekBankowy2' => [
                    [
                        'nrRBGroup' => [
                            'nrRB' => '99999999999999999999999999',
                        ],
                        'nazwaBanku' => 'Bank 2'
                    ],
                ],
                'ipksef' => '123A1b2C3d4E5',
                'linkDoPlatnosci' => 'https://sub.example.com/path/to/pay?foo=bar&IPKSeF=123A1b2C3d4E5&x=1'
            ],
        ],
        'stopka' => [
            'informacje' => [
                [
                    'stopkaFaktury' => 'Super Rolnik 2000'
                ]
            ],
            'rejestry' => [
                [
                    'krs' => '0000099999',
                    'regon' => '999999999',
                    'bdo' => '000099999'
                ]
            ]
        ]
    ];
}
