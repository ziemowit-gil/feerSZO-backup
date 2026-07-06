<?php
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
            trim($d['adres'] ?? ''),
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
