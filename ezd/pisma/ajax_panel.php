<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_login(); require_module_enabled('ezd_enabled', ''); ezd_require_access();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$id    = (int)($_GET['id'] ?? 0);
$pismo = ezd_pismo_get($id);
if (!$pismo) { http_response_code(404); echo '<div class="text-danger p-3">Pismo nie istnieje.</div>'; exit; }

$sprawa_id = (int)$pismo['sprawa_id'];
$sprawa    = ezd_sprawa_get($sprawa_id);
$access    = $sprawa ? ezd_sprawa_access($sprawa, (int)current_user()['id']) : null;
if (!$access) { http_response_code(403); echo '<div class="text-danger p-3">Brak dostępu.</div>'; exit; }

$can_act = $access === 'write' && ($pismo['sprawa_status'] ?? '') !== 'closed';
$kier    = EZD_KIERUNKI[$pismo['kierunek']] ?? ['label' => $pismo['kierunek'], 'icon' => 'bi-envelope', 'class' => 'secondary'];
$zal     = ezd_zalaczniki_by($sprawa_id, $id);
$csrf    = csrf_token();

$statuses = ['nowe' => 'Nowe', 'w_toku' => 'W toku', 'odpowiedziano' => 'Odpowiedziano', 'archiwum' => 'Archiwum'];
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <span class="badge bg-<?= $kier['class'] ?> bg-opacity-15 text-<?= $kier['class'] ?> border border-<?= $kier['class'] ?>">
    <i class="bi <?= $kier['icon'] ?> me-1"></i><?= h($kier['label']) ?>
  </span>
  <span class="badge bg-secondary bg-opacity-10 text-secondary border font-monospace" style="font-size:.7rem"><?= h($pismo['sygnatura']) ?></span>
  <?php if (!empty($pismo['rodzaj_medium'])): ?>
  <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.68rem">
    <i class="bi <?= EZD_MEDIA[$pismo['rodzaj_medium']]['icon'] ?? 'bi-question-circle' ?> me-1"></i><?= h(EZD_MEDIA[$pismo['rodzaj_medium']]['label'] ?? $pismo['rodzaj_medium']) ?>
  </span>
  <?php endif; ?>
</div>

<h6 class="fw-bold mb-3"><?= h($pismo['title']) ?></h6>

<dl class="row mb-3" style="font-size:.82rem;row-gap:.25rem">
  <?php if ($pismo['nadawca']): ?>
  <dt class="col-5 text-muted fw-normal">Nadawca</dt><dd class="col-7 mb-0"><?= h($pismo['nadawca']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['odbiorca']): ?>
  <dt class="col-5 text-muted fw-normal">Odbiorca</dt><dd class="col-7 mb-0"><?= h($pismo['odbiorca']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['data_pisma']): ?>
  <dt class="col-5 text-muted fw-normal">Data pisma</dt><dd class="col-7 mb-0"><?= date_pl($pismo['data_pisma']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['data_wplywu']): ?>
  <dt class="col-5 text-muted fw-normal">Data wpływu</dt><dd class="col-7 mb-0"><?= date_pl($pismo['data_wplywu']) ?></dd>
  <?php endif; ?>
  <?php if ($pismo['data_wysylki']): ?>
  <dt class="col-5 text-muted fw-normal">Data wysyłki</dt><dd class="col-7 mb-0"><?= date_pl($pismo['data_wysylki']) ?></dd>
  <?php endif; ?>
  <dt class="col-5 text-muted fw-normal">Referent</dt><dd class="col-7 mb-0"><?= h($pismo['owner_name'] ?? '—') ?></dd>
</dl>

<?php if ($pismo['tresc']): ?>
<div class="border rounded p-2 mb-3 bg-light" style="font-size:.82rem;white-space:pre-wrap;max-height:160px;overflow-y:auto"><?= h($pismo['tresc']) ?></div>
<?php endif; ?>

