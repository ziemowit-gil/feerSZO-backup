<?php
/**
 * crm/cases/types.php — typy spraw i SLA.
 *
 * Typ nadaje sprawie kategorię (skarga, wniosek, zgłoszenie beneficjenta) i czasy
 * SLA: ile godzin na pierwszą odpowiedź, ile dni na zamknięcie. Bez typów wszystkie
 * sprawy są jedną kupą — nie da się powiedzieć „ile skarg w kwartale" ani pilnować
 * terminów różnych dla różnych spraw.
 *
 * SLA liczymy od WPŁYWU sprawy, bo klient liczy czas od swojego zgłoszenia.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_case_extras.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('cases', 'write');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}

$PAGE_TITLE = 'Typy spraw i SLA';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'save') {
        $id = crm_case_type_save([
            'name'           => $_POST['name'] ?? '',
            'color'          => $_POST['color'] ?? '#6B7280',
            'sla_response_h' => $_POST['sla_response_h'] ?? 0,
            'sla_close_d'    => $_POST['sla_close_d'] ?? 0,
            'sort_order'     => $_POST['sort_order'] ?? 0,
            'is_active'      => !empty($_POST['is_active']),
        ], (int)($_POST['id'] ?? 0) ?: null);
        flash_set($id ? 'success' : 'danger', $id ? 'Typ zapisany.' : 'Typ musi mieć nazwę.');
        header('Location: ' . APP_URL . '/crm/cases/types.php'); exit;
    }

    if ($op === 'delete') {
        crm_case_type_delete((int)($_POST['id'] ?? 0));
        flash_set('success', 'Typ usunięty — sprawy zostały bez typu.');
        header('Location: ' . APP_URL . '/crm/cases/types.php'); exit;
    }
}

$edit_param = trim((string)($_GET['edit'] ?? ''));
$edit_id    = ctype_digit($edit_param) ? (int)$edit_param : 0;
$edit       = $edit_id ? crm_case_type($edit_id) : null;
$show_form  = $edit_param !== '' && ($edit_id === 0 || $edit !== null);
$rows       = crm_case_types(false);

// Ile spraw w każdym typie — bez tego nie widać, czy katalog żyje
$counts = [];
try {
    foreach (db_all("SELECT type_id, COUNT(*) AS n FROM crm_cases WHERE type_id IS NOT NULL GROUP BY type_id") as $r) {
        $counts[(int)$r['type_id']] = (int)$r['n'];
    }
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<style>
.tp-card { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem 1.15rem;margin-bottom:.8rem }
.tp-dot { width:10px;height:10px;border-radius:3px;display:inline-block;flex-shrink:0 }
.tp-meta { font-size:.78rem;color:#9CA3AF }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-tags me-2" style="color:#0176D3"></i>Typy spraw i SLA</h1>
  <span class="text-muted small">kategoria + czasy reakcji liczone od wpływu</span>
  <a class="btn btn-crm-primary btn-sm ms-auto" href="?edit=new"><i class="bi bi-plus-lg me-1"></i>Nowy typ</a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if (!$rows): ?>
    <div class="tp-card text-muted small">
      Brak typów. Sensowny start: <strong>Skarga</strong> (24 h na odpowiedź, 14 dni na zamknięcie),
      <strong>Wniosek</strong> (48 h / 30 dni), <strong>Zapytanie ogólne</strong> (bez SLA).
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $t): ?>
    <div class="tp-card d-flex align-items-start gap-2">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2">
          <span class="tp-dot" style="background:<?= h($t['color']) ?>"></span>
          <strong><?= h($t['name']) ?></strong>
          <?php if ((int)$t['is_active'] !== 1): ?>
          <span class="tp-meta">(wyłączony)</span>
          <?php endif; ?>
          <span class="tp-meta ms-auto"><?= (int)($counts[(int)$t['id']] ?? 0) ?> spraw</span>
        </div>
        <div class="tp-meta">
          SLA:
          <?= (int)$t['sla_response_h'] ? (int)$t['sla_response_h'] . ' h na pierwszą odpowiedź' : 'bez terminu odpowiedzi' ?>
          ·
          <?= (int)$t['sla_close_d'] ? (int)$t['sla_close_d'] . ' dni na zamknięcie' : 'bez terminu zamknięcia' ?>
        </div>
      </div>
      <div class="d-flex gap-1">
        <a class="btn btn-outline-secondary btn-sm py-0 px-2" href="?edit=<?= (int)$t['id'] ?>" title="Edytuj">
          <i class="bi bi-pencil"></i>
        </a>
        <form method="post" onsubmit="return confirm('Usunąć typ „<?= h(addslashes($t['name'])) ?>”? Sprawy zostaną bez typu.')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="delete">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-outline-danger btn-sm py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="col-lg-5">
    <?php if ($show_form): ?>
    <div class="tp-card">
      <h2 class="h6 fw-bold mb-2"><?= $edit ? 'Edycja typu' : 'Nowy typ' ?></h2>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

        <div class="mb-2">
          <label class="form-label small fw-semibold" for="tpName">Nazwa <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" id="tpName" name="name" required maxlength="100"
                 value="<?= h($edit['name'] ?? '') ?>" placeholder="np. Skarga">
        </div>

        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label small fw-semibold" for="tpResp">Odpowiedź w (godz.)</label>
            <input type="number" min="0" max="720" class="form-control form-control-sm" id="tpResp"
                   name="sla_response_h" value="<?= (int)($edit['sla_response_h'] ?? 0) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold" for="tpClose">Zamknięcie w (dni)</label>
            <input type="number" min="0" max="365" class="form-control form-control-sm" id="tpClose"
                   name="sla_close_d" value="<?= (int)($edit['sla_close_d'] ?? 0) ?>">
          </div>
          <div class="col-12 form-text" style="font-size:.72rem">0 = bez terminu. Czas liczy się od wpływu sprawy.</div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label small fw-semibold" for="tpColor">Kolor</label>
            <input type="color" class="form-control form-control-sm form-control-color" id="tpColor"
                   name="color" value="<?= h($edit['color'] ?? '#6B7280') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold" for="tpSort">Kolejność</label>
            <input type="number" class="form-control form-control-sm" id="tpSort" name="sort_order"
                   value="<?= (int)($edit['sort_order'] ?? 0) ?>">
          </div>
        </div>

        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="tpActive" value="1"
                 <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="tpActive">Aktywny (do wyboru przy sprawie)</label>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
          <a class="btn btn-outline-secondary btn-sm" href="<?= APP_URL ?>/crm/cases/types.php">Anuluj</a>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div class="tp-card text-muted small">
      <strong class="d-block mb-1 text-dark">Po co typy</strong>
      Kategoria pozwala policzyć sprawy w podziale na rodzaje, a SLA pilnuje terminów:
      karta sprawy pokazuje, ile zostało do pierwszej odpowiedzi i do zamknięcia,
      a cron przypomina prowadzącemu, zanim termin minie.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
