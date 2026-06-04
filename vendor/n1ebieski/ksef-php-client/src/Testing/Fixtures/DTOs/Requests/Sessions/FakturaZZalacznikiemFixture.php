<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Testing\Fixtures\DTOs\Requests\Sessions;

final class FakturaZZalacznikiemFixture extends AbstractFakturaFixture
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'naglowek' => [
            'wariantFormularza' => 'FA (3)',
            'systemInfo' => 'KSEF-PHP-Client'
        ],
        'podmiot1' => [
            'daneIdentyfikacyjne' => [
                'nip' => '1111111111',
                'nazwa' => 'Testowa Firma'
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => 'ul. Kwiatowa 1 m. 2',
                'adresL2' => '00-001 Warszawa'
            ],
            'daneKontaktowe' => [
                [
                    'email' => 'abc@abc.pl',
                    'telefon' => '667444555'
                ]
            ]
        ],
        'podmiot2' => [
            'daneIdentyfikacyjne' => [
                'idGroup' => [
                    'nip' => '5123957531'
                ],
                'nazwa' => 'Gmina Bzdziszewo'
            ],
            'adres' => [
                'kodKraju' => 'PL',
                'adresL1' => 'Bzdziszewo 1',
                'adresL2' => '00-007 Bzdziszewo'
            ],
            'daneKontaktowe' => [
                [
                    'email' => 'bzdziszewo@tuwartoinwestowac.pl',
                    'telefon' => '555777999'
                ]
            ],
            'nrKlienta' => '99999999',
        ],
        'fa' => [
            'kodWaluty' => 'PLN',
            'p_1' => '2026-02-28',
            'p_1M' => 'Warszawa',
            'p_2' => 'FV2026/02/150',
            'p_6Group' => [
                'okresFa' => [
                    'p_6_Od' => '2026-01-01',
                    'p_6_Do' => '2026-02-28'
                ]
            ],
            'p_13_1Group' => [
                'p_13_1' => '43.60',
                'p_14_1' => '10.03',
            ],
            'p_15' => '53.63',
            'rodzajFaktury' => 'VAT',
            'dodatkowyOpis' => [
                [
                    'klucz' => 'Informacja o akcyzie',
                    'wartosc' => 'Od 20 kWh energii elektrycznej czynnej naliczono akcyzę w kwocie 0,10 zł'
                ]
            ],
            'faWiersz' => [
                [
                    'nrWierszaFa' => 1,
                    'p_7' => 'Razem za energię czynną',
                    'p_11' => '18.00',
                    'p_12' => '23'
                ],
                [
                    'nrWierszaFa' => 2,
                    'p_7' => 'Razem za usługi dystrybucji',
                    'p_11' => '25.60',
                    'p_12' => '23'
                ]
            ],
            'rozliczenie' => [
                'obciazenia' => [
                    [
                        'kwota' => '0.00',
                        'powod' => 'Odsetki'
                    ]
                ],
                'sumaObciazen' => '0.00',
                'odliczenia' => [
                    [
                        'kwota' => '0.00',
                        'powod' => 'Nadpłata'
                    ]
                ],
                'sumaOdliczen' => '0.00',
                'rozliczenieGroup' => [
                    'doGroup' => [
                        'doZaplaty' => '53.63'
                    ]
                ]
            ],
            'platnosc' => [
                'terminPlatnosci' => [
                    [
                        'termin' => '2026-03-15'
                    ]
                ],
                'platnoscGroup' => [
                    'formaPlatnosci' => '6'
                ],
                'rachunekBankowy' => [
                    [
                        'nrRBGroup' => [
                            'nrRB' => '73111111111111111111111111'
                        ],
                        'nazwaBanku' => 'Bank Bankowości Bankowej S. A.',
                        'opisRachunku' => 'PLN'
                    ]
                ]
            ]
        ],
        'stopka' => [
            'informacje' => [
                [
                    'stopkaFaktury' => 'Kapiał zakładowy 5 000 000'
                ]
            ],
            'rejestry' => [
                [
                    'krs' => '0000099999',
                    'regon' => '999999999',
                    'bdo' => '000099999'
                ]
            ]
        ],
        'zalacznik' => [
            'blokDanych' => [
                [
                    'metaDane' => [
                        [
                            'zKlucz' => 'Miejsce poboru energii',
                            'zWartosc' => 'ul. Polna 1, 00-001 Warszawa'
                        ],
                        [
                            'zKlucz' => 'Kod PPE',
                            'zWartosc' => '999999999999999999'
                        ],
                        [
                            'zKlucz' => 'Nr kontrahenta (odbiorcy)',
                            'zWartosc' => '99999999'
                        ],
                        [
                            'zKlucz' => 'Za okres od',
                            'zWartosc' => '2026-01-01'
                        ],
                        [
                            'zKlucz' => 'Za okres do',
                            'zWartosc' => '2026-02-28'
                        ],
                        [
                            'zKlucz' => 'Grupa taryfowa',
                            'zWartosc' => 'G11'
                        ],
                        [
                            'zKlucz' => 'Energia zużyta w roku 2025',
                            'zWartosc' => '999 kWh'
                        ],
                        [
                            'zKlucz' => 'Energia zużyta w analogicznym okresie poprzedniego roku kalendarzowego',
                            'zWartosc' => '118 kWh'
                        ],
                    ],
                    'tabela' => [
                        [
                            'opis' => 'Odczyty',
                            'tNaglowek' => [
                                'kol' => [
                                    [
                                        'typ' => 'txt',
                                        'nKom' => 'Licznik/Strefa'
                                    ],
                                    [
                                        'typ' => 'date',
                                        'nKom' => 'Data'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Wskazanie bieżące'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Wskazanie poprzednie'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Mnożna'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Ilość'
                                    ],
                                    [
                                        'typ' => 'txt',
                                        'nKom' => 'Sposób odczytu'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Straty'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Razem'
                                    ]
                                ]
                            ],
                            'wiersz' => [
                                [
                                    'wKom' => [
                                        'Licznik rozliczeniowy  energii czynnej nr 99999999'
                                    ]
                                ],
                                [
                                    'wKom' => [
                                        'całodobowa',
                                        '2026-02-28',
                                        '1020',
                                        '1000',
                                        '1',
                                        '20',
                                        'Fizyczny',
                                        '0',
                                        '20',
                                    ]
                                ]
                            ]
                        ],
                        [
                            'tMetaDane' => [
                                [
                                    'tKlucz' => 'Informacja o akcyzie',
                                    'tWartosc' => 'Od 20 kWh energii elektrycznej czynnej naliczono akcyzę w kwocie 0,10 zł'
                                ]
                            ],
                            'opis' => 'Rozliczenie - sprzedaż energii',
                            'tNaglowek' => [
                                'kol' => [
                                    [
                                        'typ' => 'txt',
                                        'nKom' => 'Opis/Strefa'
                                    ],
                                    [
                                        'typ' => 'txt',
                                        'nKom' => 'j. m.'
                                    ],
                                    [
                                        'typ' => 'date',
                                        'nKom' => 'Data'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Ilość'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Ilość m-cy'
                                    ],
                                    [
                                        'typ' => 'dec',
                                        'nKom' => 'Współczynniki'
                                    ],
                                    [
                                        'typ' => 'dec',
                                        'nKom' => 'Cena jedn. netto (zł)'
                                    ],
                                    [
                                        'typ' => 'dec',
                                        'nKom' => 'Należność netto (zł)'
                                    ],
                                    [
                                        'typ' => 'int',
                                        'nKom' => 'Stawka VAT(%)'
                                    ]
                                ]
                            ],
                            'wiersz' => [
                                [
                                    'wKom' => [
                                        'całodobowa',
                                        'kWh',
                                        '20',
                                        '0.9000',
                                        '18.00',
                                        '23',
                                    ]
                                ]
                            ],
                            'suma' => [
                                'sKom' => [
                                    'Ogółem wartość - sprzedaż energii:',
                                    '-',
                                    '-',
                                    '-',
                                    '18.00',
                                    '-',
                                ]
                            ]
                        ],
                        [
                            'opis' => 'Rozliczenie - usługa dystrybucji energii',
                            'tNaglowek' => [
                                'kol' => [
                                    [
                                        'typ'  => 'txt',
                                        'nKom' => 'Opis/Strefa',
                                    ],
                                    [
                                        'typ'  => 'txt',
                                        'nKom' => 'j. m.',
                                    ],
                                    [
                                        'typ'  => 'date',
                                        'nKom' => 'Data',
                                    ],
                                    [
                                        'typ'  => 'int',
                                        'nKom' => 'Ilość',
                                    ],
                                    [
                                        'typ'  => 'int',
                                        'nKom' => 'Ilość m-cy',
                                    ],
                                    [
                                        'typ'  => 'dec',
                                        'nKom' => 'Współczynniki',
                                    ],
                                    [
                                        'typ'  => 'dec',
                                        'nKom' => 'Cena jedn. netto (zł)',
                                    ],
                                    [
                                        'typ'  => 'dec',
                                        'nKom' => 'Należność netto (zł)',
                                    ],
                                    [
                                        'typ'  => 'int',
                                        'nKom' => 'Stawka VAT(%)',
                                    ],
                                ],
                            ],
                            'wiersz' => [
                                [
                                    'wKom' => [
                                        'Opłata stała sieciowa - układ 1-fazowy',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        '-',
                                        'zł/mc',
                                        '2026-02-28',
                                        '-',
                                        '2',
                                        '0.0000',
                                        '7.3000',
                                        '14.60',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata przejściowa <500 kWh',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        '-',
                                        'zł/mc',
                                        '2026-02-28',
                                        '-',
                                        '2',
                                        '0.0000',
                                        '0.0300',
                                        '0.06',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata mocowa <500 kWh',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        '-',
                                        'zł/mc',
                                        '2026-02-28',
                                        '-',
                                        '2',
                                        '0.0000',
                                        '0.0000',
                                        '0.00',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata zmienna sieciowa',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'całodobowa',
                                        'kWh',
                                        '2026-02-28',
                                        '20',
                                        '-',
                                        '-',
                                        '0.2500',
                                        '5.00',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata jakościowa',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'całodobowa',
                                        'kWh',
                                        '2026-02-28',
                                        '20',
                                        '-',
                                        '-',
                                        '0.0400',
                                        '0.80',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata OZE',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'całodobowa',
                                        'kWh',
                                        '2026-02-28',
                                        '20',
                                        '-',
                                        '-',
                                        '0.0040',
                                        '0.08',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata kogeneracyjna',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'całodobowa',
                                        'kWh',
                                        '2026-02-28',
                                        '20',
                                        '-',
                                        '-',
                                        '0.0030',
                                        '0.06',
                                        '23',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        'Opłata abonamentowa',
                                    ],
                                ],
                                [
                                    'wKom' => [
                                        '-',
                                        'zł/mc',
                                        '2026-02-28',
                                        '-',
                                        '2',
                                        '0.0000',
                                        '2.5000',
                                        '5.00',
                                        '23',
                                    ],
                                ],
                            ],
                            'suma' => [
                                'sKom' => [
                                    'Ogółem wartość - usługa dystrybucji:',
                                    '-',
                                    '-',
                                    '-',
                                    '-',
                                    '-',
                                    '-',
                                    '25.60',
                                    '-',
                                ],
                            ],
                        ],
                    ],
                ]
            ]
        ]
    ];
}
