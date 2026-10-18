<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseDocument.php — wydruki wzorów AM5 (licencja dydaktyczna) i AM7 (Office 365) w układzie urzędowego
 * formularza (jak OKK-1 / deklaracja CIT-8): symbol AM5 w kaflu nagłówka, sekcje z szarymi belkami, numerowane pola
 * w ramkach, kratki ☒/☐, pola jasne (składający) i ciemne (wypełnia licencjodawca / Fundacja), stopka z symbolem i numerem.
 *
 *   AM5/1 — Wniosek o udzielenie licencji na korzystanie z materiałów w celach dydaktycznych + Decyzja licencjodawcy
 *   AM5/2 — Oświadczenie licencjobiorcy – kursanta
 *   AM5/3 — Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu materiałów
 *   AM7/1 — Wniosek o udostępnienie licencji Office 365 do celów dydaktycznych (podpis kursanta w portalu)
 *   AM7/2 — Decyzja o przyznaniu licencji lub dostępu do Office 365 (PDF wysyłany kursantowi)
 *   AM7/3 — Decyzja o odmowie (PDF wysyłany kursantowi)
 *   AM7/4 — Krótkie oświadczenie licencjobiorcy — kursanta
 *   AM7/5 — Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu konta
 * Tenant Microsoft 365 jest tenantem ośrodka (nie szkolnym) — teksty wzorów AM7 mówią o „tenancie ośrodka”.
 *
 * Pola puste drukują się z podpowiedzią wzoru i kropkami (do wypełnienia ręcznie). Podpis złożony w portalu drukuje się
 * jako adnotacja „złożono elektronicznie” (data, godzina, IP, skrót SHA-256 treści) — wersję papierową można podpisać odręcznie.
 * Fallback: brak mPDF lub błąd renderowania → render() zwraca dokument HTML (mime text/html) z tą samą treścią.
 */
