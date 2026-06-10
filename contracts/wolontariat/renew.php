<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';

require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Brak ID umowy.');
    header('Location: list.php');
    exit;
}

$TABLE = 'umowy_wolontariat';
$TYPE  = 'wolontariat';

$row = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$id]);
if (!$row) {
    http_response_code(404);
    flash_set('error', 'Umowa nie istnieje.');
    header('Location: list.php');
    exit;
}

$PAGE_TITLE = 'Przedłużenie umowy — ' . $row['numer_umowy'];

// ── Oblicz domyślną nową datę zakończenia (+1 rok) ────────────────────────────
$current_end = $row['data_zakonczenia'] ?? null;
$default_new_end = '';
if ($current_end) {
    $dt = date_create($current_end);
    if ($dt) {
        date_add($dt, date_interval_create_from_date_string('1 year'));
        $default_new_end = date_format($dt, 'Y-m-d');
    }
}
$default_new_start = $row['data_zakonczenia'] ?? $row['data_rozpoczecia'] ?? date('Y-m-d');

// ── Oblicz następny suffix /Pn ────────────────────────────────────────────────
function next_renewal_suffix(string $base_nr): string {
    // Szuka istniejących /P1, /P2 itd. i wybiera kolejny
    $existing = db_all(
        "SELECT numer_umowy FROM umowy_wolontariat WHERE numer_umowy LIKE ? ORDER BY numer_umowy",
        [$base_nr . '/P%']
    );
    $max_n = 0;
    foreach ($existing as $e) {
        if (preg_match('|/P(\d+)$|', $e['numer_umowy'], $m)) {
            $max_n = max($max_n, (int)$m[1]);
        }
    }
    return $base_nr . '/P' . ($max_n + 1);
}

// Bazowy numer (usuń ewentualne wcześniejsze sufiksy /P1, /P2 itd.)
$base_numer = preg_replace('|/P\d+$|', '', $row['numer_umowy']);
$suggested_numer = next_renewal_suffix($base_numer);

