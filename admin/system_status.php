<?php
/**
 * admin/system_status.php — Globalny status systemu.
 * Pozwala administratorowi przełączyć system w tryb:
 *   • aktywny,
 *   • tylko do odczytu (zapis wstrzymany dla zwykłych użytkowników),
 *   • przestój w pracy (system niedostępny dla zwykłych użytkowników).
 * W obu trybach nieaktywnych admin podaje powód, widoczny w nagłówku/na stronie informacyjnej.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Status systemu';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode   = $_POST['mode'] ?? 'active';
    if (!array_key_exists($mode, SYSTEM_STATUS_MODES)) $mode = 'active';
    $reason = trim($_POST['reason'] ?? '');

    if ($mode !== 'active' && $reason === '') {
        flash_set('error', 'Podaj powód — jest wymagany przy ustawieniu systemu jako nieaktywny.');
        header('Location: ' . APP_URL . '/admin/system_status.php'); exit;
    }

    $by = current_user()['name'] ?? (current_user()['email'] ?? 'admin');
    system_status_set($mode, $reason, $by);

    $lbl = SYSTEM_STATUS_MODES[$mode]['label'];
    flash_set('success', $mode === 'active'
        ? 'System ustawiony jako aktywny.'
        : 'Status systemu: ' . $lbl . '. Zapis danych dla zwykłych użytkowników jest wstrzymany.');
    header('Location: ' . APP_URL . '/admin/system_status.php'); exit;
}

$cur    = system_status_meta();
$reason = $cur['reason'];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-toggles text-primary me-2"></i>Status systemu</h4>
  <span class="badge bg-<?= h($cur['color']) ?>-subtle text-<?= h($cur['color']) ?>-emphasis border border-<?= h($cur['color']) ?>-subtle">
    <i class="bi <?= h($cur['icon']) ?> me-1"></i><?= h($cur['label']) ?>
  </span>
</div>

<?= flash_html() ?>

<p class="text-body-secondary">
  Globalne wstrzymanie pracy systemu. Administratorzy zawsze zachowują pełny dostęp —
  ograniczenia dotyczą tylko zwykłych użytkowników.
</p>

<div class="card border-0 shadow-sm" style="max-width:720px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <?php foreach (SYSTEM_STATUS_MODES as $key => $m): ?>
      <label class="d-flex align-items-start gap-3 border rounded p-3 mb-3 <?= $cur['mode']===$key ? 'border-'.$m['color'].' bg-'.$m['color'].'-subtle' : '' ?>"
             style="cursor:pointer">
        <input class="form-check-input mt-1 flex-shrink-0" type="radio" name="mode" value="<?= h($key) ?>"
               <?= $cur['mode']===$key ? 'checked' : '' ?>
               onchange="document.getElementById('reasonWrap').style.display = this.value==='active' ? 'none' : 'block'">
        <span>
          <span class="fw-semibold d-flex align-items-center gap-2">
            <i class="bi <?= h($m['icon']) ?> text-<?= h($m['color']) ?>"></i><?= h($m['label']) ?>
          </span>
          <span class="text-body-secondary small"><?= h($m['desc']) ?></span>
        </span>
      </label>
      <?php endforeach; ?>

      <div id="reasonWrap" style="display:<?= $cur['mode']==='active' ? 'none' : 'block' ?>">
        <label class="form-label fw-semibold" for="reason">Powód (widoczny dla użytkowników)</label>
        <textarea class="form-control" id="reason" name="reason" rows="3"
                  placeholder="np. Prace serwisowe do godz. 16:00, migracja danych, inwentaryzacja…"><?= h($reason) ?></textarea>
        <div class="form-text">Wymagany przy trybie nieaktywnym. Pojawi się w nagłówku oraz na stronie informacyjnej.</div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Zapisz status</button>
      </div>
    </form>
  </div>
</div>

<?php if ($cur['mode'] !== 'active' && !empty($cur['since'])): ?>
<p class="text-body-secondary small mt-3">
  Obecny status obowiązuje od <?= h(date('d.m.Y H:i', strtotime($cur['since']))) ?><?= !empty($cur['by']) ? ', ustawił(a): '.h($cur['by']) : '' ?>.
</p>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
