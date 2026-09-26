<?php
require_once dirname(__DIR__) . '/modules/address_format/logic/addressFormat.php';
/**
 * includes/guardian_consent.php
 *
 * Zgoda przedstawiciela ustawowego (rodzica/opiekuna prawnego) na wykonywanie
 * świadczeń wolontariackich przez małoletniego + zgoda RODO na przetwarzanie
 * jego danych (art. 17-18 Kodeksu cywilnego, art. 6 ust. 1 lit. a i art. 8 RODO).
 *
 * Zgoda jest ważna 6 miesięcy i wymaga odnowienia — składana „na klik" w panelu
 * przez opiekuna (jego własne konto portalu, dopasowanie po zalogowanym e-mailu
 * do umowy_wolontariat.rodzic_email), niezależnie od zgody na weryfikację RPTS
 * (zob. includes/rpts.php — inna podstawa prawna, inny cykl odnowienia).
 *
 * Dane trzymane PER UMOWA (na umowy_wolontariat), nie na koncie opiekuna —
 * oświadczenie dotyczy konkretnego dziecka/porozumienia, a jeden opiekun może
 * mieć więcej niż jednego małoletniego wolontariusza pod opieką.
 */

const GUARDIAN_CONSENT_VALID_MONTHS = 6;

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'zgoda_przedstawiciela'               => "INTEGER",
        'zgoda_przedstawiciela_at'            => "DATETIME",
        'zgoda_przedstawiciela_wygasa'        => "DATE",
        'zgoda_przedstawiciela_adres'         => "VARCHAR(500)",
        'zgoda_przedstawiciela_dowod'         => "VARCHAR(50)",
        'zgoda_przedstawiciela_ezd_sprawa_id' => "INTEGER",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje — ignorujemy.
        }
    }
})();

/** Czy zgoda przedstawiciela na tę konkretną umowę jest aktualnie ważna. */
if (!function_exists('guardian_consent_is_valid')) {
    function guardian_consent_is_valid(array $contract): bool {
        if (empty($contract['zgoda_przedstawiciela'])) return false;
        $expires = $contract['zgoda_przedstawiciela_wygasa'] ?? null;
        return !$expires || $expires >= date('Y-m-d');
    }
}

/**
 * Umowy wolontariackie małoletnich, dla których zalogowany e-mail (opiekun)
 * musi złożyć/odnowić zgodę. Dopasowanie po umowy_wolontariat.rodzic_email.
 */
