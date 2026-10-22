<?php
/**
 * modules/noclegi/lib/noclegiDict.php — słowniki modułu Noclegi w Słowniku Centralnym (modules/dictionary).
 *
 * Kody słowników: nocl_status (systemowy — logika przejść), nocl_role_modulu (systemowy), nocl_status_faktury (systemowy),
 * nocl_rola_osoby, nocl_typ_pokoju, nocl_typ_obiektu, nocl_preferencje, nocl_kategoria_kosztu (biznesowe — edytowalne).
 * Stałe NOCL_* poniżej to zasiew i zapas (gdy słownika jeszcze nie ma).
 */
require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';

const NOCL_STATUSY = [
    'wniosek'      => ['Wniosek',      'text-bg-secondary'],
    'w_trakcie'    => ['W trakcie',    'text-bg-info'],
    'potwierdzone' => ['Potwierdzone', 'text-bg-primary'],
    'zrealizowane' => ['Zrealizowane', 'text-bg-success'],
    'anulowane'    => ['Anulowane',    'text-bg-dark'],
];
/** Statusy, w których osoba zajmuje miejsce w pokoju. */
const NOCL_STATUSY_ZAJETE = ['w_trakcie', 'potwierdzone', 'zrealizowane'];
/** Statusy wliczane do kosztów budżetu. */
const NOCL_STATUSY_KOSZT = ['w_trakcie', 'potwierdzone', 'zrealizowane'];

const NOCL_ROLE_MODULU = [
    'uzytkownik'  => 'Użytkownik',
    'koordynator' => 'Koordynator Projektu',
    'logistyk'    => 'Logistyk / Administrator',
];
const NOCL_ROLA_OSOBY = ['dydaktyk' => 'Dydaktyk', 'wolontariusz' => 'Wolontariusz', 'czlonek_fundacji' => 'Członek Fundacji', 'pracownik' => 'Pracownik', 'gosc' => 'Gość / inna osoba'];
const NOCL_TYP_POKOJU = ['jednoosobowy' => 'Pokój jednoosobowy', 'dwuosobowy' => 'Pokój dwuosobowy', 'trzyosobowy' => 'Pokój trzyosobowy', 'wieloosobowy' => 'Pokój wieloosobowy (4+)', 'apartament' => 'Apartament'];
const NOCL_TYP_OBIEKTU = ['hotel' => 'Hotel', 'pensjonat' => 'Pensjonat', 'hostel' => 'Hostel', 'kwatera' => 'Kwatera prywatna', 'mieszkanie' => 'Mieszkanie / apartament', 'osrodek' => 'Ośrodek szkoleniowy'];
const NOCL_PREFERENCJE = ['cisza' => 'Cicho, z dala od ulicy', 'parter' => 'Parter / bez schodów', 'winda' => 'Obiekt z windą', 'sniadanie' => 'Śniadanie w cenie', 'blisko' => 'Blisko miejsca szkolenia', 'zwierze' => 'Pies przewodnik / zwierzę', 'osobna_lazienka' => 'Własna łazienka', 'kuchnia' => 'Dostęp do kuchni'];
const NOCL_KATEGORIA_KOSZTU = ['nocleg' => 'Nocleg', 'sniadanie' => 'Śniadania', 'parking' => 'Parking', 'klimatyczna' => 'Opłata klimatyczna', 'inne' => 'Inne'];
const NOCL_STATUS_FAKTURY = [
    'oczekuje'   => ['Oczekuje na fakturę', 'text-bg-secondary'],
    'otrzymana'  => ['Otrzymana',           'text-bg-info'],
    'zatwierdzona' => ['Zatwierdzona',      'text-bg-primary'],
    'zaplacona'  => ['Zapłacona',           'text-bg-success'],
    'odrzucona'  => ['Odrzucona',           'text-bg-danger'],
];

