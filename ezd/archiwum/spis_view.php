<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$user_id = (int)current_user()['id'];
$id      = (int)($_GET['id'] ?? 0);
$spis    = ezd_arch_spis_get($id);
if (!$spis) { flash_set('error', 'Spis nie istnieje.'); header('Location:' . APP_URL . '/ezd/archiwum/index.php'); exit; }

$self = APP_URL . '/ezd/archiwum/spis_view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    try {
        if (!can_edit()) throw new \RuntimeException('Brak uprawnień.');
        if ($op === 'add_teczka') {
            $tid = (int)($_POST['teczka_id'] ?? 0);
            if ($tid) ezd_arch_spis_add_teczka($id, $tid, $user_id);
            flash_set('success', 'Dodano pozycję.');
        } elseif ($op === 'del_poz') {
            ezd_arch_poz_delete((int)($_POST['poz_id'] ?? 0), $user_id);
            flash_set('success', 'Usunięto pozycję.');
        } elseif ($op === 'save') {
            ezd_arch_spis_update($id, $_POST, $user_id);
            flash_set('success', 'Zapisano.');
        } elseif ($op === 'approve') {
            ezd_arch_spis_approve($id, $user_id);
            flash_set('success', 'Spis zatwierdzony.');
        } elseif ($op === 'realize') {
            if (!is_admin()) throw new \RuntimeException('Realizacja wymaga uprawnień administratora.');
            ezd_arch_spis_realize($id, $user_id);
            flash_set('success', 'Spis zrealizowany — segregatory zaktualizowane.');
        } elseif ($op === 'delete') {
            if (!is_admin()) throw new \RuntimeException('Usunięcie wymaga uprawnień administratora.');
            ezd_arch_spis_delete($id, $user_id);
            flash_set('success', 'Spis usunięty.');
            header('Location:' . APP_URL . '/ezd/archiwum/index.php'); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:' . $self); exit;
}

$spis     = ezd_arch_spis_get($id); // odśwież po ew. zmianie
$pozycje  = ezd_arch_pozycje($id);
$tm       = EZD_ARCH_SPIS_TYPY[$spis['typ']] ?? ['label'=>$spis['typ'],'icon'=>'bi-journal','class'=>'secondary'];
$editable = $spis['status'] !== 'zrealizowany' && can_edit();
// Kandydaci do dodania: dla ZO — zamknięte nieprzekazane; dla brakowania — w archiwum po terminie
$kandydaci = $spis['typ'] === 'brakowanie' ? ezd_teczki_do_brakowania() : ezd_teczki_do_przekazania();
$wSpisie   = array_column($pozycje, 'teczka_id');
$kandydaci = array_values(array_filter($kandydaci, fn($t) => !in_array($t['id'], $wSpisie)));

$PAGE_TITLE = ezd_arch_spis_sygnatura($spis);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/archiwum/index.php">Archiwum zakładowe</a></li>
  <li class="breadcrumb-item active"><?= h(ezd_arch_spis_sygnatura($spis)) ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge bg-<?= $tm['class'] ?>"><i class="bi <?= $tm['icon'] ?> me-1"></i><?= h($tm['label']) ?></span>
          <span class="font-monospace fw-bold"><?= h(ezd_arch_spis_sygnatura($spis)) ?></span>
          <?= ezd_arch_spis_status_badge($spis['status']) ?>
        </div>
        <h4 class="fw-bold mb-1"><?= h($spis['tytul'] ?: '(bez tytułu)') ?></h4>
        <div class="text-muted" style="font-size:.8rem">
          <?php if ($spis['komorka']): ?><i class="bi bi-diagram-3 me-1"></i><?= h($spis['komorka']) ?> &nbsp;·&nbsp;<?php endif; ?>
          <i class="bi bi-person me-1"></i><?= h($spis['created_name'] ?: '—') ?>
          <?php if ($spis['zgoda_ap']): ?>&nbsp;·&nbsp;<i class="bi bi-patch-check me-1"></i>Zgoda AP: <?= h($spis['zgoda_ap']) ?><?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/ezd/archiwum/spis_print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Drukuj</a>
        <?php if ($editable): ?>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal"><i class="bi bi-pencil me-1"></i>Edytuj</button>
        <?php endif; ?>
        <?php if (can_edit() && $spis['status'] === 'projekt'): ?>
        <form method="post" action="<?= $self ?>" onsubmit="return confirm('Zatwierdzić spis? Po zatwierdzeniu pozycji nie zmienisz.');" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="approve">
          <button class="btn btn-warning btn-sm"><i class="bi bi-check2-square me-1"></i>Zatwierdź</button>
        </form>
        <?php endif; ?>
        <?php if (is_admin() && $spis['status'] === 'zatwierdzony'): ?>
        <form method="post" action="<?= $self ?>" onsubmit="return confirm('<?= $spis['typ']==='brakowanie' ? 'Zrealizować brakowanie? Segregatory zostaną oznaczone jako wybrakowane.' : 'Zrealizować przekazanie? Segregatory trafią do archiwum zakładowego.' ?>');" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="realize">
          <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i>Zrealizuj</button>
        </form>
        <?php endif; ?>
        <?php if (is_admin() && $spis['status'] !== 'zrealizowany'): ?>
        <form method="post" action="<?= $self ?>" onsubmit="return confirm('Usunąć spis?');" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="delete">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($spis['uwagi']): ?><div class="mt-2 small text-muted"><?= nl2br(h($spis['uwagi'])) ?></div><?php endif; ?>
  </div>
