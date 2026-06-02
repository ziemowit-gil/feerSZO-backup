<?php
/**
 * admin/import_volunteers.php — Import wolontariuszy z CSV
 *
 * Trzy etapy:
 *   GET              — formularz uploadu + opcje
 *   POST _step=upload — parsuje CSV, zapisuje w sesji, pokazuje podgląd z mapowaniem kolumn
 *   POST _step=import — tworzy rekordy umowy_wolontariat
 *   GET  ?template=1  — pobierz wzorcowy plik CSV
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
require_once dirname(__DIR__) . '/includes/admin_audit.php';

$PAGE_TITLE = 'Import wolontariuszy z CSV';

// ── Wzorzec CSV do pobrania ───────────────────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wzorzec_wolontariusze.csv"');
    echo "\xEF\xBB\xBF"; // BOM dla Excela
    $cols = ['imie_nazwisko', 'email', 'pesel', 'telefon', 'data_urodzenia',
             'adres', 'projekt_program', 'data_zawarcia', 'data_zakonczenia'];
    echo implode(';', $cols) . "\r\n";
    echo 'Jan Kowalski;jan@example.com;12345678901;500100200;1990-01-15;"ul. Przykładowa 1, 00-001 Warszawa";Projekt Alpha;2026-01-01;2026-12-31' . "\r\n";
    exit;
}

// ── Inicjalizacja sesji ───────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

$step       = '';
$errors     = [];
$notice     = '';
$csv_rows   = [];
$csv_header = [];
$delimiter  = ';';
$has_header = true;

// ── Mapowalne pola ────────────────────────────────────────────────────────────
$FIELDS = [
    'imie_nazwisko'   => 'Imię i nazwisko (wymagane)',
    'email'           => 'E-mail',
    'pesel'           => 'PESEL',
    'telefon'         => 'Telefon',
    'data_urodzenia'  => 'Data urodzenia',
    'adres'           => 'Adres',
    'projekt_program' => 'Projekt / program',
    'data_zawarcia'   => 'Data zawarcia',
    'data_zakonczenia'=> 'Data zakończenia',
];

// ── POST: Upload CSV ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'upload') {
    csrf_check();

    $delimiter  = ($_POST['delimiter'] ?? ';') === ',' ? ',' : ';';
    $has_header = (int)($_POST['has_header'] ?? 1) === 1;

    if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Nie przesłano pliku lub wystąpił błąd uploadu.';
    } else {
        $mime_ok = in_array($_FILES['csv_file']['type'] ?? '', [
            'text/csv', 'text/plain', 'application/csv',
            'application/vnd.ms-excel', 'application/octet-stream',
        ], true);
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $errors[] = 'Dozwolone są tylko pliki .csv lub .txt.';
        }
    }

    if (!$errors) {
        $tmp   = $_FILES['csv_file']['tmp_name'];
        $lines = [];

        // Wykryj i usuń BOM
        $raw = file_get_contents($tmp);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        file_put_contents($tmp, $raw);

        $handle = fopen($tmp, 'r');
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lines[] = array_map('trim', $row);
        }
        fclose($handle);

        if (count($lines) < ($has_header ? 2 : 1)) {
            $errors[] = 'Plik CSV jest pusty lub zawiera tylko nagłówek.';
        } else {
            if ($has_header) {
                $csv_header = array_shift($lines);
            } else {
                $cols = count($lines[0]);
                $csv_header = array_map(fn($i) => "Kolumna " . ($i + 1), range(0, $cols - 1));
            }
            $csv_rows = $lines;

            // Zapisz w sesji
            $_SESSION['csv_import'] = [
                'rows'      => $csv_rows,
                'header'    => $csv_header,
                'delimiter' => $delimiter,
            ];
            $step = 'preview';
        }
    }

// ── POST: Import ──────────────────────────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'import') {
    csrf_check();

    if (empty($_SESSION['csv_import'])) {
        $errors[] = 'Brak danych do importu. Zacznij od nowa.';
    } else {
        $session_data = $_SESSION['csv_import'];
        $csv_rows     = $session_data['rows'];
        $csv_header   = $session_data['header'];

        // Mapowanie: field_name => index kolumny lub '' (pomiń)
        $mapping = [];
        foreach (array_keys($FIELDS) as $fname) {
            $val = $_POST['map_' . $fname] ?? '';
            $mapping[$fname] = ($val !== '') ? (int)$val : null;
        }

        if ($mapping['imie_nazwisko'] === null) {
            $errors[] = 'Musisz zmapować kolumnę "Imię i nazwisko".';
        }

        if (!$errors) {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';

            $imported = 0;
            $skipped  = 0;
            $import_errors = [];

            foreach ($csv_rows as $row_idx => $row) {
                $row_num = $row_idx + 2; // +1 za nagłówek, +1 za indexing od 1

                $get = function(string $field) use ($row, $mapping): string {
                    $idx = $mapping[$field] ?? null;
                    if ($idx === null) return '';
                    return trim($row[$idx] ?? '');
                };

                $imie_nazwisko = $get('imie_nazwisko');
                if (!$imie_nazwisko) {
                    $import_errors[] = "Wiersz {$row_num}: pominięto — brak imienia i nazwiska.";
                    $skipped++;
                    continue;
                }

                // Generuj numer umowy
                try {
                    $numer = next_contract_number('wolontariat');
                } catch (\Throwable $e) {
                    $year  = date('Y');
                    $seq   = (int)(db_one("SELECT COUNT(*) AS c FROM umowy_wolontariat")['c'] ?? 0) + 1 + $row_idx;
                    $numer = sprintf('W-%s-%04d', $year, $seq);
                }

                $data_zawarcia   = $get('data_zawarcia')   ?: date('Y-m-d');
                $data_zakonczenia = $get('data_zakonczenia') ?: null;
                $email           = $get('email');
                $pesel           = $get('pesel');
                $telefon         = $get('telefon');
                $data_urodzenia  = $get('data_urodzenia') ?: null;
                $adres           = $get('adres');
                $projekt_program = $get('projekt_program');

                // Walidacja daty (prosta)
                foreach (['data_zawarcia' => $data_zawarcia,
                          'data_zakonczenia' => $data_zakonczenia,
                          'data_urodzenia' => $data_urodzenia] as $dfield => $dval) {
                    if ($dval && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dval)) {
                        $import_errors[] = "Wiersz {$row_num}: nieprawidłowy format daty w polu {$dfield} ({$dval}). Wymagany: YYYY-MM-DD. Wiersz pominięty.";
                        $skipped++;
                        continue 2;
                    }
                }

                $user = current_user();

                try {
                    db_insert('umowy_wolontariat', array_filter([
                        'numer_umowy'     => $numer,
                        'status'          => 'podpisana',
                        'imie_nazwisko'   => $imie_nazwisko,
                        'email'           => $email ?: null,
                        'pesel'           => $pesel ?: null,
                        'telefon'         => $telefon ?: null,
                        'data_urodzenia'  => $data_urodzenia,
                        'adres'           => $adres ?: null,
                        'projekt_program' => $projekt_program ?: null,
                        'data_zawarcia'   => $data_zawarcia,
                        'data_zakonczenia'=> $data_zakonczenia,
                        'created_by'      => $user ? (int)$user['id'] : null,
                        'created_at'      => date('Y-m-d H:i:s'),
                        'updated_at'      => date('Y-m-d H:i:s'),
                    ], fn($v) => $v !== null));

                    $new_id = db()->lastInsertId();

                    // Utwórz konto portalu jeśli jest e-mail
                    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $existing = db_one("SELECT id FROM users WHERE email = ?", [$email]);
                        if (!$existing) {
                            $plain = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(18))), 0, 12);
                            $hash  = password_hash($plain, PASSWORD_BCRYPT);
                            db_insert('users', [
                                'name'       => $imie_nazwisko,
                                'email'      => $email,
                                'password'   => $hash,
                                'role'       => 'viewer',
                                'is_active'  => 1,
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                            // Wyślij mail powitalny (jeśli kolejka dostępna)
                            try {
                                $org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                                $login_url = APP_URL . '/auth/login.php';
                                $body = "Witaj {$imie_nazwisko},\n\n"
                                      . "Zostało dla Ciebie utworzone konto w systemie {$org}.\n\n"
                                      . "Login: {$email}\n"
                                      . "Hasło: {$plain}\n\n"
                                      . "Zaloguj się: {$login_url}\n\n"
                                      . "Zmień hasło po pierwszym logowaniu.";
                                mail_queue_add($email, $imie_nazwisko, "Twoje konto w {$org}", $body);
                            } catch (\Throwable $e) { /* brak kolejki — pomiń */ }
                        }
                    }

                    admin_audit('import_csv_row', 'wolontariat', "Zaimportowano: {$imie_nazwisko}", (int)$new_id, $numer);
                    $imported++;

                } catch (\Throwable $e) {
                    $import_errors[] = "Wiersz {$row_num} ({$imie_nazwisko}): błąd bazy danych — " . $e->getMessage();
                    $skipped++;
                }
            }

            unset($_SESSION['csv_import']);
            admin_audit('import_csv_done', 'wolontariat', "Import CSV: {$imported} zaimportowanych, {$skipped} pominiętych.");

            $step   = 'done';
            $notice = "Zaimportowano: <strong>{$imported}</strong>, pominięto: <strong>{$skipped}</strong>.";
        }
    }

