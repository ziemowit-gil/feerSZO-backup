<?php
/**
 * Migracja: certificate_requests → ezd_zaswiadczenia_wlasne
 *
 * Uruchomienie: php cli/migrations/migrate_certificates_to_ezd.php [--dry-run]
 *
 * Przenosi rekordy z tabeli certificate_requests (stary system) do
 * ezd_zaswiadczenia_wlasne (EZD), zachowując powiązanie z umową
 * przez contract_type + contract_id.
 */

define('FEER_CLI', true);
chdir(dirname(dirname(__DIR__)));
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/zaswiadczenia_ezd.php';

$dry = in_array('--dry-run', $argv);
echo "=== Migracja zaświadczeń: certificate_requests → EZD ===\n";
if ($dry) echo "[DRY RUN — żadne zmiany nie zostaną zapisane]\n";

// Sprawdź czy źródłowa tabela istnieje
try {
    db()->query("SELECT 1 FROM certificate_requests LIMIT 1");
} catch (\Throwable $e) {
    echo "BŁĄD: Tabela certificate_requests nie istnieje lub jest niedostępna.\n";
    exit(1);
}

// Mapowanie statusów
$status_map = [
    'oczekuje'       => 'wniosek',
    'gotowe'         => 'weryfikacja',
    'esign_oczekuje' => 'weryfikacja',
    'wydane'         => 'wydane',
    'odrzucone'      => 'odrzucone',
];

// Mapowanie typów certyfikatów → kod EZD
$cert_type_map = [
    'wolontariat'  => 'zaswiadczenie_wolontariat',
    'zatrudnienie' => 'zaswiadczenie_zatrudnienie',
    'wspolpraca'   => 'zaswiadczenie_wspolpraca',
];

// Wczytaj typy EZD (muszą już istnieć w DB)
$typy = [];
foreach (db_all("SELECT id, kod FROM ezd_zas_typy") as $t) {
    $typy[$t['kod']] = (int)$t['id'];
}

// Fallback typ
$fallback_typ_id = $typy['zaswiadczenie_umowy'] ?? array_values($typy)[0] ?? null;
if (!$fallback_typ_id) {
    echo "BŁĄD: Brak typów zaświadczeń w EZD. Upewnij się, że zaswiadczenia_ezd.php był załadowany.\n";
    exit(1);
}

$rows = db_all("SELECT * FROM certificate_requests ORDER BY id ASC");
echo "Znaleziono " . count($rows) . " rekordów do migracji.\n\n";

$migrated = 0; $skipped = 0; $errors = 0;

foreach ($rows as $cr) {
    $cr_id = (int)$cr['id'];

    // Sprawdź czy już migrowano (po polu zmigrowany lub po cert_number w EZD)
    $existing = db_one(
        "SELECT id FROM ezd_zaswiadczenia_wlasne WHERE contract_type=? AND contract_id=? AND nr_zaswiadczenia=?",
        [$cr['contract_type'], $cr['contract_id'], $cr['cert_number'] ?? '']
    );
    if ($existing && $cr['cert_number']) {
        echo "  [{$cr_id}] POMIŃ (już istnieje w EZD jako #{$existing['id']})\n";
        $skipped++;
        continue;
    }

    // Wyznacz typ EZD
    $cert_type_key = $cr['certificate_type'] ?? 'wolontariat';
    $ezd_kod  = $cert_type_map[$cert_type_key] ?? 'zaswiadczenie_umowy';
    $typ_id   = $typy[$ezd_kod] ?? $fallback_typ_id;

    // Zbuduj dane JSON z dostępnych pól
    $dane = [];
    if ($cr['certificate_content']) {
        // Stary system: treść tekstowa → wstaw jako dane
        $dane['_legacy_content'] = $cr['certificate_content'];
    }

    $ezd_status = $status_map[$cr['status'] ?? 'oczekuje'] ?? 'wniosek';
    $nr  = $cr['cert_number']  ?? null;
    $cel = $cr['cel']          ?? '';

    // Treść HTML — tylko jeśli wydane i ma treść
    $tresc_html = '';
    if ($ezd_status === 'wydane' && $cr['certificate_content']) {
        $tresc_html = nl2br(htmlspecialchars($cr['certificate_content'], ENT_QUOTES, 'UTF-8'));
    }

    echo "  [{$cr_id}] {$cr['contract_type']}#{$cr['contract_id']} {$cr['requester_name']} [{$cr['status']}→{$ezd_status}]";

    if (!$dry) {
        try {
            db()->prepare(
                "INSERT INTO ezd_zaswiadczenia_wlasne
                 (typ_id, nr_zaswiadczenia, status, wnioskodawca_name, wnioskodawca_email,
                  dane_json, tresc_html, contract_type, contract_id,
                  created_by, created_at, updated_at,
                  zatwierdzone_at, verify_code)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $typ_id,
                $nr,
                $ezd_status,
                $cr['requester_name'] ?? '',
                $cr['requester_email'] ?? '',
                json_encode($dane + ['cel' => $cel], JSON_UNESCAPED_UNICODE),
                $tresc_html,
                $cr['contract_type'],
                $cr['contract_id'],
                $cr['requested_by'] ?? null,
                $cr['created_at']   ?? date('Y-m-d H:i:s'),
                $cr['issued_at']    ?? $cr['created_at'] ?? date('Y-m-d H:i:s'),
                $cr['status'] === 'wydane' ? $cr['issued_at'] : null,
                $cr['verify_code'] ?? null,
            ]);
            echo " → OK (EZD#" . db()->lastInsertId() . ")\n";
            $migrated++;
        } catch (\Throwable $e) {
            echo " → BŁĄD: " . $e->getMessage() . "\n";
            $errors++;
        }
    } else {
        echo " [dry]\n";
        $migrated++;
    }
}

echo "\n";
echo "Podsumowanie:\n";
echo "  Zmigrowano: {$migrated}\n";
echo "  Pominięto:  {$skipped}\n";
echo "  Błędów:     {$errors}\n";
if ($dry) echo "\n[DRY RUN — uruchom bez --dry-run, aby zapisać zmiany]\n";
