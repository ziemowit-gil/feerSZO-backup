<?php
/**
 * crm/import.php — Import kontaktów z pliku CSV
 *
 * Krok 1: upload pliku CSV → podgląd + mapowanie kolumn
 * Krok 2: potwierdzenie i import do crm_contacts (pomijaj duplikaty po e-mail)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
crm_require('import', 'write');
if (!can_write('crm_import') && !is_admin()) {
    http_response_code(403);
    exit('Brak uprawnień do importu kontaktów CRM.');
}

$PAGE_TITLE = 'Import kontaktów CSV';

/** Pola docelowe w crm_contacts wraz z etykietami. */
$TARGET_FIELDS = [
    ''             => '— pomiń —',
    'imie_nazwisko'=> 'Imię i nazwisko *',
    'email'        => 'E-mail',
    'telefon'      => 'Telefon',
    'organizacja'  => 'Organizacja / Firma',
    'stanowisko'   => 'Stanowisko',
    'status'       => 'Status CRM',
    'type'         => 'Typ (osoba / organizacja / kontrahent / partner)',
    'notatka'      => 'Notatka',
    'adres'        => 'Adres',
    'wojewodztwo'  => 'Województwo',
];

/** Automatyczne dopasowanie nagłówka CSV → pole docelowe. */
function _csv_guess_field(string $h): string {
    $h = mb_strtolower(trim($h));
    $map = [
        'imie_nazwisko'  => ['imię i nazwisko','imie i nazwisko','imie_nazwisko','name','full name','fullname','nazwa','kontakt'],
        'email'          => ['email','e-mail','mail','e mail'],
        'telefon'        => ['telefon','phone','tel','komórka','mobile','gsm'],
        'organizacja'    => ['organizacja','firma','company','organization','instytucja'],
        'stanowisko'     => ['stanowisko','position','rola','role','job title'],
        'status'         => ['status'],
        'type'           => ['typ','type','rodzaj'],
        'notatka'        => ['notatka','note','notes','uwagi','opis'],
        'adres'          => ['adres','address'],
        'wojewodztwo'    => ['województwo','wojewodztwo','region','voivodeship'],
    ];
    foreach ($map as $field => $aliases) {
        foreach ($aliases as $alias) {
            if ($h === $alias) return $field;
        }
    }
    return '';
}

/** Wczytuje CSV z pliku tymczasowego, zwraca [headers, rows[]] */
function _parse_csv(string $path): array {
    $raw = file_get_contents($path);
    // Wykryj kodowanie i skonwertuj do UTF-8
    $enc = mb_detect_encoding($raw, ['UTF-8','Windows-1250','ISO-8859-2','ISO-8859-1'], true);
    if ($enc && $enc !== 'UTF-8') {
        $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
    }
    // Normalizuj EOL
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_filter(explode("\n", $raw), 'strlen');
    $rows  = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line, ';');
        if (count($rows) === 1) {
            // jeśli tylko 1 kolumna, spróbuj przecinkiem
            if (count($rows[0]) <= 1) {
                $rows = [];
                foreach (array_filter(explode("\n", $raw), 'strlen') as $l2) {
                    $rows[] = str_getcsv($l2, ',');
                }
                break;
            }
        }
    }
    if (empty($rows)) return [[], []];
    $headers = array_shift($rows);
    return [$headers, $rows];
}

/* ─────────────────── SESSION — tymczasowe przechowywanie ─────────────────── */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$msg     = null;
$preview = [];
$headers = [];
$mapping = [];
$csv_key = 'crm_import_csv_' . session_id();