if (!function_exists('guardian_consent_pending_for_email')) {
    function guardian_consent_pending_for_email(string $email): array {
        $email = trim($email);
        if ($email === '') return [];
        try {
            $rows = db_all(
                "SELECT * FROM umowy_wolontariat
                 WHERE niepelnoletni = 1 AND rodzic_email = ?
                   AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')",
                [$email]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_filter($rows, fn($c) => !guardian_consent_is_valid($c)));
    }
}

/** Zapisuje zgodę przedstawiciela na daną umowę + dane wymagane przez oświadczenie. */
if (!function_exists('guardian_consent_save')) {
    function guardian_consent_save(int $contract_id, array $d): string {
        $expires = date('Y-m-d', strtotime('+' . GUARDIAN_CONSENT_VALID_MONTHS . ' months'));
        db()->prepare(
            "UPDATE umowy_wolontariat SET
                zgoda_przedstawiciela = 1,
                zgoda_przedstawiciela_at = ?,
                zgoda_przedstawiciela_wygasa = ?,
                zgoda_przedstawiciela_adres = ?,
                zgoda_przedstawiciela_dowod = ?,
                rodzic_telefon = COALESCE(NULLIF(?, ''), rodzic_telefon)
             WHERE id = ?"
        )->execute([
            date('Y-m-d H:i:s'),
            $expires,
            normalizePlAddress($d['adres'] ?? ''),
            trim($d['dowod_seria_nr'] ?? ''),
            trim($d['telefon'] ?? ''),
            $contract_id,
        ]);
        return $expires;
    }
}

/**
 * Kontakt „w razie pytań" do pisma o odnowieniu zgody — dane pobierane
 * z UMOWY (opiekun przypisany do tego konkretnego porozumienia,
 * umowy_wolontariat.guardian_editor_id), nie z ogólnych ustawień organizacji.
 * To osoba, która faktycznie zna sprawę tego wolontariusza. Gdy umowa nie ma
 * przypisanego opiekuna, awaryjnie korzysta z ogólnego adresu organizacji.
 */
if (!function_exists('guardian_consent_contact')) {
    function guardian_consent_contact(array $contract): array {
        $editor_id = (int)($contract['guardian_editor_id'] ?? 0);
        if ($editor_id) {
            $ed = db_one("SELECT email, phone_number FROM users WHERE id=? AND is_active=1", [$editor_id]);
            if ($ed && !empty($ed['email'])) {
                return [
                    'email'   => $ed['email'],
                    'telefon' => $ed['phone_number'] ?? '',
                ];
            }
        }
        return [
            'email'   => org_setting('notify_from_email') ?: '',
            'telefon' => org_setting('org_telefon') ?: '',
        ];
    }
}

/** Pełny tekst oświadczenia (bez nagłówka miejscowość/data) z podstawionymi danymi. */
if (!function_exists('guardian_consent_statement_html')) {
    function guardian_consent_statement_html(array $contract, string $rep_name): string {
        $org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju "FEER"');
        $org_adres = org_setting('org_adres') ?: 'ul. Barbackiego 28/18, 33-300 Nowy Sącz';
        $org_krs   = org_setting('org_krs') ?: '';

        $rep_name_h = h($rep_name);
        $rep_adres  = h($contract['zgoda_przedstawiciela_adres'] ?? '');
        $rep_dowod  = h($contract['zgoda_przedstawiciela_dowod'] ?? '');
        $rep_tel    = h($contract['rodzic_telefon'] ?? '');

        $child_name  = h($contract['imie_nazwisko'] ?? '');
        $child_dob   = !empty($contract['data_urodzenia']) ? date('d.m.Y', strtotime($contract['data_urodzenia'])) : '';
        $child_adres = h($contract['adres'] ?? '');
        $zakres      = nl2br(h($contract['przedmiot_porozumienia'] ?? ''));
        $krs_fragment = $org_krs !== '' ? ", wpisanej do KRS pod numerem: {$org_krs}" : '';

        return <<<HTML
<div class="gc-statement">
  <h5 class="text-center fw-bold mb-3">OŚWIADCZENIE PRZEDSTAWICIELA USTAWOWEGO</h5>
  <p class="text-center text-muted mb-4">o wyrażeniu zgody na wykonywanie świadczeń wolontariackich przez małoletniego</p>

  <h6 class="fw-bold">I. Dane przedstawiciela ustawowego (rodzica/opiekuna prawnego)</h6>
  <p>Ja, niżej podpisany/a:<br>
  Imię i nazwisko: <strong>{$rep_name_h}</strong><br>
  Adres zamieszkania: <strong>{$rep_adres}</strong><br>
  Seria i numer dowodu osobistego: <strong>{$rep_dowod}</strong><br>
  Numer telefonu: <strong>{$rep_tel}</strong></p>

  <h6 class="fw-bold mt-4">II. Dane małoletniego wolontariusza</h6>
  <p>działając jako przedstawiciel ustawowy małoletniego/małoletniej:<br>
  Imię i nazwisko: <strong>{$child_name}</strong><br>
  Data urodzenia: <strong>{$child_dob}</strong><br>
  Adres zamieszkania: <strong>{$child_adres}</strong></p>

  <h6 class="fw-bold mt-4">III. Oświadczenie woli i zgoda na wolontariat</h6>
  <p>Oświadczam, że – działając na podstawie art. 17 i art. 18 ustawy z dnia 23 kwietnia 1964 r.
  Kodeks cywilny – wyrażam zgodę na zawarcie i wykonywanie przez moje dziecko porozumienia
  o wykonywaniu świadczeń wolontariackich na rzecz Fundacji {$org_name} z siedzibą w Nowym Sączu
  ({$org_adres}){$krs_fragment} (dalej jako „Fundacja").</p>
  <p>Zostałem/am poinformowany/a, że zakres świadczeń wolontariackich będzie obejmował
  w szczególności:</p>
  <div class="border-start border-3 ps-3 mb-3">{$zakres}</div>
  <p>Oświadczam również, że zapoznałem/am się z zakresem obowiązków i praw wolontariusza oraz
  zasadami bezpieczeństwa obowiązującymi w Fundacji.</p>
  <p class="fw-semibold">Niniejsza zgoda jest ważna przez okres 6 (sześciu) miesięcy od dnia jej
  podpisania i wymaga pisemnego odnowienia na każdy kolejny okres. Brak odnowienia zgody jest
  równoznaczny z jej wygaśnięciem.</p>

  <h6 class="fw-bold mt-4">IV. Zgoda na przetwarzanie danych osobowych (RODO)</h6>
  <p>Na podstawie art. 6 ust. 1 lit. a oraz art. 8 Rozporządzenia Parlamentu Europejskiego i Rady
  (UE) 2016/679 z dnia 27 kwietnia 2016 r. (RODO), wyrażam zgodę na przetwarzanie danych
  osobowych mojego dziecka, {$child_name}, przez Fundację w celu realizacji porozumienia
  o wykonywaniu świadczeń wolontariackich, w tym w celach ewidencyjnych, kontaktowych oraz
  ubezpieczeniowych.</p>
  <p>Oświadczam, iż zostałem/am poinformowany/a, że:</p>
  <ul>
    <li>Administratorem danych osobowych jest Fundacja {$org_name}, {$org_adres}.</li>
    <li>Dane osobowe będą przetwarzane przez czas trwania wolontariatu oraz przez okres
    wymagany przepisami prawa w celach archiwizacyjnych.</li>
    <li>Przysługuje mi prawo dostępu do treści danych mojego dziecka, prawo ich sprostowania,
    usunięcia, ograniczenia przetwarzania, a także prawo do cofnięcia zgody w dowolnym momencie
    bez wpływu na zgodność z prawem przetwarzania, którego dokonano na podstawie zgody przed
    jej cofnięciem.</li>
    <li>Podanie danych jest dobrowolne, ale niezbędne do realizacji wolontariatu.</li>
    <li>Przysługuje mi prawo wniesienia skargi do Prezesa Urzędu Ochrony Danych Osobowych.</li>
  </ul>
</div>
HTML;
    }
}

/**
 * Treść pisma przewodniego (zaproszenia do odnowienia zgody) jako fragment
 * HTML pod mPDF — ten sam sens co szablon maila 'guardian_consent_renewal'
 * (includes/email_templates.php), ale uproszczony pod druk (bez gradientów/
 * przycisków) i z miejscem na podpis przedstawiciela Fundacji.
 */
if (!function_exists('guardian_consent_cover_letter_html')) {
    function guardian_consent_cover_letter_html(array $contract, string $guardian_name, string $znak_sprawy, ?array $signer = null): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $org_nazwa   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju "FEER"');
        $org_adres   = org_setting('org_adres') ?: 'ul. Barbackiego 28/18, 33-300 Nowy Sącz';
        $org_email   = org_setting('notify_from_email') ?: '';
        $org_telefon = org_setting('org_telefon') ?: '';
        $miejscowosc = org_setting('org_miejscowosc') ?: 'Nowy Sącz';

        $kontakt   = guardian_consent_contact($contract);
        $dziecko   = $h($contract['imie_nazwisko'] ?? '');
        $login_url = defined('APP_URL') ? APP_URL . '/auth/login.php' : '';

        // Podpisuje osoba faktycznie generująca pismo (zalogowany admin/editor);
        // brak $signer (np. cron bez sesji) — fallback na przedstawiciela Fundacji.
        if ($signer && !empty($signer['name'])) {
            $podpis_imie = $signer['name'];
            $podpis_funk = $signer['title'] ?? '';
        } else {
            $reps        = function_exists('org_representatives') ? org_representatives() : [];
            $podpis_imie = $reps ? $reps[0]['name'] : '';
            $podpis_funk = $reps ? ($reps[0]['title'] ?? '') : '';
        }

        // Logo Fundacji jako data-URI w nagłówku (jeśli skonfigurowane w Ustawieniach organizacji).
        $logo_html = '';
        $logo_file = org_setting('org_logo') ?: '';
        if ($logo_file) {
            $logo_path = dirname(__DIR__) . '/assets/logo/' . $logo_file;
            if (is_file($logo_path)) {
                $ext  = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
                $mime = $ext === 'svg' ? 'image/svg+xml' : ('image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
                $data = base64_encode((string)file_get_contents($logo_path));
                $logo_html = '<img src="data:' . $mime . ';base64,' . $data . '" style="max-height:44px;max-width:120px">';
            }
        }

        $data_pisma = $h(date('d.m.Y'));

        return '
<table width="100%" style="margin-bottom:8pt">
  <tr>'
    . ($logo_html ? '<td width="70" style="vertical-align:middle">' . $logo_html . '</td>' : '') . '
    <td style="vertical-align:middle">
      <div style="font-size:13pt;font-weight:700">' . $h($org_nazwa) . '</div>
      <div style="font-size:9pt;color:#444">
        ' . $h($org_adres)
        . ($org_email ? ' &nbsp;·&nbsp; e-mail: ' . $h($org_email) : '')
        . ($org_telefon ? ' &nbsp;·&nbsp; tel: ' . $h($org_telefon) : '') . '
      </div>
    </td>
  </tr>
</table>
<div style="border-bottom:1.5pt solid #1D4ED8;margin-bottom:6pt"></div>
<p style="margin:0 0 14pt;font-size:8.5pt;color:#555;font-style:italic">
  Niniejsze pismo zostało wygenerowane automatycznie przez system i opatrzone kwalifikowanym
  podpisem elektronicznym — nie wymaga podpisu własnoręcznego.
</p>

<table width="100%" style="margin-bottom:14pt">
  <tr>
    <td width="50%" style="font-size:9pt;color:#666;vertical-align:top">Znak sprawy: <strong>' . $h($znak_sprawy) . '</strong></td>
    <td width="50%" style="text-align:right;vertical-align:top">' . $h($miejscowosc) . ', dnia ' . $data_pisma . ' r.</td>
  </tr>
</table>

<p style="margin:0 0 14pt">
  Do:<br>
  <strong>' . $h($guardian_name) . '</strong><br>
  Przedstawiciel ustawowy (rodzic/opiekun) małoletniego/małoletniej <strong>' . $dziecko . '</strong>
</p>

<p style="margin:0 0 14pt"><strong>Dotyczy:</strong> Wyrażenie zgody na udział dziecka w wolontariacie</p>

<p>Szanowni Państwo,</p>
<p>
  w związku z kontynuacją przez Państwa dziecko, <strong>' . $dziecko . '</strong>, świadczeń w ramach
  wolontariatu na rzecz naszej Fundacji, zwracamy się z uprzejmą prośbą o dopełnienie niezbędnych
  formalności w formie online.
</p>
<p>
  Zgodnie z obowiązującymi przepisami prawa, w tym Kodeksu cywilnego oraz Ogólnego Rozporządzenia
  o Ochronie Danych (RODO), do dalszego udziału Państwa dziecka w wolontariacie niezbędne jest
  regularne, składane co 6 miesięcy, potwierdzenie Państwa zgody — zarówno na sam wolontariat, jak
  i na przetwarzanie danych osobowych w celach z nim związanych.
</p>
<p>Aby dopełnić formalności, prosimy o zalogowanie się na Państwa konto w naszym systemie pod adresem
  ' . $h($login_url) . ' i przejście do sekcji „Zgody i Oświadczenia", gdzie znajdą Państwo pełną
  treść oświadczenia wraz z formularzem potwierdzenia.</p>
<p>W razie pytań lub problemów technicznych prosimy o kontakt: ' . $h($kontakt['email'])
    . ($kontakt['telefon'] ? ' lub telefonicznie: ' . $h($kontakt['telefon']) : '') . '.</p>

<p style="margin-top:20pt">Z wyrazami szacunku,</p>
<p style="margin-top:30pt">'
    . ($podpis_imie ? '<strong>' . $h($podpis_imie) . '</strong>' . ($podpis_funk ? '<br><span style="font-size:9pt;color:#555">' . $h($podpis_funk) . '</span>' : '') : '<strong>' . $h($org_nazwa) . '</strong>')
    . '</p>
<p style="margin-top:2pt;font-size:8.5pt;color:#666">Dokument podpisany kwalifikowanym podpisem elektronicznym.</p>

<p style="margin-top:24pt;color:#888;font-size:8pt;border-top:0.5pt solid #ddd;padding-top:6pt">
  Pismo wygenerowano automatycznie ' . $h(date('d.m.Y H:i')) . ' — ' . $h($org_nazwa) . '.
</p>';
    }
}

/**
 * Renderuje pismo przewodnie jako gotowe bajty PDF (mPDF) — wzorzec jak
 * helpdesk/escalation_pdf.php (jedyny ustalony wzorzec mPDF w tym repo:
 * dejavuserif dla polskich znaków, własny tempDir zapisywalny na produkcji).
 * Zwraca null przy błędzie (np. brak mPDF) zamiast rzucać wyjątek dalej.
 */
if (!function_exists('guardian_consent_generate_pdf')) {
    function guardian_consent_generate_pdf(array $contract, string $guardian_name, string $znak_sprawy, ?array $signer = null): ?string {
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';

            $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
            if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

            $mpdf = new \Mpdf\Mpdf([
                'mode'          => 'utf-8',
                'format'        => 'A4',
                'margin_left'   => 25,
                'margin_right'  => 20,
                'margin_top'    => 18,
                'margin_bottom' => 18,
                'default_font'  => 'dejavuserif',
                'tempDir'       => $mpdf_tmp,
            ]);
            $osoba = $contract['imie_nazwisko'] ?? '';
            $mpdf->SetTitle('Pismo przewodnie — zgoda na wolontariat ' . $osoba);
            $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
            $mpdf->WriteHTML(
                'body { font-family: "DejaVu Serif", serif; font-size: 11pt; line-height: 1.5; color: #000; }
                 table { border-collapse: collapse; } td { vertical-align: top; }',
                \Mpdf\HTMLParserMode::HEADER_CSS
            );
            $mpdf->WriteHTML(
                guardian_consent_cover_letter_html($contract, $guardian_name, $znak_sprawy, $signer),
                \Mpdf\HTMLParserMode::HTML_BODY
            );

            return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        } catch (\Throwable $e) {
            error_log('[guardian_consent_pdf] ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * Generuje pismo przewodnie w PDF i dołącza je jako załącznik do sprawy EZD
 * (ezd_attach_path — ta sama ścieżka co przy ręcznym wgraniu pliku). Cała
 * operacja jest pomocnicza — błąd nie może przerwać wysyłki samego pisma,
 * dlatego funkcja nigdy nie rzuca dalej, tylko loguje.
 */
if (!function_exists('guardian_consent_attach_pdf')) {
    function guardian_consent_attach_pdf(int $sprawa_id, ?int $pismo_id, array $contract, string $guardian_name, string $znak_sprawy, int $user_id): void {
        if (!$sprawa_id || !function_exists('ezd_attach_path')) return;
        try {
            // $user_id = osoba wywołująca (0 przy cronie — brak zalogowanego,
            // fallback na przedstawiciela Fundacji obsłużony w generate_pdf).
            $signer = null;
            if ($user_id > 0) {
                $u = db_one("SELECT name, crm_job_title FROM users WHERE id=?", [$user_id]);
                if ($u && !empty($u['name'])) {
                    $signer = ['name' => $u['name'], 'title' => $u['crm_job_title'] ?? ''];
                }
            }
            $bytes = guardian_consent_generate_pdf($contract, $guardian_name, $znak_sprawy, $signer);
            if (!$bytes) return;

            $tmp_dir = UPLOAD_DIR . 'mpdf_tmp';
            if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
            $osoba    = $contract['imie_nazwisko'] ?? 'wolontariusz';
            $filename = 'Pismo przewodnie — zgoda na wolontariat — ' . $osoba . '.pdf';
            $tmp_path = $tmp_dir . '/' . uniqid('gc_pdf_', true) . '.pdf';

            if (file_put_contents($tmp_path, $bytes) === false) return;
            ezd_attach_path($tmp_path, $filename, $sprawa_id, $pismo_id, $user_id);
            @unlink($tmp_path);
        } catch (\Throwable $e) {
            error_log('[guardian_consent_attach_pdf] ' . $e->getMessage());
        }
    }
}
