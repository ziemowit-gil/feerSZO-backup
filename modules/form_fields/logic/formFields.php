<?php
/**
 * modules/form_fields/logic/formFields.php — Wymagalność pól w formularzach umów.
 *
 * Jedno źródło prawdy dla admin/form_fields.php (konfiguracja) i formularzy umów
 * (ostrzeżenia). Poziom pola: required | recommended | optional, zapisywany w
 * settings pod kluczem form_field_{typ}_{pole}. Formularz NIGDY nie blokuje zapisu —
 * „wymagane" daje czerwone ostrzeżenie i prośbę o potwierdzenie, „zalecane" żółte
 * ostrzeżenie przy zmianie zakładki.
 *
 * Domyślny poziom, gdy administrator nic nie ustawił: wolontariat = recommended
 * (zgodność z dotychczasowym zachowaniem contracts/wolontariat/add.php i wizard.php),
 * pozostałe typy = optional (brak zmian w działaniu formularzy do czasu konfiguracji).
 * Kolumna „suggested" w rejestrze to propozycja, którą admin może zastosować jednym kliknięciem.
 *
 * Użycie w formularzu (add.php / edit.php typu z komponentem Alpine tabbedContractForm):
 *   require_once dirname(dirname(__DIR__)) . '/modules/form_fields/logic/formFields.php';
 *   formFieldsAttach('zlecenie');   // tuż przed include footer.php
 */

function formFieldsLevelsMeta(): array {
    return [
        'required'    => ['label' => 'Wymagane',   'short' => 'W', 'badge' => 'danger',    'icon' => 'bi-asterisk',    'color' => '#dc2626'],
        'recommended' => ['label' => 'Zalecane',   'short' => 'Z', 'badge' => 'warning',   'icon' => 'bi-exclamation', 'color' => '#d97706'],
        'optional'    => ['label' => 'Opcjonalne', 'short' => 'O', 'badge' => 'secondary', 'icon' => 'bi-dash',        'color' => '#6b7280'],
    ];
}

/**
 * Rejestr typów formularzy: label, icon, color, default (poziom domyślny), forms (gdzie działa),
 * steps => [nazwa grupy => [klucz pola => etykieta]], suggested => [klucz => poziom].
 * Klucz pola = atrybut name w formularzu (contracts/{typ}/add.php, edit.php).
 */