/* ─────────────────── POST ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* KROK 1 — upload */
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        [$headers, $all_rows] = _parse_csv($tmp);

        if (empty($headers)) {
            $msg = ['type' => 'danger', 'text' => 'Nie udało się odczytać pliku CSV. Sprawdź format pliku.'];
        } else {
            // Zapisz wiersze w sesji (maks. 2000)
            $_SESSION[$csv_key] = array_slice($all_rows, 0, 2000);
            $_SESSION[$csv_key . '_headers'] = $headers;

            $preview = array_slice($all_rows, 0, 5);
            $mapping = array_map('_csv_guess_field', $headers);
        }

    /* KROK 2 — import */
    } elseif (isset($_POST['do_import'])) {
        csrf_check();

        $headers  = $_SESSION[$csv_key . '_headers'] ?? [];
        $all_rows = $_SESSION[$csv_key] ?? [];
        $mapping  = $_POST['map'] ?? [];   // ['col_0' => 'email', 'col_1' => 'imie_nazwisko', ...]

        if (empty($headers) || empty($all_rows)) {
            $msg = ['type' => 'danger', 'text' => 'Sesja wygasła. Prześlij plik ponownie.'];
        } else {
            $imported = 0;
            $skipped  = 0;
            $errors   = 0;
            $uid      = (int)current_user()['id'];
            $now      = date('Y-m-d H:i:s');

            foreach ($all_rows as $row) {
                $data = [
                    'type'         => 'osoba',
                    'status'       => 'prospect',
                    'source'       => 'import_csv',
                    'crm_active'   => 1,
                    'created_by'   => $uid,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                    'imie_nazwisko'=> '',
                ];

                foreach ($headers as $i => $hdr) {
                    $field = $mapping['col_' . $i] ?? '';
                    if (!$field || !isset($TARGET_FIELDS[$field]) || $field === '') continue;
                    $val = trim($row[$i] ?? '');
                    if ($val === '') continue;

                    if ($field === 'status') {
                        // Waliduj status
                        $val = strtolower($val);
                        if (!array_key_exists($val, crm_statuses())) {
                            $val = 'prospect';
                        }
                    } elseif ($field === 'type') {
                        $v = strtolower(trim($val));
                        if (in_array($v, ['organizacja', 'org', 'organization', 'firma'])) {
                            $val = 'organizacja';
                        } elseif (in_array($v, ['kontrahent', 'contractor', 'dostawca', 'supplier'])) {
                            $val = 'kontrahent';
                        } elseif (in_array($v, ['partner', 'partnership'])) {
                            $val = 'partner';
                        } else {
                            $val = 'osoba';
                        }
                    }
                    $data[$field] = $val;
                }

                // Pomiń jeśli brak imię_nazwisko
                if (empty(trim($data['imie_nazwisko'] ?? ''))) {
                    $skipped++;
                    continue;
                }

                // Pomiń duplikaty po e-mail
                if (!empty($data['email'])) {
                    $exists = db_one(
                        "SELECT id FROM crm_contacts WHERE email=? AND crm_active=1 LIMIT 1",
                        [$data['email']]
                    );
                    if ($exists) {
                        $skipped++;
                        continue;
                    }
                }

                try {
                    db_insert('crm_contacts', $data);
                    $imported++;
                } catch (\Throwable $ex) {
                    $errors++;
                }
            }

            // Wyczyść sesję
            unset($_SESSION[$csv_key], $_SESSION[$csv_key . '_headers']);

            $parts = [];
            if ($imported) $parts[] = "Zaimportowano: <strong>$imported</strong> kontaktów.";
            if ($skipped)  $parts[] = "Pominięto: <strong>$skipped</strong> (duplikaty e-mail lub brak nazwy).";
            if ($errors)   $parts[] = "Błędy: <strong>$errors</strong>.";

            $type = $imported > 0 ? 'success' : 'warning';
            $msg  = ['type' => $type, 'text' => implode(' ', $parts)];
        }

    /* Przywróć podgląd z sesji (np. po złym mapowaniu) */
    } elseif (isset($_POST['back_to_preview'])) {
        $headers = $_SESSION[$csv_key . '_headers'] ?? [];
        $all_rows = $_SESSION[$csv_key] ?? [];
        $preview = array_slice($all_rows, 0, 5);
        $mapping = array_map('_csv_guess_field', $headers);
    }
}

/* Wczytaj podgląd z sesji po powrocie (GET z sesją) */
if (empty($headers) && !empty($_SESSION[$csv_key . '_headers'])) {
    $headers  = $_SESSION[$csv_key . '_headers'];
    $all_rows = $_SESSION[$csv_key] ?? [];
    $preview  = array_slice($all_rows, 0, 5);
    $mapping  = array_map('_csv_guess_field', $headers);
}

/* ─────────────────── WIDOK ─────────────────────────────────────────────────── */
include __DIR__ . '/includes/header_crm.php';
?>

