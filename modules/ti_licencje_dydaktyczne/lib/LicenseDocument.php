<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseDocument.php — wydruki wzorów AM5 (licencja dydaktyczna) i AM7 (oprogramowanie i usługi cyfrowe) w układzie urzędowego
 * formularza (jak OKK-1 / deklaracja CIT-8): symbol AM5 w kaflu nagłówka, sekcje z szarymi belkami, numerowane pola
 * w ramkach, kratki ☒/☐, pola jasne (składający) i ciemne (wypełnia licencjodawca / Fundacja), stopka z symbolem i numerem.
 *
 *   AM5/1 — Wniosek o udzielenie licencji na korzystanie z materiałów w celach dydaktycznych + Decyzja licencjodawcy
 *   AM5/2 — Oświadczenie licencjobiorcy – kursanta
 *   AM5/3 — Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu materiałów
 *   AM7/1 — Wniosek o licencję lub dostęp do oprogramowania w celach dydaktycznych (podpis kursanta w portalu)
 *   AM7/2 — Decyzja o przyznaniu licencji lub dostępu do oprogramowania (PDF wysyłany kursantowi)
 *   AM7/3 — Decyzja o odmowie (PDF wysyłany kursantowi)
 *   AM7/4 — Krótkie oświadczenie licencjobiorcy — kursanta
 *   AM7/5 — Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu konta
 * Tenant (np. Microsoft 365) jest tenantem ośrodka (nie szkolnym) — teksty wzorów AM7 mówią o „tenancie ośrodka”.
 *
 * Pola puste drukują się z podpowiedzią wzoru i kropkami (do wypełnienia ręcznie). Podpis złożony w portalu drukuje się
 * jako adnotacja „złożono elektronicznie” (data, godzina, IP, skrót SHA-256 treści) — wersję papierową można podpisać odręcznie.
 * Fallback: brak mPDF lub błąd renderowania → render() zwraca dokument HTML (mime text/html) z tą samą treścią.
 */
require_once __DIR__ . '/ldSchema.php';
require_once __DIR__ . '/LicenseCodes.php';
require_once __DIR__ . '/LicenseEnums.php';
require_once __DIR__ . '/LicenseDto.php';
require_once __DIR__ . '/LicenseOperationDefs.php';

final class LicenseDocument
{
    public const VERSION = '1/2026';

    /** Treść oświadczeń wg wzoru (bez zmian redakcyjnych). */
    public const TEXT_REQUEST = 'Zwracam się z prośbą o udzielenie licencji na korzystanie w celach dydaktycznych z następującego utworu lub materiału:';
    public const TEXT_REQUEST_SCOPE = 'Wnioskuję o udzielenie licencji w poniższym zakresie; proszę zaznaczyć i uzupełnić wyłącznie te sposoby korzystania, na które ma zostać udzielona zgoda:';
    public const TEXT_REQUEST_DECL = 'Oświadczam, że w razie udzielenia licencji będę korzystać z utworu wyłącznie w zaakceptowanym zakresie oraz w celach dydaktycznych wskazanych powyżej.';
    public const TEXT_DECISION = 'Udzielam licencjobiorcy wskazanemu we wniosku licencji na korzystanie z opisanego utworu wyłącznie w zakresie zaakceptowanym poniżej i na warunkach wskazanych w niniejszym formularzu.';
    public const TEXT_PARTICIPANT = [
        'Oświadczam, że jako licencjobiorca – kursant uzyskałem/am dostęp do wskazanego wyżej utworu lub materiału w związku z udziałem w kursie. Zobowiązuję się korzystać z niego wyłącznie w zakresie i przez okres objęty udzieloną licencją oraz na potrzeby własnego udziału w kursie.',
        'Przyjmuję do wiadomości, że niniejsze oświadczenie samo w sobie nie rozszerza zakresu licencji ani nie zezwala na korzystanie z materiału poza warunkami udzielonej licencji.',
    ];
    public const TEXT_NONTRANSFER = [
        'Oświadczam, że nie odstąpię ani nie przeniosę na inną osobę uprawnień wynikających z udzielonej mi licencji. Nie będę udzielać sublicencji ani upoważniać innych osób do korzystania z utworu lub materiału, a także nie będę przekazywać im jego kopii ani danych dostępowych, chyba że licencja lub odrębna zgoda licencjodawcy wyraźnie na to pozwala.',
        'Zobowiązuję się korzystać z utworu lub materiału wyłącznie w zakresie udzielonej licencji.',
    ];

    private int $n = 0;

    /**
     * Numer dokumentu: sprawa 4021-MS_AM/NNNNNN/AB; AM5/1 = numer sprawy, AM5/2 i AM5/3 = numer sprawy + symbol + przydział + wersja.
     * Pusty dla czystego wzoru do druku.
     */
    public static function number(LicenseDoc $doc, array $license, ?array $assignment = null, int $version = 1): string
    {
        if ((int)($license['id'] ?? 0) === 0) return '';   // pusty wzór do wydruku
        $case = trim((string)($license['case_number'] ?? '')) ?: ldCaseNumber((int)$license['id'], 'XX');
        if ($doc === LicenseDoc::Am5Request || $doc === LicenseDoc::Am7Request) return $case;
        if (!$assignment) return $case . ' · ' . $doc->symbol();   // decyzje AM7/2, AM7/3
        return $case . ' · ' . $doc->symbol() . '-' . (int)$assignment['id'] . ' v' . $version;
    }

    // ── Elementy formularza ─────────────────────────────────────────────────────

    private static function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    private static function box(bool $on): string
    {
        return '<span class="bx">' . ($on ? '&#9746;' : '&#9744;') . '</span>';
    }

    /** Wartość albo podpowiedź wzoru z kropkami. */
    private static function val(string $v, string $hint): string
    {
        $v = trim($v);
        return $v === '' ? '<span class="ph">' . self::e($hint) . '</span><div class="dots">&nbsp;</div>' : nl2br(self::e($v));
    }

    private function fld(string $label, string $valueHtml, string $extra = '', bool $dark = false): string
    {
        $this->n++;
        if ($dark) $extra = str_contains($extra, 'style="') ? str_replace('style="', 'style="background:#e6e6e6;', $extra) : $extra . ' style="background:#e6e6e6"';
        return '<td class="f"' . $extra . '><div class="lb"><b>' . $this->n . '.</b> ' . $label . '</div><div class="v">' . ($valueHtml !== '' ? $valueHtml : '&nbsp;') . '</div></td>';
    }

    private static function sec(string $l, string $t, int $cols = 1, string $hint = ''): string
    {
        return '<tr><td class="sec" colspan="' . $cols . '"><b>' . $l . '. ' . $t . '</b>' . ($hint !== '' ? ' <span class="hint">' . $hint . '</span>' : '') . '</td></tr>';
    }

    private function header(LicenseDoc $doc, string $number, string $course, string $titleLines, string $legend): string
    {
        return '<table class="t"><tr>'
            . $this->fld('Numer sprawy / dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="40%"')
            . $this->fld('Kurs / zajęcia', self::e($course), ' width="40%"')
            . '<td class="sym" width="20%"><div class="symb">' . $doc->form()->value . '</div><div class="symp">/' . $doc->part() . '</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
            . '</tr></table>'
            . '<table class="t"><tr><td class="ttl">' . $titleLines . '<div class="ttl2">' . ($doc->form() === LicenseForm::Am7 ? 'licencje do oprogramowania i usług cyfrowych' : 'licencja dydaktyczna') . ' — dokumentacja usługi szkoleniowej (MSUES 2.0)</div></td></tr>'
            . '<tr><td class="lgd">' . $legend . '</td></tr></table>';
    }

    /** Adnotacja o złożeniu elektronicznym w portalu (zamiast podpisu odręcznego). */
    private static function eSign(?array $sig): string
    {
        if (!$sig || empty($sig['at'])) return '';
        return '<div class="esig">' . self::e($sig['label'] ?? 'Złożono elektronicznie w portalu ' . ldOrgName()) . ': ' . self::e(ldDate(substr((string)$sig['at'], 0, 10)) . ' ' . substr((string)$sig['at'], 11, 5))
            . (($sig['ip'] ?? '') !== '' ? ', IP ' . self::e($sig['ip']) : '') . (($sig['by'] ?? '') !== '' ? ', ' . self::e($sig['by']) : '') . '</div>';
    }

    // ── AM5/1 Wniosek + decyzja ─────────────────────────────────────────────────

    public function requestHtml(array $l): string
    {
        $this->n = 0;
        $r = $l['request'] ?? [];
        $d = $l['decision'] ?? [];
        $scopes = (array)($r['scopes'] ?? []);
        $h = $this->header(LicenseDoc::Am5Request, self::number(LicenseDoc::Am5Request, $l), (string)$l['course_name'],
            '<div class="ttl1">WNIOSEK O UDZIELENIE LICENCJI</div><div class="ttl1">NA KORZYSTANIE Z MATERIAŁÓW W CELACH DYDAKTYCZNYCH</div>',
            '<b>Składający:</b> wnioskodawca / przyszły licencjobiorca.&nbsp; <b>Wypełnia:</b> pola jasne — wnioskodawca (sekcje A–G), pola ciemne — licencjodawca (sekcja H).&nbsp; '
            . '<b>Termin realizacji:</b> do ' . LD_REALIZATION_DAYS . ' dni od wszczęcia' . (($l['due_on'] ?? '') !== '' ? ' (do ' . self::e(ldDate($l['due_on'])) . ')' : '') . '.');

        $h .= '<table class="t">' . self::sec('A', 'MIEJSCE I DATA', 2) . '<tr>'
            . $this->fld('Miejscowość', self::val((string)($r['place'] ?? ''), 'miejscowość'), ' width="60%"')
            . $this->fld('Data (dzień — miesiąc — rok)', self::val(ldDate($r['date'] ?? ''), 'data'), ' width="40%"') . '</tr></table>';

        $h .= '<table class="t">' . self::sec('B', 'ADRESAT — LICENCJODAWCA', 2, '(Do:)') . '<tr>'
            . $this->fld('Imię i nazwisko lub firma licencjodawcy', self::val((string)$l['licensor_name'], 'imię i nazwisko lub firma licencjodawcy'), ' width="50%"')
            . $this->fld('Adres lub dane kontaktowe licencjodawcy', self::val(trim((string)$l['licensor_address'] . "\n" . implode(', ', array_filter([(string)$l['licensor_email'], (string)$l['licensor_phone']]))), 'adres lub dane kontaktowe'), ' width="50%"')
            . '</tr></table>';

        $h .= '<table class="t">' . self::sec('C', 'WNIOSKODAWCA / PRZYSZŁY LICENCJOBIORCA', 2) . '<tr>'
            . $this->fld('Imię i nazwisko lub firma wnioskodawcy', self::val((string)$l['applicant_name'], 'imię i nazwisko lub firma wnioskodawcy'), ' width="50%"')
            . $this->fld('Adres lub dane kontaktowe wnioskodawcy', self::val((string)$l['applicant_contact'], 'adres lub dane kontaktowe wnioskodawcy'), ' width="50%"')
            . '</tr><tr>' . $this->fld('Nazwa kursu / zajęć', self::val((string)$l['course_name'], 'nazwa kursu lub zajęć dydaktycznych'), ' colspan="2"') . '</tr></table>';

        $h .= '<table class="t">' . self::sec('D', 'WNIOSEK — UTWÓR LUB MATERIAŁ', 1)
            . '<tr><td class="pc"><div class="pt">' . self::e(self::TEXT_REQUEST) . '</div></td></tr><tr>'
            . $this->fld('Dokładny tytuł, autor i opis utworu lub materiału', self::val(trim((string)$l['title'] . "\n" . (string)$l['work_description']), 'dokładny tytuł, autor i opis utworu lub materiału'))
            . '</tr></table>';

        $rows = '<tr><td class="pc" colspan="2"><div class="pt">' . self::e(self::TEXT_REQUEST_SCOPE) . '</div></td></tr>';
        foreach (LicenseRequestDto::SCOPES as $k => [$label, $hint]) {
            $on = array_key_exists($k, $scopes);
            $rows .= '<tr>' . $this->fld('Sposób korzystania', self::box($on) . ' <span class="st">' . self::e($label) . '</span>', ' width="45%"')
                   . $this->fld(self::e(ucfirst($hint)), $on ? self::val((string)$scopes[$k], $hint) : '', ' width="55%"') . '</tr>';
        }
        $h .= '<table class="t">' . self::sec('E', 'ZAKRES WNIOSKOWANEJ LICENCJI', 2, '(zaznaczyć ☒ i uzupełnić)') . $rows . '</table>';

        $h .= '<table class="t">' . self::sec('F', 'OKRES, TERYTORIUM I WYNAGRODZENIE', 3) . '<tr>'
            . $this->fld('Okres korzystania (od — do)', self::val(trim(ldDate($l['period_from'] ?? '') . ' — ' . ldDate($l['period_to'] ?? ''), ' —'), 'data rozpoczęcia i zakończenia licencji'), ' width="34%"')
            . $this->fld('Terytorium korzystania', self::val((string)$l['territory'], 'terytorium licencji'), ' width="26%"')
            . $this->fld('Wynagrodzenie lub informacja o nieodpłatności', self::val((string)($r['remuneration'] ?? ''), 'wysokość wynagrodzenia albo informacja o licencji nieodpłatnej'), ' width="40%"')
            . '</tr></table>';

        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('G', 'OŚWIADCZENIE I PODPIS WNIOSKODAWCY', 2)
            . '<tr><td class="f" colspan="2"><div class="st2">' . self::e(self::TEXT_REQUEST_DECL) . '</div></td></tr><tr>'
            . $this->fld('Miejscowość i data', self::e(trim((string)($r['place'] ?? '') . ', ' . ldDate($r['date'] ?? ''), ', ')), ' width="35%" style="height:15mm"')
            . $this->fld('Podpis wnioskodawcy', '', ' width="65%"') . '</tr></table>';

        // ── H. Decyzja licencjodawcy (pola ciemne) ──
        $decided = in_array($l['request_status'], [LicenseRequestStatus::Granted->value, LicenseRequestStatus::Refused->value], true);
        $grant = $decided && !empty($d['grant']);
        $sig = $decided && ($l['decided_via'] ?? '') === 'portal' ? ['at' => $l['decided_at'], 'ip' => $l['decided_ip'], 'by' => (string)($d['signer_name'] ?? '')] : null;
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('H', 'DECYZJA LICENCJODAWCY', 2, '(wypełnia licencjodawca)')
            . '<tr><td class="f" colspan="2" style="background:#e6e6e6"><div class="st2">' . self::e(self::TEXT_DECISION) . '</div>'
            . ($decided && !$grant ? '<div class="st2" style="margin-top:1mm"><b>Licencjodawca odmówił udzielenia licencji.</b> ' . self::e((string)($d['refusal_reason'] ?? '')) . '</div>' : '') . '</td></tr><tr>'
            . $this->fld('Rodzaj licencji', self::box($grant && ($d['kind'] ?? '') === 'niewylaczna') . ' niewyłączna &nbsp;&nbsp; ' . self::box($grant && ($d['kind'] ?? '') === 'wylaczna') . ' wyłączna', ' colspan="2"', true)
            . '</tr><tr>' . $this->fld('Zaakceptowane sposoby korzystania i ewentualne ograniczenia', $grant ? self::val((string)$d['accepted_scope'], 'zaakceptowany zakres licencji i ograniczenia') : '', ' colspan="2" style="height:14mm"', true)
            . '</tr><tr>' . $this->fld('Okres licencji', $grant ? self::e(ldDate($d['period_from'] ?? '') . ' — ' . ldDate($d['period_to'] ?? '')) : '', ' width="50%"', true)
            . $this->fld('Terytorium', $grant ? self::e((string)$d['territory']) : '', ' width="50%"', true)
            . '</tr><tr>' . $this->fld('Wynagrodzenie / pozostałe warunki', $grant ? self::e((string)($d['terms'] ?? '')) : '', ' colspan="2"', true)
            . '</tr><tr>' . $this->fld('Miejscowość i data', $decided ? self::e(trim((string)($d['place'] ?? '') . ', ' . ldDate($d['date'] ?? ''), ', ')) : '', ' width="35%" style="height:15mm"', true)
            . $this->fld('Podpis licencjodawcy', $decided ? self::e((string)($d['signer_name'] ?? '')) . self::eSign($sig) : '', ' width="65%"', true)
            . '</tr></table>';

        return self::css() . $h;
    }

