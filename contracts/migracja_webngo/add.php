<?php
/**
 * contracts/migracja_webngo/add.php — Rejestracja migracji umowy z webNGO.
 *
 * Formularz wymaga podania uzasadnienia migracji (pole obowiązkowe).
 * Po zapisaniu dostępne jest potwierdzenie migracji do wydruku.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once __DIR__ . '/_table.php';

require_role('admin', 'editor');
migracja_webngo_ensure_table();

$PAGE_TITLE = 'Migracja umowy z webNGO';
$errors     = [];
$row = [
    'status'          => 'w_toku',
    'data_migracji'   => date('Y-m-d'),
    'osoba_migrujaca' => current_user()['name'] ?? '',
];

// ── Pre-wypełnienie z istniejącej umowy (np. wolontariat) ────────────────────
$from_type       = trim($_GET['from_type'] ?? '');
$from_id         = intval($_GET['from_id'] ?? 0);
$from_contract   = null;
$from_back_url   = '';

$VALID_FROM_TYPES = ['wolontariat' => 'umowy_wolontariat', 'zlecenie' => 'umowy_zlecenie',
                     'dzielo' => 'umowy_dzielo', 'praca' => 'umowy_praca', 'uslugi' => 'umowy_uslugi'];

if ($from_type && $from_id && isset($VALID_FROM_TYPES[$from_type])) {
    $from_contract = db_one("SELECT * FROM {$VALID_FROM_TYPES[$from_type]} WHERE id = ?", [$from_id]);
    if ($from_contract) {
        $from_back_url = APP_URL . '/contracts/' . $from_type . '/view.php?id=' . $from_id;
        // Pre-wypełnij pola
        $row = array_merge($row, [
            'imie_nazwisko'      => $from_contract['imie_nazwisko'] ?? '',
            'pesel'              => $from_contract['pesel']         ?? '',
            'email'              => $from_contract['email']         ?? ($from_contract['m365_login'] ?? ''),
            'typ_umowy_zrodla'   => $from_type,
            'webngo_id'          => $from_contract['webngo_id']          ?? '',
            'webngo_numer_umowy' => $from_contract['webngo_numer_umowy'] ?? '',
            'webngo_data_zawarcia' => $from_contract['data_zawarcia']    ?? '',
            'source_contract_type' => $from_type,
            'source_contract_id'   => $from_id,
        ]);
        $PAGE_TITLE = 'Protokół migracji — ' . ($from_contract['numer_umowy'] ?? '');
    }
}

$POWODY = [
    'zmiana_systemu'   => 'Zmiana systemu (przejście z webNGO na FEER SZO)',
    'blad_danych'      => 'Błąd / niekompletność danych w webNGO',
    'rozszerzenie'     => 'Rozszerzenie zakresu funkcji (nowe moduły)',
    'wymog_ustawowy'   => 'Wymóg ustawowy lub regulaminowy',
    'ujednolicenie'    => 'Ujednolicenie dokumentacji w organizacji',
    'inne'             => 'Inne (opisz w uzasadnieniu)',
];

$TYPY_UMOW = [
    'zlecenie'    => 'Umowa zlecenie',
    'wolontariat' => 'Porozumienie wolontariackie',
    'dzielo'      => 'Umowa o dzieło',
    'praca'       => 'Umowa o pracę',
    'uslugi'      => 'Umowa o świadczenie usług',
    'inne'        => 'Inna umowa',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = array_merge($row, $_POST);
    unset($row['_csrf']);

    // Walidacja
    if (empty(trim($row['webngo_numer_umowy'] ?? '')))
        $errors[] = 'Numer umowy w webNGO jest wymagany.';
    if (empty(trim($row['typ_umowy_zrodla'] ?? '')))
        $errors[] = 'Typ umowy źródłowej jest wymagany.';
    if (empty(trim($row['imie_nazwisko'] ?? '')))
        $errors[] = 'Imię i nazwisko jest wymagane.';
    if (empty(trim($row['powod_migracji'] ?? '')))
        $errors[] = 'Powód migracji jest wymagany.';
    if (strlen(trim($row['opis_powodu'] ?? '')) < 20)
        $errors[] = 'Uzasadnienie migracji jest wymagane (minimum 20 znaków).';
    if (empty(trim($row['data_migracji'] ?? '')))
        $errors[] = 'Data migracji jest wymagana.';

    if (!$errors) {
        $allowed = [
            'numer_umowy', 'imie_nazwisko', 'pesel', 'email',
            'typ_umowy_zrodla', 'webngo_id', 'webngo_numer_umowy', 'webngo_data_zawarcia',
            'powod_migracji', 'opis_powodu',
            'nowy_typ_umowy', 'nowy_numer_umowy',
            'source_contract_type', 'source_contract_id',
            'status', 'data_migracji', 'osoba_migrujaca', 'uwagi',
            'created_by', 'created_at', 'updated_at',
        ];
        // Zachowaj powiązanie z umową źródłową (mogło przyjść z GET, nie z POST)
        if ($from_type && $from_id) {
            $row['source_contract_type'] = $from_type;
            $row['source_contract_id']   = $from_id;
        }

        $row['numer_umowy'] = 'MIG-' . date('Y') . '-' . str_pad(
            (db_one("SELECT COUNT(*) AS c FROM umowy_migracja_webngo WHERE numer_umowy LIKE ?",
                ['MIG-' . date('Y') . '-%'])['c'] ?? 0) + 1, 4, '0', STR_PAD_LEFT
        );
        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        $data = array_intersect_key($row, array_flip($allowed));
        $id   = db_insert('umowy_migracja_webngo', $data);

        log_contract_action('migracja_webngo', $id, current_user()['id'], 'create',
            'Zarejestrowano migrację: ' . $row['numer_umowy']);

        flash_set('success', 'Migracja zarejestrowana. Możesz teraz wydrukować potwierdzenie.');
        header('Location: ' . APP_URL . '/contracts/migracja_webngo/view.php?id=' . $id);
        exit;
    }
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-arrow-left-right text-warning"></i>
    <?= $from_contract
        ? 'Protokół migracji — ' . h($from_contract['numer_umowy'] ?? '')
        : 'Migracja umowy z webNGO' ?>
  </h4>
  <div class="d-flex gap-2">
    <?php if ($from_back_url): ?>
    <a href="<?= h($from_back_url) ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> Wróć do umowy
    </a>
    <?php endif; ?>
    <a href="list.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-list"></i> Lista
    </a>
  </div>
</div>

<?php if ($from_contract): ?>
<div class="alert alert-info py-2 mb-3">
  <i class="bi bi-link-45deg"></i>
  Protokół zostanie powiązany z umową
  <strong><?= h($from_contract['numer_umowy'] ?? '') ?></strong>
  (<?= h($from_contract['imie_nazwisko'] ?? '') ?>).
  Dane wypełnione automatycznie — sprawdź i uzupełnij uzasadnienie.
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <strong>Popraw błędy:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <?php if ($from_type && $from_id): ?>
  <input type="hidden" name="source_contract_type" value="<?= h($from_type) ?>">
  <input type="hidden" name="source_contract_id"   value="<?= h($from_id) ?>">
  <?php endif; ?>

  <!-- ── Umowa źródłowa (webNGO) ─────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-warning bg-opacity-10 fw-semibold">
      <i class="bi bi-database-down text-warning"></i> Umowa w systemie webNGO
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Typ umowy <span class="text-danger">*</span></label>
          <select name="typ_umowy_zrodla" class="form-select" required>
            <option value="">— wybierz —</option>
            <?php foreach ($TYPY_UMOW as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($row['typ_umowy_zrodla'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Numer umowy w webNGO <span class="text-danger">*</span></label>
          <input type="text" name="webngo_numer_umowy" class="form-control"
                 value="<?= h($row['webngo_numer_umowy'] ?? '') ?>" placeholder="np. WNG/2023/456" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">ID w webNGO <small class="text-muted">(opcjonalne)</small></label>
          <input type="text" name="webngo_id" class="form-control"
                 value="<?= h($row['webngo_id'] ?? '') ?>" placeholder="wewnętrzny identyfikator">
        </div>
        <div class="col-md-4">
          <label class="form-label">Data zawarcia w webNGO</label>
          <input type="date" name="webngo_data_zawarcia" class="form-control"
                 value="<?= h($row['webngo_data_zawarcia'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="imie_nazwisko" class="form-control"
                 value="<?= h($row['imie_nazwisko'] ?? '') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">PESEL</label>
          <input type="text" name="pesel" class="form-control font-monospace" maxlength="11"
                 value="<?= h($row['pesel'] ?? '') ?>" placeholder="11 cyfr">
        </div>
        <div class="col-md-2">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control"
                 value="<?= h($row['email'] ?? '') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- ── Powód migracji (WYMAGANY) ──────────────────────────────────── -->
  <div class="card shadow-sm mb-3 border-danger border-opacity-25">
    <div class="card-header bg-danger bg-opacity-10 fw-semibold">
      <i class="bi bi-exclamation-circle text-danger"></i>
      Uzasadnienie migracji <span class="text-danger">*</span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-5">
          <label class="form-label fw-semibold">Powód migracji <span class="text-danger">*</span></label>
          <select name="powod_migracji" class="form-select" required>
            <option value="">— wybierz powód —</option>
            <?php foreach ($POWODY as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($row['powod_migracji'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">
            Szczegółowe uzasadnienie migracji <span class="text-danger">*</span>
            <small class="text-muted fw-normal">(wymagane, min. 20 znaków)</small>
          </label>
          <textarea name="opis_powodu" class="form-control" rows="5" required
                    placeholder="Opisz szczegółowo powód przeniesienia tej umowy z webNGO do FEER SZO. Wskaż co zmieniło się w danych, jakie błędy zostały wykryte lub z jakiego powodu konieczna była migracja..."><?= h($row['opis_powodu'] ?? '') ?></textarea>
          <div class="form-text">
            <i class="bi bi-info-circle"></i>
            Uzasadnienie jest archiwizowane jako część dokumentacji technicznej organizacji.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Nowa umowa w systemie ──────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header bg-success bg-opacity-10 fw-semibold">
      <i class="bi bi-database-up text-success"></i> Nowa umowa w FEER SZO
      <small class="text-muted fw-normal">(jeśli już dodana)</small>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Typ nowej umowy</label>
          <select name="nowy_typ_umowy" class="form-select">
            <option value="">— nie dodano jeszcze —</option>
            <?php foreach ($TYPY_UMOW as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($row['nowy_typ_umowy'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Numer nowej umowy</label>
          <input type="text" name="nowy_numer_umowy" class="form-control"
                 value="<?= h($row['nowy_numer_umowy'] ?? '') ?>" placeholder="np. ZL/2024/001">
        </div>
      </div>
    </div>
  </div>

  <!-- ── Dane operacyjne ────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">
      <i class="bi bi-person-gear"></i> Dane operacyjne
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label fw-semibold">Data migracji <span class="text-danger">*</span></label>
          <input type="date" name="data_migracji" class="form-control"
                 value="<?= h($row['data_migracji'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Osoba wykonująca migrację</label>
          <input type="text" name="osoba_migrujaca" class="form-control"
                 value="<?= h($row['osoba_migrujaca'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="w_toku"     <?= ($row['status'] ?? '') === 'w_toku'     ? 'selected' : '' ?>>W toku</option>
            <option value="zakonczona" <?= ($row['status'] ?? '') === 'zakonczona' ? 'selected' : '' ?>>Zakończona</option>
            <option value="anulowana"  <?= ($row['status'] ?? '') === 'anulowana'  ? 'selected' : '' ?>>Anulowana</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Uwagi dodatkowe</label>
          <textarea name="uwagi" class="form-control" rows="2"><?= h($row['uwagi'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-warning fw-semibold">
      <i class="bi bi-save"></i> Zapisz migrację
    </button>
    <a href="list.php" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</form>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