<div class="container-fluid py-3" style="max-width:900px">

  <!-- Nagłówek -->
  <div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Powrót do kontaktów
    </a>
    <h1 class="h5 mb-0 ms-2">
      <i class="bi bi-file-earmark-arrow-up me-1 text-primary" aria-hidden="true"></i>
      Import kontaktów CSV
    </h1>
  </div>

  <?php if ($msg): ?>
  <div class="alert alert-<?= h($msg['type']) ?> alert-dismissible" role="alert">
    <i class="bi bi-<?= $msg['type'] === 'success' ? 'check-circle' : ($msg['type'] === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-1" aria-hidden="true"></i>
    <?= $msg['text'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
  </div>
  <?php endif; ?>

  <?php if (empty($headers)): ?>
  <!-- ═══ KROK 1: Upload ═══════════════════════════════════════════════════════ -->
  <div class="card shadow-sm">
    <div class="card-header">
      <i class="bi bi-upload me-1" aria-hidden="true"></i>Prześlij plik CSV
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Obsługiwane formaty: CSV z separatorem <code>;</code> lub <code>,</code>.<br>
        Kodowanie: UTF-8, Windows-1250 (CP1250), ISO-8859-2 — wykrywane automatycznie.<br>
        Pierwszy wiersz musi zawierać nagłówki kolumn.
      </p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="mb-3">
          <label for="csv_file" class="form-label fw-semibold">Wybierz plik CSV</label>
          <input type="file" name="csv_file" id="csv_file"
                 class="form-control" accept=".csv,text/csv,text/plain"
                 required aria-describedby="csvHelp">
          <div id="csvHelp" class="form-text">Maksymalny rozmiar: 5 MB. Maks. 2000 rekordów na import.</div>
        </div>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-eye me-1" aria-hidden="true"></i>Wczytaj i pokaż podgląd
        </button>
      </form>
    </div>
  </div>

  <!-- Instrukcja kolumn -->
  <div class="card mt-3 shadow-sm">
    <div class="card-header">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Obsługiwane kolumny
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Pole systemowe</th><th>Przykładowe nagłówki CSV</th><th>Uwagi</th></tr>
        </thead>
        <tbody style="font-size:.83rem">
          <tr><td><code>imie_nazwisko</code></td><td>Imię i Nazwisko, Name, Kontakt</td><td class="text-danger">Wymagane</td></tr>
          <tr><td><code>email</code></td><td>Email, E-mail, Mail</td><td>Służy do wykrywania duplikatów</td></tr>
          <tr><td><code>telefon</code></td><td>Telefon, Phone, Tel</td><td></td></tr>
          <tr><td><code>organizacja</code></td><td>Organizacja, Firma, Company</td><td></td></tr>
          <tr><td><code>stanowisko</code></td><td>Stanowisko, Position</td><td></td></tr>
          <tr><td><code>status</code></td><td>Status</td><td>prospect, lead, klient, partner, nieaktywny</td></tr>
          <tr><td><code>type</code></td><td>Typ, Type</td><td>osoba lub organizacja</td></tr>
          <tr><td><code>notatka</code></td><td>Notatka, Note, Uwagi</td><td></td></tr>
          <tr><td><code>adres</code></td><td>Adres, Address</td><td></td></tr>
          <tr><td><code>wojewodztwo</code></td><td>Województwo, Region</td><td></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: ?>
  <!-- ═══ KROK 2: Podgląd + mapowanie + import ═══════════════════════════════ -->
  <?php $row_count = count($_SESSION[$csv_key] ?? []); ?>
  <div class="alert alert-info py-2">
    <i class="bi bi-table me-1" aria-hidden="true"></i>
    Wczytano <strong><?= $row_count ?></strong> wierszy z <?= count($headers) ?> kolumnami.
    Sprawdź mapowanie poniżej i kliknij <strong>Importuj</strong>.
  </div>

  <form method="post">
    <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
    <input type="hidden" name="do_import"  value="1">

    <!-- Mapowanie kolumn -->
    <div class="card shadow-sm mb-3">
      <div class="card-header">
        <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Mapowanie kolumn
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:40%">Kolumna w pliku CSV</th>
              <th>Mapuj na pole</th>
              <th class="text-muted" style="width:30%">Przykładowa wartość</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($headers as $i => $hdr): ?>
            <tr>
              <td class="fw-semibold" style="font-size:.84rem"><?= h($hdr) ?></td>
              <td>
                <select name="map[col_<?= $i ?>]" class="form-select form-select-sm" style="max-width:220px"
                        aria-label="Mapowanie kolumny <?= h($hdr) ?>">
                  <?php foreach ($TARGET_FIELDS as $fval => $flabel): ?>
                  <option value="<?= h($fval) ?>"
                    <?= ($mapping[$i] ?? '') === $fval ? 'selected' : '' ?>>
                    <?= h($flabel) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="text-muted" style="font-size:.78rem">
                <?= h(trim($preview[0][$i] ?? '')) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Podgląd danych -->
    <div class="card shadow-sm mb-3">
      <div class="card-header">
        <i class="bi bi-eye me-1" aria-hidden="true"></i>
        Podgląd pierwszych <?= count($preview) ?> wierszy
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0" style="font-size:.8rem">
          <thead class="table-light">
            <tr>
              <?php foreach ($headers as $hdr): ?>
              <th><?= h($hdr) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $prow): ?>
            <tr>
              <?php foreach ($headers as $i => $hdr): ?>
              <td><?= h(trim($prow[$i] ?? '')) ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>
        Importuj <?= $row_count ?> rekordów
      </button>
      <button type="submit" name="back_to_preview" value="1" class="btn btn-outline-secondary"
              formnovalidate>
        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Wgraj inny plik
      </button>
      <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-link text-muted">Anuluj</a>
    </div>
  </form>

  <!-- Formularz "Wgraj inny plik" — nowe żądanie upload -->
  <form method="post" enctype="multipart/form-data" class="mt-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="input-group input-group-sm" style="max-width:380px">
      <input type="file" name="csv_file" class="form-control form-control-sm"
             accept=".csv,text/csv,text/plain" aria-label="Nowy plik CSV">
      <button type="submit" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-upload me-1" aria-hidden="true"></i>Wczytaj nowy
      </button>
    </div>
  </form>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