require_once __DIR__ . '/ldSchema.php';
require_once __DIR__ . '/LicenseEnums.php';
require_once __DIR__ . '/LicenseDto.php';

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
            . '<table class="t"><tr><td class="ttl">' . $titleLines . '<div class="ttl2">' . ($doc->form() === LicenseForm::Am7 ? 'udostępnienie licencji Office 365 kursantom' : 'licencja dydaktyczna') . ' — dokumentacja usługi szkoleniowej (MSUES 2.0)</div></td></tr>'
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
            . $this->fld('Rozstrzygnięcie', self::box(($assignment['status'] ?? '') === 'accepted') . ' zatwierdzono (audyt MSUES) &nbsp;&nbsp; ' . self::box(($assignment['status'] ?? '') === 'rejected') . ' odrzucono', ' width="60%"', true)
            . $this->fld('Zweryfikował(a), data', self::e(trim((string)($assignment['decided_name'] ?? '') . ' ' . ldDate(substr((string)($assignment['decided_at'] ?? ''), 0, 10)))), ' width="40%"', true)
            . '</tr></table>';

        return self::css() . $h;
    }


    // ── AM7 — Office 365 ────────────────────────────────────────────────────────

    public const AM7_REQUEST = 'Zwracam się o przyznanie dostępu do usług Microsoft 365 aktywowanych w planie wskazanym przez placówkę, w celu nauki i wykonywania zadań związanych z udziałem we wskazanym kursie. Przyjmuję do wiadomości, że zakres dostępnych usług zależy od planu przypisanego przez administratora oraz konfiguracji tenanta ośrodka.';
    public const AM7_REQUEST_DECL = 'Oświadczam, że podane dane są prawidłowe, a wnioskowanego dostępu będę używać w związku z nauką na wskazanym kursie.';
    public const AM7_GRANT = 'Po rozpatrzeniu wniosku AM7 przyznaję kursantowi licencję lub dostęp do Office 365 na potrzeby nauki w ramach wskazanego kursu, w zakresie i na okres określonych poniżej.';
    public const AM7_GRANT_SCOPE = 'Dostęp jest przyznawany wyłącznie w celu udziału we wskazanym kursie i w zakresie usług aktywowanych dla przypisanego konta. Niniejsza decyzja dotyczy dostępu organizowanego przez placówkę; nie przesądza o uprawnieniu do korzystania z usług po wygaśnięciu dostępu w tenancie ośrodka.';
    public const AM7_GRANT_END = 'Kursant powinien przed końcem dostępu wykonać wskazane przez placówkę czynności dotyczące swoich materiałów. Nie należy zakładać, że po odebraniu licencji lub zamknięciu konta konto, pliki albo dostęp do usług pozostaną dostępne; sposób i termin dalszego postępowania określają zasady placówki podane powyżej.';
    public const AM7_REFUSAL = 'Po rozpatrzeniu wniosku AM7 odmawiam przyznania dostępu do planu Microsoft 365 używanego przez placówkę oraz przypisania albo aktywacji konta kursanta w tenancie ośrodka, o ile taki sposób dostępu był wnioskowany. Niniejsza odmowa nie przyznaje uprawnienia do korzystania z konta ani usług placówki.';
    public const AM7_PARTICIPANT = [
        'Oświadczam, że korzystam z przyznanej mi licencji lub dostępu do Office 365 w celu nauki w ramach wskazanego kursu oraz zobowiązuję się przestrzegać warunków licencji i zasad korzystania przekazanych przez podmiot udostępniający.',
        'Jeżeli dostęp został przyznany przez konto w tenancie ośrodka, będę używać wyłącznie konta przypisanego mi przez placówkę i przyjmuję do wiadomości, że dostęp do usług zależy od planu oraz konfiguracji tego tenanta. Nie będę zakładać, że dostęp lub zapisane na koncie dane pozostaną dostępne po upływie okresu wskazanego w decyzji.',
    ];
    public const AM7_NONTRANSFER = [
        'Oświadczam, że nie przekażę innej osobie przyznanego mi konta, danych logowania ani możliwości korzystania z usług udostępnionych przez placówkę. Będę korzystać z konta wyłącznie na potrzeby własnej nauki w ramach wskazanego kursu oraz zgodnie z zasadami placówki i warunkami korzystania przekazanymi kursantowi.',
        'Przyjmuję do wiadomości, że dostęp jest ograniczony do planu Microsoft 365 i okresu wskazanych w decyzji, a po zakończeniu kursu lub upływie tego okresu placówka może odebrać licencję, zablokować albo zamknąć konto zgodnie z podanymi zasadami. Przed końcem dostępu wykonam wskazane przez placówkę czynności dotyczące zapisanych danych; nie zakładam, że konto lub dane będą dostępne po jego wyłączeniu.',
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
            '<div class="ttl1">FORMULARZ AM7 — WNIOSEK O UDOSTĘPNIENIE LICENCJI</div><div class="ttl1">OFFICE 365 DO CELÓW DYDAKTYCZNYCH</div>',
            '<b>Składający:</b> kursant.&nbsp; <b>Wypełnia:</b> pola jasne — kursant (sekcje A–E), pola ciemne — placówka (sekcja F).&nbsp; '
            . '<b>Termin realizacji:</b> do ' . LD_REALIZATION_DAYS . ' dni' . (($l['due_on'] ?? '') !== '' ? ' (do ' . self::e(ldDate($l['due_on'])) . ')' : '') . '.');
        $h .= '<table class="t">' . self::sec('A', 'MIEJSCE, DATA I ADRESAT', 3) . '<tr>'
            . $this->fld('Miejscowość', self::val((string)($r['place'] ?? ''), 'miejscowość'), ' width="30%"')
            . $this->fld('Data', self::val(ldDate($r['date'] ?? ''), 'data'), ' width="20%"')
            . $this->fld('Do (podmiot udostępniający)', self::val((string)$l['licensor_name'], 'nazwa placówki'), ' width="50%"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('B', 'DANE KURSANTA', 2) . '<tr>'
            . $this->fld('Imię i nazwisko kursanta', self::val((string)$l['interested_name'], 'imię i nazwisko kursanta'), ' width="50%"')
            . $this->fld('Kurs lub program dydaktyczny', self::val((string)$l['course_name'], 'kurs lub program dydaktyczny'), ' width="50%"')
            . '</tr><tr>' . $this->fld('Adres e-mail lub identyfikator konta', self::val((string)($r['account'] ?? ''), 'adres e-mail lub identyfikator konta'), ' colspan="2"') . '</tr></table>';
        $h .= '<table class="t">' . self::sec('C', 'WNIOSKOWANY DOSTĘP', 2) . '<tr>'
            . $this->fld('Wnioskowany plan Microsoft 365', self::val((string)($r['plan'] ?? ''), 'plan Microsoft 365'), ' width="50%"')
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
            '<div class="ttl1">' . ($grant ? 'DECYZJA O PRZYZNANIU LICENCJI LUB DOSTĘPU' : 'DECYZJA O ODMOWIE PRZYZNANIA LICENCJI LUB DOSTĘPU') . '</div><div class="ttl1">DO OFFICE 365</div>',
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
                . $this->fld('Plan Microsoft 365 / SKU', self::val((string)($d['plan'] ?? ''), 'plan / SKU'), ' width="50%"')
                . '</tr><tr>' . $this->fld('Usługi lub aplikacje udostępnione w ramach planu', self::val((string)($d['services'] ?? ''), 'usługi lub aplikacje'), ' colspan="2"')
                . '</tr><tr>' . $this->fld('Okres aktywnego dostępu', 'od <b>' . self::e(ldDate($d['period_from'] ?? '')) . '</b> do <b>' . self::e(ldDate($d['period_to'] ?? '')) . '</b>', ' colspan="2"')
                . '</tr><tr>' . $this->fld('Ograniczenia i zasady korzystania', self::val((string)($d['restrictions'] ?? ''), 'ograniczenia i zasady'), ' colspan="2"')
                . '</tr><tr><td class="pc" colspan="2"><div class="pt">' . self::e(self::AM7_GRANT_SCOPE) . '</div></td></tr></table>';
            $h .= '<table class="t">' . self::sec('C', 'PO ZAKOŃCZENIU KURSU LUB OKRESU DOSTĘPU', 2) . '<tr>'
                . $this->fld('Postępowanie po zakończeniu kursu lub okresu dostępu', self::val((string)($d['after_end'] ?? ''), 'postępowanie po zakończeniu'), ' width="50%"')
                . $this->fld('Dane zapisane na koncie', self::val((string)($d['account_data'] ?? ''), 'dane zapisane na koncie'), ' width="50%"')
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
            $nt ? '<div class="ttl1">OŚWIADCZENIE O NIEODSTĘPOWANIU LICENCJI</div><div class="ttl1">I NIEUDOSTĘPNIANIU KONTA</div>' : '<div class="ttl1">KRÓTKIE OŚWIADCZENIE LICENCJOBIORCY — KURSANTA</div>',
            '<b>Składający:</b> kursant.&nbsp; <b>Decyzja:</b> ' . self::e(self::number(LicenseDoc::Am7Grant, $license)) . '.&nbsp; <b>Okres dostępu:</b> ' . self::e(ldDate($license['period_from'] ?? '') . ' — ' . ldDate($license['period_to'] ?? '')) . '.');
        $h .= '<table class="t">' . self::sec('A', 'DANE', 2) . '<tr>'
            . $this->fld($nt ? 'Imię i nazwisko kursanta' : 'Imię i nazwisko', self::val((string)($data['name'] ?? ''), 'imię i nazwisko'), ' width="50%"')
            . $this->fld('Konto Office 365', self::val((string)($data['account'] ?? ''), 'konto Office 365'), ' width="50%"')
            . ($nt ? '' : '</tr><tr>' . $this->fld('Kurs', self::val((string)($data['course'] ?? ''), 'kurs'), ' colspan="2"'))
            . '</tr></table>';
        $rows = '';
        foreach ($nt ? self::AM7_NONTRANSFER : self::AM7_PARTICIPANT as $i => $t) $rows .= '<tr><td class="pc"><div class="pt"><b>' . ($i + 1) . '.</b> ' . self::e($t) . '</div></td></tr>';
        $h .= '<table class="t">' . self::sec('B', 'OŚWIADCZENIE', 1) . $rows . '</table>';
        $h .= '<table class="t">' . self::sec('C', 'PLAN, OKRES I DANE', 2) . '<tr>'
            . $this->fld($nt ? 'Plan i okres dostępu' : 'Plan i okres przyznanego dostępu', self::val((string)($data['plan_period'] ?? ''), 'plan i okres'), ' width="45%"')
            . $this->fld($nt ? 'Zasady postępowania z danymi po zakończeniu dostępu' : 'Czynności po zakończeniu dostępu dotyczące moich danych',
                         self::val((string)($nt ? ($data['data_rules'] ?? '') : ($data['after_end'] ?? '')), 'zasady / czynności'), ' width="55%"') . '</tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('D', 'MIEJSCE, DATA I PODPIS', 2) . '<tr>'
            . $this->fld('Miejscowość i data', self::val(trim((string)($data['place'] ?? '') . ', ' . ldDate($data['date'] ?? ''), ', '), 'miejscowość i data'), ' width="35%" style="height:16mm"')
            . $this->fld('Podpis kursanta', $sig ? self::e((string)($data['name'] ?? '')) . self::eSign($sig) : '', ' width="65%"') . '</tr></table>';
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('E', 'WERYFIKACJA', 2, '(wypełnia placówka)') . '<tr>'
            . $this->fld('Rozstrzygnięcie', self::box(($assignment['status'] ?? '') === 'accepted') . ' zatwierdzono (audyt MSUES) &nbsp;&nbsp; ' . self::box(($assignment['status'] ?? '') === 'rejected') . ' odrzucono', ' width="60%"', true)
            . $this->fld('Zweryfikował(a), data', self::e(trim((string)($assignment['decided_name'] ?? '') . ' ' . ldDate(substr((string)($assignment['decided_at'] ?? ''), 0, 10)))), ' width="40%"', true)
            . '</tr></table>';
        return self::css() . $h;
    }

    /** Pusty wzór (druk do obiegu papierowego) dowolnego dokumentu. */
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
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 8, 'margin_bottom' => 13,
                                    'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
            $mpdf->SetTitle($title);
            $mpdf->SetAuthor(ldOrgName());
            $mpdf->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr>'
                . '<td width="33%"><b>' . htmlspecialchars($symbol) . '</b> (' . self::VERSION . ')</td>'
                . '<td width="34%" style="text-align:center">' . htmlspecialchars($number) . '</td>'
                . '<td width="33%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
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