</div>

<?php if ($editable && $kandydaci): ?>
<form method="post" action="<?= $self ?>" class="card shadow-sm mb-3">
  <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="add_teczka">
    <label class="fw-semibold small mb-0"><i class="bi bi-plus-circle me-1"></i>Dodaj segregator:</label>
    <select name="teczka_id" class="form-select form-select-sm" style="max-width:520px" required>
      <option value="">— wybierz —</option>
      <?php foreach ($kandydaci as $t): ?>
      <option value="<?= (int)$t['id'] ?>"><?= h($t['symbol'] . ' — ' . $t['title'] . ' (' . $t['rok'] . ', kat. ' . ($t['kat_arch'] ?: '—') . ')') ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm">Dodaj</button>
  </div>
</form>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between">
    <span><i class="bi bi-list-ol me-2"></i>Pozycje spisu</span>
    <span class="text-muted small"><?= count($pozycje) ?> poz.</span>
  </div>
  <div class="card-body p-0">
    <?php if (!$pozycje): ?>
      <p class="text-muted small p-3 mb-0">Brak pozycji. <?= $editable ? 'Dodaj segregatory z listy powyżej.' : '' ?></p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light"><tr>
          <th style="width:40px">Lp.</th><th>Znak segregatora</th><th>Tytuł</th>
          <th>Lata</th><th>Kat.</th><th><?= $spis['typ']==='brakowanie' ? 'Rok brak.' : 'Brakowanie po' ?></th>
          <?php if ($editable): ?><th></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($pozycje as $p): ?>
          <tr>
            <td><?= (int)$p['lp'] ?></td>
            <td class="font-monospace fw-bold text-primary"><?= h($p['znak']) ?></td>
            <td><?= h($p['tytul']) ?></td>
            <td class="text-muted"><?= h(trim(($p['rok_od'] ?: '') . (($p['rok_do'] && $p['rok_do']!=$p['rok_od']) ? '–' . $p['rok_do'] : ''), '–')) ?: '—' ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($p['kat_arch'] ?: '—') ?></span></td>
            <td><?= $p['rok_brakowania'] ? '<span class="badge bg-danger bg-opacity-10 text-danger">'.(int)$p['rok_brakowania'].'</span>' : '<span class="text-muted small">wieczyste / n.d.</span>' ?></td>
            <?php if ($editable): ?>
            <td class="text-end">
              <form method="post" action="<?= $self ?>" onsubmit="return confirm('Usunąć pozycję ze spisu?');" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="del_poz">
                <input type="hidden" name="poz_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0 px-1"><i class="bi bi-x-lg"></i></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($editable): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="<?= $self ?>" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="save">
      <div class="modal-header py-2"><h2 class="modal-title h6 mb-0">Edytuj spis</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Tytuł</label>
          <input type="text" name="tytul" class="form-control" value="<?= h($spis['tytul']) ?>"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Komórka organizacyjna</label>
          <input type="text" name="komorka" class="form-control" value="<?= h($spis['komorka']) ?>"></div>
        <?php if ($spis['typ']==='brakowanie'): ?>
        <div class="mb-3"><label class="form-label fw-semibold">Nr zgody archiwum państwowego</label>
          <input type="text" name="zgoda_ap" class="form-control" value="<?= h($spis['zgoda_ap']) ?>"></div>
        <?php endif; ?>
        <div class="mb-0"><label class="form-label fw-semibold">Uwagi</label>
          <textarea name="uwagi" class="form-control" rows="2"><?= h($spis['uwagi']) ?></textarea></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-primary btn-sm">Zapisz</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