    // ── AM5/2 i AM5/3 ───────────────────────────────────────────────────────────

    /**
     * @param array $data ['name','course','work','place','date','address']; $sig ['at','ip'] albo null
     */
    public function declarationHtml(LicenseDoc $doc, array $license, array $assignment, array $data, ?array $sig, int $version = 1): string
    {
        if ($doc->form() === LicenseForm::Am7) return $this->am7DeclarationHtml($doc, $license, $assignment, $data, $sig, $version);
        $this->n = 0;
        $nonTransfer = $doc === LicenseDoc::Am5NonTransfer;
        $h = $this->header($doc, self::number($doc, $license, $assignment, $version), (string)($data['course'] ?? ''),
            $nonTransfer ? '<div class="ttl1">OŚWIADCZENIE O NIEODSTĘPOWANIU LICENCJI</div><div class="ttl1">I NIEUDOSTĘPNIANIU MATERIAŁÓW</div>'
                         : '<div class="ttl1">OŚWIADCZENIE LICENCJOBIORCY – KURSANTA</div>',
            '<b>Składający:</b> licencjobiorca – kursant.&nbsp; <b>Licencja:</b> ' . self::e(self::number(LicenseDoc::Am5Request, $license)) . ' (' . self::e((string)$license['licensor_name']) . ').&nbsp; '
            . '<b>Okres licencji:</b> ' . self::e(ldDate($license['period_from'] ?? '') . ' — ' . ldDate($license['period_to'] ?? '')) . '.');

        $h .= '<table class="t">' . self::sec('A', 'DANE SKŁADAJĄCEGO I PRZEDMIOT LICENCJI', 2) . '<tr>'
            . $this->fld($nonTransfer ? 'Imię i nazwisko licencjobiorcy – kursanta' : 'Imię i nazwisko', self::val((string)($data['name'] ?? ''), 'imię i nazwisko kursanta'), ' width="45%"')
            . $this->fld('Adres', self::val((string)($data['address'] ?? ''), 'adres zamieszkania'), ' width="55%"')
            . '</tr><tr>' . $this->fld('Kurs', self::val((string)($data['course'] ?? ''), 'nazwa kursu'), ' colspan="2"')
            . '</tr><tr>' . $this->fld('Utwór lub materiał objęty licencją', self::val((string)($data['work'] ?? ''), 'tytuł lub opis materiału'), ' colspan="2"')
            . '</tr></table>';

        $rows = '';
        foreach ($nonTransfer ? self::TEXT_NONTRANSFER : self::TEXT_PARTICIPANT as $i => $t) {
            $rows .= '<tr><td class="pc"><div class="pt"><b>' . ($i + 1) . '.</b> ' . self::e($t) . '</div></td></tr>';
        }
        $h .= '<table class="t">' . self::sec('B', 'OŚWIADCZENIE', 1) . $rows . '</table>';

        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('C', 'MIEJSCE, DATA I PODPIS', 2) . '<tr>'
            . $this->fld('Miejscowość i data', self::val(trim((string)($data['place'] ?? '') . ', ' . ldDate($data['date'] ?? ''), ', '), 'miejscowość i data'), ' width="35%" style="height:16mm"')
            . $this->fld($nonTransfer ? 'Podpis licencjobiorcy – kursanta' : 'Podpis kursanta', $sig ? self::e((string)($data['name'] ?? '')) . self::eSign($sig) : '', ' width="65%"')
            . '</tr></table>';

        // ── D. Weryfikacja (pola ciemne) ──
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('D', 'WERYFIKACJA', 2, '(wypełnia Fundacja)') . '<tr>'
            . $this->fld('Rozstrzygnięcie', self::box(($assignment['status'] ?? '') === 'accepted') . ' zatwierdzono &nbsp;&nbsp; ' . self::box(($assignment['status'] ?? '') === 'rejected') . ' odrzucono', ' width="60%"', true)
            . $this->fld('Zweryfikował(a), data', self::e(trim((string)($assignment['decided_name'] ?? '') . ' ' . ldDate(substr((string)($assignment['decided_at'] ?? ''), 0, 10)))), ' width="40%"', true)
            . '</tr></table>';

        return self::css() . $h;
    }


    // ── AM7 — oprogramowanie i usługi cyfrowe ────────────────────────────────────────────────────────

    public const AM7_REQUEST = 'Zwracam się o udostępnienie wskazanego oprogramowania lub usługi cyfrowej na potrzeby nauki w ramach wskazanego kursu. Przyjmuję do wiadomości, że zakres i sposób dostępu zależą od rodzaju licencji, warunków dostawcy oraz sposobu aktywacji przyjętego przez placówkę; jeżeli dostęp wymaga konta w tenancie ośrodka lub innym środowisku placówki, jego używanie podlega także właściwym zasadom organizacyjnym i konfiguracji.';
    public const AM7_REQUEST_DECL = 'Oświadczam, że podane dane są prawidłowe, a wnioskowanego dostępu będę używać w związku z nauką na wskazanym kursie.';
    public const AM7_GRANT = 'Po rozpatrzeniu wniosku AM7 przyznaję kursantowi licencję lub dostęp do wskazanego oprogramowania albo usługi cyfrowej na potrzeby nauki w ramach wskazanego kursu, w określonym poniżej zakresie i okresie oraz w granicach uprawnienia przysługującego podmiotowi udostępniającemu i warunków licencji dostawcy.';
    public const AM7_GRANT_SCOPE = 'Licencja lub dostęp są przyznawane wyłącznie na potrzeby udziału we wskazanym kursie i w zakresie określonym w decyzji oraz warunkach licencji. Niniejsza decyzja nie rozszerza uprawnień, które podmiot udostępniający posiada wobec dostawcy, ani nie zmienia warunków licencji dostawcy.';
    public const AM7_GRANT_END = 'Kursant powinien przed końcem dostępu wykonać wskazane przez placówkę czynności dotyczące danych, plików i materiałów. Nie należy zakładać, że po odebraniu licencji, wyłączeniu konta lub zakończeniu subskrypcji dostęp do oprogramowania, usług albo zapisanych danych pozostanie dostępny; sposób i termin dalszego postępowania określają warunki dostawcy i zasady placówki wskazane powyżej.';
    public const AM7_REFUSAL = 'Po rozpatrzeniu wniosku AM7 odmawiam przyznania licencji lub dostępu do wskazanego oprogramowania albo usługi cyfrowej w zakresie objętym wnioskiem. Niniejsza decyzja nie przyznaje uprawnienia do korzystania z produktu, konta ani usług placówki.';
    public const AM7_PARTICIPANT = [
        'Oświadczam, że korzystam z przyznanej mi licencji lub dostępu do wskazanego oprogramowania albo usługi cyfrowej w celu nauki w ramach wskazanego kursu oraz zobowiązuję się przestrzegać warunków licencji, warunków dostawcy i zasad korzystania przekazanych przez podmiot udostępniający.',
        'Jeżeli dostęp wymaga konta, klucza licencyjnego lub środowiska udostępnionego przez placówkę, będę używać przydzielonych mi danych i uprawnień zgodnie z decyzją, warunkami licencji oraz zasadami placówki. Przyjmuję do wiadomości, że zakres i okres dostępu zależą od rodzaju licencji, konfiguracji usługi oraz postanowień decyzji.',
    ];
    public const AM7_NONTRANSFER = [
        'Oświadczam, że nie przekażę innej osobie przyznanego mi konta, danych logowania, klucza licencyjnego ani możliwości korzystania z licencji lub usług w zakresie niedozwolonym warunkami licencji albo zasadami placówki. Będę korzystać z przyznanego produktu wyłącznie na potrzeby własnej nauki w ramach wskazanego kursu, chyba że decyzja i warunki licencji wyraźnie dopuszczają inny sposób korzystania.',
        'Przyjmuję do wiadomości, że dostęp jest ograniczony do produktu, zakresu licencji i okresu wskazanych w decyzji, a po zakończeniu kursu lub upływie tego okresu placówka może zakończyć udostępnianie, odebrać licencję, dezaktywować konto lub klucz albo usunąć dostęp — zgodnie z warunkami dostawcy i zasadami placówki. Przed końcem dostępu wykonam wskazane czynności dotyczące danych i materiałów; nie zakładam, że licencja, konto lub dane pozostaną dostępne po tym terminie.',
    ];

    /** Teksty oświadczeń licencjobiorcy dla portalu (obie rodziny). @return list<string> */
    public static function declarationTexts(LicenseDoc $doc): array
    {
        return match ($doc) {
            LicenseDoc::Am5Participant => self::TEXT_PARTICIPANT,
            LicenseDoc::Am5NonTransfer => self::TEXT_NONTRANSFER,
            LicenseDoc::Am7Participant => self::AM7_PARTICIPANT,
            LicenseDoc::Am7NonTransfer => self::AM7_NONTRANSFER,
            default => [],
        };
    }

    /** Sposób dostępu z kratkami: konto w tenancie ośrodka / inny sposób. */
    private static function accessBoxes(string $mode, string $other): string
    {
        return self::box($mode === 'tenant') . ' konto użytkownika w tenancie ośrodka &nbsp;&nbsp; ' . self::box($mode === 'other') . ' inny sposób: '
            . ($mode === 'other' && trim($other) !== '' ? '<b>' . self::e($other) . '</b>' : '<span class="ph">…………………………</span>');
    }