function formFieldsRegistry(): array {
    static $reg = null;
    if ($reg !== null) return $reg;

    $podpis = [
        'forma_podpisania' => 'Forma podpisania',
        'plik_umowy'       => 'Plik umowy',
    ];

    $reg = [
        'wolontariat' => [
            'label' => 'Wolontariat', 'icon' => 'bi-heart', 'color' => '#16a34a', 'default' => 'recommended',
            'forms' => ['contracts/wolontariat/add.php', 'contracts/wolontariat/wizard.php'],
            'steps' => [
                'Wolontariusz' => [
                    'imie_nazwisko'  => 'Imię i nazwisko',
                    'email'          => 'E-mail',
                    'pesel'          => 'PESEL',
                    'data_urodzenia' => 'Data urodzenia',
                    'telefon'        => 'Telefon',
                    'adres'          => 'Adres zamieszkania',
                ],
                'Porozumienie' => [
                    'numer_umowy'            => 'Numer umowy',
                    'data_zawarcia'          => 'Data zawarcia',
                    'data_rozpoczecia'       => 'Data rozpoczęcia',
                    'data_zakonczenia'       => 'Data zakończenia',
                    'opiekun'                => 'Opiekun wolontariusza',
                    'miejsce_wolontariatu'   => 'Miejsce wolontariatu',
                    'przedmiot_porozumienia' => 'Przedmiot porozumienia',
                    'projekt_program'        => 'Projekt / program',
                ],
                'Microsoft 365 i dokumenty' => [
                    'm365_security_group_id' => 'Security Group M365',
                    'plik_umowy'             => 'Plik porozumienia',
                ],
            ],
            'suggested' => ['imie_nazwisko' => 'required', 'email' => 'required', 'pesel' => 'required', 'numer_umowy' => 'required',
                            'data_zawarcia' => 'required', 'data_rozpoczecia' => 'required', 'przedmiot_porozumienia' => 'required',
                            'telefon' => 'recommended', 'adres' => 'recommended', 'data_urodzenia' => 'recommended', 'opiekun' => 'recommended',
                            'miejsce_wolontariatu' => 'recommended', 'plik_umowy' => 'recommended', 'data_zakonczenia' => 'optional',
                            'projekt_program' => 'optional', 'm365_security_group_id' => 'optional'],
        ],

        'zlecenie' => [
            'label' => 'Umowa zlecenia', 'icon' => 'bi-person-lines-fill', 'color' => '#2563eb', 'default' => 'optional',
            'forms' => ['contracts/zlecenie/add.php', 'contracts/zlecenie/edit.php'],
            'steps' => [
                'Umowa' => [
                    'numer_umowy'          => 'Numer umowy',
                    'nr_rejestru'          => 'Nr rejestru (RU)',
                    'data_zawarcia'        => 'Data zawarcia',
                    'data_rozpoczecia'     => 'Data rozpoczęcia',
                    'data_zakonczenia'     => 'Data zakończenia',
                    'opiekun'              => 'Opiekun umowy',
                    'przedmiot_zlecenia'   => 'Przedmiot zlecenia',
                    'numer_projektu'       => 'Numer projektu / źródło finansowania',
                    'org_unit_id'          => 'Komórka organizacyjna',
                ],
                'Zleceniobiorca' => [
                    'imie_nazwisko'    => 'Imię i nazwisko',
                    'pesel'            => 'PESEL',
                    'seria_nr_dowodu'  => 'Seria i nr dowodu',
                    'email'            => 'E-mail',
                    'urzad_skarbowy'   => 'Urząd skarbowy',
                    'rachunek_bankowy' => 'Rachunek bankowy',
                    'm365_login'       => 'E-mail do logowania w panelu',
                ],
                'Wynagrodzenie i ZUS' => [
                    'wynagrodzenie_brutto'    => 'Wynagrodzenie brutto',
                    'typ_stawki'              => 'Typ stawki',
                    'stawka_kwota'            => 'Kwota stawki',
                    'liczba_godzin_planowana' => 'Liczba godzin (planowana)',
                    'sposob_rozliczenia'      => 'Sposób rozliczenia',
                    'termin_platnosci'        => 'Termin płatności',
                    'tytul_ubezpieczenia'     => 'Tytuł ubezpieczenia ZUS',
                    'zus_data_rejestracji'    => 'Data zgłoszenia do ZUS',
                    'kup'                     => 'Koszty uzyskania przychodu',
                ],
                'Podpis i dokumenty' => $podpis,
            ],
            'suggested' => ['numer_umowy' => 'required', 'data_zawarcia' => 'required', 'data_rozpoczecia' => 'required', 'przedmiot_zlecenia' => 'required',
                            'imie_nazwisko' => 'required', 'pesel' => 'required', 'wynagrodzenie_brutto' => 'required',
                            'data_zakonczenia' => 'recommended', 'opiekun' => 'recommended', 'email' => 'recommended', 'rachunek_bankowy' => 'recommended',
                            'urzad_skarbowy' => 'recommended', 'tytul_ubezpieczenia' => 'recommended', 'sposob_rozliczenia' => 'recommended',
                            'plik_umowy' => 'recommended', 'forma_podpisania' => 'recommended'],
        ],

        'dzielo' => [
            'label' => 'Umowa o dzieło', 'icon' => 'bi-brush', 'color' => '#7c3aed', 'default' => 'optional',
            'forms' => ['contracts/dzielo/add.php', 'contracts/dzielo/edit.php'],
            'steps' => [
                'Umowa' => [
                    'numer_umowy'    => 'Numer umowy',
                    'nr_rejestru'    => 'Nr rejestru (RU)',
                    'data_zawarcia'  => 'Data zawarcia',
                    'opiekun'        => 'Opiekun umowy',
                    'numer_projektu' => 'Numer projektu / źródło finansowania',
                    'org_unit_id'    => 'Komórka organizacyjna',
                ],
                'Wykonawca' => [
                    'imie_nazwisko'    => 'Imię i nazwisko',
                    'pesel'            => 'PESEL',
                    'email'            => 'E-mail',
                    'urzad_skarbowy'   => 'Urząd skarbowy',
                    'rachunek_bankowy' => 'Rachunek bankowy',
                    'm365_login'       => 'E-mail do logowania w panelu',
                ],
                'Dzieło i wynagrodzenie' => [
                    'opis_dziela'          => 'Opis dzieła',
                    'termin_oddania'       => 'Termin oddania',
                    'wynagrodzenie_brutto' => 'Wynagrodzenie brutto',
                    'zakres_praw'          => 'Zakres praw autorskich',
                    'data_odbioru'         => 'Data odbioru',
                ],
                'Podpis i dokumenty' => $podpis,
            ],
            'suggested' => ['numer_umowy' => 'required', 'data_zawarcia' => 'required', 'imie_nazwisko' => 'required', 'pesel' => 'required',
                            'opis_dziela' => 'required', 'termin_oddania' => 'required', 'wynagrodzenie_brutto' => 'required',
                            'email' => 'recommended', 'opiekun' => 'recommended', 'rachunek_bankowy' => 'recommended', 'urzad_skarbowy' => 'recommended',
                            'zakres_praw' => 'recommended', 'plik_umowy' => 'recommended', 'forma_podpisania' => 'recommended'],
        ],

        'praca' => [
            'label' => 'Umowa o pracę', 'icon' => 'bi-briefcase', 'color' => '#0f766e', 'default' => 'optional',
            'forms' => ['contracts/praca/add.php', 'contracts/praca/edit.php'],
            'steps' => [
                'Umowa' => [
                    'numer_umowy'      => 'Numer umowy',
                    'nr_rejestru'      => 'Nr rejestru (RU)',
                    'rodzaj_umowy'     => 'Rodzaj umowy',
                    'data_zawarcia'    => 'Data zawarcia',
                    'data_rozpoczecia' => 'Data rozpoczęcia',
                    'data_zakonczenia' => 'Data zakończenia',
                    'org_unit_id'      => 'Komórka organizacyjna',
                ],
                'Pracownik' => [
                    'imie_nazwisko'    => 'Imię i nazwisko',
                    'pesel'            => 'PESEL',
                    'seria_nr_dowodu'  => 'Seria i nr dowodu',
                    'adres'            => 'Adres zamieszkania',
                    'email'            => 'E-mail',
                    'urzad_skarbowy'   => 'Urząd skarbowy',
                    'rachunek_bankowy' => 'Rachunek bankowy',
                    'email_login'      => 'E-mail do logowania w panelu',
                ],
                'Stanowisko i warunki' => [
                    'stanowisko'              => 'Stanowisko',
                    'dzial_projekt'           => 'Dział / projekt',
                    'wymiar_etatu'            => 'Wymiar etatu',
                    'opiekun_przelozony'      => 'Opiekun / przełożony',
                    'wynagrodzenie_brutto'    => 'Wynagrodzenie brutto',
                    'skladniki_wynagrodzenia' => 'Składniki wynagrodzenia',
                    'okres_wypowiedzenia'     => 'Okres wypowiedzenia',
                    'urlop_wymiar'            => 'Wymiar urlopu',
                    'badania_data_waznosci'   => 'Badania lekarskie — ważne do',
                    'bhp_data_waznosci'       => 'Szkolenie BHP — ważne do',
                ],
                'Podpis i dokumenty' => $podpis,
            ],
            'suggested' => ['numer_umowy' => 'required', 'rodzaj_umowy' => 'required', 'data_zawarcia' => 'required', 'data_rozpoczecia' => 'required',
                            'imie_nazwisko' => 'required', 'pesel' => 'required', 'adres' => 'required', 'stanowisko' => 'required',
                            'wymiar_etatu' => 'required', 'wynagrodzenie_brutto' => 'required',
                            'email' => 'recommended', 'rachunek_bankowy' => 'recommended', 'urzad_skarbowy' => 'recommended', 'opiekun_przelozony' => 'recommended',
                            'badania_data_waznosci' => 'recommended', 'bhp_data_waznosci' => 'recommended', 'plik_umowy' => 'recommended', 'forma_podpisania' => 'recommended'],
        ],

        'uslugi' => [
            'label' => 'Umowa o usługi', 'icon' => 'bi-tools', 'color' => '#ea580c', 'default' => 'optional',
            'forms' => ['contracts/uslugi/add.php', 'contracts/uslugi/edit.php'],
            'steps' => [
                'Umowa' => [
                    'numer_umowy'         => 'Numer umowy',
                    'nr_rejestru'         => 'Nr rejestru (RU)',
                    'data_zawarcia'       => 'Data zawarcia',
                    'data_rozpoczecia'    => 'Data rozpoczęcia',
                    'data_zakonczenia'    => 'Data zakończenia',
                    'okres_wypowiedzenia' => 'Okres wypowiedzenia',
                    'opiekun'             => 'Opiekun umowy',
                    'numer_projektu'      => 'Numer projektu / źródło finansowania',
                    'przedmiot_uslugi'    => 'Przedmiot usługi',
                    'zakres_uslug'        => 'Zakres usług',
                ],
                'Wykonawca' => [
                    'nazwa_wykonawcy'      => 'Nazwa wykonawcy',
                    'nip_pesel'            => 'NIP / PESEL',
                    'adres'                => 'Adres',
                    'email'                => 'E-mail',
                    'telefon'              => 'Telefon kontaktowy',
                    'rachunek_lub_faktura' => 'Rachunek bankowy / dane do faktury',
                ],
                'Finanse i rozliczenie' => [
                    'wartosc_netto'         => 'Wartość netto',
                    'stawka_vat'            => 'VAT (%)',
                    'wartosc_brutto'        => 'Wartość brutto',
                    'harmonogram_platnosci' => 'Harmonogram płatności',
                    'termin_platnosci_dni'  => 'Termin płatności (dni)',
                    'data_odbioru'          => 'Data odbioru',
                ],
                'Podpis i dokumenty' => $podpis,
            ],
            'suggested' => ['numer_umowy' => 'required', 'data_zawarcia' => 'required', 'przedmiot_uslugi' => 'required', 'nazwa_wykonawcy' => 'required',
                            'nip_pesel' => 'required', 'wartosc_brutto' => 'required',
                            'data_rozpoczecia' => 'recommended', 'data_zakonczenia' => 'recommended', 'opiekun' => 'recommended', 'email' => 'recommended',
                            'zakres_uslug' => 'recommended', 'wartosc_netto' => 'recommended', 'harmonogram_platnosci' => 'recommended',
                            'plik_umowy' => 'recommended', 'forma_podpisania' => 'recommended'],
        ],

        'inne' => [
            'label' => 'Inne umowy', 'icon' => 'bi-file-earmark-text', 'color' => '#64748b', 'default' => 'optional',
            'forms' => ['contracts/inne/add.php', 'contracts/inne/edit.php'],
            'steps' => [
                'Umowa' => [
                    'numer_umowy'         => 'Numer umowy',
                    'nr_rejestru'         => 'Nr rejestru (RU)',
                    'typ_umowy'           => 'Typ umowy',
                    'przedmiot_umowy'     => 'Przedmiot umowy',
                    'data_zawarcia'       => 'Data zawarcia',
                    'data_rozpoczecia'    => 'Data rozpoczęcia',
                    'data_zakonczenia'    => 'Data zakończenia',
                    'okres_wypowiedzenia' => 'Okres wypowiedzenia',
                    'opiekun'             => 'Opiekun umowy',
                    'numer_projektu'      => 'Numer projektu / źródło finansowania',
                ],
                'Strona umowy' => [
                    'strona_umowy'  => 'Nazwa / strona umowy',
                    'pesel_nip_krs' => 'PESEL / NIP / KRS',
                    'adres'         => 'Adres',
                    'email'         => 'E-mail',
                ],
                'Finanse i przegląd' => [
                    'wartosc_umowy'      => 'Wartość umowy',
                    'waluta'             => 'Waluta',
                    'warunki_finansowe'  => 'Warunki finansowe / opis płatności',
                    'data_przegladu'     => 'Data przeglądu / odnowienia',
                ],
                'Podpis i dokumenty' => $podpis,
            ],
            'suggested' => ['numer_umowy' => 'required', 'typ_umowy' => 'required', 'przedmiot_umowy' => 'required', 'data_zawarcia' => 'required',
                            'strona_umowy' => 'required',
                            'pesel_nip_krs' => 'recommended', 'data_rozpoczecia' => 'recommended', 'opiekun' => 'recommended', 'email' => 'recommended',
                            'wartosc_umowy' => 'recommended', 'plik_umowy' => 'recommended', 'forma_podpisania' => 'recommended'],
        ],

        'powierzenie' => [
            'label' => 'Umowa powierzenia', 'icon' => 'bi-bank', 'color' => '#b45309', 'default' => 'optional',
            'forms' => ['contracts/powierzenie/add.php', 'contracts/powierzenie/edit.php'],
            'steps' => [
                'Umowa i zadanie' => [
                    'numer_umowy'     => 'Numer umowy',
                    'nr_rejestru'     => 'Nr rejestru (RU)',
                    'forma_zlecenia'  => 'Forma zlecenia',
                    'tryb_zlecenia'   => 'Tryb zlecenia',
                    'opiekun'         => 'Opiekun umowy',
                    'numer_projektu'  => 'Numer projektu / źródło finansowania',
                    'nazwa_zadania'   => 'Nazwa zadania publicznego',
                    'sfera_zadania'   => 'Sfera pożytku publicznego',
                    'nazwa_konkursu'  => 'Nr / nazwa konkursu',
                    'zakres_rzeczowy' => 'Zakres rzeczowy zadania',
                    'rezultaty'       => 'Zakładane rezultaty',
                ],
                'Organ zlecający' => [
                    'organ_zlecajacy'     => 'Organ zlecający',
                    'organ_reprezentacja' => 'Reprezentowany przez',
                    'organ_adres'         => 'Adres organu',
                    'email'               => 'E-mail kontaktowy',
                ],
                'Finanse dotacji' => [
                    'kwota_dotacji'        => 'Kwota dotacji',
                    'rachunek_dotacji'     => 'Wyodrębniony rachunek dotacji',
                    'wklad_wlasny'         => 'Wkład własny finansowy',
                    'wklad_osobowy'        => 'Wkład osobowy / rzeczowy',
                    'calkowity_koszt'      => 'Całkowity koszt zadania',
                    'transze'              => 'Harmonogram / transze płatności',
                    'koszty_kwalifikowane' => 'Koszty kwalifikowane / kosztorys',
                ],
                'Terminy' => [
                    'data_zawarcia'        => 'Data zawarcia',
                    'data_rozpoczecia'     => 'Realizacja od',
                    'data_zakonczenia'     => 'Realizacja do',
                    'termin_wykorzystania' => 'Termin wykorzystania dotacji',
                    'termin_sprawozdania'  => 'Termin sprawozdania końcowego',
                ],
                'Podpis i dokumenty' => $podpis,
            ],
            'suggested' => ['numer_umowy' => 'required', 'nazwa_zadania' => 'required', 'organ_zlecajacy' => 'required', 'kwota_dotacji' => 'required',
                            'data_zawarcia' => 'required', 'data_rozpoczecia' => 'required', 'data_zakonczenia' => 'required',
                            'forma_zlecenia' => 'recommended', 'tryb_zlecenia' => 'recommended', 'opiekun' => 'recommended', 'rachunek_dotacji' => 'recommended',
                            'calkowity_koszt' => 'recommended', 'termin_sprawozdania' => 'recommended', 'termin_wykorzystania' => 'recommended',
                            'plik_umowy' => 'recommended', 'forma_podpisania' => 'recommended'],
        ],
    ];
    return $reg;
}

