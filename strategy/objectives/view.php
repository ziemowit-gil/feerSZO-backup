<?php
/**
 * strategy/objectives/view.php — Szczegóły i edycja celu strategicznego.
 * Cross-link: powiązane umowy, granty, działania.
 */
// Deleguj do admin/strategy_objective.php z nadpisaniem nagłówka
define('STRATEGY_MODULE_HEADER', dirname(__DIR__) . '/includes/header_strategy.php');
define('STRATEGY_MODULE_FOOTER', dirname(__DIR__) . '/includes/footer_strategy.php');
define('STRATEGY_BASE_URL', 'APP_URL_PLACEHOLDER/strategy');

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/strategy.php';

require_login();

$id     = (int)($_GET['id'] ?? 0);
$is_new = isset($_GET['new']);
$errors = [];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $nazwa    = trim($_POST['nazwa'] ?? '');
        $sphere   = (int)($_POST['sphere_id'] ?? 0) ?: null;
        $data_od  = $_POST['data_od']  ?: null;
        $data_do  = $_POST['data_do']  ?: null;
        $wd = $_POST['wartosc_docelowa'] !== '' ? (float)$_POST['wartosc_docelowa'] : null;
        $wb = $_POST['wartosc_bazowa']   !== '' ? (float)$_POST['wartosc_bazowa']   : null;
        $status = in_array($_POST['status'],['aktywny','wstrzymany','zakończony'],true) ? $_POST['status'] : 'aktywny';

        if (!$nazwa) $errors[] = 'Nazwa celu jest wymagana.';
        if (!$errors) {
            if ($id) {
                db()->prepare("UPDATE strategy_objectives
                    SET sphere_id=?,nazwa=?,opis=?,cel_miernika=?,wartosc_docelowa=?,wartosc_bazowa=?,
                        data_od=?,data_do=?,status=?,waga=?,updated_at=datetime('now','localtime')
                    WHERE id=?")->execute([
                    $sphere, $nazwa, trim($_POST['opis']??''), trim($_POST['cel_miernika']??''),
                    $wd, $wb, $data_od, $data_do, $status, max(1,(int)($_POST['waga']??1)), $id
                ]);
                flash_set('success', 'Cel zaktualizowany.');
            } else {
                $id = db_insert('strategy_objectives', [
                    'sphere_id'=>$sphere,'nazwa'=>$nazwa,'opis'=>trim($_POST['opis']??''),
                    'cel_miernika'=>trim($_POST['cel_miernika']??''),'wartosc_docelowa'=>$wd,
                    'wartosc_bazowa'=>$wb,'data_od'=>$data_od,'data_do'=>$data_do,'status'=>$status,
                    'waga'=>max(1,(int)($_POST['waga']??1)),'created_by'=>current_user()['id'],
                ]);
                flash_set('success', 'Cel dodany.');
            }
            header("Location: " . APP_URL . "/strategy/objectives/view.php?id={$id}"); exit;
        }
    }

    if ($action === 'add_mapping') {
        $obj_id = (int)($_POST['objective_id'] ?? 0);
        $etype  = in_array($_POST['entity_type'],['contract','grant','action'],true)?$_POST['entity_type']:'';
        $eid    = (int)($_POST['entity_id'] ?? 0);
        $ctype  = ($etype === 'contract') ? trim($_POST['contract_type']??'') : null;
        if ($obj_id && $etype && $eid) {
            try {
                db_insert('strategy_mapping', [
                    'objective_id'=>$obj_id,'entity_type'=>$etype,'entity_id'=>$eid,
                    'contract_type'=>$ctype,'contribution_weight'=>(float)($_POST['contribution_weight']??100),
                    'note'=>trim($_POST['mapping_note']??''),'added_by'=>current_user()['id'],
                ]);
                flash_set('success', 'Powiązanie dodane.');
            } catch (\Throwable $e) {
                flash_set('error', str_contains($e->getMessage(),'UNIQUE') ? 'Powiązanie już istnieje.' : $e->getMessage());
            }
        }
        header("Location: " . APP_URL . "/strategy/objectives/view.php?id={$obj_id}"); exit;
    }

    if ($action === 'delete_mapping') {
        $mid = (int)($_POST['mapping_id'] ?? 0);
        $oid = (int)($_POST['objective_id'] ?? $id);
        if ($mid) { db()->prepare("DELETE FROM strategy_mapping WHERE id=?")->execute([$mid]); flash_set('success','Powiązanie usunięte.'); }
        header("Location: " . APP_URL . "/strategy/objectives/view.php?id={$oid}"); exit;
    }

    if ($action === 'refresh_progress') {
        $oid = (int)($_POST['objective_id'] ?? $id);
        strategy_add_progress_snapshot($oid);
        flash_set('success', 'Snapshot dodany.');
        header("Location: " . APP_URL . "/strategy/objectives/view.php?id={$oid}"); exit;
    }

    if ($action === 'add_progress') {
        $oid = (int)($_POST['objective_id'] ?? $id);
        db_insert('strategy_progress', [
            'objective_id'=>$oid,'wartosc_realizowana'=>(float)($_POST['wartosc_realizowana']??0),
            'budzet_wydany'=>(float)($_POST['budzet_wydany']??0),'budzet_przypisany'=>(float)($_POST['budzet_przypisany']??0),
            'snapshot_date'=>date('Y-m-d'),'source'=>'manual','notatka'=>trim($_POST['notatka']??''),
        ]);
        flash_set('success','Wpis postępu zapisany.');
        header("Location: " . APP_URL . "/strategy/objectives/view.php?id={$oid}"); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$obj      = $id ? db_one("SELECT * FROM v_strategy_dashboard WHERE id=?", [$id]) : null;
$spheres  = db_all("SELECT id,kod,nazwa FROM public_benefit_spheres WHERE is_active=1 ORDER BY sort_order");
$mappings = $id ? strategy_mappings_for_objective($id) : [];
$progress = $id ? db_all("SELECT * FROM strategy_progress WHERE objective_id=? ORDER BY snapshot_date DESC,id DESC LIMIT 20",[$id]) : [];
$grants_list  = db_all("SELECT id,nazwa,nr_umowy FROM grants ORDER BY nazwa LIMIT 200");
$actions_list = db_all("SELECT id,nazwa FROM actions ORDER BY nazwa LIMIT 200");
$contract_types = ['wolontariat'=>'Wolontariat','zlecenie'=>'Zlecenie','dzielo'=>'Dzieło','uslugi'=>'Usługi','praca'=>'Praca','inne'=>'Inne'];

$PAGE_TITLE = $obj ? h($obj['nazwa']) : 'Nowy cel strategiczny';

// Pre-fill sphere from URL
$preselect_sphere = (int)($_GET['sphere_id'] ?? 0);

include dirname(__DIR__) . '/includes/header_strategy.php';
?>

<div class="mb-3">
  <a href="<?= APP_URL ?>/strategy/objectives/index.php" class="text-muted text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Wszystkie cele
  </a>
</div>

<?php if (!empty($errors)): ?>
<div class="strat-alert strat-alert-danger" role="alert">
  <i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;flex-shrink:0;margin-top:.15rem"></i>
  <ul class="mb-0 ps-3"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- Formularz celu -->
<div class="col-lg-5">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-bullseye" style="color:var(--strat-accent)"></i>
      <?= $id ? 'Edytuj cel' : 'Nowy cel strategiczny' ?>
      <?php if ($obj): ?>&nbsp;<?= strategy_health_badge(strategy_health_score($obj)) ?><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">

        <div class="mb-3">
          <label class="form-label fw-semibold small">Nazwa celu <span class="text-danger">*</span></label>
          <input name="nazwa" class="form-control form-control-sm" required value="<?= h($obj['nazwa']??'') ?>">
        </div>
        <div class="row g-2 mb-3">
          <div class="col-8">
            <label class="form-label fw-semibold small">Sfera pożytku</label>
            <select name="sphere_id" class="form-select form-select-sm">
              <option value="">— bez sfery —</option>
              <?php foreach($spheres as $s):
                  $sel = ($obj['sphere_id']??$preselect_sphere) == $s['id'];
              ?>
              <option value="<?= $s['id'] ?>" <?= $sel?'selected':'' ?>><?= h($s['kod']) ?> — <?= h($s['nazwa']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold small">Status</label>
            <select name="status" class="form-select form-select-sm">
              <?php foreach(['aktywny'=>'Aktywny','wstrzymany'=>'Wstrzymany','zakończony'=>'Zakończony'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($obj['status']??'aktywny')===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Miernik sukcesu</label>
          <input name="cel_miernika" class="form-control form-control-sm"
                 placeholder="np. Liczba wolontariuszy, % realizacji"
                 value="<?= h($obj['cel_miernika']??'') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Opis</label>
          <textarea name="opis" class="form-control form-control-sm" rows="2"><?= h($obj['opis']??'') ?></textarea>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label fw-semibold small">Wartość docelowa</label>
            <input name="wartosc_docelowa" type="number" step="0.01" class="form-control form-control-sm"
                   value="<?= h($obj['wartosc_docelowa']??'') ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small">Priorytet (1–10)</label>
            <input name="waga" type="number" min="1" max="10" class="form-control form-control-sm"
                   value="<?= (int)($obj['waga']??5) ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small">Data od</label>
            <input name="data_od" type="date" class="form-control form-control-sm" value="<?= h($obj['data_od']??'') ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small">Data do</label>
            <input name="data_do" type="date" class="form-control form-control-sm" value="<?= h($obj['data_do']??'') ?>">
          </div>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm"
                  style="background:var(--strat-accent);color:#fff;border:none">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
          <a href="<?= APP_URL ?>/strategy/objectives/index.php" class="btn btn-outline-secondary btn-sm">Anuluj</a>
          <?php if ($id): ?>
          <form method="post" class="ms-auto">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="refresh_progress">
            <input type="hidden" name="objective_id" value="<?= $id ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($id && $progress): ?>
  <!-- Historia postępu -->
  <div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold small"><i class="bi bi-clock-history me-1"></i>Historia postępu</div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
      <thead class="table-light">
        <tr><th>Data</th><th>Realizacja</th><th>Budżet</th><th>Źródło</th></tr>
      </thead>
      <tbody>
        <?php foreach($progress as $p): ?>
        <tr>
          <td><?= date('d.m.Y',strtotime($p['snapshot_date'])) ?></td>
          <td><?= number_format((float)$p['wartosc_realizowana'],2) ?></td>
          <td><?= number_format((float)$p['budzet_wydany'],2) ?>/<?= number_format((float)$p['budzet_przypisany'],2) ?></td>
          <td><span class="badge bg-secondary"><?= h($p['source']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Cross-link powiązań -->
<?php if ($id): ?>
<div class="col-lg-7">
  <!-- Dodaj powiązanie -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">
      <i class="bi bi-link-45deg me-1" style="color:var(--strat-accent)"></i>
      Powiązania Cross-Link
      <span class="badge bg-secondary ms-1"><?= count($mappings) ?></span>
    </div>
    <div class="card-body">
      <?php if (can_edit() || is_admin()): ?>
      <form method="post" class="mb-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_mapping">
        <input type="hidden" name="objective_id" value="<?= $id ?>">
        <div class="row g-2 align-items-end">
          <div class="col-3">
            <label class="form-label fw-semibold small">Typ</label>
            <select name="entity_type" id="entity_type_sel" class="form-select form-select-sm"
                    onchange="toggleEntityFields()">
              <option value="contract">Umowa</option>
              <option value="grant">Grant</option>
              <option value="action">Działanie</option>
            </select>
          </div>
          <div id="cf" class="col-5">
            <label class="form-label fw-semibold small">Typ umowy + ID</label>
            <div class="input-group input-group-sm">
              <select name="contract_type" class="form-select" style="max-width:100px">
                <?php foreach($contract_types as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
                <?php endforeach; ?>
              </select>
              <input name="entity_id" type="number" min="1" class="form-control" placeholder="ID" id="ce_id">
            </div>
          </div>
          <div id="gf" class="col-5" style="display:none">
            <label class="form-label fw-semibold small">Grant</label>
            <select name="entity_id" class="form-select form-select-sm" id="ge_id">
              <option value="">— wybierz —</option>
              <?php foreach($grants_list as $g): ?>
              <option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="af" class="col-5" style="display:none">
            <label class="form-label fw-semibold small">Działanie</label>
            <select name="entity_id" class="form-select form-select-sm" id="ae_id">
              <option value="">— wybierz —</option>
              <?php foreach($actions_list as $a): ?>
              <option value="<?= $a['id'] ?>"><?= h($a['nazwa']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-2">
            <label class="form-label fw-semibold small">Waga %</label>
            <input name="contribution_weight" type="number" value="100" min="0" max="100"
                   class="form-control form-control-sm">
          </div>
          <div class="col-2">
            <button type="submit" class="btn btn-sm w-100"
                    style="background:var(--strat-accent);color:#fff;border:none">
              <i class="bi bi-plus-lg"></i>
            </button>
          </div>
        </div>
        <input name="mapping_note" class="form-control form-control-sm mt-2" placeholder="Notatka (opcjonalnie)">
      </form>
      <?php endif; ?>

      <!-- Lista powiązań -->
      <?php if ($mappings): ?>
      <div class="list-group list-group-flush rounded border">
        <?php
        $type_icons  = ['contract'=>'bi-file-earmark-text','grant'=>'bi-cash-coin','action'=>'bi-lightning'];
        $type_colors = ['contract'=>'#2563eb','grant'=>'#16a34a','action'=>'#d97706'];
        $type_labels = ['contract'=>'Umowa','grant'=>'Grant','action'=>'Działanie'];
        foreach ($mappings as $m):
            $d = $m['_detail'];
        ?>
        <div class="list-group-item px-3 py-2">
          <div class="d-flex align-items-start gap-2">
            <i class="bi <?= $type_icons[$m['entity_type']] ?> mt-1"
               style="color:<?= $type_colors[$m['entity_type']] ?>;flex-shrink:0"></i>
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge" style="background:<?= $type_colors[$m['entity_type']] ?>;font-size:.65rem">
                  <?= $type_labels[$m['entity_type']] ?>
                  <?php if ($m['contract_type']) echo ' · ' . h(ucfirst($m['contract_type'])); ?>
                </span>
                <?php if ($d): ?>
                  <?php if ($m['entity_type'] === 'contract'): ?>
                  <a href="<?= APP_URL ?>/contracts/<?= h($m['contract_type']) ?>/view.php?id=<?= $d['id'] ?>"
                     class="fw-semibold text-decoration-none small"><?= h($d['numer_umowy']??'#'.$d['id']) ?></a>
                  <span class="badge bg-secondary" style="font-size:.65rem"><?= h($d['status']) ?></span>
                  <?php elseif ($m['entity_type'] === 'grant'): ?>
                  <span class="fw-semibold small"><?= h($d['nazwa']) ?></span>
                  <?php if ($d['kwota_przyznana']): ?>
                  <span class="text-muted" style="font-size:.72rem"><?= number_format((float)$d['kwota_przyznana'],2,',',' ') ?> PLN</span>
                  <?php endif; ?>
                  <?php else: ?>
                  <span class="fw-semibold small"><?= h($d['nazwa']) ?></span>
                  <span class="badge bg-secondary" style="font-size:.65rem"><?= h($d['status']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                <span class="text-danger small">ID <?= (int)$m['entity_id'] ?> — nie znaleziono</span>
                <?php endif; ?>
                <?php if ($m['contribution_weight'] != 100): ?>
                <span class="badge bg-light text-dark border" style="font-size:.62rem"><?= (float)$m['contribution_weight'] ?>%</span>
                <?php endif; ?>
              </div>
              <?php if ($m['note']): ?><div class="text-muted mt-1" style="font-size:.72rem"><?= h($m['note']) ?></div><?php endif; ?>
            </div>
            <?php if (can_edit() || is_admin()): ?>
            <form method="post" onsubmit="return confirm('Usunąć powiązanie?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_mapping">
              <input type="hidden" name="mapping_id" value="<?= $m['id'] ?>">
              <input type="hidden" name="objective_id" value="<?= $id ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1 ms-1">
                <i class="bi bi-x" style="font-size:.8rem"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="strat-alert strat-alert-info" role="alert" style="font-size:.85rem">
        <i class="bi bi-info-circle-fill" style="font-size:1rem;flex-shrink:0;margin-top:.15rem"></i>
        <span>Brak powiązań. Dodaj umowy, granty lub działania które realizują ten cel.</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ręczny wpis postępu -->
  <div class="card shadow-sm">
    <div class="card-header fw-semibold small"><i class="bi bi-graph-up me-1"></i>Dodaj wpis postępu</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_progress">
        <input type="hidden" name="objective_id" value="<?= $id ?>">
        <div class="row g-2 mb-2">
          <div class="col-4">
            <label class="form-label fw-semibold" style="font-size:.72rem">Realizacja</label>
            <input name="wartosc_realizowana" type="number" step="0.01" class="form-control form-control-sm"
                   placeholder="0" value="<?= h($obj['wartosc_realizowana']??'0') ?>">
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold" style="font-size:.72rem">Budżet wydany</label>
            <input name="budzet_wydany" type="number" step="0.01" class="form-control form-control-sm"
                   placeholder="0.00" value="<?= h($obj['budzet_wydany']??'0') ?>">
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold" style="font-size:.72rem">Budżet przypisany</label>
            <input name="budzet_przypisany" type="number" step="0.01" class="form-control form-control-sm"
                   placeholder="0.00" value="<?= h($obj['budzet_przypisany']??'0') ?>">
          </div>
        </div>
        <div class="mb-2">
          <input name="notatka" class="form-control form-control-sm" placeholder="Notatka">
        </div>
        <button type="submit" class="btn btn-success btn-sm">
          <i class="bi bi-plus-lg me-1"></i>Zapisz wpis
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
</div>

<script>
function toggleEntityFields() {
  const t = document.getElementById('entity_type_sel').value;
  document.getElementById('cf').style.display = t==='contract'?'':'none';
  document.getElementById('gf').style.display = t==='grant'?'':'none';
  document.getElementById('af').style.display = t==='action'?'':'none';
}
</script>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