    /** AM7/1 — wniosek kursanta (podpis: adnotacja elektroniczna z portalu, gdy złożony). */
    public function am7RequestHtml(array $l): string
    {
        $this->n = 0;
        $r = $l['request'] ?? [];
        $sig = !empty($l['requester_signed_at']) ? ['at' => $l['requester_signed_at'], 'ip' => $l['requester_signed_ip'] ?? '', 'by' => (string)($l['requester_signed_name'] ?? '')] : null;
        $h = $this->header(LicenseDoc::Am7Request, self::number(LicenseDoc::Am7Request, $l), (string)$l['course_name'],
            '<div class="ttl1">FORMULARZ AM7 — WNIOSEK O LICENCJĘ LUB DOSTĘP</div><div class="ttl1">DO OPROGRAMOWANIA W CELACH DYDAKTYCZNYCH</div>',
            '<b>Składający:</b> kursant.&nbsp; <b>Wypełnia:</b> pola jasne — kursant (sekcje A–E), pola ciemne — placówka (sekcja F).&nbsp; '
            . '<b>Termin realizacji:</b> do ' . LD_REALIZATION_DAYS . ' dni' . (($l['due_on'] ?? '') !== '' ? ' (do ' . self::e(ldDate($l['due_on'])) . ')' : '') . '.');
        $h .= '<table class="t">' . self::sec('A', 'MIEJSCE, DATA I ADRESAT', 3) . '<tr>'
            . $this->fld('Miejscowość', self::val((string)($r['place'] ?? ''), 'miejscowość'), ' width="30%"')
            . $this->fld('Data', self::val(ldDate($r['date'] ?? ''), 'data'), ' width="20%"')
            . $this->fld('Do (podmiot udostępniający)', self::val((string)$l['licensor_name'], 'nazwa placówki'), ' width="50%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('B', 'DANE KURSANTA', 2) . '<tr>'
            . $this->fld('Imię i nazwisko kursanta', self::val((string)$l['interested_name'], 'imię i nazwisko kursanta'), ' width="50%"')
            . $this->fld('Kurs lub program dydaktyczny', self::val((string)$l['course_name'], 'kurs lub program dydaktyczny'), ' width="50%"')
            . '</tr><tr>' . $this->fld('Konto, identyfikator użytkownika lub klucz licencyjny', self::val((string)($r['account'] ?? ''), 'konto, identyfikator lub klucz'), ' colspan="2"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('C', 'WNIOSKOWANY DOSTĘP', 2) . '<tr>'
            . $this->fld('Wnioskowany produkt i licencja', self::val((string)($r['plan'] ?? ''), 'produkt i licencja'), ' width="50%"')
            . $this->fld('Wnioskowany okres', self::val(trim(ldDate($l['period_from'] ?? '') . ' — ' . ldDate($l['period_to'] ?? ''), ' —'), 'okres'), ' width="50%"')
            . '</tr><tr>' . $this->fld('Wnioskowany sposób dostępu', self::accessBoxes((string)($r['access'] ?? ''), (string)($r['access_other'] ?? '')), ' colspan="2"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('D', 'WNIOSEK', 1) . '<tr><td class="pc"><div class="pt">' . self::e(self::AM7_REQUEST) . '</div></td></tr><tr>'
            . $this->fld('Uzasadnienie lub opis potrzeb dydaktycznych', self::val((string)($r['justification'] ?? ''), 'uzasadnienie lub opis potrzeb dydaktycznych'), ' style="height:16mm"') . '</tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('E', 'OŚWIADCZENIE I PODPIS KURSANTA', 2)
            . '<tr><td class="f" colspan="2"><div class="st2">' . self::e(self::AM7_REQUEST_DECL) . '</div></td></tr><tr>'
            . $this->fld('Miejscowość i data', self::e(trim((string)($r['place'] ?? '') . ', ' . ldDate($r['date'] ?? ''), ', ')), ' width="35%" style="height:15mm"')
            . $this->fld('Podpis kursanta', $sig ? self::e((string)$l['interested_name']) . self::eSign($sig) : '', ' width="65%"') . '</tr></table>';
        $decided = in_array($l['request_status'], [LicenseRequestStatus::Granted->value, LicenseRequestStatus::Refused->value], true);
        $grant = $l['request_status'] === LicenseRequestStatus::Granted->value;
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('F', 'ROZSTRZYGNIĘCIE', 2, '(wypełnia placówka)') . '<tr>'
            . $this->fld('Decyzja', self::box($decided && $grant) . ' przyznano — ' . LicenseDoc::Am7Grant->symbol() . ' &nbsp;&nbsp; ' . self::box($decided && !$grant) . ' odmowa — ' . LicenseDoc::Am7Refusal->symbol(), ' width="60%"', true)
            . $this->fld('Data decyzji', $decided ? self::e(ldDate(substr((string)$l['decided_at'], 0, 10))) : '', ' width="40%"', true) . '</tr></table>';
        return self::css() . $h;
    }

    /** AM7/2 (przyznanie) albo AM7/3 (odmowa) — decyzja placówki wysyłana kursantowi w PDF. */
    public function am7DecisionHtml(array $l): string
    {
        $this->n = 0;
        $d = $l['decision'] ?? [];
        $grant = !empty($d['grant']);
        $doc = $grant ? LicenseDoc::Am7Grant : LicenseDoc::Am7Refusal;
        $sig = !empty($d['approved_at']) ? ['at' => $d['approved_at'], 'ip' => '', 'by' => (string)($d['approved_name'] ?? ''), 'label' => 'Zatwierdzono elektronicznie w SZO przez kierownika'] : null;
        $h = $this->header($doc, self::number($doc, $l), (string)$l['course_name'],
            '<div class="ttl1">' . ($grant ? 'DECYZJA O PRZYZNANIU LICENCJI LUB DOSTĘPU' : 'DECYZJA O ODMOWIE PRZYZNANIA LICENCJI LUB DOSTĘPU') . '</div><div class="ttl1">DO OPROGRAMOWANIA</div>',
            '<b>Wydaje:</b> ' . self::e((string)$l['licensor_name']) . '.&nbsp; <b>Adresat:</b> kursant wskazany we wniosku.&nbsp; <b>Sprawa:</b> ' . self::e((string)$l['case_number']) . '.');
        $reqDate = ldDate((string)($l['request']['date'] ?? '')) ?: ldDate(substr((string)$l['started_at'], 0, 10));
        $h .= '<table class="t">' . self::sec('A', 'STRONY I WNIOSEK', 2) . '<tr>'
            . $this->fld($grant ? 'Podmiot udostępniający' : 'Podmiot rozpatrujący', self::e((string)$l['licensor_name']), ' width="50%"')
            . $this->fld('Kursant', self::e((string)$l['interested_name']), ' width="50%"')
            . '</tr><tr>' . $this->fld('Kurs', self::val((string)$l['course_name'], 'kurs'), ' width="50%"')
            . $this->fld('Wniosek', 'formularz ' . LicenseDoc::Am7Request->symbol() . ' z dnia ' . self::e($reqDate) . ' (' . self::e((string)$l['case_number']) . ')', ' width="50%"') . '</tr></table>';
        if ($grant) {
            $h .= '<table class="t">' . self::sec('B', 'DECYZJA O PRZYZNANIU', 2) . '<tr><td class="pc" colspan="2"><div class="pt">' . self::e(self::AM7_GRANT) . '</div></td></tr><tr>'
                . $this->fld('Sposób udostępnienia', self::accessBoxes((string)($d['access'] ?? ''), (string)($d['access_other'] ?? '')), ' colspan="2"')
                . '</tr><tr>' . $this->fld('Konto przypisane kursantowi', self::val((string)($d['account'] ?? ''), 'konto'), ' width="50%"')
                . $this->fld('Produkt, rodzaj licencji, plan lub SKU', self::val((string)($d['plan'] ?? ''), 'produkt, licencja, plan / SKU'), ' width="50%"')
                . '</tr><tr>' . $this->fld('Zakres udostępnionych funkcji lub usług', self::val((string)($d['services'] ?? ''), 'zakres funkcji lub usług'), ' colspan="2"')
                . '</tr><tr>' . $this->fld('Okres aktywnego dostępu', 'od <b>' . self::e(ldDate($d['period_from'] ?? '')) . '</b> do <b>' . self::e(ldDate($d['period_to'] ?? '')) . '</b>', ' colspan="2"')
                . '</tr><tr>' . $this->fld('Ograniczenia i zasady korzystania', self::val((string)($d['restrictions'] ?? ''), 'ograniczenia i zasady'), ' colspan="2"')
                . '</tr><tr><td class="pc" colspan="2"><div class="pt">' . self::e(self::AM7_GRANT_SCOPE) . '</div></td></tr></table>';
            $h .= '<table class="t">' . self::sec('C', 'PO ZAKOŃCZENIU KURSU LUB OKRESU DOSTĘPU', 2) . '<tr>'
                . $this->fld('Postępowanie po zakończeniu kursu lub okresu dostępu', self::val((string)($d['after_end'] ?? ''), 'postępowanie po zakończeniu'), ' width="50%"')
                . $this->fld('Postępowanie z danymi i materiałami', self::val((string)($d['account_data'] ?? ''), 'postępowanie z danymi i materiałami'), ' width="50%"')
                . '</tr><tr><td class="pc" colspan="2"><div class="pt">' . self::e(self::AM7_GRANT_END) . '</div></td></tr></table>';
            $sl = 'D';
        } else {
            $h .= '<table class="t">' . self::sec('B', 'DECYZJA O ODMOWIE', 1) . '<tr><td class="pc"><div class="pt">' . self::e(self::AM7_REFUSAL) . '</div></td></tr><tr>'
                . $this->fld('Uzasadnienie odmowy', self::val((string)($d['refusal_reason'] ?? ''), 'uzasadnienie odmowy'), ' style="height:18mm"') . '</tr></table>';
            $sl = 'C';
        }
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec($sl, 'OSOBA UPOWAŻNIONA I PODPIS', 2) . '<tr>'
            . $this->fld($grant ? 'Osoba upoważniona do podjęcia decyzji' : 'Osoba podejmująca decyzję', self::val((string)($d['signer_name'] ?? ''), 'imię, nazwisko, funkcja'), ' width="45%" style="height:16mm"')
            . $this->fld('Podpis', $sig ? self::e((string)($d['signer_name'] ?? '')) . self::eSign($sig) : '', ' width="55%"') . '</tr></table>';
        return self::css() . $h;
    }

    /** AM7/4 i AM7/5 — oświadczenia kursanta po przyznaniu dostępu. $data: name, course, account, plan_period, after_end, data_rules, place, date. */
    private function am7DeclarationHtml(LicenseDoc $doc, array $license, array $assignment, array $data, ?array $sig, int $version): string
    {
        $this->n = 0;
        $nt = $doc === LicenseDoc::Am7NonTransfer;
        $h = $this->header($doc, self::number($doc, $license, $assignment, $version), (string)($data['course'] ?? ''),
            $nt ? '<div class="ttl1">OŚWIADCZENIE O NIEPRZEKAZYWANIU</div><div class="ttl1">LICENCJI I DOSTĘPU</div>' : '<div class="ttl1">KRÓTKIE OŚWIADCZENIE LICENCJOBIORCY — KURSANTA</div>',
            '<b>Składający:</b> kursant.&nbsp; <b>Decyzja:</b> ' . self::e(self::number(LicenseDoc::Am7Grant, $license)) . '.&nbsp; <b>Okres dostępu:</b> ' . self::e(ldDate($license['period_from'] ?? '') . ' — ' . ldDate($license['period_to'] ?? '')) . '.');
        $h .= '<table class="t">' . self::sec('A', 'DANE', 2) . '<tr>'
            . $this->fld($nt ? 'Imię i nazwisko kursanta' : 'Imię i nazwisko', self::val((string)($data['name'] ?? ''), 'imię i nazwisko'), ' width="50%"')
            . $this->fld(($nt ? 'Produkt, licencja lub konto' : 'Konto, identyfikator użytkownika lub licencji'), self::val((string)($data['account'] ?? ''), 'konto / licencja'), ' width="50%"')
            . ($nt ? '' : '</tr><tr>' . $this->fld('Kurs', self::val((string)($data['course'] ?? ''), 'kurs'), ' colspan="2"'))
            . '</tr></table>';
        $rows = '';
        foreach ($nt ? self::AM7_NONTRANSFER : self::AM7_PARTICIPANT as $i => $t) $rows .= '<tr><td class="pc"><div class="pt"><b>' . ($i + 1) . '.</b> ' . self::e($t) . '</div></td></tr>';
        $h .= '<table class="t">' . self::sec('B', 'OŚWIADCZENIE', 1) . $rows . '</table>';
        $h .= '<table class="t">' . self::sec('C', 'PLAN, OKRES I DANE', 2) . '<tr>'
            . $this->fld($nt ? 'Produkt, licencja i okres dostępu' : 'Produkt, licencja lub plan i okres dostępu', self::val((string)($data['plan_period'] ?? ''), 'plan i okres'), ' width="45%"')
            . $this->fld($nt ? 'Zasady postępowania z danymi po zakończeniu dostępu' : 'Czynności po zakończeniu dostępu dotyczące moich danych',
                         self::val((string)($nt ? ($data['data_rules'] ?? '') : ($data['after_end'] ?? '')), 'zasady / czynności'), ' width="55%"') . '</tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('D', 'MIEJSCE, DATA I PODPIS', 2) . '<tr>'
            . $this->fld('Miejscowość i data', self::val(trim((string)($data['place'] ?? '') . ', ' . ldDate($data['date'] ?? ''), ', '), 'miejscowość i data'), ' width="35%" style="height:16mm"')
            . $this->fld('Podpis kursanta', $sig ? self::e((string)($data['name'] ?? '')) . self::eSign($sig) : '', ' width="65%"') . '</tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('E', 'WERYFIKACJA', 2, '(wypełnia placówka)') . '<tr>'
            . $this->fld('Rozstrzygnięcie', self::box(($assignment['status'] ?? '') === 'accepted') . ' zatwierdzono &nbsp;&nbsp; ' . self::box(($assignment['status'] ?? '') === 'rejected') . ' odrzucono', ' width="60%"', true)
            . $this->fld('Zweryfikował(a), data', self::e(trim((string)($assignment['decided_name'] ?? '') . ' ' . ldDate(substr((string)($assignment['decided_at'] ?? ''), 0, 10)))), ' width="40%"', true)
            . '</tr></table>';
        return self::css() . $h;
    }

    /** Pusty wzór (druk do obiegu papierowego) dowolnego dokumentu. */
    // ── AM90: potwierdzenie wykonania operacji (konto w tenancie) — układ CIT ────────────

    /** Numer druku AM90: numer sprawy + symbol; rewizja ≥ 2 (aktualizacja danych po zmianie UPN) → AM90/2, AM90/3… */
    public static function am90Number(array $l, int $rev = 1): string
    {
        return (trim((string)($l['case_number'] ?? '')) ?: ldCaseNumber((int)$l['id'], 'XX')) . ' · AM90' . ($rev > 1 ? '/' . $rev : '');
    }

    /**
     * Druk AM90 „Potwierdzenie wykonania operacji” — wyłącznie POTWIERDZA warunki udostępnienia (konto w tenancie dla przyznanego
     * wniosku AM7). Bez podpisów i bez haseł; z zastrzeżeniem zmiany warunków przez Fundację, gdy zmieni je licencjodawca.
     * $info: tenant_label, upn, created_at, sku_name, name, email, email_sent.
     */
    public function am90Html(array $l, array $info): string
    {
        $this->n = 0;
        $rev = max(1, (int)($info['revision'] ?? 1));
        $chg = $rev > 1 ? (array)($info['change'] ?? []) : [];
        $number = self::am90Number($l, $rev);
        $d = $l['decision'] ?? [];
        $plan = (string)(($d['plan'] ?? '') ?: ($l['request']['plan'] ?? '') ?: $l['title']);
        $when = ldDate(substr((string)$info['created_at'], 0, 10)) . ' ' . substr((string)$info['created_at'], 11, 5);
        $h = '<table class="t"><tr>'
           . $this->fld('Numer sprawy / dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="40%"')
           . $this->fld('Kurs / zajęcia', self::e((string)($l['course_name'] ?: '—')), ' width="40%"')
           . '<td class="sym" width="20%" style="background:#0f766e"><div class="symb">AM90</div><div class="symp">' . ($rev > 1 ? '/' . $rev : '&nbsp;') . '</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">POTWIERDZENIE WYKONANIA OPERACJI' . ($rev > 1 ? ' — AKTUALIZACJA DANYCH' : '') . '</div>'
           . '<div class="ttl2">' . ($rev > 1 ? 'aktualizacja danych konta po zmianie loginu (UPN) — zastępuje poprzednie potwierdzenie AM90' : 'licencje do oprogramowania i usług cyfrowych — potwierdzenie warunków udostępnienia') . '</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Adresat:</b> osoba wskazana we wniosku.&nbsp; <b>Sprawa:</b> ' . self::e((string)$l['case_number'])
           . '.&nbsp; ' . ($rev > 1 ? 'Dokument potwierdza <b>aktualizację danych konta</b> (zmiana loginu UPN), zastępuje poprzednie potwierdzenie AM90 i <b>nie wymaga podpisu</b>.' : 'Dokument jedynie potwierdza warunki — <b>nie wymaga podpisu</b>.') . '</td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'OSOBA I SPRAWA', 2) . '<tr>'
            . $this->fld('Imię i nazwisko', self::e((string)($info['name'] ?? $l['interested_name'])), ' width="50%"')
            . $this->fld('Wniosek i decyzja', 'wniosek ' . self::e(LicenseDoc::Am7Request->symbol()) . ' (' . self::e((string)$l['case_number']) . '), decyzja ' . self::e(LicenseDoc::Am7Grant->symbol())
                . (!empty($d['approved_at']) ? ' z dnia ' . self::e(ldDate(substr((string)$d['approved_at'], 0, 10))) : ''), ' width="50%"')
            . '</tr><tr>' . $this->fld('Usługa / produkt / plan', self::val($plan, 'usługa'), ' colspan="2"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('B', 'WYKONANA OPERACJA', 2) . '<tr><td class="pc" colspan="2"><div class="pt">' . ($rev > 1
                ? 'Potwierdza się zaktualizowanie danych konta użytkownika w tenancie Microsoft 365 (zmiana loginu UPN z powodu błędu). Konto, jego hasło, dane i nadane licencje pozostają bez zmian; dokument zastępuje poprzednie potwierdzenie AM90.'
                : 'Potwierdza się utworzenie konta użytkownika w tenancie Microsoft 365 na potrzeby usługi wskazanej w części A.') . '</div></td></tr><tr>'
            . $this->fld('Tenant', self::e((string)$info['tenant_label']), ' width="50%"')
            . $this->fld('Login (UPN)', '<b>' . self::e((string)$info['upn']) . '</b>', ' width="50%"')
            . '</tr><tr>' . $this->fld('Licencja nadana w tenancie', self::val((string)($info['sku_name'] ?? ''), 'brak — licencja do nadania ręcznie'), ' width="50%"')
            . $this->fld($rev > 1 ? 'Data i godzina aktualizacji' : 'Data i godzina wykonania', self::e($rev > 1 ? ldDate(substr((string)($chg['at'] ?? ''), 0, 10)) . ' ' . substr((string)($chg['at'] ?? ''), 11, 5) : $when), ' width="50%"') . '</tr>'
            . ($rev > 1 ? '<tr>' . $this->fld('Poprzedni login (UPN)', self::e((string)($chg['old'] ?? '')), ' width="50%"')
                . $this->fld('Uzasadnienie aktualizacji', self::val((string)($chg['reason'] ?? ''), 'uzasadnienie'), ' width="50%"') . '</tr>' : '')
            . '</table>';
        $mail = $rev > 1 ? 'nowy login przekazany e-mailem na ' . self::e((string)($info['email'] ?? '')) . '; hasło bez zmian' : (!empty($info['email_sent']) ? 'login i instrukcja aktywacji wysłane e-mailem na ' . self::e((string)($info['email'] ?? '')) : 'login i instrukcja aktywacji do przekazania osobie');
        $h .= '<table class="t">' . self::sec('C', 'WARUNKI UDOSTĘPNIENIA', 2) . '<tr>'
            . $this->fld('Okres aktywnego dostępu', ($d['period_from'] ?? '') !== '' ? 'od <b>' . self::e(ldDate($d['period_from'])) . '</b> do <b>' . self::e(ldDate($d['period_to'] ?? '')) . '</b>' : self::val('', 'okres dostępu'), ' width="50%"')
            . $this->fld('Dane dostępowe', $mail, ' width="50%"')
            . '</tr><tr>' . $this->fld('Zakres udostępnionych funkcji lub usług', self::val((string)($d['services'] ?? ''), 'zakres funkcji lub usług'), ' colspan="2"')
            . '</tr><tr>' . $this->fld('Ograniczenia i zasady korzystania', self::val((string)($d['restrictions'] ?? ''), 'ograniczenia i zasady'), ' width="50%"')
            . $this->fld('Postępowanie po zakończeniu okresu dostępu', self::val((string)($d['after_end'] ?? ''), 'postępowanie po zakończeniu'), ' width="50%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('D', 'ZASTRZEŻENIE', 1) . '<tr><td class="pc"><div class="pt">Warunki wskazane w niniejszym dokumencie odzwierciedlają warunki licencji lub usługi ustalone przez licencjodawcę (dostawcę oprogramowania). '
            . 'Jeżeli licencjodawca zmieni te warunki, Fundacja może dokonać odpowiedniej zmiany warunków udostępnienia, w tym okresu dostępu, zakresu funkcji lub zasad korzystania, o czym poinformuje osobę, której dotyczy konto. '
            . 'Dokument został wygenerowany elektronicznie w SZO (' . self::e($when) . ') i nie wymaga podpisu.</div></td></tr></table>';
        return self::css() . $h;
    }

    // ── AM91: zmiana loginu (UPN) konta w tenancie po błędzie — układ CIT ────────────

    /** Numer druku AM91: numer sprawy + symbol + numer kolejnej zmiany. */
    public static function am91Number(array $l, int $n = 1): string
    {
        return (trim((string)($l['case_number'] ?? '')) ?: ldCaseNumber((int)$l['id'], 'XX')) . ' · AM91/' . $n;
    }

    /**
     * Druk AM91 „Zmiana loginu (UPN)” — dokumentuje wykonaną zmianę: poprzedni i nowy login, powód (błąd), kto i kiedy zmienił.
     * Bez podpisów i bez haseł (hasło konta się nie zmienia). $info: tenant_label, name, email, change{old,new,reason,at,by}, change_no.
     */
    public function am91Html(array $l, array $info): string
    {
        $this->n = 0;
        $c = (array)$info['change'];
        $number = self::am91Number($l, (int)($info['change_no'] ?? 1));
        $when = ldDate(substr((string)$c['at'], 0, 10)) . ' ' . substr((string)$c['at'], 11, 5);
        $h = '<table class="t"><tr>'
           . $this->fld('Numer sprawy / dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="40%"')
           . $this->fld('Kurs / zajęcia', self::e((string)($l['course_name'] ?: '—')), ' width="40%"')
           . '<td class="sym" width="20%" style="background:#9f1239"><div class="symb">AM91</div><div class="symp">&nbsp;</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">ZMIANA LOGINU (UPN) KONTA</div>'
           . '<div class="ttl2">korekta błędu w loginie konta Microsoft 365 utworzonego w ramach licencji</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Adresat:</b> osoba, której dotyczy konto.&nbsp; <b>Sprawa:</b> ' . self::e((string)$l['case_number'])
           . '.&nbsp; Dokument jedynie potwierdza wykonaną zmianę — <b>nie wymaga podpisu</b>.</td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'OSOBA I SPRAWA', 2) . '<tr>'
            . $this->fld('Imię i nazwisko', self::e((string)($info['name'] ?? $l['interested_name'])), ' width="50%"')
            . $this->fld('Tenant', self::e((string)$info['tenant_label']), ' width="50%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('B', 'WYKONANA ZMIANA', 2) . '<tr><td class="pc" colspan="2"><div class="pt">Ze względu na błąd w loginie zmieniono login (UPN) konta użytkownika w tenancie Microsoft 365. Konto, jego hasło, dane i nadane licencje pozostają bez zmian.</div></td></tr><tr>'
            . $this->fld('Dotychczasowy login (UPN)', '<b>' . self::e((string)$c['old']) . '</b>', ' width="50%"')
            . $this->fld('Nowy login (UPN)', '<b>' . self::e((string)$c['new']) . '</b>', ' width="50%"')
            . '</tr><tr>' . $this->fld('Uzasadnienie zmiany (opis błędu)', self::val((string)$c['reason'], 'uzasadnienie zmiany'), ' colspan="2"')
            . '</tr><tr>' . $this->fld('Data i godzina zmiany', self::e($when), ' width="50%"')
            . $this->fld('Zmiany dokonał(a)', self::val((string)($c['by'] ?? ''), 'osoba dokonująca zmiany'), ' width="50%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('C', 'CO ZROBIĆ PO ZMIANIE', 1) . '<tr><td class="pc"><div class="pt">'
            . '1. Od teraz loguj się wyłącznie <b>nowym loginem</b> (' . self::e((string)$c['new']) . '); dotychczasowy login przestaje działać.<br>'
            . '2. <b>Hasło pozostaje takie samo.</b><br>'
            . '3. W aplikacjach Microsoft (Teams, Outlook, Office, OneDrive) wyloguj się i zaloguj ponownie nowym loginem; w razie potrzeby usuń zapamiętane konto przeglądarki.<br>'
            . '4. W razie trudności zgłoś sprawę do Helpdesku IT, podając numer sprawy ' . self::e((string)$l['case_number']) . '.</div></td></tr></table>';
        $h .= '<table class="t">' . self::sec('D', 'INFORMACJA', 1) . '<tr><td class="pc"><div class="pt">Dokument został wygenerowany elektronicznie w SZO (' . self::e($when) . ') i nie wymaga podpisu. '
            . 'Zmiana dotyczy wyłącznie loginu konta i nie zmienia warunków udostępnienia licencji potwierdzonych dokumentem AM90.</div></td></tr></table>';
        return self::css() . $h;
    }

    // ── AMSK: korekta danych w dokumentach AM; AMSK/1 — druk kontrolny do ODRĘCZNEGO podpisu kierownika (QR + Code128) ──

    /**
     * $c — korekta (LicenseCorrection::get), $t — sprawa / operacja, $control — AMSK/1 (zmiany + kody + podpis odręczny) albo AMSK (korekta dla osoby, po zatwierdzeniu).
     */
    public function amskHtml(array $c, array $t, bool $control): string
    {
        $this->n = 0;
        $code = LicenseCorrection::code((int)$c['id']);
        $number = LicenseCorrection::docNumber($c, $control);
        $isOp = $c['scope'] === 'operation';
        $person = (string)($isOp ? ($t['account_name'] ?? '') : ($t['interested_name'] ?? ''));
        $doc = $isOp ? (string)$t['formEnum']->value . ' — ' . $t['formEnum']->title() : (($t['form'] ?? null) === LicenseForm::Am7 ? 'AM7 — licencja / dostęp do oprogramowania' : 'AM5 — licencja na materiały dydaktyczne');
        $st = (string)$c['status'];
        $h = '<table class="t"><tr>'
           . $this->fld('Numer dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="45%"')
           . $this->fld('Dotyczy dokumentów AM w sprawie', self::e((string)$c['target_number']), ' width="35%"')
           . '<td class="sym" width="20%" style="background:#7c2d12"><div class="symb">AMSK</div><div class="symp">' . ($control ? '/1' : '&nbsp;') . '</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">' . ($control ? 'DRUK KONTROLNY KOREKTY — DO PODPISU' : 'KOREKTA DANYCH W DOKUMENTACH AM') . '</div>'
           . '<div class="ttl2">' . ($control ? 'dokument dotyczy danych osobowych — wymaga ODRĘCZNEGO podpisu kierownika; zmiany wchodzą w życie po potwierdzeniu podpisu w SZO' : 'korekta błędnych danych — zastępuje wskazane wartości w dokumentach sprawy') . '</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Korekta:</b> ' . self::e($code) . '.&nbsp; <b>Status:</b> ' . self::e(LicenseCorrection::statusLabel($st)) . '.'
           . ($control ? '' : '&nbsp; Dokument <b>nie wymaga podpisu</b>.') . '</td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'SPRAWA I DOKUMENT', 2) . '<tr>'
            . $this->fld('Osoba, której dotyczy sprawa', self::val($person, 'osoba'), ' width="50%"')
            . $this->fld('Dokument', self::e($doc), ' width="50%"') . '</tr><tr>'
            . $this->fld('Korekta wystawiona', self::e(ldDate(substr((string)$c['created_at'], 0, 10)) . ' ' . substr((string)$c['created_at'], 11, 5)), ' width="50%"')
            . $this->fld('Wystawił(a)', self::val((string)$c['created_name'], 'osoba wystawiająca'), ' width="50%"') . '</tr></table>';
        $rows = '';
        foreach ($c['itemsList'] as $i => $it) {
            $rows .= '<tr><td class="f" style="width:4%">' . ($i + 1) . '</td><td class="f" style="width:19%"><div class="lb">' . self::e((string)$it['doc']) . '</div><b>' . self::e((string)$it['label']) . '</b></td>'
                  . '<td class="f" style="width:38.5%">' . nl2br(self::e((string)$it['old_disp'])) . '</td><td class="f" style="width:38.5%"><b>' . nl2br(self::e((string)$it['new_disp'])) . '</b></td></tr>';
        }
        $h .= '<table class="t">' . self::sec('B', 'ZAKRES KOREKTY', 4)
            . '<tr><td class="lb" style="padding:1mm"><b>Lp.</b></td><td class="lb" style="padding:1mm"><b>Dokument i pole</b></td><td class="lb" style="padding:1mm"><b>Było (błędnie)</b></td><td class="lb" style="padding:1mm"><b>Jest po korekcie</b></td></tr>'
            . $rows . '</table>';
        $h .= '<table class="t">' . self::sec('C', 'UZASADNIENIE KOREKTY', 1) . '<tr><td class="pc"><div class="pt">' . nl2br(self::e((string)$c['reason'])) . '</div></td></tr></table>';
        if ($control) {
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('D', 'KONTROLA I POWIĄZANIE Z DOKUMENTAMI AM', 3) . '<tr>'
                . '<td class="f" style="width:22%;text-align:center"><div class="lb">Kod QR — status korekty w SZO</div><barcode code="' . self::e(LicenseCorrection::verifyUrl($c)) . '" type="QR" class="barcode" size="0.85" error="M" disableborder="1" /></td>'
                . '<td class="f" style="width:38%;text-align:center"><div class="lb">Code128 — kod korekty (łączy formularze sprawy)</div><barcode code="' . self::e($code) . '" type="C128B" size="1" height="1.1" /><div class="v">' . self::e($code) . '</div></td>'
                . '<td class="pc" style="width:40%"><div class="pt">Kod <b>' . self::e($code) . '</b> wpisz (zeskanuj) w polu „Skanuj kod” na pulpicie Licencji — otworzy korektę, a kod w stopce każdego formularza AM tej sprawy (<b>'
                . self::e(LicenseCodes::formCode(LicenseCodes::caseCode($c['scope'], (int)$c['target_id']), 'AM')) . '</b> i kolejne symbole) otworzy sprawę. Kod QR prowadzi do strony ze statusem korekty (bez danych osobowych).</div></td></tr></table>';
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('E', 'ZATWIERDZENIE — PODPIS ODRĘCZNY KIEROWNIKA', 2) . '<tr><td class="pc" colspan="2"><div class="pt">'
                . 'Zatwierdzam korektę wskazanych powyżej danych (dane osobowe) i potwierdzam, że zmiany są zgodne z dokumentami źródłowymi. Podpis składam odręcznie; po podpisaniu druk należy zeskanować do teczki sprawy i potwierdzić w SZO (Licencje › Korekty AMSK).</div></td></tr><tr>'
                . $this->fld('Imię i nazwisko kierownika (czytelnie)', '', ' width="55%" style="height:20mm"') . $this->fld('Data i miejscowość', '', ' width="45%" style="height:20mm"') . '</tr><tr>'
                . $this->fld('Podpis odręczny kierownika', '', ' colspan="2" style="height:26mm"') . '</tr></table>';
        } else {
            $h .= '<table class="t">' . self::sec('D', 'ZATWIERDZENIE', 1) . '<tr><td class="pc"><div class="pt">Korekta została zatwierdzona <b>' . self::e(ldDate(substr((string)$c['applied_at'], 0, 10)) . ' ' . substr((string)$c['applied_at'], 11, 5))
                . '</b> (' . self::e((string)$c['applied_name']) . ') na podstawie druku kontrolnego AMSK/1 podpisanego odręcznie przez kierownika (kod ' . self::e($code) . '). '
                . ($isOp ? 'Korekta zmienia dane dokumentów sprawy; nie cofa skutków operacji już wykonanych (np. doliczonej opłaty) — te wymagają odrębnej operacji. ' : '')
                . 'Pozostałe dane w dokumentach sprawy pozostają bez zmian. Dokument wygenerowano elektronicznie w SZO.</div></td></tr></table>';
        }
        return self::css() . $h;
    }

    // ── AMSG: regeneracja pakietu druków AM (po braku pakietu / ponownym generowaniu) — druk informacyjny ──────────────

    /**
     * $pk — pakiet (license_doc_packages), $c — korekta AMSK, $issues — druki pakietu (obowiązujące), $void — unieważnione druki tej korekty.
     */
    public function amsgHtml(array $pk, array $c, array $issues, array $void): string
    {
        $this->n = 0;
        $number = (string)$c['target_number'] . ' · AMSG/' . (int)$pk['gen_no'];
        $h = '<table class="t"><tr>'
           . $this->fld('Numer dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="45%"')
           . $this->fld('Dotyczy sprawy', self::e((string)$c['target_number']), ' width="35%"')
           . '<td class="sym" width="20%" style="background:#334155"><div class="symb">AMSG</div><div class="symp">/' . (int)$pk['gen_no'] . '</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">REGENERACJA PAKIETU DRUKÓW AM</div>'
           . '<div class="ttl2">protokół wygenerowania pakietu poprawionych dokumentów po korekcie AMSK — kody i unieważnienia</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Korekta:</b> ' . self::e(LicenseCorrection::code((int)$c['id'])) . '.&nbsp; Dokument <b>nie wymaga podpisu</b>.</td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'PAKIET', 2) . '<tr>'
            . $this->fld('Rewizja druków', '<b>R' . (int)$pk['revision'] . '</b>', ' width="25%"')
            . $this->fld('Generowanie nr', (string)(int)$pk['gen_no'], ' width="25%"')
            . $this->fld('Wygenerowano', self::e(ldDate(substr((string)$pk['created_at'], 0, 10)) . ' ' . substr((string)$pk['created_at'], 11, 5)), ' width="25%"')
            . $this->fld('Wygenerował(a)', self::val((string)$pk['created_name'], 'osoba'), ' width="25%"') . '</tr><tr>'
            . $this->fld('Powód', self::val((string)($pk['gen_reason'] ?? ''), 'powód'), ' colspan="4"') . '</tr></table>';
        $rows = '';
        foreach ($issues as $i => $r) {
            $rows .= '<tr><td class="f" style="width:4%">' . ($i + 1) . '</td><td class="f" style="width:30%"><b>' . self::e((string)$r['label']) . '</b></td><td class="f" style="width:28%">' . self::e((string)$r['doc_number'])
                  . '</td><td class="f" style="width:28%"><b>' . self::e((string)$r['code']) . '</b></td><td class="f" style="width:10%">obowiązuje</td></tr>';
        }
        $h .= '<table class="t">' . self::sec('B', 'DRUKI OBOWIĄZUJĄCE (zastępują dotychczasowe)', 5)
            . '<tr><td class="lb" style="padding:1mm"><b>Lp.</b></td><td class="lb" style="padding:1mm"><b>Dokument</b></td><td class="lb" style="padding:1mm"><b>Numer</b></td><td class="lb" style="padding:1mm"><b>Kod Code128 (stopka druku)</b></td><td class="lb" style="padding:1mm"><b>Stan</b></td></tr>'
            . ($rows ?: '<tr><td class="f" colspan="5">—</td></tr>') . '</table>';
        $vr = '';
        foreach ($void as $i => $r) {
            $vr .= '<tr><td class="f" style="width:4%">' . ($i + 1) . '</td><td class="f" style="width:30%">' . self::e((string)$r['label']) . '</td><td class="f" style="width:28%">' . self::e((string)$r['doc_number'])
                 . '</td><td class="f" style="width:28%">' . self::e((string)$r['code']) . '</td><td class="f" style="width:10%"><b>UNIEWAŻNIONY</b></td></tr>';
        }
        $h .= '<table class="t">' . self::sec('C', 'DRUKI UNIEWAŻNIONE (nie używać)', 5) . ($vr ?: '<tr><td class="f" colspan="5">—</td></tr>') . '</table>';
        $h .= '<table class="t">' . self::sec('D', 'ROZDZIELNIK I ZASADY', 1) . '<tr><td class="pc"><div class="pt">Pakiet (ZIP z hasłem) wysłano do kierownika; hasło przekazano osobnym SMS-em. Osoba, której dotyczy sprawa, otrzymuje pakiet po jego wysłaniu z karty korekty. '
            . 'Kod Code128 w stopce każdego druku zawiera rewizję — druk z niższą rewizją lub bez rewizji jest nieaktualny. Dokument wygenerowano elektronicznie w SZO.</div></td></tr></table>';
        return self::css() . $h;
    }

    // ── ASK-UP1: uzasadnienie rotacji licencji — druk do ODRĘCZNEGO podpisu kierownika (QR + Code128) ──────────────────

    /** $r — rotacja (LicenseRotation), $t — sprawa / operacja. */
    public function askUp1Html(array $r, array $t): string
    {
        $this->n = 0;
        $code = LicenseRotation::code((int)$r['id']);
        $number = LicenseRotation::docNumber($r);
        $h = '<table class="t"><tr>'
           . $this->fld('Numer dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="45%"')
           . $this->fld('Dotyczy sprawy', self::e((string)$r['target_number']) . ($r['scope'] === 'operation' ? ' (operacja)' : ' (sprawa)'), ' width="35%"')
           . '<td class="sym" width="20%" style="background:#14532d"><div class="symb">ASK-UP1</div><div class="symp">&nbsp;</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">UZASADNIENIE ROTACJI LICENCJI</div>'
           . '<div class="ttl2">zmiana użytkownika / konta licencji — wymaga ODRĘCZNEGO podpisu kierownika (dane osobowe)</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Kod:</b> ' . self::e($code) . '.&nbsp; <b>Status:</b> ' . self::e(LicenseRotation::statusLabel((string)$r['status'])) . '.&nbsp; Dokument uzasadnia rotację — samej zmiany nie wykonuje (robi to operacja AM22 / AM91).</td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'LICENCJA', 2) . '<tr>'
            . $this->fld('Licencja / usługa', self::val((string)$r['license_label'], 'licencja'), ' width="60%"')
            . $this->fld('Data rotacji', $r['rotation_date'] !== '' ? self::e(ldDate((string)$r['rotation_date'])) : self::val('', 'data rotacji'), ' width="40%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('B', 'ROTACJA: BYŁO → JEST', 2) . '<tr>'
            . $this->fld('Dotychczasowy użytkownik', self::val((string)$r['from_holder'], 'użytkownik'), ' width="50%"')
            . $this->fld('Nowy użytkownik', '<b>' . self::val((string)$r['to_holder'], 'użytkownik') . '</b>', ' width="50%"') . '</tr><tr>'
            . $this->fld('Dotychczasowy login (UPN)', self::val((string)$r['from_upn'], 'brak'), ' width="50%"')
            . $this->fld('Nowy login (UPN)', self::val((string)$r['to_upn'], 'brak'), ' width="50%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('C', 'UZASADNIENIE', 1) . '<tr><td class="pc"><div class="pt">' . nl2br(self::e((string)$r['reason'])) . '</div></td></tr>'
            . '<tr><td class="pc"><div class="lb">Wystawił(a): ' . self::e((string)$r['created_name']) . ', ' . self::e(ldDate(substr((string)$r['created_at'], 0, 10)) . ' ' . substr((string)$r['created_at'], 11, 5)) . '</div></td></tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('D', 'KONTROLA I POWIĄZANIE', 3) . '<tr>'
            . '<td class="f" style="width:22%;text-align:center"><div class="lb">Kod QR — status w SZO</div><barcode code="' . self::e(LicenseRotation::verifyUrl($r)) . '" type="QR" class="barcode" size="0.85" error="M" disableborder="1" /></td>'
            . '<td class="f" style="width:38%;text-align:center"><div class="lb">Code128 — kod uzasadnienia</div><barcode code="' . self::e($code) . '" type="C128B" size="1" height="1.1" /><div class="v">' . self::e($code) . '</div></td>'
            . '<td class="pc" style="width:40%"><div class="pt">Kod <b>' . self::e($code) . '</b> wpisz (zeskanuj) w polu „Skanuj kod” na pulpicie Licencji — otworzy kartę uzasadnienia. Kod QR prowadzi do strony ze statusem (bez danych osobowych).</div></td></tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('E', 'ZATWIERDZENIE — PODPIS ODRĘCZNY KIEROWNIKA', 2) . '<tr><td class="pc" colspan="2"><div class="pt">'
            . 'Potwierdzam zasadność rotacji licencji opisanej powyżej (dane osobowe). Podpis składam odręcznie; po podpisaniu druk należy zeskanować do teczki sprawy i potwierdzić w SZO.</div></td></tr><tr>'
            . $this->fld('Imię i nazwisko kierownika (czytelnie)', '', ' width="55%" style="height:20mm"') . $this->fld('Data i miejscowość', '', ' width="45%" style="height:20mm"') . '</tr><tr>'
            . $this->fld('Podpis odręczny kierownika', '', ' colspan="2" style="height:26mm"') . '</tr></table>';
        return self::css() . $h;
    }

    // ── PP (AM Pre-shared Protocol): protokół przekazania hasła do pakietu druków AM ───────────────────────────────

    /** $pk — pakiet, $c — korekta, $hand — przekazania haseł (license_pwd_handovers), $issues — druki pakietu. Dokument NIE zawiera hasła. */
    public function ppHtml(array $pk, array $c, array $hand, array $issues): string
    {
        $this->n = 0;
        $code = LicenseDocPackage::ppCode((int)$pk['id']);
        $number = (string)$c['target_number'] . ' · ' . $code . ' · PP';
        $h = '<table class="t"><tr>'
           . $this->fld('Numer dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="45%"')
           . $this->fld('Dotyczy sprawy', self::e((string)$c['target_number']), ' width="35%"')
           . '<td class="sym" width="20%" style="background:#1e3a8a"><div class="symb">PP</div><div class="symp">&nbsp;</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">PROTOKÓŁ PRZEKAZANIA HASŁA DO PAKIETU DRUKÓW AM</div>'
           . '<div class="ttl2">AM Pre-shared Protocol (PP) — hasło do archiwum ZIP przekazane osobnym kanałem (SMS); dokument nie zawiera hasła</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Protokół:</b> ' . self::e($code) . '.&nbsp; <b>Korekta:</b> ' . self::e(LicenseCorrection::code((int)$c['id'])) . '.</td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'PAKIET', 4) . '<tr>'
            . $this->fld('Rewizja druków', '<b>R' . (int)$pk['revision'] . '</b>', ' width="20%"')
            . $this->fld('Generowanie nr', (string)(int)$pk['gen_no'], ' width="20%"')
            . $this->fld('Wygenerowano', self::e(ldDate(substr((string)$pk['created_at'], 0, 10)) . ' ' . substr((string)$pk['created_at'], 11, 5)), ' width="30%"')
            . $this->fld('Wygenerował(a)', self::val((string)$pk['created_name'], 'osoba'), ' width="30%"') . '</tr></table>';
        $rows = '';
        foreach ($hand as $i => $r) {
            $res = ['sent' => 'wysłano', 'deferred' => 'odłożono (cisza nocna)', 'failed' => 'BŁĄD', 'skipped' => 'pominięto'][$r['result']] ?? $r['result'];
            $rows .= '<tr><td class="f" style="width:4%">' . ($i + 1) . '</td><td class="f" style="width:24%"><b>' . self::e($r['recipient_kind'] === 'manager' ? 'kierownik' : 'osoba') . '</b><br>' . self::e((string)$r['recipient_name'])
                  . '</td><td class="f" style="width:12%">' . self::e((string)$r['channel']) . ' ' . self::e((string)$r['phone_masked']) . '</td><td class="f" style="width:14%">' . self::e($r['kind'] === 'regenerated' ? 'nowe hasło (regeneracja)' : 'hasło do pakietu')
                  . '</td><td class="f" style="width:12%">' . self::e($res) . ($r['error'] !== '' ? '<div class="lb">' . self::e((string)$r['error']) . '</div>' : '') . '</td><td class="f" style="width:17%">'
                  . self::e(ldDate(substr((string)$r['created_at'], 0, 10)) . ' ' . substr((string)$r['created_at'], 11, 5)) . '<div class="lb">zlecił(a): ' . self::e((string)$r['actor_name']) . '</div></td><td class="f" style="width:17%"><span class="lb">SHA-256 ZIP</span><br>'
                  . self::e(substr((string)$r['zip_sha256'], 0, 16)) . '</td></tr>';
        }
        $h .= '<table class="t">' . self::sec('B', 'PRZEKAZANIA HASŁA (rejestr)', 7)
            . '<tr><td class="lb" style="padding:1mm"><b>Lp.</b></td><td class="lb" style="padding:1mm"><b>Odbiorca</b></td><td class="lb" style="padding:1mm"><b>Kanał / numer</b></td><td class="lb" style="padding:1mm"><b>Rodzaj</b></td><td class="lb" style="padding:1mm"><b>Wynik</b></td><td class="lb" style="padding:1mm"><b>Data i godzina</b></td><td class="lb" style="padding:1mm"><b>Archiwum</b></td></tr>'
            . ($rows ?: '<tr><td class="f" colspan="7">Brak zarejestrowanych przekazań hasła dla tego pakietu.</td></tr>') . '</table>';
        $ir = '';
        foreach ($issues as $i => $r) $ir .= '<tr><td class="f" style="width:4%">' . ($i + 1) . '</td><td class="f" style="width:36%">' . self::e((string)$r['label']) . '</td><td class="f" style="width:30%">' . self::e((string)$r['doc_number']) . '</td><td class="f" style="width:30%"><b>' . self::e((string)$r['code']) . '</b></td></tr>';
        $h .= '<table class="t">' . self::sec('C', 'DOKUMENTY W ARCHIWUM', 4) . ($ir ?: '<tr><td class="f" colspan="4">—</td></tr>') . '</table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('D', 'KONTROLA I POWIĄZANIE', 3) . '<tr>'
            . '<td class="f" style="width:22%;text-align:center"><div class="lb">Kod QR — status korekty w SZO</div><barcode code="' . self::e(LicenseCorrection::verifyUrl($c)) . '" type="QR" class="barcode" size="0.85" error="M" disableborder="1" /></td>'
            . '<td class="f" style="width:38%;text-align:center"><div class="lb">Code128 — kod protokołu</div><barcode code="' . self::e($code) . '" type="C128B" size="1" height="1.1" /><div class="v">' . self::e($code) . '</div></td>'
            . '<td class="pc" style="width:40%"><div class="pt">Kod <b>' . self::e($code) . '</b> wpisz (zeskanuj) w polu „Skanuj kod” na pulpicie Licencji — otworzy kartę korekty i pakiet. Hasło nie jest zapisywane w SZO ani w tym dokumencie.</div></td></tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('E', 'POTWIERDZENIE ODBIORU HASŁA', 2) . '<tr><td class="pc" colspan="2"><div class="pt">Potwierdzam odbiór hasła do archiwum ZIP z pakietem poprawionych dokumentów AM (rewizja R' . (int)$pk['revision'] . ') osobnym kanałem (SMS) i zobowiązuję się do nieprzekazywania go osobom nieupoważnionym.</div></td></tr><tr>'
            . $this->fld('Imię i nazwisko odbierającego (czytelnie)', '', ' width="55%" style="height:18mm"') . $this->fld('Data i miejscowość', '', ' width="45%" style="height:18mm"') . '</tr><tr>'
            . $this->fld('Podpis odbierającego', '', ' colspan="2" style="height:22mm"') . '</tr></table>';
        return self::css() . $h;
    }

    // ── AM56: spis dokumentów sprawy z QR do spisu elektronicznego i skrótem historii zmian ───────────────────────

    /** $rows — LicenseCaseIndex::documents, $hist — LicenseCaseIndex::changes (skrót), $scope/$id — cel. */
    public function am56Html(string $scope, int $id, string $targetNumber, string $person, array $rows, array $hist): string
    {
        $this->n = 0;
        $rev = LicenseCodes::revision($scope, $id);
        $caseCode = LicenseCodes::caseCode($scope, $id);
        $number = LicenseCaseIndex::number([], $targetNumber);
        $h = '<table class="t"><tr>'
           . $this->fld('Numer dokumentu', '<span class="big">' . self::e($number) . '</span>', ' width="45%"')
           . $this->fld('Dotyczy ' . ($scope === 'operation' ? 'operacji' : 'sprawy'), self::e($targetNumber), ' width="35%"')
           . '<td class="sym" width="20%" style="background:#0f4c5c"><div class="symb">AM56</div><div class="symp">&nbsp;</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">SPIS DOKUMENTÓW SPRAWY</div>'
           . '<div class="ttl2">wykaz dokumentów AM sprawy z kodami, rewizją i stanem — spis elektroniczny pod kodem QR</div></td></tr>'
           . '<tr><td class="lgd"><b>Wystawia:</b> ' . self::e(ldOrgName()) . '.&nbsp; <b>Osoba:</b> ' . self::e($person !== '' ? $person : '—') . '.&nbsp; <b>Rewizja druków:</b> R' . $rev . '.&nbsp; <b>Stan na:</b> ' . self::e(ldDate(date('Y-m-d')) . ' ' . date('H:i')) . '.</td></tr></table>';
        $body = ''; $grp = ''; $i = 0;
        foreach ($rows as $r) {
            if ($r['group'] !== $grp) { $grp = $r['group']; $body .= '<tr><td class="lb" colspan="6" style="padding:1mm;background:#eef2f3"><b>' . self::e($grp) . '</b></td></tr>'; }
            $i++;
            $body .= '<tr><td class="f" style="width:4%">' . $i . '</td><td class="f" style="width:27%"><b>' . self::e((string)$r['label']) . '</b></td><td class="f" style="width:22%">' . self::e((string)$r['number'])
                  . '</td><td class="f" style="width:23%">' . self::e((string)$r['code']) . '</td><td class="f" style="width:13%"><b>' . self::e((string)$r['state']) . '</b></td><td class="f" style="width:11%">'
                  . self::e($r['date'] !== '' ? ldDate(substr((string)$r['date'], 0, 10)) : '—') . '</td></tr>';
        }
        $h .= '<table class="t">' . self::sec('A', 'DOKUMENTY SPRAWY', 6)
            . '<tr><td class="lb" style="padding:1mm"><b>Lp.</b></td><td class="lb" style="padding:1mm"><b>Dokument</b></td><td class="lb" style="padding:1mm"><b>Numer</b></td><td class="lb" style="padding:1mm"><b>Kod</b></td><td class="lb" style="padding:1mm"><b>Stan</b></td><td class="lb" style="padding:1mm"><b>Data</b></td></tr>'
            . ($body ?: '<tr><td class="f" colspan="6">—</td></tr>') . '</table>';
        $hr = '';
        foreach (array_slice($hist, 0, 20) as $k => $e) {
            $hr .= '<tr><td class="f" style="width:4%">' . ($k + 1) . '</td><td class="f" style="width:14%">' . self::e(ldDate(substr((string)$e['at'], 0, 10)) . ' ' . substr((string)$e['at'], 11, 5)) . '</td><td class="f" style="width:18%"><b>' . self::e((string)$e['kind'])
                 . '</b></td><td class="f" style="width:46%">' . self::e((string)$e['title']) . '</td><td class="f" style="width:18%">' . self::e((string)$e['by']) . '</td></tr>';
        }
        $h .= '<table class="t">' . self::sec('B', 'HISTORIA ZMIAN (skrót: ostatnie 20 pozycji)', 5) . ($hr ?: '<tr><td class="f" colspan="5">Brak zmian — wniosek bez korekt.</td></tr>') . '</table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('C', 'SPIS ELEKTRONICZNY', 3) . '<tr>'
            . '<td class="f" style="width:22%;text-align:center"><div class="lb">Kod QR — spis elektroniczny</div><barcode code="' . self::e(LicenseCaseIndex::url($scope, $id)) . '" type="QR" class="barcode" size="0.85" error="M" disableborder="1" /></td>'
            . '<td class="f" style="width:38%;text-align:center"><div class="lb">Code128 — kod sprawy i spisu</div><barcode code="' . self::e(LicenseCodes::formCode($caseCode, 'AM56') . '/R' . $rev) . '" type="C128B" size="1" height="1.1" /><div class="v">' . self::e(LicenseCodes::formCode($caseCode, 'AM56') . '/R' . $rev) . '</div></td>'
            . '<td class="pc" style="width:40%"><div class="pt">Kod QR otwiera <b>spis elektroniczny</b> sprawy: aktualną listę dokumentów z kodami, rewizją i stanem (obowiązuje / unieważniony) — bez danych osobowych. Druk z niższą rewizją niż w spisie jest nieaktualny.</div></td></tr></table>';
        return self::css() . $h;
    }

    // ── Okładka sprawy AM (teczka): numer sprawy, osoba, licencja, spis zawartości, QR do spisu elektronicznego i Code128 ──────

    /** $t — sprawa / operacja, $rows — LicenseCaseIndex::documents (zawartość teczki). */
    public function coverHtml(string $scope, int $id, string $targetNumber, array $t, array $rows): string
    {
        $this->n = 0;
        $isOp = $scope === 'operation';
        $rev = LicenseCodes::revision($scope, $id);
        $caseCode = LicenseCodes::caseCode($scope, $id);
        $code = LicenseCodes::formCode($caseCode, 'AM-OK') . '/R' . $rev;
        $person = (string)($isOp ? ($t['account_name'] ?? '') : ($t['interested_name'] ?? ''));
        if ($isOp) {
            $f = $t['formEnum'];
            $what = $f->value . ' — ' . $f->title(); $status = $t['statusEnum']->label(); $period = ''; $course = (string)($t['course_name'] ?? ''); $licensor = '';
        } else {
            $d = $t['decision'] ?? [];
            $what = trim((string)(($d['plan'] ?? '') ?: ($t['request']['plan'] ?? '') ?: $t['title']));
            $status = ($t['poolStatus']?->label() ?? $t['requestStatus']->label());
            $period = trim(ldDate((string)$t['period_from']) . ' — ' . ldDate((string)$t['period_to']), ' —');
            $course = (string)$t['course_name']; $licensor = (string)$t['licensor_name'];
        }
        $qr = '<barcode code="' . self::e(LicenseCaseIndex::url($scope, $id)) . '" type="QR" class="barcode" size="1.15" error="M" disableborder="1" />';
        // Wiersz 1: tytuł + numer sprawy + osoba (lewa kolumna), QR (prawa); symbol dokumentu w rogu
        $h = '<table class="t"><tr>'
           . '<td class="f" style="width:72%;padding:4mm 5mm"><div class="lb">' . ($isOp ? 'OPERACJA NA LICENCJI' : 'SPRAWA — LICENCJE I OPROGRAMOWANIE') . ' · OKŁADKA TECZKI AM · rewizja druków R' . $rev . '</div>'
           . '<div style="font-size:21pt;font-weight:bold;margin:2mm 0 1mm">' . self::e($targetNumber) . '</div>'
           . '<div style="font-size:13pt">' . self::e($person !== '' ? $person : '—') . '</div>'
           . '<div class="lb" style="margin-top:2mm">Stan: <b>' . self::e($status) . '</b></div></td>'
           . '<td class="f" style="width:28%;text-align:center;padding:3mm"><div class="lb">QR — spis elektroniczny</div>' . $qr . '</td></tr></table>';
        // Wiersz 2: Code128 na całą szerokość (czytelny z biurka)
        $h .= '<table class="t"><tr><td class="f" style="text-align:center;padding:3mm"><barcode code="' . self::e($code) . '" type="C128B" size="1.4" height="1.5" />'
            . '<div class="v" style="letter-spacing:1px">' . self::e($code) . '</div></td></tr></table>';
        // Wiersz 3: dwie kolumny — dane sprawy | zawartość teczki
        $left = '<div class="lb"><b>A. DANE SPRAWY</b></div>'
            . '<div class="lb" style="margin-top:2mm">Licencja / usługa</div><div class="v">' . self::val($what, 'licencja') . '</div>'
            . '<div class="lb" style="margin-top:2mm">Okres</div><div class="v">' . ($period !== '' ? self::e($period) : self::val('', 'okres')) . '</div>'
            . '<div class="lb" style="margin-top:2mm">Kurs / zajęcia</div><div class="v">' . self::val($course, 'kurs') . '</div>'
            . ($licensor !== '' ? '<div class="lb" style="margin-top:2mm">Licencjodawca / podmiot</div><div class="v">' . self::e($licensor) . '</div>' : '')
            . '<div class="lb" style="margin-top:3mm">Kod wpisz (zeskanuj) w „Skanuj kod” na pulpicie Licencji. Druki z rewizją niższą niż R' . $rev . ' są nieaktualne.</div>';
        $right = '<div class="lb"><b>B. ZAWARTOŚĆ TECZKI (obowiązujące dokumenty)</b></div><table class="t" style="margin-top:1mm">'; $i = 0;
        foreach ($rows as $r) {
            if ($r['state'] === 'UNIEWAŻNIONY') continue;
            $i++;
            $right .= '<tr><td class="f" style="width:6%">' . $i . '</td><td class="f" style="width:94%"><b>' . self::e((string)$r['label']) . '</b><div class="lb">' . self::e((string)$r['number']) . ' · ' . self::e((string)$r['state']) . '</div></td></tr>';
        }
        $right .= ($i === 0 ? '<tr><td class="f" colspan="2">—</td></tr>' : '') . '</table>';
        $h .= '<table class="t"><tr><td class="f" style="width:38%;vertical-align:top;padding:3mm 4mm">' . $left . '</td><td class="f" style="width:62%;vertical-align:top;padding:3mm 4mm">' . $right . '</td></tr></table>';
        // Wiersz 4: uwagi | założenie teczki
        $h .= '<table class="t" style="page-break-inside:avoid"><tr>'
            . $this->fld('Uwagi', '', ' style="width:62%;height:34mm"')
            . '<td class="f" style="width:38%;padding:0"><table class="t" style="border:0"><tr>' . $this->fld('Teczkę założył(a) — imię, nazwisko, data', '', ' style="height:17mm;border-left:0;border-top:0;border-right:0"') . '</tr><tr>'
            . $this->fld('Miejsce przechowywania / sygnatura teczki', '', ' style="height:17mm;border-left:0;border-bottom:0;border-right:0"') . '</tr></table></td></tr></table>';
        return self::css() . $h;
    }

    public function blankHtml(LicenseDoc $doc): string
    {
        $l = ['id' => 0, 'case_number' => '', 'created_at' => date('Y'), 'title' => '', 'work_description' => '', 'course_name' => '', 'licensor_name' => '', 'licensor_email' => '',
              'licensor_phone' => '', 'licensor_address' => '', 'applicant_name' => '', 'applicant_contact' => '', 'interested_name' => '', 'period_from' => '', 'period_to' => '',
              'territory' => '', 'request' => [], 'decision' => ['grant' => $doc !== LicenseDoc::Am7Refusal], 'request_status' => 'draft', 'due_on' => '', 'decided_via' => '', 'decided_at' => '', 'started_at' => ''];
        return match ($doc) {
            LicenseDoc::Am5Request => $this->requestHtml($l),
            LicenseDoc::Am7Request => $this->am7RequestHtml($l),
            LicenseDoc::Am7Grant, LicenseDoc::Am7Refusal => $this->am7DecisionHtml($l),
            default => $this->declarationHtml($doc, $l, ['id' => 0, 'status' => ''], [], null),
        };
    }


    // ── Rejestr operacji: AM11, AM12, AM13, AM20–AM24 (druk w układzie CIT) ─────────────

    /** Wartość pola formularza operacji do druku (select → etykieta, money → zł, date → dd.mm.rrrr, account → NRB). */
    private static function opVal(array $def, string $v): string
    {
        [, $type, , $opts] = $def + [null, 'text', false, null];
        if ($v === '') return '';
        return match ($type) {
            'select' => (string)(($opts[$v] ?? $v)),
            'money'  => number_format((float)$v, 2, ',', ' ') . ' zł',
            'date'   => ldDate($v),
            'account' => LicenseOperationService::nrb($v),
            'check'  => 'tak',
            default  => $v,
        };
    }

    /** Pola w dwóch kolumnach (textarea na całą szerokość). */
    private function opRows(array $fields, array $data, bool $dark, array $skip = []): string
    {
        $h = ''; $row = [];
        foreach ($fields as $k => $def) {
            if (in_array($k, $skip, true) || $def[1] === 'check') continue;
            $cell = $this->fld(self::e($def[0]), self::val(self::opVal($def, (string)($data[$k] ?? '')), mb_strtolower($def[0])), $def[1] === 'textarea' ? ' colspan="2"' : ' width="50%"', $dark);
            if ($def[1] === 'textarea') { if ($row) { $h .= '<tr>' . implode('', $row) . '<td class="f"' . ($dark ? ' style="background:#e6e6e6"' : '') . '>&nbsp;</td></tr>'; $row = []; } $h .= '<tr>' . $cell . '</tr>'; continue; }
            $row[] = $cell;
            if (count($row) === 2) { $h .= '<tr>' . implode('', $row) . '</tr>'; $row = []; }
        }
        if ($row) $h .= '<tr>' . $row[0] . '<td class="f"' . ($dark ? ' style="background:#e6e6e6"' : '') . '>&nbsp;</td></tr>';
        return $h;
    }

    public function operationHtml(array $op): string
    {
        $this->n = 0;
        $f = $op['formEnum'];
        $st = $op['statusEnum'];
        $r = $op['request'];
        $d = $op['decision'];
        $state = $op['state'];
        $stampBox = fn(?string $who, ?string $at, ?string $ip, string $label) => $at ? '<div class="esig">' . self::e($label) . ': ' . self::e((string)$who) . ', '
            . self::e(ldDate(substr((string)$at, 0, 10)) . ' ' . substr((string)$at, 11, 5)) . ($ip ? ', IP ' . self::e($ip) : '') . '</div>' : '';
        // Nagłówek: numer sprawy, SubID, symbol formularza
        $h = '<table class="t"><tr>'
           . $this->fld('Numer sprawy', '<span class="big">' . self::e($op['number']) . '</span>', ' width="52%"')
           . $this->fld('Kurs / projekt', self::e($op['course_name'] ?: '—'), ' width="28%"')
           . '<td class="sym" width="20%" style="background:' . self::e($f->color()) . '"><div class="symb">' . self::e($f->value) . '</div><div class="symp">' . ($f->lifecycle() ? 'GEN' : '&nbsp;') . '</div><div class="symv">wersja wzoru ' . self::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">' . self::e(mb_strtoupper(($f->lifecycle() ? '' : 'FORMULARZ ' . $f->value . ' — ') . $f->title())) . '</div>'
           . '<div class="ttl2">licencje do oprogramowania i usług cyfrowych — tryb decyzji cyfrowej (stempel: ID, czas, IP)</div></td></tr>'
           . '<tr><td class="lgd"><b>Składa:</b> ' . self::e($op['created_name']) . '.&nbsp; <b>Wypełnia:</b> pola jasne — wnioskodawca, pola ciemne — ' . ($f->needsApproval() ? 'zatwierdzający' : 'system (bez akceptacji)')
           . '.&nbsp; <b>Termin realizacji:</b> do ' . LD_REALIZATION_DAYS . ' dni (do ' . self::e(ldDate($op['due_on'])) . ').&nbsp; <b>Stan:</b> ' . self::e($st->label()) . '.</td></tr></table>';
        // A. Osoba i konto
        $h .= '<table class="t">' . self::sec('A', 'OSOBA I KONTO', 2) . '<tr>'
            . $this->fld('Imię i nazwisko', self::e($op['account_name']), ' width="50%"')
            . $this->fld('Rola w systemie / ID konta SZO', self::e($op['account_role'] . ' · ' . ($op['account_kind'] === 'client' ? 'uczestnik' : 'konto') . ' #' . (int)$op['account_id']), ' width="50%"')
            . '</tr><tr>' . $this->fld('Identyfikator / login / UPN', self::val((string)$op['account_login'], 'login'), ' width="50%"')
            . $this->fld('SubID (nr kursu / nr kursanta)', self::e(implode('/', array_slice(explode('/', (string)$op['number']), -2))), ' width="50%"') . '</tr></table>';
        $L = 'B';
        if ($state) {
            $h .= '<table class="t">' . self::sec($L, 'LICENCJA — STAN OBECNY', 2) . '<tr>'
                . $this->fld('Dostawca / producent', self::e($state['vendor'] ?? ''), ' width="50%"')
                . $this->fld('Produkt / SKU', self::e($state['sku'] ?? ''), ' width="50%"')
                . '</tr><tr>' . $this->fld('Typ licencji', self::e($state['type'] ?? ''), ' width="50%"')
                . $this->fld('Okres (aktywacja — wygaśnięcie)', self::e(trim(ldDate($state['assigned_at'] ?? '') . ' — ' . ldDate($state['expires_at'] ?? ''), ' —')), ' width="50%"')
                . '</tr><tr>' . $this->fld('Obecny płatnik / źródło finansowania', self::e(trim(($state['payer'] ?? '') . ' (' . ($state['funding_source'] ?? '') . ')')), ' width="50%"')
                . $this->fld('Pozycja budżetowa / projekt', self::val((string)($state['budget_position_id'] ?? ''), 'pozycja budżetowa'), ' width="50%"') . '</tr></table>';
            $L = 'C';
        }
        // Wniosek
        $h .= '<table class="t">' . self::sec($L, $f->lifecycle() ? 'DYSPOZYCJA' : 'WNIOSEK', 2) . $this->opRows($f->requestFields(), $r, false, $f === OperationForm::Am20 && ($r['tryb'] ?? '') === 'zajecia' ? ['rachunek'] : [])
            . ($f === OperationForm::Am11 ? '<tr><td class="pc" colspan="2"><div class="pt"><b>Źródło finansowania:</b> Fundacja FEER — płatność wymaga potwierdzenia dostępności budżetu.</div></td></tr>' : '')
            . ($f === OperationForm::Am12 && $op['proof_name'] !== '' ? '<tr>' . $this->fld('Załączony plik dowodu', self::e($op['proof_name']), ' colspan="2"') . '</tr>' : '')
            . '<tr><td class="pc" colspan="2"><div class="pt">' . self::e($f->statement()) . '</div></td></tr><tr>'
            . $this->fld('Podpis wnioskodawcy (decyzja cyfrowa)', self::e($op['created_name']) . $stampBox($op['created_name'], $op['created_at'], $op['created_ip'], 'Złożono elektronicznie w SZO'), ' colspan="2" style="height:12mm"')
            . '</tr></table>';
        $L = chr(ord($L) + 1);
        // Weryfikacja i decyzja (pola ciemne)
        if ($f->needsApproval()) {
            $decided = in_array($st, [OperationStatus::Approved, OperationStatus::Executed, OperationStatus::Rejected], true);
            $grant = $decided && !empty($d['grant']);
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec($L, 'WERYFIKACJA I DECYZJA ' . $f->value, 2, '(wypełnia zatwierdzający)')
                . $this->opRows($f->decisionFields(), $d, true)
                . '<tr>' . $this->fld('Decyzja', self::box($grant) . ' ' . ($f->lifecycle() ? 'zatwierdzono' : 'przyznano') . ' &nbsp;&nbsp; ' . self::box($decided && !$grant) . ' odmowa', ' colspan="2"', true) . '</tr>'
                . '<tr>' . $this->fld('Osoba zatwierdzająca, data i podpis', $decided ? self::e($op['decided_name']) . $stampBox($op['decided_name'], $op['decided_at'], $op['decided_ip'], 'Zatwierdzono elektronicznie w SZO') : '', ' width="60%" style="height:14mm"', true)
                . $this->fld('Realizacja', self::e(($op['execution_mode'] === 'AUTOMATIC_WEBHOOK_API' ? 'automatycznie w SZO' : ($op['execution_mode'] !== '' ? 'zadanie dla IT / finansów' : '—')) . ($op['helpdesk_number'] !== '' ? ' · zgłoszenie ' . $op['helpdesk_number'] : '')), ' width="40%"', true)
                . '</tr></table>';
        } else {
            // AM20 — bez akceptacji: rejestracja zmiany płatnika
            $pay = ($r['tryb'] ?? '') === 'zajecia'
                ? 'doliczenie do opłaty za zajęcia: <b>+' . self::e(number_format((float)($r['kwota'] ?? 0), 2, ',', ' ')) . ' zł</b> (od ' . self::e(ldDate($r['data_zmiany'] ?? '')) . ')'
                : 'wpłata <b>' . self::e(number_format((float)($r['kwota'] ?? 0), 2, ',', ' ')) . ' zł</b> na rachunek <b>' . self::e(LicenseOperationService::nrb((string)($r['rachunek'] ?? ''))) . '</b>, tytuł: ' . self::e($op['number']);
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec($L, 'REJESTRACJA ZMIANY PŁATNIKA', 2, '(tryb bez akceptacji)')
                . '<tr>' . $this->fld('Zatwierdzony płatnik i sposób rozliczenia', self::e(($r['nowy_platnik'] ?? '') . ' (' . ($r['nowe_zrodlo'] ?? '') . ')') . '<br>' . $pay, ' colspan="2"', true) . '</tr>'
                . '<tr>' . $this->fld('Licencjobiorca, użytkownik i właściciel konta po zmianie', 'pozostają bez zmian', ' width="60%"', true)
                . $this->fld('Data wejścia zmiany w życie', self::e(ldDate($r['data_zmiany'] ?? '')), ' width="40%"', true) . '</tr>'
                . '<tr><td class="pc" colspan="2" style="background:#e6e6e6"><div class="pt"><b>Potwierdzenie skutku:</b> ' . self::e($f->effect()) . '</div></td></tr>'
                . '<tr>' . $this->fld('Potwierdzenie wnioskodawcy', !empty($op['signed_at']) ? self::e($op['signed_name']) . $stampBox($op['signed_name'], $op['signed_at'], $op['signed_ip'], 'Potwierdzono elektronicznie przez link') : '<span class="ph">czeka na potwierdzenie przez link</span>', ' colspan="2" style="height:12mm"', true) . '</tr>'
                . '<tr>' . $this->fld('Rejestracja', $stampBox($op['created_name'], $op['created_at'], $op['created_ip'], 'Złożono w SZO (bez akceptacji kierownika)') . ($op['execution_notes'] ? '<div class="esig">' . self::e($op['execution_notes']) . '</div>' : ''), ' colspan="2" style="height:12mm"', true) . '</tr></table>';
        }
        if ($f === OperationForm::Am20) $h .= '<pagebreak />' . $this->am20AnnexHtml($op);
        return self::css() . $h;
    }

    /** Sam załącznik AM20-1 (wydruk osobny / pusty wzór do obiegu papierowego). */
    public function am20AnnexOnlyHtml(array $op): string
    {
        $this->n = 0;
        return self::css() . $this->am20AnnexHtml($op);
    }

    /** AM20-1 — wykaz dokumentów księgowych wystawionych dotąd przez FEER w związku z opłatą za licencję. */
    private function am20AnnexHtml(array $op): string
    {
        $r = $op['request'];
        $rows = (array)($r['am20_1'] ?? []);
        $sum = LicenseResources::accountingSummary($rows);
        $h = '<table class="t"><tr>'
           . $this->fld('Numer sprawy', '<span class="big">' . self::e($op['number']) . '</span>', ' width="52%"')
           . $this->fld('Produkt i licencja', self::e((string)($op['state']['sku'] ?? $r['produkt'] ?? '')), ' width="28%"')
           . '<td class="sym" width="20%" style="background:' . self::e(OperationForm::Am20->color()) . '"><div class="symb">AM20</div><div class="symp">-1</div><div class="symv">załącznik do AM20</div></td></tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">WYKAZ DOKUMENTÓW KSIĘGOWYCH</div><div class="ttl1">WYSTAWIONYCH DOTYCHCZAS PRZEZ FEER</div>'
           . '<div class="ttl2">w związku z opłatą za licencję — załącznik AM20-1 do wniosku o zmianę sposobu płatności</div></td></tr>'
           . '<tr><td class="lgd"><b>Licencjobiorca / użytkownik:</b> ' . self::e($op['account_name']) . '.&nbsp; <b>Obecny płatnik:</b> ' . self::e((string)($r['obecny_platnik'] ?? '')) . '.&nbsp; <b>Nowy płatnik od</b> '
           . self::e(ldDate($r['data_zmiany'] ?? '')) . ': ' . self::e((string)($r['nowy_platnik'] ?? '')) . '.</td></tr></table>';
        $body = '';
        if (!$rows && (string)($op['number'] ?? '') === '') {
            // pusty wzór do druku: wiersze do wypełnienia ręcznie
            for ($i = 1; $i <= 14; $i++) $body .= '<tr><td class="f" style="text-align:center;height:7mm">' . $i . '</td><td class="f"></td><td class="f"></td><td class="f"></td><td class="f"></td></tr>';
        } elseif (!$rows) {
            $body = '<tr><td class="pc" colspan="5"><div class="pt">' . (!empty($r['am20_1_brak']) ? 'Fundacja FEER nie wystawiała dotąd dokumentów księgowych w związku z opłatą za tę licencję.' : '<span class="ph">brak pozycji</span>') . '</div></td></tr>';
        }
        foreach ($rows as $i => $d) {
            $body .= '<tr><td class="f" style="text-align:center">' . ($i + 1) . '</td><td class="f">' . self::e($d['typ']) . '</td><td class="f"><b>' . self::e($d['numer']) . '</b></td>'
                   . '<td class="f">' . self::e(ldDate($d['data'])) . '</td><td class="f" style="text-align:right">' . ($d['kwota'] !== '' ? self::e(number_format((float)$d['kwota'], 2, ',', ' ')) . ' zł' : '—') . '</td></tr>';
        }
        $h .= '<table class="t">' . self::sec('A', 'DOKUMENTY KSIĘGOWE', 5, '(faktury, faktury zbiorcze, korekty, rozliczenia)')
            . '<tr><td class="lb" style="padding:1mm;width:7%"><b>Lp.</b></td><td class="lb" style="padding:1mm;width:23%"><b>Rodzaj dokumentu</b></td><td class="lb" style="padding:1mm;width:32%"><b>Numer</b></td>'
            . '<td class="lb" style="padding:1mm;width:16%"><b>Data wystawienia</b></td><td class="lb" style="padding:1mm;width:22%;text-align:right"><b>Kwota brutto</b></td></tr>' . $body . '</table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('B', 'PODSUMOWANIE', 4) . '<tr>'
            . $this->fld('Liczba dokumentów', self::e((string)$sum['count']), ' width="20%"')
            . $this->fld('Łączna kwota', self::e(number_format($sum['sum'], 2, ',', ' ') . ' zł'), ' width="26%"')
            . $this->fld('Data pierwszej faktury', self::val(ldDate($sum['first_invoice']), 'brak faktury'), ' width="27%"')
            . $this->fld('Data ostatniej faktury', self::val(ldDate($sum['last_invoice']), 'brak faktury'), ' width="27%"')
            . '</tr><tr>' . $this->fld('Sporządził(a) wykaz', self::e($op['created_name']) . '<div class="esig">Złożono elektronicznie w SZO: ' . self::e(ldDate(substr((string)$op['created_at'], 0, 10)) . ' ' . substr((string)$op['created_at'], 11, 5)) . '</div>', ' colspan="2"')
            . $this->fld('Potwierdzenie wnioskodawcy', !empty($op['signed_at']) ? self::e($op['signed_name']) . '<div class="esig">Potwierdzono elektronicznie przez link: ' . self::e(ldDate(substr((string)$op['signed_at'], 0, 10)) . ' ' . substr((string)$op['signed_at'], 11, 5)) . ', IP ' . self::e($op['signed_ip']) . '</div>' : '<span class="ph">czeka na potwierdzenie</span>', ' colspan="2"')
            . '</tr></table>';
        return $h;
    }


    // ── AMZ — zestawienie licencji i usług na koncie kursanta ───────────────────

    public function amzHtml(array $st): string
    {
        $this->n = 0;
        $p = $st['person'];
        $h = '<table class="t"><tr>'
           . $this->fld('Numer zestawienia', '<span class="big">' . self::e($st['number']) . '</span>', ' width="44%"')
           . $this->fld('Stan na dzień', self::e(ldDate(substr($st['generated_at'], 0, 10)) . ' ' . substr($st['generated_at'], 11, 5)), ' width="36%"')
           . '<td class="sym" width="20%" style="background:#0050ef"><div class="symb">AMZ</div><div class="symv">zestawienie</div></td></tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">ZESTAWIENIE LICENCJI I USŁUG NA KONCIE KURSANTA</div>'
           . '<div class="ttl2">oprogramowanie, materiały dydaktyczne, Microsoft 365, kursy, egzaminy, domeny, hosting i inne usługi</div></td></tr></table>';
        $h .= '<table class="t">' . self::sec('A', 'DANE IDENTYFIKACYJNE KURSANTA', 2) . '<tr>'
            . $this->fld('Imię i nazwisko', self::e($p['name']), ' width="50%"')
            . $this->fld('Identyfikator (ID uczestnika / login / e-mail)', self::e('#' . $p['id'] . ($p['login'] !== '' ? ' · ' . $p['login'] : '') . ($p['email'] !== '' ? ' · ' . $p['email'] : '')), ' width="50%"')
            . '</tr><tr>' . $this->fld('Organizacja / firma (licencje grupowe)', self::val((string)$p['organization'], 'nie dotyczy'), ' width="50%"')
            . $this->fld('Kurs / grupa', self::val((string)$p['course_name'], 'brak'), ' width="50%"') . '</tr></table>';
        $rows = '';
        $i = 0;
        $group = '';
        foreach ($st['items'] as $it) {
            if ($it['group'] !== $group) { $group = $it['group']; $rows .= '<tr><td class="sub" colspan="6" style="background:#d9d9d9;padding:0.8mm 1.6mm"><b>' . self::e($group) . '</b></td></tr>'; }
            $i++;
            $period = trim(ldDate($it['from']) . ' — ' . ($it['to'] !== '' ? ldDate($it['to']) . ', 23:59' : 'bezterminowo'), ' —');
            $usage = $it['usage'] . ($it['progress'] !== null ? ($it['usage'] !== '' ? '; ' : '') . 'postęp ' . $it['progress'] . '%' : '');
            $extra = array_filter([$it['license_key'] !== '' ? 'klucz: ' . $it['license_key'] : '', $it['renewal']]);
            $rows .= '<tr><td class="f" style="text-align:center">' . $i . '</td>'
                . '<td class="f"><b>' . self::e($it['name']) . '</b><div class="lb">' . self::e($it['vendor']) . '</div></td>'
                . '<td class="f">' . self::e($it['variant']) . '</td>'
                . '<td class="f" style="white-space:nowrap"><b>' . self::e($it['status']) . '</b></td>'
                . '<td class="f">' . self::e($period) . '</td>'
                . '<td class="f">' . self::e($usage !== '' ? $usage : '—') . ($extra ? '<div class="lb">' . self::e(implode(' · ', $extra)) . '</div>' : '') . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td class="pc" colspan="6">Na koncie nie ma przypisanych licencji ani usług.</td></tr>';
        $h .= '<table class="t">' . self::sec('B', 'WYKAZ PRZYPISANYCH LICENCJI, DOSTĘPÓW I USŁUG', 6)
            . '<tr><td class="lb" style="padding:1mm;width:4%"><b>Lp.</b></td><td class="lb" style="padding:1mm;width:25%"><b>Nazwa licencji / dostępu</b></td><td class="lb" style="padding:1mm;width:15%"><b>Typ / wariant</b></td>'
            . '<td class="lb" style="padding:1mm;width:14%"><b>Status</b></td><td class="lb" style="padding:1mm;width:17%"><b>Okres obowiązywania</b></td><td class="lb" style="padding:1mm;width:25%"><b>Zużycie i limity</b></td></tr>' . $rows . '</table>';
        $sum = [];
        foreach ($st['counts'] as $k => $v) $sum[] = $k . ': ' . $v;
        $certs = $st['certs'] ? implode('; ', array_map(fn($c) => $c['title'] . ' (' . ldDate(substr((string)$c['created_at'], 0, 10)) . ')', $st['certs'])) : '';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('C', 'PODSUMOWANIE, CERTYFIKATY I AKCJE', 2) . '<tr>'
            . $this->fld('Liczba pozycji wg statusu', self::e($sum ? implode(' · ', $sum) : '—'), ' colspan="2"') . '</tr><tr>'
            . $this->fld('Certyfikaty i zaświadczenia (do pobrania w panelu kursanta › Dokumenty)', self::val($certs, 'brak'), ' colspan="2"') . '</tr><tr>'
            . $this->fld('Przejście do kursów, materiałów i kluczy licencyjnych', self::e($st['portal']), ' width="60%"')
            . $this->fld('Przedłużenie / odnowienie', self::e('przez biuro ' . ldOrgName() . ' (wniosek AM24) albo portal płatności'), ' width="40%"') . '</tr>'
            . '<tr><td class="pc" colspan="2"><div class="st2">Status „Wygasająca” oznacza koniec obowiązywania w ciągu ' . AmzStatement::SOON_DAYS . ' dni. Klucze licencyjne są w zestawieniu zamaskowane — pełny klucz jest widoczny po zalogowaniu do panelu kursanta. Zestawienie ma charakter informacyjny; stan na dzień i godzinę wydruku.</div></td></tr></table>';
        return self::css() . $h;
    }

    private static function css(): string
    {
        return '<style>
          body{font-family:dejavusans;font-size:8.6pt;color:#000}
          table.t{width:100%;border-collapse:collapse;margin:0}
          table.t td{border:0.25mm solid #000;vertical-align:top}
          td.f{padding:0.6mm 1.4mm 0.9mm;background:#fff}
          td.pc{padding:1mm 1.6mm 1.2mm;background:#fff}
          .pt{font-size:8.6pt;text-align:justify;line-height:1.35}
          .lb{font-size:6.4pt;color:#222;margin-bottom:0.4mm}
          .v{font-size:9pt;font-weight:bold}
          .ph{font-weight:normal;font-size:7pt;color:#666;font-style:italic}
          .dots{border-bottom:0.2mm dotted #000;height:4mm}
          .big{font-size:10.5pt;letter-spacing:0.2mm}
          td.sec{background:#bfbfbf;padding:1mm 1.6mm;font-size:8.6pt;letter-spacing:0.15mm}
          .hint{font-weight:normal;font-size:7pt}
          td.sym{background:#1ba1e2;color:#fff;text-align:center;vertical-align:middle}
          .symb{font-size:22pt;font-weight:bold;letter-spacing:0.5mm}
          .symp{font-size:11pt;font-weight:bold}
          .symv{font-size:6.5pt}
          td.ttl{text-align:center;padding:1.6mm 2mm;background:#fff}
          .ttl1{font-size:12pt;font-weight:bold;letter-spacing:0.2mm}
          .ttl2{font-size:7.6pt;margin-top:0.8mm}
          td.lgd{font-size:6.8pt;padding:1mm 1.6mm;background:#f2f2f2}
          .bx{font-size:10pt;font-family:dejavusans}
          .st{font-size:7.6pt;font-weight:normal}
          .st2{font-size:8.2pt;text-align:justify}
          .esig{font-size:6.6pt;font-weight:normal;color:#333;margin-top:1mm}
        </style>';
    }

    /**
     * Render do pliku: ['bytes' => …, 'mime' => 'application/pdf'|'text/html', 'ext' => 'pdf'|'html'].
     * Fallback na HTML, gdy mPDF niedostępny lub zawiedzie — dokument i tak trafia do teczki (z tą samą treścią i skrótem).
     */
    public static function render(string $html, string $title, string $number, string $symbol = LD_SYMBOL): array
    {
        try {
            $auto = dirname(__DIR__, 3) . '/vendor/autoload.php';
            if (!is_file($auto)) throw new RuntimeException('brak vendor/autoload.php');
            require_once $auto;
            if (!class_exists(\Mpdf\Mpdf::class)) throw new RuntimeException('brak mPDF');
            $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
            $code = LicenseCodes::forNumber($number, $symbol);   // Code128 łączący formularze jednej sprawy (skan → sprawa)
            $qr = '';   // QR do publicznego eSpisu koszulki EZD sprawy (gdy koszulka już istnieje)
            if ($code !== '' && is_file(dirname(__DIR__, 2) . '/espis/lib/Espis.php')) { require_once dirname(__DIR__, 2) . '/espis/lib/Espis.php'; $qr = Espis::qrForAmCode($code); }
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 8, 'margin_bottom' => $code !== '' ? ($qr !== '' ? 25 : 21) : 13,
                                    'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
            $mpdf->SetTitle($title);
            $mpdf->SetAuthor(ldOrgName());
            $mpdf->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr>'
                . '<td width="33%"><b>' . htmlspecialchars($symbol) . '</b> (' . self::VERSION . ')</td>'
                . '<td width="34%" style="text-align:center">' . htmlspecialchars($number) . '</td>'
                . '<td width="33%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr>'
                . ($code !== '' ? '<tr><td colspan="3" style="text-align:right;font-size:6pt"><table align="right" style="border:0"><tr><td style="text-align:right;vertical-align:bottom;font-size:6pt"><barcode code="' . htmlspecialchars($code) . '" type="C128B" size="0.75" height="0.55" /><br>' . htmlspecialchars($code) . '<br><span style="font-size:5pt">Sprawdź dokument: ' . htmlspecialchars(preg_replace('#^https?://#', '', rtrim(APP_URL, '/'))) . '/espis/</span></td>'
                    . ($qr !== '' ? '<td style="vertical-align:bottom;padding-left:2mm">' . $qr . '<div style="font-size:5pt;text-align:center">eSpis</div></td>' : '') . '</tr></table></td></tr>' : '')
                . '</table>');
            $mpdf->WriteHTML($html);
            return ['bytes' => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (\Throwable $e) {
            error_log('am5 pdf fallback html: ' . $e->getMessage());
            $doc = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
                 . '<style>body{font-family:DejaVu Sans,Arial,sans-serif;max-width:190mm;margin:10mm auto}</style></head><body>' . $html
                 . '<p style="font-size:7pt">' . htmlspecialchars($symbol) . ' (' . self::VERSION . ') · ' . htmlspecialchars($number) . ' · wersja HTML (PDF niedostępny)</p></body></html>';
            return ['bytes' => $doc, 'mime' => 'text/html', 'ext' => 'html'];
        }
    }
}