// ── POST: zapis ───────────────────────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_renew'])) {
    csrf_check();

    $mode        = $_POST['renew_mode']   ?? 'new';   // 'new' | 'update'
    $new_end     = trim($_POST['data_zakonczenia'] ?? '');
    $new_start   = trim($_POST['data_rozpoczecia'] ?? '');
    $new_status  = trim($_POST['status'] ?? 'podpisana');
    $note        = trim($_POST['uwagi_przedluzenia'] ?? '');
    $send_email  = !empty($_POST['send_email']);

    // Walidacja
    if (!$new_end) {
        $errors[] = 'Data zakończenia jest wymagana.';
    } elseif ($new_start && $new_end < $new_start) {
        $errors[] = 'Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.';
    }
    if (!array_key_exists($new_status, STATUS_LABELS)) {
        $new_status = 'podpisana';
    }

    if (!$errors) {
        $uid = (int)current_user()['id'];

        if ($mode === 'update') {
            // Zmień daty w istniejącej umowie
            $update_data = ['data_zakonczenia' => $new_end, 'status' => $new_status];
            if ($new_start) $update_data['data_rozpoczecia'] = $new_start;
            if ($note) {
                $old_uwagi = trim($row['uwagi'] ?? '');
                $update_data['uwagi'] = $old_uwagi
                    ? $old_uwagi . "\n\n[Przedłużenie " . date('d.m.Y') . "]: " . $note
                    : '[Przedłużenie ' . date('d.m.Y') . ']: ' . $note;
            }
            db_update($TABLE, $update_data, $id);

            log_contract_action($TYPE, $id, $uid, 'renewal_update',
                'Przedłużono umowę (zmiana dat): data_zakonczenia=' . $new_end
                . ($note ? ', uwaga: ' . $note : ''));

            $new_id = $id;
            flash_set('success', 'Daty umowy zostały zaktualizowane.');

        } else {
            // Utwórz nową umowę jako kopię
            $new_numer = trim($_POST['numer_umowy_new'] ?? $suggested_numer);
            if (!$new_numer) $new_numer = $suggested_numer;

            // Pola do skopiowania (wyklucz id, numer_umowy, daty, status, uwagi, created_*)
            $exclude = ['id', 'numer_umowy', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
                        'status', 'uwagi', 'created_at', 'created_by', 'updated_at',
                        'plik_umowy', 'plik_potwierdzenia',
                        'id_dokumentu_el', 'godzin_przepracowanych'];
            $new_data = [];
            foreach ($row as $k => $v) {
                if (!in_array($k, $exclude, true)) {
                    $new_data[$k] = $v;
                }
            }
            $new_data['numer_umowy']        = $new_numer;
            $new_data['data_zawarcia']       = date('Y-m-d');
            $new_data['data_rozpoczecia']    = $new_start ?: $current_end;
            $new_data['data_zakonczenia']    = $new_end;
            $new_data['status']              = $new_status;
            $new_data['godzin_przepracowanych'] = 0;
            $new_data['uwagi']               = $note ?: null;
            $new_data['created_by']          = $uid;
            $new_data['created_at']          = date('Y-m-d H:i:s');
            $new_data['updated_at']          = date('Y-m-d H:i:s');

            $new_id = db_insert($TABLE, $new_data);

            // Ustaw status oryginału na "aneks" — blokuje edycję, sygnalizuje że jest nowsza wersja
            db_update($TABLE, ['status' => 'aneks'], $id);

            log_contract_action($TYPE, $new_id, $uid, 'renewal_create',
                'Utworzono nową umowę z przedłużenia #' . $id . ' (' . $row['numer_umowy'] . ').'
                . ($note ? ' Uwaga: ' . $note : ''));
            log_contract_action($TYPE, $id, $uid, 'renewed_by',
                'Przedłużona nową umową #' . $new_id . ' (' . $new_numer . '). Status zmieniony na: aneks.');

            flash_set('success', 'Nowa umowa przedłużenia została utworzona: ' . $new_numer);
        }

        // ── E-mail do wolontariusza ───────────────────────────────────────────
        if ($send_email) {
            $target_row  = db_one("SELECT * FROM {$TABLE} WHERE id=?", [$new_id]);
            $vol_email   = trim($target_row['email'] ?? $row['email'] ?? '');
            $vol_name    = $target_row['imie_nazwisko'] ?? $row['imie_nazwisko'] ?? '';
            $org         = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';

            if ($vol_email && filter_var($vol_email, FILTER_VALIDATE_EMAIL)) {
                $end_fmt   = date_pl($new_end);
                $start_fmt = $new_start ? date_pl($new_start) : date_pl($current_end ?? '');
                $numer_display = ($mode === 'update') ? h($row['numer_umowy']) : h($new_data['numer_umowy'] ?? $suggested_numer);

                $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#1e40af,#3b82f6);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">
    <span style="opacity:.8">Przedłużenie porozumienia wolontariackiego</span>
  </h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$vol_name}</strong>!</p>
  <p>Informujemy, że Twoje porozumienie wolontariackie z organizacją <strong>{$org}</strong>
     zostało przedłużone.</p>
  <table style="width:100%;border-collapse:collapse;margin:16px 0">
    <tr style="background:#f8fafc">
      <td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600">Nr umowy</td>
      <td style="padding:8px 12px;border:1px solid #e2e8f0">{$numer_display}</td>
    </tr>
    <tr>
      <td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:600">Nowy okres</td>
      <td style="padding:8px 12px;border:1px solid #e2e8f0">{$start_fmt} – {$end_fmt}</td>
    </tr>
  </table>
  <?php if ($note): ?>
  <div style="background:#eff6ff;border-left:4px solid #3b82f6;padding:12px 16px;border-radius:4px;margin:16px 0;font-size:.9em">
    <?= h($note) ?>
  </div>
  <?php endif; ?>
  <p style="font-size:.85em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    W razie pytań skontaktuj się ze swoim opiekunem w {$org}.
  </p>
</div>
</body></html>
HTML;
                try {
                    approval_send_email($vol_email,
                        'Przedłużenie porozumienia wolontariackiego — ' . $org,
                        $body);
                } catch (\Throwable $e) {
                    // Nie przerywamy — mail to opcja, nie blokada
                }
            }
        }

        // Przekieruj na stronę przedłużenia z proponowanym pobraniem aneksu
        header('Location: view.php?id=' . $new_id . '&show_aneks=1&orig_id=' . $id);
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bi bi-arrow-repeat text-primary me-1"></i>
    Przedłużenie umowy
    <span class="badge bg-secondary fw-normal ms-1"><?= h($row['numer_umowy']) ?></span>
  </h4>
  <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Wróć do umowy
  </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0">
    <?php foreach ($errors as $e): ?>
    <li><?= h($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- Karta z danymi bieżącej umowy -->
<div class="card shadow-sm mb-4 border-0" style="background:#f8fafc">
  <div class="card-body py-3">
    <div class="row g-2">
      <div class="col-sm-3">
        <div class="text-muted" style="font-size:.75rem">Wolontariusz</div>
        <div class="fw-semibold"><?= h($row['imie_nazwisko'] ?? '—') ?></div>
      </div>
      <div class="col-sm-2">
        <div class="text-muted" style="font-size:.75rem">Status</div>
        <div><?= status_badge($row['status']) ?></div>
      </div>
      <div class="col-sm-2">
        <div class="text-muted" style="font-size:.75rem">Data rozpoczęcia</div>
        <div><?= date_pl($row['data_rozpoczecia']) ?></div>
      </div>
      <div class="col-sm-2">
        <div class="text-muted" style="font-size:.75rem">Data zakończenia</div>
        <div><?= date_pl($row['data_zakonczenia']) ?></div>
      </div>
      <div class="col-sm-3">
        <div class="text-muted" style="font-size:.75rem">Miejsce</div>
        <div><?= h($row['miejsce_wolontariatu'] ?? '—') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Formularz przedłużenia -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-arrow-repeat text-primary me-1"></i> Parametry przedłużenia
  </div>
  <div class="card-body">
    <form method="post" id="renewForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_renew" value="1">
      <input type="hidden" name="id" value="<?= $id ?>">

      <!-- Tryb: nowa umowa vs. zmiana dat -->
      <div class="mb-4">
        <label class="form-label fw-semibold">Sposób przedłużenia</label>
        <div class="d-flex gap-3 flex-wrap">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="renew_mode" id="mode_new" value="new" checked
                   onchange="toggleRenewMode(this.value)">
            <label class="form-check-label" for="mode_new">
              <i class="bi bi-plus-circle text-success me-1"></i>
              <strong>Utwórz nową umowę</strong>
              <span class="text-muted small d-block">Kopia z nowym numerem, oryginał bez zmian</span>
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="renew_mode" id="mode_update" value="update"
                   onchange="toggleRenewMode(this.value)">
            <label class="form-check-label" for="mode_update">
              <i class="bi bi-pencil-square text-warning me-1"></i>
              <strong>Zmień daty bieżącej umowy</strong>
              <span class="text-muted small d-block">Modyfikuje istniejący rekord</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Numer nowej umowy (tylko w trybie "nowa") -->
      <div id="field_new_numer" class="mb-3">
        <label for="numer_umowy_new" class="form-label">Numer nowej umowy</label>
        <input type="text" id="numer_umowy_new" name="numer_umowy_new"
               class="form-control" value="<?= h($suggested_numer) ?>">
        <div class="form-text">Sugerowany: <code><?= h($suggested_numer) ?></code></div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label for="data_rozpoczecia" class="form-label">Nowa data rozpoczęcia</label>
          <input type="date" id="data_rozpoczecia" name="data_rozpoczecia"
                 class="form-control" value="<?= h($default_new_start) ?>">
        </div>
        <div class="col-sm-6">
          <label for="data_zakonczenia" class="form-label">Nowa data zakończenia <span class="text-danger">*</span></label>
          <input type="date" id="data_zakonczenia" name="data_zakonczenia"
                 class="form-control" value="<?= h($default_new_end) ?>" required>
          <div class="form-text">Domyślnie +1 rok od bieżącej daty zakończenia.</div>
        </div>
      </div>

      <div class="mb-3">
        <label for="status" class="form-label">Nowy status</label>
        <select id="status" name="status" class="form-select">
          <?php foreach (STATUS_LABELS as $sv => $sm): ?>
          <option value="<?= h($sv) ?>" <?= $sv === 'podpisana' ? 'selected' : '' ?>>
            <?= h($sm['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label for="uwagi_przedluzenia" class="form-label">Notatka o przedłużeniu</label>
        <textarea id="uwagi_przedluzenia" name="uwagi_przedluzenia"
                  class="form-control" rows="3"
                  placeholder="Opcjonalna notatka do przedłużenia (np. podstawa, warunki)"></textarea>
      </div>

      <!-- Checkbox e-mail -->
      <?php if (!empty($row['email'])): ?>
      <div class="mb-4 form-check form-switch">
        <input class="form-check-input" type="checkbox" id="send_email" name="send_email" value="1" checked>
        <label class="form-check-label" for="send_email">
          Wyślij e-mail do wolontariusza o przedłużeniu
          <span class="text-muted small">(<?= h($row['email']) ?>)</span>
        </label>
      </div>
      <?php else: ?>
      <div class="alert alert-warning py-2 small mb-4">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Brak adresu e-mail — powiadomienie nie zostanie wysłane.
      </div>
      <?php endif; ?>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-arrow-repeat me-1"></i> Przedłuż umowę
        </button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
          <i class="bi bi-x-lg me-1"></i> Anuluj
        </a>
      </div>

    </form>
  </div>
</div>

<script>
function toggleRenewMode(mode) {
    var fieldNumer = document.getElementById('field_new_numer');
    if (fieldNumer) {
        fieldNumer.style.display = (mode === 'new') ? '' : 'none';
    }
}
// Inicjalizacja
toggleRenewMode('new');
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
