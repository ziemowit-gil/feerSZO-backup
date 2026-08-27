<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja_offers.php'; // klasa VolunteerModuleManager

require_role('admin', 'editor');

$rm = new VolunteerModuleManager();

// Tryb edycji
$edit_id = (int)($_GET['id'] ?? 0);
$offer   = $edit_id ? $rm->getOfferOrFail($edit_id) : null;
$is_edit = $offer !== null;

$PAGE_TITLE = $is_edit ? 'Edytuj ogłoszenie' : 'Nowe ogłoszenie rekrutacyjne';

// ── Domyślne pola ─────────────────────────────────────────────────────────
$vals = [
    'title'          => $offer['title']          ?? '',
    'content'        => $offer['content']        ?? '',
    'status'         => $offer['status']         ?? 'draft',
    'max_candidates' => $offer['max_candidates'] ?? '',
    'custom_fields'  => $offer['custom_fields']  ?? [],
    'avail_from'     => $offer['avail_from']     ?? '',
    'avail_to'       => $offer['avail_to']       ?? '',
    'rodo_text'      => $offer['rodo_text']      ?? '',
];
$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $vals['title']          = trim($_POST['title']          ?? '');
    $vals['content']        = trim($_POST['content']        ?? '');
    $vals['status']         = $_POST['status']              ?? 'draft';
    $vals['max_candidates'] = trim($_POST['max_candidates'] ?? '');
    $vals['avail_from']     = trim($_POST['avail_from']     ?? '');
    $vals['avail_to']       = trim($_POST['avail_to']       ?? '');
    $vals['rodo_text']      = trim($_POST['rodo_text']      ?? '');

    // Pola dodatkowe (custom_fields) — format: [{label, type, required}]
    $raw_labels   = $_POST['cf_label']    ?? [];
    $raw_types    = $_POST['cf_type']     ?? [];
    $raw_required = $_POST['cf_required'] ?? [];
    $cf = [];
    foreach ($raw_labels as $i => $lbl) {
        $lbl = trim($lbl);
        if (!$lbl) continue;
        $cf[] = [
            'label'    => $lbl,
            'type'     => in_array($raw_types[$i] ?? '', ['text','textarea','select','checkbox','date'], true)
                              ? $raw_types[$i] : 'text',
            'required' => isset($raw_required[$i]) ? 1 : 0,
        ];
    }
    $vals['custom_fields'] = $cf;

    // Walidacja
    if (!$vals['title'])  $errors[] = 'Tytuł ogłoszenia jest wymagany.';
    if (!in_array($vals['status'], array_keys(VolunteerModuleManager::OFFER_STATUSES), true)) {
        $errors[] = 'Nieprawidłowy status.';
    }
    if ($vals['max_candidates'] !== '' && ((int)$vals['max_candidates'] < 1)) {
        $errors[] = 'Limit kandydatów musi być liczbą większą od zera.';
    }

    if (!$errors) {
        $user = current_user();
        try {
            if ($is_edit) {
                $rm->updateOffer($edit_id, $vals);
                flash_set('success', 'Ogłoszenie zostało zaktualizowane.');
                header('Location: view.php?id=' . $edit_id); exit;
            } else {
                $new_id = $rm->createOffer(array_merge($vals, ['created_by' => (int)$user['id']]));
                flash_set('success', 'Ogłoszenie zostało utworzone.');
                header('Location: view.php?id=' . $new_id); exit;
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="index.php">Rekrutacja</a></li>
    <?php if ($is_edit): ?>
    <li class="breadcrumb-item"><a href="view.php?id=<?= $edit_id ?>"><?= h($offer['title']) ?></a></li>
    <li class="breadcrumb-item active">Edytuj</li>
    <?php else: ?>
    <li class="breadcrumb-item active">Nowe ogłoszenie</li>
    <?php endif; ?>
  </ol>
</nav>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Proszę poprawić błędy:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($errors as $e): ?>
    <li><?= h($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" id="offerForm" novalidate>
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="row g-4">

    <!-- ── Lewa kolumna: treść ogłoszenia ────────────────────────────────── -->
    <div class="col-xl-8">

      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">
          <i class="bi bi-megaphone text-primary me-1"></i>Treść ogłoszenia
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Tytuł ogłoszenia <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control"
                   value="<?= h($vals['title']) ?>"
                   placeholder="np. Wolontariusz ds. komunikacji i mediów społecznościowych"
                   required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Opis stanowiska / zakres zadań</label>
            <textarea name="content" class="form-control" rows="10"
                      placeholder="Opisz zakres obowiązków, wymagania, oferowane korzyści…"><?= h($vals['content']) ?></textarea>
            <div class="form-text">Możesz używać prostego formatowania tekstowego.</div>
          </div>
          <div class="mt-3">
            <label class="form-label fw-semibold">Klauzula RODO / informacja o danych osobowych</label>
            <textarea name="rodo_text" class="form-control" rows="5"
                      placeholder="Administratorem danych osobowych jest… Pozostaw puste aby użyć tekstu domyślnego."><?= h($vals['rodo_text']) ?></textarea>
            <div class="form-text">Wyświetlana kandydatom w formularzu zgłoszeniowym. Puste = tekst ogólny systemu.</div>
          </div>
        </div>
      </div>

      <!-- ── Pola dodatkowe w formularzu zgłoszeniowym ────────────────────── -->
      <div class="card shadow-sm">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
          <i class="bi bi-ui-checks text-secondary me-1"></i>Dodatkowe pola formularza zgłoszeniowego
          <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="addCfBtn">
            <i class="bi bi-plus-lg me-1"></i>Dodaj pole
          </button>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Zdefiniuj dodatkowe pytania, które kandydaci będą musieli wypełnić podczas zgłoszenia.
            Pola <em>Imię i nazwisko</em>, <em>E-mail</em> i <em>Telefon</em> są zawsze dostępne.
          </p>
          <div id="cfList">
            <?php foreach ($vals['custom_fields'] as $i => $cf): ?>
            <div class="cf-row border rounded p-3 mb-2 bg-light">
              <div class="row g-2 align-items-center">
                <div class="col-md-5">
                  <input type="text" name="cf_label[]" class="form-control form-control-sm"
                         placeholder="Etykieta pola" value="<?= h($cf['label']) ?>" required>
                </div>
                <div class="col-md-3">
                  <select name="cf_type[]" class="form-select form-select-sm">
                    <option value="text"     <?= ($cf['type'] ?? '') === 'text'     ? 'selected' : '' ?>>Tekst</option>
                    <option value="textarea" <?= ($cf['type'] ?? '') === 'textarea' ? 'selected' : '' ?>>Długi tekst</option>
                    <option value="date"     <?= ($cf['type'] ?? '') === 'date'     ? 'selected' : '' ?>>Data</option>
                    <option value="checkbox" <?= ($cf['type'] ?? '') === 'checkbox' ? 'selected' : '' ?>>Checkbox</option>
                  </select>
                </div>
                <div class="col-md-2">
                  <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox"
                           name="cf_required[<?= $i ?>]"
                           <?= !empty($cf['required']) ? 'checked' : '' ?>>
                    <label class="form-check-label small">Wymagane</label>
                  </div>
                </div>
                <div class="col-md-2 text-end">
                  <button type="button" class="btn btn-sm btn-outline-danger remove-cf-btn">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (!$vals['custom_fields']): ?>
          <div id="cfEmpty" class="text-muted small fst-italic">
            Brak dodatkowych pól. Kliknij „Dodaj pole" aby dodać pytania.
          </div>
          <?php else: ?>
          <div id="cfEmpty" class="text-muted small fst-italic d-none">
            Brak dodatkowych pól. Kliknij „Dodaj pole" aby dodać pytania.
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /col -->

    <!-- ── Prawa kolumna: ustawienia ─────────────────────────────────────── -->
    <div class="col-xl-4">

      <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">
          <i class="bi bi-gear text-secondary me-1"></i>Ustawienia
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
            <select name="status" class="form-select">
              <?php foreach (VolunteerModuleManager::OFFER_STATUSES as $s => $cfg): ?>
              <option value="<?= $s ?>" <?= $vals['status'] === $s ? 'selected' : '' ?>>
                <?= h($cfg['label']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">
              <strong>Szkic</strong> — widoczne tylko dla adminów.<br>
              <strong>Aktywne</strong> — przyjmuje zgłoszenia.<br>
              <strong>Zamknięte</strong> — zgłoszenia zablokowane.
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Wolontariat od</label>
            <input type="date" name="avail_from" class="form-control"
                   value="<?= h($vals['avail_from']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Wolontariat do</label>
            <input type="date" name="avail_to" class="form-control"
                   value="<?= h($vals['avail_to']) ?>">
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold">Limit kandydatów</label>
            <input type="number" name="max_candidates" class="form-control" min="1"
                   value="<?= h($vals['max_candidates']) ?>"
                   placeholder="Bez limitu">
            <div class="form-text">Pozostaw puste dla braku limitu.</div>
          </div>
        </div>
      </div>

      <?php if ($is_edit): ?>
      <div class="card shadow-sm mb-4 border-0 bg-light">
        <div class="card-body py-2 px-3">
          <div class="row g-2 text-muted small">
            <div class="col-6">
              <div>Utworzone</div>
              <div class="fw-semibold text-dark"><?= date_pl($offer['created_at']) ?></div>
            </div>
            <?php if ($offer['published_at']): ?>
            <div class="col-6">
              <div>Opublikowane</div>
              <div class="fw-semibold text-dark"><?= date_pl($offer['published_at']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($offer['closed_at']): ?>
            <div class="col-6">
              <div>Zamknięte</div>
              <div class="fw-semibold text-dark"><?= date_pl($offer['closed_at']) ?></div>
            </div>
            <?php endif; ?>
            <div class="col-6">
              <div>Zgłoszeń</div>
              <div class="fw-semibold text-dark"><?= (int)$offer['app_count'] ?></div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── Akcje ──────────────────────────────────────────────────────── -->
      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check2-circle me-1"></i>
          <?= $is_edit ? 'Zapisz zmiany' : 'Utwórz ogłoszenie' ?>
        </button>
        <a href="<?= $is_edit ? 'view.php?id=' . $edit_id : 'index.php' ?>"
           class="btn btn-outline-secondary">
          Anuluj
        </a>
      </div>

    </div><!-- /col -->
  </div><!-- /row -->
</form>

<template id="cfRowTemplate">
  <div class="cf-row border rounded p-3 mb-2 bg-light">
    <div class="row g-2 align-items-center">
      <div class="col-md-5">
        <input type="text" name="cf_label[]" class="form-control form-control-sm"
               placeholder="Etykieta pola" required>
      </div>
      <div class="col-md-3">
        <select name="cf_type[]" class="form-select form-select-sm">
          <option value="text">Tekst</option>
          <option value="textarea">Długi tekst</option>
          <option value="date">Data</option>
          <option value="checkbox">Checkbox</option>
        </select>
      </div>
      <div class="col-md-2">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" name="cf_required[]">
          <label class="form-check-label small">Wymagane</label>
        </div>
      </div>
      <div class="col-md-2 text-end">
        <button type="button" class="btn btn-sm btn-outline-danger remove-cf-btn">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    </div>
  </div>
</template>

<script>
(function () {
  const list    = document.getElementById('cfList');
  const empty   = document.getElementById('cfEmpty');
  const addBtn  = document.getElementById('addCfBtn');
  const tmpl    = document.getElementById('cfRowTemplate');

  function updateEmpty() {
    const rows = list.querySelectorAll('.cf-row');
    empty.classList.toggle('d-none', rows.length > 0);
  }

  addBtn.addEventListener('click', function () {
    const clone = tmpl.content.cloneNode(true);
    list.appendChild(clone);
    updateEmpty();
    list.lastElementChild.querySelector('input[name="cf_label[]"]').focus();
  });

  list.addEventListener('click', function (e) {
    const btn = e.target.closest('.remove-cf-btn');
    if (!btn) return;
    btn.closest('.cf-row').remove();
    updateEmpty();
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
