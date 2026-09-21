<?php
/**
 * cli/seed_edok_test_docs.php — zakłada 10 testowych dokumentów WYDATKÓW i 10
 * testowych dokumentów PRZYCHODÓW w EODoK (numeracja EODoK-TEST/…, patrz
 * edok_next_test_number()), żeby było na czym testować listę, Preliminarz,
 * tabelę analityczną przychody/koszty i eksporty PDF/XLS.
 *
 * Większość dokumentów (8/10 w każdej grupie) jest od razu 'zaakceptowana'
 * (z pełnymi 5 etapami w edok_steps, jak po prawdziwej akceptacji), po jednym
 * w stanie 'draft' i 'w_obiegu' — żeby dało się przetestować też sam obieg.
 *
 * Idempotentne w sensie: każde uruchomienie dokłada kolejne 20 dokumentów
 * (numeracja testowa sama się zwiększa) — nie nadpisuje ani nie duplikuje
 * po treści. Do sprzątania: przycisk "Usuń testowe" w edok/index.php (admin)
 * albo edok_delete_test_documents() / php cli/cleanup_edok_kontrahent_test.php
 * (to drugie czyści tylko dane Portalu Kontrahenta, nie te dokumenty).
 *
 * Użycie:
 *   php cli/seed_edok_test_docs.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten skrypt można uruchomić tylko z CLI.\n");
}

$base = dirname(__DIR__);
if (!file_exists($base . '/config.php')) {
    fwrite(STDERR, "Brak config.php — aplikacja nie jest zainstalowana.\n");
    exit(2);
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/edok.php';

edok_migrate();

const WYDATEK_TYPES  = ['faktura_vat', 'faktura_korygujaca', 'rachunek', 'nota_ksiegowa', 'lista_plac', 'inny'];
const RODZAJE        = ['projekt', 'odplatna', 'nieodplatna'];
const PROJEKTY       = ['Projekt Alfa', 'Projekt Beta', 'Projekt Gamma'];
const KONTRAHENCI     = ['Testowy Dostawca A Sp. z o.o.', 'Testowy Dostawca B S.A.', 'Jan Testowy (os. fiz.)'];

function seed_kwoty(int $i): array {
    $netto  = round(100 + $i * 37.5, 2);
    $vat    = round($netto * 0.23, 2);
    $brutto = round($netto + $vat, 2);
    $fmt = fn(float $v) => number_format($v, 2, ',', '');
    return [$fmt($netto), $fmt($vat), $fmt($brutto)];
}

/** Wstawia pełne 5 etapów 'ok' (imitacja prawdziwej akceptacji) dla dokumentu w stanie 'zaakceptowany'. */
function seed_insert_steps(int $doc_id, array $doc): void {
    $base_time = strtotime($doc['created_at']);
    $i = 0;
    foreach (array_keys(EDOK_STEPS) as $step_key) {
        $decided_at = date('Y-m-d H:i:s', $base_time + ($i * 3600));
        db_insert('edok_steps', [
            'doc_id'        => $doc_id,
            'step_key'      => $step_key,
            'status'        => 'ok',
            'user_id'       => 1,
            'user_name'     => 'Seed Testowy',
            'user_role'     => $step_key,
            'decided_at'    => $decided_at,
            'notes'         => '',
            'verify_method' => 'pin',
            'verify_result' => 'ok',
            'verified_at'   => $decided_at,
        ]);
        $i++;
    }
}

function seed_batch(string $kierunek, array $typy): void {
    $n_ok = 0;
    for ($i = 0; $i < 10; $i++) {
        $number  = edok_next_test_number();
        $typ     = $typy[$i % count($typy)];
        $rodzaj  = RODZAJE[$i % count(RODZAJE)];
        $projekt = $rodzaj === 'projekt' ? PROJEKTY[$i % count(PROJEKTY)] : '';
        $kontrahent = KONTRAHENCI[$i % count(KONTRAHENCI)];
        [$netto, $vat, $brutto] = seed_kwoty($i);
        $data = date('Y-m-d', strtotime('-' . $i . ' days'));

        $status = $i === 0 ? 'draft' : ($i === 1 ? 'w_obiegu' : 'zaakceptowany');

        $doc = [
            'number'              => $number,
            'title'               => ($kierunek === 'przychod' ? 'PRZYCHÓD TEST ' : 'WYDATEK TEST ') . ($i + 1),
            'kierunek'            => $kierunek,
            'typ_dokumentu'       => $typ,
            'description'         => 'Dokument testowy (' . ($kierunek === 'przychod' ? 'przychód' : 'wydatek') . ') wygenerowany przez cli/seed_edok_test_docs.php.',
            'kontrahent_nazwa'    => $kontrahent,
            'kontrahent_nip'      => '5260001246',
            'nr_faktury'          => strtoupper(substr($kierunek, 0, 3)) . '/TEST/' . ($i + 1),
            'zrodlo_przychodu'    => $kierunek === 'przychod' ? 'Testowy darczyńca / kontrahent nr ' . ($i + 1) : '',
            'data_wystawienia'    => $data,
            'data_sprzedazy'      => $data,
            'data_wplywu'         => $data,
            'kwota_netto'         => $netto,
            'kwota_vat'           => $vat,
            'kwota_brutto'        => $brutto,
            'waluta'              => 'PLN',
            'rodzaj_dzialalnosci' => $rodzaj,
            'projekt'             => $projekt,
            'mpk'                 => '',
            'termin_platnosci'    => $kierunek === 'wydatek' ? date('Y-m-d', strtotime('+14 days')) : null,
            'status'              => $status,
            'created_by'          => null,
            'creator_name'        => 'seed_edok_test_docs.php',
            'created_at'          => date('Y-m-d H:i:s', strtotime('-' . $i . ' days')),
            'updated_at'          => date('Y-m-d H:i:s', strtotime('-' . $i . ' days')),
        ];
        $doc['tytul_przelewu'] = edok_generate_tytul_przelewu($doc);

        $doc_id = db_insert('edok_documents', $doc);
        if ($status === 'zaakceptowany') {
            seed_insert_steps($doc_id, $doc);
            $n_ok++;
        }
        echo "UTWORZONO {$number} (id={$doc_id}, {$kierunek}, {$typ}, status={$status}, brutto={$brutto} PLN)\n";
    }
    echo "  -> {$n_ok} w pełni zaakceptowanych.\n";
}

echo "== Wydatki ==\n";
seed_batch('wydatek', WYDATEK_TYPES);
echo "\n== Przychody ==\n";
seed_batch('przychod', EDOK_TYPES_PRZYCHOD);

echo "\nGotowe — 20 dokumentów testowych (numeracja EODoK-TEST/…).\n";
echo "Sprzątanie: przycisk \"Usuń testowe\" w " . (defined('APP_URL') ? APP_URL : '') . "/edok/index.php (admin).\n";
