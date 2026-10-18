<?php
/**
 * admin/application.php — szczegóły pisma / wniosku użytkownika: pola formularza, dokumenty wzoru
 * (wniosek, PDF, potwierdzenie, przesyłka EZD), załącznik, odpowiedź i status.
 * Lista (admin/applications.php) pokazuje tylko wiersze z odnośnikiem tutaj.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/letters.php';
require_once dirname(__DIR__) . '/includes/applications.php';

require_login();
if (!can_read('admin') && !can_read('pisma')) {
    http_response_code(403); die('Brak dostępu.');
}

$status_meta = [
    'nowy'        => ['label' => 'Nowy',        'class' => 'primary'],
    'w_trakcie'   => ['label' => 'W trakcie',   'class' => 'warning'],
    'rozpatrzony' => ['label' => 'Rozpatrzony', 'class' => 'success'],
    'odrzucony'   => ['label' => 'Odrzucony',   'class' => 'danger'],
];
$contract_labels = ['zlecenie' => 'Zlecenie', 'wolontariat' => 'Wolontariat', 'dzielo' => 'Dzieło', 'praca' => 'Praca'];

$id   = (int)($_GET['id'] ?? 0);
$SELF = APP_URL . '/admin/application.php?id=' . $id;
$LIST = APP_URL . '/admin/applications.php';

// ── Akcje ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_write('pisma') && !can_write('admin')) { http_response_code(403); die(); }
    $action = $_POST['action'] ?? '';
    if ($action === 'odpowiedz' && $id) {
        $status = $_POST['status'] ?? 'rozpatrzony';
        if (!isset($status_meta[$status]) || $status === 'nowy') $status = 'rozpatrzony';
        db_update('user_applications', [
            'status'       => $status,
            'odpowiedz'    => trim($_POST['odpowiedz'] ?? ''),
            'odpowiedz_at' => date('Y-m-d H:i:s'),
            'answered_by'  => current_user()['id'],
        ], $id);
        flash_set('success', 'Odpowiedź zapisana.');
    } elseif ($action === 'set_status' && $id) {
        $status = $_POST['status'] ?? '';
        if (isset($status_meta[$status])) {
            db_update('user_applications', ['status' => $status], $id);
            flash_set('success', 'Status zmieniony: ' . $status_meta[$status]['label'] . '.');
        }
    }
    header('Location: ' . $SELF); exit;
}

$app = db_one(
    "SELECT a.*, u.name AS user_name, u.email AS user_email, ab.name AS answered_by_name,
            t.label AS type_label, t.icon AS type_icon, t.allow_attachment AS type_allow_att, t.name AS type_name
     FROM user_applications a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN users ab ON ab.id = a.answered_by
     LEFT JOIN application_types t ON t.id = a.type_id
     WHERE a.id = ?", [$id]);
if (!$app) { flash_set('danger', 'Pismo nie istnieje.'); header('Location: ' . $LIST); exit; }

$st     = $status_meta[$app['status']] ?? ['label' => $app['status'], 'class' => 'secondary'];
$fields = $app['type_id'] ? app_type_fields((int)$app['type_id']) : [];
$fdata  = json_decode($app['fields_json'] ?? '{}', true) ?: [];
$field_labels = array_column($fields, 'label', 'name');

$PAGE_TITLE = $app['tytul'] . ' — pismo użytkownika';
include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h($LIST) ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Pisma i wnioski użytkowników</a></nav>

<div class="d-flex flex-wrap align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0">
    <i class="bi <?= h($app['type_icon'] ?? 'bi-file-text') ?> text-primary fs-4" aria-hidden="true"></i>
  </div>
  <div class="flex-grow-1">
    <h1 class="h4 mb-1"><?= h($app['tytul']) ?> <span class="badge bg-<?= $st['class'] ?> align-middle fs-6"><?= $st['label'] ?></span></h1>
    <div class="text-muted small d-flex flex-wrap gap-2 align-items-center">
      <span><?= h($app['type_label'] ?? '—') ?></span>
      <span class="opacity-50">·</span>
      <span>złożone <?= h(date('d.m.Y H:i', strtotime($app['created_at']))) ?></span>
      <span class="opacity-50">·</span>
      <span>nr #<?= (int)$app['id'] ?></span>
    </div>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-8">
    <section class="card shadow-sm" aria-labelledby="h-fields">
      <div class="card-header bg-white"><h2 id="h-fields" class="h6 mb-0"><i class="bi bi-ui-checks me-1" aria-hidden="true"></i>Treść wniosku</h2></div>
      <div class="card-body">
        <?php if (!$fdata): ?><p class="text-muted mb-3">Brak wypełnionych pól.</p><?php endif; ?>
<!-- Pola dynamiczne -->
        <?php if ($fdata): ?>
        <div class="row g-2 mb-3">
          <?php foreach ($fields as $f):
              $val = $fdata[$f['name']] ?? null;
              if ($val === null || $val === '') continue;
          ?>
          <div class="col-sm-6 col-lg-4">
            <div class="bg-light rounded p-2">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em"><?= h($f['label']) ?></div>
              <div class="fw-semibold small"><?= h($val) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php // fallback dla starych rekordów bez type_id
          foreach ($fdata as $k => $v): if ($v && !isset($field_labels[$k])): ?>
          <div class="col-sm-6 col-lg-4">
            <div class="bg-light rounded p-2">
              <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em"><?= h(str_replace('_', ' ', $k)) ?></div>
              <div class="fw-semibold small"><?= h($v) ?></div>
            </div>
          </div>
          <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Dokument wniosku ze wzoru (ZSW-1, ZSW-2, DOK) + potwierdzenie + przesyłka w EZD -->
        <?php $_adoc = appDocsTemplateFor($app['type_name'] ?? null); if ($_adoc): $_arpw = appDocsRpw($app); ?>
        <div class="mb-3 d-flex flex-wrap gap-2">
          <a href="<?= h(appDocsUrl((int)$app['id'])) ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-file-earmark-text me-1"></i>Wniosek <?= h($_adoc['code']) ?></a>
          <a href="<?= h(appDocsUrl((int)$app['id'], true)) ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
          <a href="<?= h(appDocsReceiptUrl((int)$app['id'])) ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-patch-check me-1"></i>Potwierdzenie</a>
          <?php if ($_arpw): ?>
          <a href="<?= APP_URL ?>/ezd/rpw/view.php?id=<?= (int)$_arpw['id'] ?>" class="btn btn-sm btn-outline-dark"><i class="bi bi-mailbox me-1"></i>EZD: <?= h($_arpw['label']) ?></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Załącznik -->
        <?php if ($app['plik']): ?>
        <div class="mb-3">
          <a href="<?= h(letter_file_url($app['plik'])) ?>" class="btn btn-sm btn-outline-secondary" download target="_blank">
            <i class="bi bi-paperclip me-1"></i>Pobierz załącznik
          </a>
        </div>
        <?php endif; ?>

        <!-- Odpowiedź admina -->
        <?php if ($app['odpowiedz']): ?>
        <div class="alert alert-success py-2 px-3 mb-3 small">
          <i class="bi bi-reply me-1"></i>
          <strong>Odpowiedź:</strong> <?= h($app['odpowiedz']) ?>
          <span class="text-muted ms-2"><?= h(substr($app['odpowiedz_at'] ?? '', 0, 10)) ?></span>
        </div>
        <?php endif; ?>

      </div>
    </section>
  </div>

  <div class="col-lg-4">
    <section class="card shadow-sm mb-4" aria-labelledby="h-who">
      <div class="card-header bg-white"><h2 id="h-who" class="h6 mb-0"><i class="bi bi-person me-1" aria-hidden="true"></i>Składający</h2></div>
      <div class="card-body small">
        <div class="fw-semibold"><?= h($app['user_name']) ?></div>
        <div class="text-muted mb-2"><?= h($app['user_email']) ?></div>
        <?php if ($app['contract_type'] && $app['contract_id']): ?>
        <a href="<?= APP_URL ?>/contracts/<?= h($app['contract_type']) ?>/view.php?id=<?= (int)$app['contract_id'] ?>" class="text-decoration-none">
          <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i><?= h($contract_labels[$app['contract_type']] ?? $app['contract_type']) ?> #<?= (int)$app['contract_id'] ?></a>
        <?php else: ?><span class="text-muted">bez powiązanej umowy</span><?php endif; ?>
      </div>
    </section>

    <?php if (can_write('pisma') || can_write('admin')): ?>
    <section class="card shadow-sm mb-4" aria-labelledby="h-status">
      <div class="card-header bg-white"><h2 id="h-status" class="h6 mb-0"><i class="bi bi-flag me-1" aria-hidden="true"></i>Status</h2></div>
      <div class="card-body">
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="set_status">
          <label for="st_sel" class="visually-hidden">Status</label>
          <select id="st_sel" name="status" class="form-select form-select-sm">
            <?php foreach ($status_meta as $sv => $sm): ?>
            <option value="<?= $sv ?>"<?= $app['status'] === $sv ? ' selected' : '' ?>><?= $sm['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Zmień</button>
        </form>
      </div>
    </section>

    <section class="card shadow-sm" aria-labelledby="h-reply">
      <div class="card-header bg-white"><h2 id="h-reply" class="h6 mb-0"><i class="bi bi-reply me-1" aria-hidden="true"></i>Odpowiedź dla składającego</h2></div>
      <div class="card-body">
        <?php if ($app['odpowiedz_at']): ?>
        <p class="small text-muted mb-2">Ostatnio: <?= h(date('d.m.Y H:i', strtotime($app['odpowiedz_at']))) ?><?= $app['answered_by_name'] ? ' · ' . h($app['answered_by_name']) : '' ?></p>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="odpowiedz">
          <label for="odp" class="visually-hidden">Treść odpowiedzi</label>
          <textarea id="odp" name="odpowiedz" class="form-control form-control-sm mb-2" rows="5" placeholder="Treść odpowiedzi dla użytkownika..."><?= h($app['odpowiedz'] ?? '') ?></textarea>
          <div class="d-flex gap-2 align-items-center">
            <label for="odp_st" class="visually-hidden">Status po odpowiedzi</label>
            <select id="odp_st" name="status" class="form-select form-select-sm w-auto">
              <?php foreach (['rozpatrzony', 'w_trakcie', 'odrzucony'] as $sv): ?>
              <option value="<?= $sv ?>"<?= $app['status'] === $sv ? ' selected' : '' ?>><?= $status_meta[$sv]['label'] ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz odpowiedź</button>
          </div>
        </form>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
