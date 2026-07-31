<?php
/**
 * Eksport Preliminarza Płatności → MultiCash PLI (GOonline Biznes).
 * Kodowanie CP852, separator przecinek, pola tekstowe w cudzysłowach.
 * Format uwzględnia Split Payment (pole 15 = 53) oraz płatności zwykłe (51).
 *
 * Spec: GOonline Biznes — „Predefiniowany szablon importu – MultiCash PLI", sekcja 3.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

kdok_require_access();
kdok_migrate();

if (!kdok_has_role('zatwierdza')) {
    flash_set('danger', 'Brak dostępu — eksport PLI wymaga roli „zatwierdza".');
    header('Location: ' . APP_URL . '/ksiegowosc/preliminarz.php');
    exit;
}

// ── Rachunek własny ───────────────────────────────────────────────────────────

$nrb_param = preg_replace('/[\s\-]/', '', $_GET['nrb'] ?? '');
if (strlen($nrb_param) === 26 && ctype_digit($nrb_param)) {
    org_setting_set('kdok_rachunek_wlasny', $nrb_param);
    $sender_nrb = $nrb_param;
} else {
    $sender_nrb = preg_replace('/[\s\-]/', '', org_setting('kdok_rachunek_wlasny'));
}

// Buduj back URL (bez parametru nrb)
$back_params = array_diff_key($_GET, ['nrb' => 1]);
$back_url    = APP_URL . '/ksiegowosc/preliminarz.php' . ($back_params ? '?' . http_build_query($back_params) : '');

if (strlen($sender_nrb) !== 26 || !ctype_digit($sender_nrb)) {
    flash_set('danger', 'Podaj prawidłowy NRB rachunku organizacji (26 cyfr) przed pobraniem pliku PLI.');
    header('Location: ' . $back_url);
    exit;
}

// ── Filtry — te same co Preliminarz ──────────────────────────────────────────

$filters = [];
if (!empty($_GET['termin_od']))       $filters['termin_od']        = $_GET['termin_od'];
if (!empty($_GET['termin_do']))       $filters['termin_do']         = $_GET['termin_do'];
if (!empty($_GET['status']))          $filters['status_platnosci']  = $_GET['status'];
if (!empty($_GET['waluta']))          $filters['waluta']            = $_GET['waluta'];
if (!empty($_GET['mpp']))             $filters['mpp']               = true;
if (!empty($_GET['centrum_kosztow'])) $filters['centrum_kosztow']  = $_GET['centrum_kosztow'];
if (!empty($_GET['q']))               $filters['q']                 = $_GET['q'];

$only_ids = array_values(array_filter(array_map('intval', (array)($_GET['ids'] ?? []))));

$docs = kdok_preliminarz_query($filters);
if ($only_ids) {
    $idset = array_flip($only_ids);
    $docs  = array_values(array_filter($docs, fn($d) => isset($idset[(int)$d['id']])));
}

// ── Helpery PLI ───────────────────────────────────────────────────────────────

/**
 * Czyści tekst do użycia w polu PLI: uppercase, usuwa cudzysłowy, obcina do max znaków.
 * PLI: dozwolone litery (w tym polskie), cyfry, spacja, , . : ; - ( ) [ ] { } / = + < > ! _ % ~ ^ ' `
 * Niedozwolone: " (zamień na ')
 */
function pli_clean(string $s, int $max = 35): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = str_replace('"', "'", $s);
    return mb_substr($s, 0, $max, 'UTF-8');
}

/**
 * Formatuje tablicę linii do 4-liniowego pola PLI oddzielonego znakiem |.
 * Każda linia max $maxlen znaków.
 */
function pli_4lines(array $lines, int $maxlen = 35): string {
    $out = [];
    for ($i = 0; $i < 4; $i++) {
        $out[] = (isset($lines[$i]) && $lines[$i] !== '') ? pli_clean($lines[$i], $maxlen) : '';
    }
    return implode('|', $out);
}

// ── Dane nadawcy — z konfiguracji rachunku lub danych org ────────────────────

$sender_bank = substr($sender_nrb, 2, 8);

$rachunki_org = json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true) ?: [];
$sender_acct  = null;
foreach ($rachunki_org as $acct) {
    if ($acct['nrb'] === $sender_nrb) { $sender_acct = $acct; break; }
}

$org_name = pli_clean(($sender_acct['nazwa'] ?? '') ?: ORG_NAME, 35);
$org_addr = pli_clean(($sender_acct['adres'] ?? '') ?: org_setting('org_adres') ?: '', 35);
$sender_4 = pli_4lines([$org_name, '', $org_addr, '']);

// ── Mapa znaku EZD (koszulka) per ezd_sprawa_id ──────────────────────────────

$ezd_ids = array_filter(array_map(fn($d) => (int)($d['ezd_sprawa_id'] ?? 0), $docs));
$ezd_signs = [];
if ($ezd_ids) {
    $placeholders = implode(',', array_fill(0, count($ezd_ids), '?'));
    $rows = db_all("SELECT id, znak_sprawy FROM ezd_sprawy WHERE id IN ($placeholders)",
                   array_values($ezd_ids));
    foreach ($rows as $r) $ezd_signs[(int)$r['id']] = $r['znak_sprawy'];
}

// ── Generowanie wierszy PLI ───────────────────────────────────────────────────

$lines_out = [];
$skipped   = [];