/** Wszystkie klucze pól danego typu (płasko). */
function formFieldsKeys(string $type): array {
    $reg = formFieldsRegistry();
    if (!isset($reg[$type])) return [];
    $keys = [];
    foreach ($reg[$type]['steps'] as $fields) foreach ($fields as $k => $_) $keys[] = $k;
    return array_values(array_unique($keys));
}

/** Etykiety pól danego typu: klucz => etykieta. */
function formFieldsLabels(string $type): array {
    $reg = formFieldsRegistry();
    $out = [];
    foreach (($reg[$type]['steps'] ?? []) as $fields) foreach ($fields as $k => $l) $out[$k] = $l;
    return $out;
}

/** Poziomy pól danego typu z ustawień (z domyślnym poziomem typu). */
function formFieldsLevels(string $type): array {
    $reg = formFieldsRegistry();
    if (!isset($reg[$type])) return [];
    $def = $reg[$type]['default'] ?? 'optional';
    $out = [];
    foreach (formFieldsKeys($type) as $k) {
        $v = org_setting('form_field_' . $type . '_' . $k) ?: $def;
        $out[$k] = in_array($v, ['required', 'recommended', 'optional'], true) ? $v : $def;
    }
    return $out;
}

/** Poziom pojedynczego pola. */
function formFieldsLevel(string $type, string $field): string {
    return formFieldsLevels($type)[$field] ?? 'optional';
}