<!-- Załączniki -->
<?php if ($zal): ?>
<div class="mb-3">
  <div class="fw-semibold mb-2" style="font-size:.8rem"><i class="bi bi-paperclip me-1 text-primary"></i>Załączniki (<?= count($zal) ?>)</div>
  <?php foreach ($zal as $z): ?>
  <?php
    $zext    = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
    $is_pdf  = $zext === 'pdf';
    $is_off  = in_array($zext, EZD_OFFICE_ONLINE_EXT, true);
  ?>
  <div class="d-flex align-items-center gap-1 py-1 border-bottom" style="font-size:.78rem">
    <i class="bi <?= ezd_file_icon($z['original_name']) ?> flex-shrink-0 text-muted"></i>
    <div class="flex-grow-1 overflow-hidden">
      <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" target="_blank"
         class="text-decoration-none fw-semibold text-truncate d-block"><?= h($z['original_name']) ?></a>
      <span class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?></span>
    </div>
    <?php if ($is_pdf): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ezd-pdf-btn flex-shrink-0"
            data-url="<?= h(APP_URL . '/ezd/serve.php?id=' . $z['id']) ?>"
            data-name="<?= h($z['original_name']) ?>"
            title="Podgląd PDF"><i class="bi bi-eye"></i></button>
    <?php endif; ?>
    <?php if ($is_off): ?>
    <a href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $z['id'] ?>" target="_blank" rel="noopener"
       class="btn btn-sm btn-outline-primary py-0 px-1 flex-shrink-0" title="Word Online"><i class="bi bi-microsoft"></i></a>
    <?php endif; ?>
    <?= ezd_kopia_btn_one('zalacznik', (int)$z['id'], $z['original_name'], 'icon', 'btn-sm py-0 px-1 flex-shrink-0', 'el') ?>
    <?php if ($can_act): ?>
    <button type="button" class="btn btn-sm btn-outline-info py-0 px-1 flex-shrink-0 ezd-panel-from-zal"
            data-zal="<?= (int)$z['id'] ?>" data-name="<?= h($z['original_name']) ?>"
            title="Utwórz pismo z pliku"><i class="bi bi-envelope-plus"></i></button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Szybkie akcje -->
<?php if ($can_act): ?>
<div class="border rounded p-2 mb-3" style="background:#f8fafc">
  <div class="fw-semibold mb-2" style="font-size:.78rem;color:#64748b"><i class="bi bi-lightning me-1"></i>Szybkie akcje</div>

  <!-- Zmiana statusu -->
  <form class="d-flex gap-2 align-items-center mb-2 ezd-panel-status-form"
        data-pismo-id="<?= $id ?>" data-action-url="<?= h(APP_URL) ?>/ezd/pisma/ajax_action.php">
    <input type="hidden" name="_action" value="set_status">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <select name="status" class="form-select form-select-sm" style="font-size:.78rem">
      <?php foreach ($statuses as $sv => $sl): ?>
      <option value="<?= $sv ?>"<?= $pismo['status'] === $sv ? ' selected' : '' ?>><?= h($sl) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap" style="font-size:.78rem">Ustaw status</button>
  </form>

  <!-- Upload pliku -->
  <form class="d-flex gap-2 align-items-center ezd-panel-upload-form"
        data-pismo-id="<?= $id ?>" data-action-url="<?= h(APP_URL) ?>/ezd/pisma/ajax_action.php">
    <input type="hidden" name="_action" value="upload_file">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="file" name="file" class="form-control form-control-sm" style="font-size:.78rem" required>
    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap" style="font-size:.78rem"><i class="bi bi-upload me-1"></i>Dodaj</button>
  </form>
</div>
<?php endif; ?>

<!-- Przyciski -->
<div class="d-flex gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $id ?>&from_sprawa=<?= $sprawa_id ?>" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-box-arrow-up-right me-1"></i>Pełny widok
  </a>
  <?= ezd_kopia_btn_one('pismo', $id, $pismo['sygnatura'], 'label', 'btn-sm', 'el') ?>
  <?php if ($can_act): ?>
  <a href="<?= APP_URL ?>/ezd/pisma/edit.php?id=<?= $id ?>&from_sprawa=<?= $sprawa_id ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-pencil me-1"></i>Edytuj
  </a>
  <?php endif; ?>
</div>