// ── GET: Wznów podgląd z sesji ────────────────────────────────────────────────
} elseif (!empty($_SESSION['csv_import']) && empty($errors)) {
    $session_data = $_SESSION['csv_import'];
    $csv_rows     = $session_data['rows'];
    $csv_header   = $session_data['header'];
    $delimiter    = $session_data['delimiter'];
    if ($csv_rows) $step = 'preview';
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:1200px">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/">Administrator</a></li>
      <li class="breadcrumb-item active">Import wolontariuszy z CSV</li>
    </ol>
  </nav>

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
      <i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>
      Import wolontariuszy z CSV
    </h1>
    <a href="?template=1" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download me-1"></i>Pobierz wzorzec CSV
    </a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <i class="bi bi-exclamation-triangle me-2"></i>
      <strong>Błędy:</strong>
      <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
          <li><?= h($e) ?></li>
        <?php endforeach ?>
      </ul>
    </div>
  <?php endif ?>

  <?php if ($notice): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $notice ?></div>
  <?php endif ?>

  <?php // ── KROK 1: Formularz uploadu ──────────────────────────────────────── ?>
  <?php if ($step === '' || $step === 'done'): ?>

    <?php if ($step === 'done' && !empty($import_errors)): ?>
      <div class="alert alert-warning">
        <strong>Ostrzeżenia / pominięte wiersze:</strong>
        <ul class="mb-0 mt-1 small">
          <?php foreach ($import_errors as $ie): ?>
            <li><?= h($ie) ?></li>
          <?php endforeach ?>
        </ul>
      </div>
    <?php endif ?>

    <?php if ($step === 'done'): ?>
      <div class="d-flex gap-2 mb-4">
        <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="btn btn-primary">
          <i class="bi bi-list-ul me-1"></i>Lista wolontariuszy
        </a>
        <a href="<?= APP_URL ?>/admin/import_volunteers.php" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-repeat me-1"></i>Nowy import
        </a>
      </div>
    <?php endif ?>

    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">
        <i class="bi bi-upload me-2 text-primary"></i>Wybierz plik CSV
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_step" value="upload">

          <div class="mb-3">
            <label class="form-label fw-semibold">Plik CSV <span class="text-danger">*</span></label>
            <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
            <div class="form-text">Obsługiwane formaty: .csv, .txt. Kodowanie: UTF-8 (zalecane).</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Separator kolumn</label>
              <select name="delimiter" class="form-select">
                <option value=";" selected>Średnik ( ; ) — domyślny Excel PL</option>
                <option value=",">Przecinek ( , )</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Pierwszy wiersz</label>
              <select name="has_header" class="form-select">
                <option value="1" selected>Zawiera nagłówki kolumn</option>
                <option value="0">Dane zaczynają się od 1. wiersza</option>
              </select>
            </div>
          </div>

          <div class="alert alert-info small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Pobierz <a href="?template=1" class="alert-link">wzorzec CSV</a>, aby zobaczyć wymagany format.
            Pole <strong>imie_nazwisko</strong> jest obowiązkowe. Daty w formacie <code>YYYY-MM-DD</code>.
          </div>

          <button type="submit" class="btn btn-primary">
            <i class="bi bi-eye me-1"></i>Podgląd i mapowanie kolumn
          </button>
        </form>
      </div>
    </div>

  <?php // ── KROK 2: Podgląd i mapowanie ──────────────────────────────────── ?>
  <?php elseif ($step === 'preview'): ?>
    <?php $preview_rows = array_slice($csv_rows, 0, 5); ?>

    <div class="alert alert-info mb-3">
      <i class="bi bi-table me-2"></i>
      Wczytano <strong><?= count($csv_rows) ?></strong> wierszy danych.
      Poniżej podgląd pierwszych <?= count($preview_rows) ?> wierszy.
      Przypisz kolumny CSV do pól systemu.
    </div>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_step" value="import">

      <!-- Mapowanie kolumn -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
          <i class="bi bi-arrow-left-right me-2 text-primary"></i>Mapowanie kolumn
        </div>
        <div class="card-body">
          <div class="row g-3">
            <?php foreach ($FIELDS as $fname => $flabel): ?>
              <div class="col-sm-6 col-lg-4">
                <label class="form-label fw-semibold small">
                  <?= h($flabel) ?>
                  <?php if ($fname === 'imie_nazwisko'): ?>
                    <span class="text-danger">*</span>
                  <?php endif ?>
                </label>
                <select name="map_<?= h($fname) ?>" class="form-select form-select-sm">
                  <option value="">— pomiń —</option>
                  <?php foreach ($csv_header as $ci => $ch): ?>
                    <?php
                    // Auto-sugestia: dopasuj nazwę kolumny do nazwy pola
                    $auto = (strtolower(trim($ch)) === strtolower($fname) ||
                             str_contains(strtolower($ch), strtolower(str_replace('_', '', $fname))));
                    ?>
                    <option value="<?= $ci ?>" <?= $auto ? 'selected' : '' ?>>
                      <?= h($ch) ?> (kol. <?= $ci + 1 ?>)
                    </option>
                  <?php endforeach ?>
                </select>
              </div>
            <?php endforeach ?>
          </div>
        </div>
      </div>

      <!-- Podgląd tabeli -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
          <i class="bi bi-table me-2 text-secondary"></i>Podgląd danych (pierwsze <?= count($preview_rows) ?> wierszy)
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered table-striped mb-0 small">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <?php foreach ($csv_header as $ch): ?>
                  <th><?= h($ch) ?></th>
                <?php endforeach ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($preview_rows as $ri => $row): ?>
                <tr>
                  <td class="text-muted"><?= $ri + 2 ?></td>
                  <?php foreach ($row as $cell): ?>
                    <td><?= h(mb_strimwidth((string)$cell, 0, 60, '…')) ?></td>
                  <?php endforeach ?>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <?php if (count($csv_rows) > 5): ?>
          <div class="card-footer text-muted small">
            … i jeszcze <?= count($csv_rows) - 5 ?> wierszy (łącznie <?= count($csv_rows) ?>).
          </div>
        <?php endif ?>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-check2-circle me-1"></i>Importuj <?= count($csv_rows) ?> wolontariuszy
        </button>
        <a href="<?= APP_URL ?>/admin/import_volunteers.php" class="btn btn-outline-secondary"
           onclick="return confirm('Anulować import i usunąć wczytane dane?')">
          <i class="bi bi-x-circle me-1"></i>Anuluj
        </a>
      </div>
    </form>

  <?php endif ?>

</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