function noclDictSeed(): string {
    $mk = function (string $code, string $name, string $kind, array $items, string $desc, int $sort, array $attrs = []): array {
        $e = []; $i = 0;
        foreach ($items as $c => $label) {
            $a = $attrs[$c] ?? [];
            $e[] = ['code' => $c, 'label' => $label, 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true, 'description' => null, 'attributes' => $a];
        }
        return ['code' => $code, 'name' => $name, 'kind' => $kind, 'parent_code' => null, 'is_protected' => false, 'sort_order' => $sort, 'description' => $desc, 'entries' => $e];
    };
    $st = []; foreach (NOCL_STATUSY as $c => $d) $st[$c] = $d[0];
    $stAttr = []; foreach (NOCL_STATUSY as $c => $d) $stAttr[$c] = ['badge' => $d[1], 'final' => in_array($c, ['zrealizowane', 'anulowane'], true)];
    $fk = []; $fkAttr = [];
    foreach (NOCL_STATUS_FAKTURY as $c => $d) { $fk[$c] = $d[0]; $fkAttr[$c] = ['badge' => $d[1]]; }
    $room = ['jednoosobowy' => 1, 'dwuosobowy' => 2, 'trzyosobowy' => 3, 'wieloosobowy' => 4, 'apartament' => 2];
    $roomAttr = []; foreach ($room as $c => $n) $roomAttr[$c] = ['lozka' => $n];
    return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
        $mk('nocl_status', 'Noclegi — statusy zgłoszeń', 'system', $st, 'Statusy zgłoszenia noclegu. Kody są użyte w logice modułu Noclegi (przejścia Wniosek → W trakcie → Potwierdzone → Zrealizowane / Anulowane); zmieniać wolno etykiety i kolory (atrybut badge).', 100, $stAttr),
        $mk('nocl_role_modulu', 'Noclegi — role w module', 'system', NOCL_ROLE_MODULU, 'Role modułu Noclegi nadawane osobno od ról SZO: Użytkownik (własne zgłoszenia), Koordynator Projektu (akceptacja kosztów swoich budżetów), Logistyk / Administrator (obiekty, rooming, faktury, role).', 110),
        $mk('nocl_status_faktury', 'Noclegi — statusy faktur', 'system', $fk, 'Obieg faktur i potwierdzeń kosztów noclegów.', 120, $fkAttr),
        $mk('nocl_rola_osoby', 'Noclegi — rola osoby w zgłoszeniu', 'business', NOCL_ROLA_OSOBY, 'Kim jest osoba, dla której zgłaszany jest nocleg (statystyki i raporty).', 130),
        $mk('nocl_typ_pokoju', 'Noclegi — typy pokoi', 'business', NOCL_TYP_POKOJU, 'Typy pokoi w obiektach. Atrybut lozka = domyślna liczba miejsc.', 140, $roomAttr),
        $mk('nocl_typ_obiektu', 'Noclegi — typy obiektów', 'business', NOCL_TYP_OBIEKTU, 'Rodzaje obiektów noclegowych w bazie.', 150),
        $mk('nocl_preferencje', 'Noclegi — preferencje i dostępność', 'business', NOCL_PREFERENCJE, 'Preferencje zaznaczane w zgłoszeniu (w tym potrzeby związane z dostępnością).', 160),
        $mk('nocl_kategoria_kosztu', 'Noclegi — kategorie kosztów', 'business', NOCL_KATEGORIA_KOSZTU, 'Kategorie pozycji kosztowych w fakturach noclegowych.', 170),
    ]], JSON_UNESCAPED_UNICODE);
}

/** Zasiew słowników (idempotentny — import po kodach). Zwraca listę błędów. */
function noclDictSeedRun(): array {
    $errors = [];
    foreach (dictTransfer()->import(noclDictSeed(), 'json') as $r) foreach ($r['errors'] as $e) $errors[] = $r['dictionary'] . ': ' . $e;
    return $errors;
}

function noclOpts(string $code, array $fallback): array { return dictOptions($code, $fallback); }

function noclStatusy(): array {
    $fb = []; foreach (NOCL_STATUSY as $c => $d) $fb[$c] = $d[0];
    return noclOpts('nocl_status', $fb);
}
function noclStatusLabel(string $c): string { return noclStatusy()[$c] ?? (NOCL_STATUSY[$c][0] ?? $c); }
function noclStatusBadge(string $c): string {
    $it = dictItems('nocl_status')[$c]['attributes']['badge'] ?? null;
    return is_string($it) && preg_match('/^[a-z\- ]+$/', $it) ? $it : (NOCL_STATUSY[$c][1] ?? 'text-bg-secondary');
}
function noclFakturaStatusy(): array {
    $fb = []; foreach (NOCL_STATUS_FAKTURY as $c => $d) $fb[$c] = $d[0];
    return noclOpts('nocl_status_faktury', $fb);
}
function noclFakturaBadge(string $c): string { return NOCL_STATUS_FAKTURY[$c][1] ?? 'text-bg-secondary'; }
function noclRoleOsoby(): array { return noclOpts('nocl_rola_osoby', NOCL_ROLA_OSOBY); }
function noclTypyPokoi(): array { return noclOpts('nocl_typ_pokoju', NOCL_TYP_POKOJU); }
function noclTypyObiektow(): array { return noclOpts('nocl_typ_obiektu', NOCL_TYP_OBIEKTU); }
function noclPreferencje(): array { return noclOpts('nocl_preferencje', NOCL_PREFERENCJE); }
function noclKategorieKosztu(): array { return noclOpts('nocl_kategoria_kosztu', NOCL_KATEGORIA_KOSZTU); }
function noclRoleModulu(): array { return noclOpts('nocl_role_modulu', NOCL_ROLE_MODULU); }
/** Domyślna liczba miejsc dla typu pokoju (atrybut lozka). */
function noclTypeBeds(string $type): int {
    $n = (int)(dictItems('nocl_typ_pokoju')[$type]['attributes']['lozka'] ?? 0);
    return $n > 0 ? $n : ['jednoosobowy' => 1, 'dwuosobowy' => 2, 'trzyosobowy' => 3, 'wieloosobowy' => 4, 'apartament' => 2][$type] ?? 1;
}