/** Zapis poziomu pola (settings). */
function formFieldsSave(string $type, string $field, string $level): void {
    if (!in_array($level, ['required', 'recommended', 'optional'], true)) $level = 'optional';
    $skey = 'form_field_' . $type . '_' . $field;
    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$skey]);
    if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$level, $skey]);
    else         db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$skey, $level]);
}

/**
 * Podpięcie ostrzeżeń do formularza umowy (komponent Alpine tabbedContractForm, panele [data-tab-pane]).
 * Wypisuje <style> + <script>. Pomija typy bez pól innych niż „opcjonalne" (zero narzutu).
 *
 * Zachowanie:
 *  - przy starcie: oznaczenie etykiet (czerwona gwiazdka = wymagane, żółta kropka = zalecane),
 *  - przy opuszczeniu zakładki: dymek z listą pustych pól (nie blokuje),
 *  - przy zapisie: puste pola wymagane → dymek z „Uzupełnij" / „Zapisz mimo to" (zapis możliwy),
 *    przyciski z formnovalidate (np. „Zapisz roboczo") pomijane.
 */
function formFieldsAttach(string $type, string $formSelector = 'form[x-data^="tabbedContractForm"]'): void {
    $levels = formFieldsLevels($type);
    $active = array_filter($levels, fn($l) => $l !== 'optional');
    if (!$active) return;
    $labels = array_intersect_key(formFieldsLabels($type), $active);
    $cfg = json_encode(['levels' => $active, 'labels' => $labels, 'form' => $formSelector], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>
<style>
.ff-mark-req{color:#dc2626;font-weight:700;margin-left:.2rem}
.ff-mark-rec{display:inline-block;width:.5rem;height:.5rem;border-radius:50%;background:#f59e0b;margin-left:.35rem;vertical-align:middle}
.ff-toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);z-index:9999;background:#fff;border:1px solid #fde68a;border-radius:.75rem;box-shadow:0 8px 32px rgba(0,0,0,.18);padding:1rem 1.25rem;min-width:320px;max-width:520px;font-size:.85rem;font-family:inherit;color:#111827}
.ff-toast.ff-toast--req{border-color:#fca5a5}
.ff-toast .ff-hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;font-weight:600;font-size:.9rem}
.ff-toast .ff-x{border:0;background:transparent;font-size:1rem;line-height:1;cursor:pointer;color:#6b7280;padding:0 .2rem}
.ff-toast .ff-row{display:flex;gap:.5rem;align-items:flex-start;padding:.15rem 0}
.ff-toast .ff-row i{flex-shrink:0;margin-top:.15rem}
.ff-toast .ff-ft{margin-top:.6rem;padding-top:.6rem;border-top:1px solid #e5e7eb;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;font-size:.78rem;color:#6b7280}
.ff-toast .ff-btn{border:1px solid #d1d5db;background:#fff;color:#111827;font:inherit;font-size:.78rem;font-weight:600;padding:.3rem .7rem;border-radius:.4rem;cursor:pointer}
.ff-toast .ff-btn--pri{background:#2563eb;border-color:#2563eb;color:#fff}
.ff-toast .ff-btn:hover{filter:brightness(.95)}
</style>
<script>
(function () {
  var CFG = <?= $cfg ?>;
  var form = null, bypass = false;

  function fieldEl(name) { return form.querySelector('[name="' + name + '"]'); }
  function isEmpty(el) {
    if (!el) return false;                         // pola nieobecnego w tym formularzu nie oceniamy
    if (el.type === 'file') return !(el.files && el.files.length) && !el.dataset.ffHasFile;
    if (el.tagName === 'SELECT') return !el.value;
    if (el.type === 'checkbox' || el.type === 'radio') return !form.querySelector('[name="' + el.name + '"]:checked');
    return !String(el.value || '').trim();
  }
  function paneOf(el) { var p = el.closest('[data-tab-pane]'); return p ? parseInt(p.dataset.tabPane, 10) : null; }
  function missing(filter) {
    var req = [], rec = [];
    Object.keys(CFG.levels).forEach(function (name) {
      var el = fieldEl(name); if (!el) return;
      if (filter && !filter(el)) return;
      if (!isEmpty(el)) return;
      var item = { name: name, label: CFG.labels[name] || name, pane: paneOf(el) };
      (CFG.levels[name] === 'required' ? req : rec).push(item);
    });
    return { req: req, rec: rec };
  }
  function removeToast() { var t = document.getElementById('ff-toast'); if (t) t.remove(); }
  function showToast(m, opts) {
    removeToast();
    if (!m.req.length && !m.rec.length) return;
    opts = opts || {};
    var hasReq = m.req.length > 0;
    var html = '<div class="ff-hd"><span><i class="bi bi-clipboard-check"></i> ' + (hasReq ? 'Brakuje wymaganych pól' : 'Warto uzupełnić') + '</span>'
             + '<button type="button" class="ff-x" aria-label="Zamknij powiadomienie">&times;</button></div>';
    m.req.forEach(function (x) { html += '<div class="ff-row"><i class="bi bi-exclamation-circle-fill" style="color:#dc2626"></i><span><strong>' + x.label + '</strong> — pole wymagane</span></div>'; });
    m.rec.forEach(function (x) { html += '<div class="ff-row"><i class="bi bi-exclamation-triangle-fill" style="color:#d97706"></i><span>' + x.label + ' — zalecane do uzupełnienia</span></div>'; });
    html += '<div class="ff-ft">';
    if (opts.submit) {
      html += '<button type="button" class="ff-btn ff-btn--pri" data-ff="fix">Uzupełnij</button>'
            + '<button type="button" class="ff-btn" data-ff="save">Zapisz mimo to</button>'
            + '<span>Zapis nie jest blokowany — to tylko ostrzeżenie.</span>';
    } else {
      html += '<span>' + (hasReq ? 'Pola wymagane sprawdzimy jeszcze przy zapisie.' : 'Możesz kontynuować — dane uzupełnij później.') + '</span>';
    }
    html += '</div>';
    var t = document.createElement('div');
    t.id = 'ff-toast'; t.className = 'ff-toast' + (hasReq ? ' ff-toast--req' : ''); t.setAttribute('role', hasReq ? 'alert' : 'status');
    t.setAttribute('aria-label', hasReq ? 'Powiadomienie: brakuje wymaganych pól' : 'Powiadomienie: warto uzupełnić');
    t.innerHTML = html;
    t.querySelector('.ff-x').onclick = removeToast;
    if (opts.submit) {
      t.querySelector('[data-ff="fix"]').onclick = function () {
        removeToast();
        var first = m.req[0] || m.rec[0];
        if (first && first.pane && window.Alpine) { try { Alpine.$data(form).tab = first.pane; } catch (e) {} }
        setTimeout(function () { var el = fieldEl(first.name); if (el && el.focus) el.focus(); }, 60);
      };
      t.querySelector('[data-ff="save"]').onclick = function () {
        removeToast(); bypass = true;
        if (opts.submitter && form.requestSubmit) form.requestSubmit(opts.submitter);
        else if (form.requestSubmit) form.requestSubmit(); else form.submit();
      };
    }
    document.body.appendChild(t);
    if (!hasReq && !opts.submit) setTimeout(function () { if (t.parentNode) t.remove(); }, 6000);
  }
  function decorate() {
    Object.keys(CFG.levels).forEach(function (name) {
      var el = fieldEl(name); if (!el) return;
      var lab = (el.id && form.querySelector('label[for="' + el.id + '"]')) || null;
      if (!lab) { var wrap = el.closest('div'); lab = wrap ? wrap.querySelector('label') : null; }
      if (!lab || lab.querySelector('.ff-mark-req, .ff-mark-rec')) return;
      var m = document.createElement('span');
      if (CFG.levels[name] === 'required') { m.className = 'ff-mark-req'; m.textContent = '*'; m.title = 'Pole wymagane (konfiguracja: Admin → Wymagane pola)'; }
      else { m.className = 'ff-mark-rec'; m.title = 'Pole zalecane'; }
      lab.appendChild(m);
    });
  }
  function watchPanes() {
    var panes = form.querySelectorAll('[data-tab-pane]');
    panes.forEach(function (p) {
      var wasVisible = p.style.display !== 'none';
      new MutationObserver(function () {
        var visible = p.style.display !== 'none';
        if (wasVisible && !visible) {
          var n = parseInt(p.dataset.tabPane, 10);
          var m = missing(function (el) { return paneOf(el) === n; });
          if (m.req.length || m.rec.length) showToast(m);
        }
        wasVisible = visible;
      }).observe(p, { attributes: true, attributeFilter: ['style'] });
    });
  }
  function init() {
    form = document.querySelector(CFG.form); if (!form) return;
    decorate(); watchPanes();
    document.addEventListener('submit', function (e) {
      if (e.target !== form) return;
      if (bypass) { bypass = false; return; }
      if (e.submitter && e.submitter.formNoValidate) return;   // „Zapisz roboczo" itp.
      var m = missing(); if (!m.req.length) return;
      e.preventDefault(); e.stopImmediatePropagation();
      showToast(m, { submit: true, submitter: e.submitter || null });
    }, true);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
<?php
}