foreach ($docs as $doc) {
    // Walidacja rachunku odbiorcy — musi być 26-cyfrowym NRB
    $nrb = preg_replace('/[\s\-]/', '', $doc['rachunek_bankowy'] ?? '');
    if (strlen($nrb) !== 26 || !ctype_digit($nrb)) {
        $skipped[] = $doc['number'];
        continue;
    }

    // Kwota brutto → grosze (bez separatora)
    $kwota_f  = (float) str_replace([' ', ','], ['', '.'], $doc['kwota_brutto'] ?: $doc['kwota'] ?: '0');
    $kwota_gr = (int) round($kwota_f * 100);
    if ($kwota_gr <= 0) {
        $skipped[] = $doc['number'];
        continue;
    }

    // Data wykonania (YYYYMMDD); gdy brak — dzisiejsza
    $termin = $doc['termin_platnosci'] ?? '';
    $date   = $termin ? str_replace('-', '', substr($termin, 0, 10)) : date('Ymd');

    // Numer rozliczeniowy banku odbiorcy (cyfry 3-10 NRB odbiorcy)
    $recip_bank = substr($nrb, 2, 8);

    // Pola nazwy i adresu odbiorcy (4 linie po 35 znaków)
    $recip_name = $doc['title'] ?: $doc['nr_faktury'] ?: $doc['number'];
    $recip_4    = pli_4lines([$recip_name, '', '', '']);

    // Tytuł i typ płatności (zwykła lub Split Payment)
    $is_mpp  = !empty($doc['wymaga_mpp']);
    $nip_raw = preg_replace('/\D/', '', $doc['nip_dostawcy'] ?? '');
    $nr_fakt = pli_clean($doc['nr_faktury'] ?? '', 35);
    $tytul   = pli_clean($doc['tytul_przelewu'] ?: $doc['title'] ?: $doc['number'], 35);

    if ($is_mpp && $nip_raw !== '' && $nr_fakt !== '') {
        // Split Payment (MPP) — pole 12 ze specjalną strukturą, pole 15 = "53"
        $vat_f    = (float) str_replace([' ', ','], ['', '.'], $doc['kwota_vat'] ?? '0');
        $vat_gr   = (int) round($vat_f * 100);
        $vat_str  = intdiv($vat_gr, 100) . ',' . str_pad($vat_gr % 100, 2, '0', STR_PAD_LEFT);
        $nip_pli  = substr($nip_raw, 0, 14);           // /IDC/ max 14 znaków
        $inv_pli  = mb_substr($nr_fakt, 0, 35, 'UTF-8'); // /INV/ max 35 znaków
        $txt_pli  = mb_substr($tytul, 0, 33, 'UTF-8');   // /TXT/ max 33 znaki
        $tytul4   = "/VAT/{$vat_str}/IDC/{$nip_pli}/INV/{$inv_pli}|/TXT/{$txt_pli}||";
        $kod      = 53;
    } else {
        // Zwykły przelew — tytuł max 35 znaków w pierwszej linii, reszta pusta
        $tytul4 = $tytul . '|||';
        $kod    = 51;
    }

    // Referencja własna (max 16 znaków) — numer KDOK + znak EZD
    $ref_str = $doc['number'];
    if (!empty($doc['ezd_sprawa_id']) && isset($ezd_signs[(int)$doc['ezd_sprawa_id']])) {
        $ref_str .= '/' . $ezd_signs[(int)$doc['ezd_sprawa_id']];
    }
    $ref = pli_clean($ref_str, 16);

    $lines_out[] = implode(',', [
        110,                          // 1. typ operacji
        $date,                        // 2. data wykonania
        $kwota_gr,                    // 3. kwota w groszach
        $sender_bank,                 // 4. nr banku nadawcy
        0,                            // 5. nieużywane
        '"' . $sender_nrb . '"',      // 6. NRB nadawcy
        '"' . $nrb . '"',             // 7. NRB odbiorcy
        '"' . $sender_4 . '"',        // 8. nazwa i adres nadawcy
        '"' . $recip_4 . '"',         // 9. nazwa i adres odbiorcy
        0,                            // 10. nieużywane
        $recip_bank,                  // 11. nr banku odbiorcy
        '"' . $tytul4 . '"',          // 12. szczegóły płatności
        '""',                         // 13. puste
        '""',                         // 14. puste
        '"' . $kod . '"',             // 15. klasyfikacja (51/53)
        '"' . $ref . '"',             // 16. referencja klienta
    ]);
}

// ── Obsługa braku wyników ─────────────────────────────────────────────────────

if (!$lines_out) {
    $msg = 'Brak dokumentów z kompletnym rachunkiem bankowym do eksportu PLI.';
    if ($skipped) {
        $msg .= ' Pominięto (' . count($skipped) . '): ' . implode(', ', array_slice($skipped, 0, 10))
            . (count($skipped) > 10 ? '…' : '') . '.';
    }
    flash_set('warning', $msg);
    header('Location: ' . $back_url);
    exit;
}

// ── Enkodowanie UTF-8 → CP852 i pobieranie ────────────────────────────────────

$content     = implode("\r\n", $lines_out) . "\r\n";
$content_enc = iconv('UTF-8', 'CP852//TRANSLIT//IGNORE', $content);

$filename = 'KDOK_PLI_' . date('Ymd_His') . '.PLI';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content_enc));
header('Cache-Control: no-cache');
header('Pragma: no-cache');
echo $content_enc;
exit;
