<?php
/**
 * admin/strategy_objective.php — Szczegóły i edycja celu strategicznego.
 * Cross-link: pokazuje wszystkie powiązane umowy, granty, działania.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/strategy.php';

require_role('admin', 'editor');

$id     = (int)($_GET['id'] ?? 0);
$errors = [];

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    // Zapis podstawowych danych celu
    if ($action === 'save') {
        $nazwa    = trim($_POST['nazwa'] ?? '');
        $sphere   = (int)($_POST['sphere_id'] ?? 0) ?: null;
        $data_od  = $_POST['data_od'] ?: null;
        $data_do  = $_POST['data_do'] ?: null;
        $wd       = $_POST['wartosc_docelowa'] !== '' ? (float)$_POST['wartosc_docelowa'] : null;
        $wb       = $_POST['wartosc_bazowa']   !== '' ? (float)$_POST['wartosc_bazowa']   : null;
        $status   = in_array($_POST['status'], ['aktywny','wstrzymany','zakończony'], true) ? $_POST['status'] : 'aktywny';

        if (!$nazwa) $errors[] = 'Nazwa celu jest wymagana.';
        if (!$errors) {
            if ($id) {
                db()->prepare("UPDATE strategy_objectives
                    SET sphere_id=?,nazwa=?,opis=?,cel_miernika=?,wartosc_docelowa=?,
                        wartosc_bazowa=?,data_od=?,data_do=?,status=?,waga=?,
                        updated_at=datetime('now','localtime')
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
                flash_set('success', 'Cel "' . $nazwa . '" dodany.');
            }
            header("Location: strategy_objective.php?id={$id}"); exit;
        }
    }

    // Dodaj powiązanie
    if ($action === 'add_mapping') {
        $obj_id   = (int)($_POST['objective_id'] ?? 0);
        $etype    = in_array($_POST['entity_type'],['contract','grant','action'],true) ? $_POST['entity_type'] : '';
        $eid      = (int)($_POST['entity_id'] ?? 0);
        $ctype    = ($etype === 'contract') ? (trim($_POST['contract_type'] ?? '')) : null;
        $weight   = max(0, min(100, (float)($_POST['contribution_weight'] ?? 100)));

        if ($obj_id && $etype && $eid) {
            try {
                db_insert('strategy_mapping', [
                    'objective_id'       => $obj_id,
                    'entity_type'        => $etype,
                    'entity_id'          => $eid,
                    'contract_type'      => $ctype,
                    'contribution_weight'=> $weight,
                    'note'               => trim($_POST['mapping_note'] ?? ''),
                    'added_by'           => current_user()['id'],
                ]);
                flash_set('success', 'Powiązanie dodane.');
            } catch (\Throwable $e) {
                flash_set('error', 'Powiązanie już istnieje lub błąd: ' . $e->getMessage());
            }
        }
        header("Location: strategy_objective.php?id={$obj_id}"); exit;
    }

    // Usuń powiązanie
    if ($action === 'delete_mapping') {
        $mid = (int)($_POST['mapping_id'] ?? 0);
        $oid = (int)($_POST['objective_id'] ?? $id);
        if ($mid) {
            db()->prepare("DELETE FROM strategy_mapping WHERE id=?")->execute([$mid]);
            flash_set('success', 'Powiązanie usunięte.');
        }
        header("Location: strategy_objective.php?id={$oid}"); exit;
    }

    // Odśwież snapshot
    if ($action === 'refresh_progress') {
        $oid = (int)($_POST['objective_id'] ?? $id);
        strategy_add_progress_snapshot($oid);
        flash_set('success', 'Snapshot postępu dodany.');
        header("Location: strategy_objective.php?id={$oid}"); exit;
    }

    // Dodaj ręczny snapshot
    if ($action === 'add_progress') {
        $oid   = (int)($_POST['objective_id'] ?? $id);
        $wr    = (float)($_POST['wartosc_realizowana'] ?? 0);
        $bw    = (float)($_POST['budzet_wydany'] ?? 0);
        $bp    = (float)($_POST['budzet_przypisany'] ?? 0);
        $notat = trim($_POST['notatka'] ?? '');
        db_insert('strategy_progress', [
            'objective_id' => $oid, 'wartosc_realizowana' => $wr,
            'budzet_wydany' => $bw, 'budzet_przypisany' => $bp,
            'snapshot_date' => date('Y-m-d'), 'source' => 'manual', 'notatka' => $notat,
        ]);
        flash_set('success', 'Wpis postępu zapisany.');
        header("Location: strategy_objective.php?id={$oid}"); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$obj      = $id ? db_one("SELECT * FROM v_strategy_dashboard WHERE id=?", [$id]) : null;
$spheres  = db_all("SELECT id,kod,nazwa FROM public_benefit_spheres WHERE is_active=1 ORDER BY sort_order");
$mappings = $id ? strategy_mappings_for_objective($id) : [];
$progress = $id ? db_all(
    "SELECT * FROM strategy_progress WHERE objective_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 20",
    [$id]
) : [];

$PAGE_TITLE = $obj ? h($obj['nazwa']) : 'Nowy cel strategiczny';

// Dla add_mapping — listy do wyboru
$contract_types = ['wolontariat'=>'Wolontariat','zlecenie'=>'Zlecenie','dzielo'=>'Dzieło',
                   'uslugi'=>'Usługi','praca'=>'Praca','inne'=>'Inne'];
$grants_list = db_all("SELECT id, nazwa, nr_umowy FROM grants ORDER BY nazwa LIMIT 200");
$actions_list = db_all("SELECT id, nazwa FROM actions ORDER BY nazwa LIMIT 200");

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item"><a href="strategy.php">Strategia</a></li>
    <li class="breadcrumb-item active"><?= $obj ? h(mb_substr($obj['nazwa'],0,40)) : 'Nowy cel' ?></li>
  </ol>
</nav>

<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>".h($e)."</li>"; ?></ul></div>
<?php endif; ?>

<div class="row g-4">

<!-- ── Lewa: formularz edycji ────────────────────────────────────────────── -->
<div class="col-lg-5">
  <div class="card shadow-sm">
    <div class="card-header fw-semibold">
      <i class="bi bi-bullseye me-1 text-primary"></i>
      <?= $id ? 'Edytuj cel strategiczny' : 'Nowy cel strategiczny' ?>
      <?php if ($obj): ?><?= strategy_health_badge(strategy_health_score($obj)) ?><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">

        <div class="mb-3">
          <label class="form-label fw-semibold small">Nazwa celu <span class="text-danger">*</span></label>
          <input name="nazwa" class="form-control form-control-sm" required value="<?= h($obj['nazwa'] ?? '') ?>">
        </div>

        <div class="row g-2 mb-3">
          <div class="col-8">
            <label class="form-label fw-semibold small">Sfera pożytku publicznego</label>
            <select name="sphere_id" class="form-select form-select-sm">
              <option value="">— bez sfery —</option>
              <?php foreach($spheres as $s): ?>
              <option value="<?= $s['id'] ?>" <?= ($obj['sphere_id']??'') == $s['id'] ? 'selected' : '' ?>>
                <?= h($s['kod']) ?> — <?= h($s['nazwa']) ?>
              </option>
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
                 placeholder="np. Liczba wolontariuszy, % realizacji budżetu"
                 value="<?= h($obj['cel_miernika'] ?? '') ?>">
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small">Opis</label>
          <textarea name="opis" class="form-control form-control-sm" rows="3"><?= h($obj['opis'] ?? '') ?></textarea>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label fw-semibold small">Wartość docelowa</label>
            <input name="wartosc_docelowa" type="number" step="0.01" class="form-control form-control-sm"
                   value="<?= h($obj['wartosc_docelowa'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label fw-semibold small">Wartość bazowa</label>
            <input name="wartosc_bazowa" type="number" step="0.01" class="form-control form-control-sm"
                   value="<?= h($obj['wartosc_bazowa'] ?? '') ?>">
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-4">
            <label class="form-label fw-semibold small">Priorytet</label>
            <input name="waga" type="number" min="1" max="10" class="form-control form-control-sm"
                   value="<?= (int)($obj['waga'] ?? 5) ?>">
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold small">Data od</label>
            <input name="data_od" type="date" class="form-control form-control-sm" value="<?= h($obj['data_od'] ?? '') ?>">
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold small">Data do</label>
            <input name="data_do" type="date" class="form-control form-control-sm" value="<?= h($obj['data_do'] ?? '') ?>">
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
          <a href="strategy.php" class="btn btn-outline-secondary btn-sm">Anuluj</a>
          <?php if ($id): ?>
          <form method="post" class="ms-auto">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="refresh_progress">
            <input type="hidden" name="objective_id" value="<?= $id ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-arrow-clockwise me-1"></i>Auto snapshot
            </button>
          </form>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($id): ?>
  <!-- Ręczny wpis postępu -->
  <div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold small">
      <i class="bi bi-graph-up me-1"></i>Dodaj wpis postępu
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_progress">
        <input type="hidden" name="objective_id" value="<?= $id ?>">
        <div class="row g-2 mb-2">
          <div class="col-4">
            <label class="form-label fw-semibold" style="font-size:.72rem">Realizacja</label>
            <input name="wartosc_realizowana" type="number" step="0.01" class="form-control form-control-sm"
                   placeholder="0" value="<?= h($obj['wartosc_realizowana'] ?? '0') ?>">
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold" style="font-size:.72rem">Budżet wydany</label>
            <input name="budzet_wydany" type="number" step="0.01" class="form-control form-control-sm"
                   placeholder="0.00" value="<?= h($obj['budzet_wydany'] ?? '0') ?>">
          </div>
          <div class="col-4">
            <label class="form-label fw-semibold" style="font-size:.72rem">Budżet przypisany</label>
            <input name="budzet_przypisany" type="number" step="0.01" class="form-control form-control-sm"
                   placeholder="0.00" value="<?= h($obj['budzet_przypisany'] ?? '0') ?>">
          </div>
        </div>
        <div class="mb-2">
          <input name="notatka" class="form-control form-control-sm" placeholder="Notatka (opcjonalnie)">
        </div>
        <button type="submit" class="btn btn-success btn-sm">
          <i class="bi bi-plus-lg me-1"></i>Zapisz wpis
        </button>
      </form>
    </div>
  </div>

  <!-- Historia postępu -->
  <?php if ($progress): ?>
  <div class="card shadow-sm mt-3">
    <div class="card-header fw-semibold small"><i class="bi bi-clock-history me-1"></i>Historia postępu</div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
      <thead class="table-light">
        <tr><th>Data</th><th>Realizacja</th><th>Budżet wyd.</th><th>Źródło</th><th>Notatka</th></tr>
      </thead>
      <tbody>
        <?php foreach($progress as $p): ?>
        <tr>
          <td><?= date('d.m.Y', strtotime($p['snapshot_date'])) ?></td>
          <td><?= number_format((float)$p['wartosc_realizowana'], 2) ?></td>
          <td><?= number_format((float)$p['budzet_wydany'], 2) ?> / <?= number_format((float)$p['budzet_przypisany'], 2) ?></td>
          <td><span class="badge bg-secondary"><?= h($p['source']) ?></span></td>
          <td class="text-muted"><?= h(mb_substr($p['notatka']??'',0,40)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ── Prawa: cross-link (powiązania) ────────────────────────────────────── -->
<?php if ($id): ?>
<div class="col-lg-7">

  <!-- Dodaj powiązanie -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">
      <i class="bi bi-link-45deg me-1 text-primary"></i>Dodaj powiązanie
      <span class="badge bg-secondary ms-1"><?= count($mappings) ?></span>
    </div>
    <div class="card-body">
      <form method="post" id="mappingForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_mapping">
        <input type="hidden" name="objective_id" value="<?= $id ?>">
        <div class="row g-2 align-items-end">
          <div class="col-3">
            <label class="form-label fw-semibold small">Typ encji</label>
            <select name="entity_type" id="entity_type" class="form-select form-select-sm"
                    onchange="toggleEntityFields()">
              <option value="contract">Umowa</option>
              <option value="grant">Grant</option>
              <option value="action">Działanie</option>
            </select>
          </div>

          <!-- Contract fields -->
          <div id="contract_fields" class="col-5">
            <label class="form-label fw-semibold small">Typ + ID umowy</label>
            <div class="input-group input-group-sm">
              <select name="contract_type" class="form-select" style="max-width:110px">
                <?php foreach($contract_types as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
                <?php endforeach; ?>
              </select>
              <input name="entity_id" type="number" min="1" class="form-control"
                     placeholder="ID umowy" id="contract_entity_id">
            </div>
          </div>

          <!-- Grant fields -->
          <div id="grant_fields" class="col-5" style="display:none">
            <label class="form-label fw-semibold small">Grant</label>
            <select name="entity_id" class="form-select form-select-sm" id="grant_entity_id">
              <option value="">— wybierz grant —</option>
              <?php foreach($grants_list as $g): ?>
              <option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?> <?= $g['nr_umowy']?'['.h($g['nr_umowy']).']':'' ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Action fields -->
          <div id="action_fields" class="col-5" style="display:none">
            <label class="form-label fw-semibold small">Działanie</label>
            <select name="entity_id" class="form-select form-select-sm" id="action_entity_id">
              <option value="">— wybierz działanie —</option>
              <?php foreach($actions_list as $a): ?>
              <option value="<?= $a['id'] ?>"><?= h($a['nazwa']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-2">
            <label class="form-label fw-semibold small">Waga %</label>
            <input name="contribution_weight" type="number" min="0" max="100" value="100"
                   class="form-control form-control-sm">
          </div>
          <div class="col-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">
              <i class="bi bi-plus-lg"></i>
            </button>
          </div>
        </div>
        <input name="mapping_note" class="form-control form-control-sm mt-2" placeholder="Notatka (opcjonalnie)">
      </form>
    </div>
  </div>

  <!-- Lista powiązań / cross-link -->
  <?php if ($mappings): ?>
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-diagram-2 me-1"></i>Powiązane elementy</div>
    <div class="list-group list-group-flush">
      <?php
      $type_labels = ['contract'=>'Umowa','grant'=>'Grant','action'=>'Działanie'];
      $type_icons  = ['contract'=>'bi-file-earmark-text','grant'=>'bi-cash-coin','action'=>'bi-lightning'];
      $type_colors = ['contract'=>'#2563eb','grant'=>'#16a34a','action'=>'#d97706'];
      foreach ($mappings as $m):
          $d = $m['_detail'];
      ?>
      <div class="list-group-item px-3 py-2">
        <div class="d-flex align-items-start gap-2">
          <i class="bi <?= $type_icons[$m['entity_type']] ?> mt-1"
             style="color:<?= $type_colors[$m['entity_type']] ?>;font-size:1rem;flex-shrink:0"></i>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="badge" style="background:<?= $type_colors[$m['entity_type']] ?>;font-size:.68rem">
                <?= $type_labels[$m['entity_type']] ?>
                <?php if ($m['contract_type']) echo ' · ' . h(ucfirst($m['contract_type'])); ?>
              </span>
              <?php if ($d): ?>
                <?php if ($m['entity_type'] === 'contract'): ?>
                <a href="<?= APP_URL ?>/contracts/<?= h($m['contract_type']) ?>/view.php?id=<?= (int)$d['id'] ?>"
                   class="fw-semibold text-decoration-none small">
                  <?= h($d['numer_umowy'] ?? '#'.$d['id']) ?>
                </a>
                <span class="badge bg-secondary" style="font-size:.65rem"><?= h($d['status']) ?></span>
                <?php if ($d['data_zakonczenia']): ?>
                <span class="text-muted" style="font-size:.72rem">do <?= date_pl($d['data_zakonczenia']) ?></span>
                <?php endif; ?>
                <?php elseif ($m['entity_type'] === 'grant'): ?>
                <a href="<?= APP_URL ?>/grants/view.php?id=<?= (int)$d['id'] ?>"
                   class="fw-semibold text-decoration-none small">
                  <?= h($d['nazwa']) ?>
                </a>
                <span class="badge bg-secondary" style="font-size:.65rem"><?= h($d['status']) ?></span>
                <?php if ($d['kwota_przyznana']): ?>
                <span class="text-muted" style="font-size:.72rem"><?= number_format((float)$d['kwota_przyznana'],2,',',' ') ?> PLN</span>
                <?php endif; ?>
                <?php elseif ($m['entity_type'] === 'action'): ?>
                <span class="fw-semibold small"><?= h($d['nazwa']) ?></span>
                <span class="badge bg-secondary" style="font-size:.65rem"><?= h($d['status']) ?></span>
                <?php endif; ?>
              <?php else: ?>
              <span class="text-danger small">ID <?= (int)$m['entity_id'] ?> — nie znaleziono</span>
              <?php endif; ?>
              <?php if ($m['contribution_weight'] != 100): ?>
              <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= (float)$m['contribution_weight'] ?>%</span>
              <?php endif; ?>
            </div>
            <?php if ($m['note']): ?>
            <div class="text-muted mt-1" style="font-size:.72rem"><?= h($m['note']) ?></div>
            <?php endif; ?>
          </div>
          <form method="post" class="ms-auto" onsubmit="return confirm('Usunąć powiązanie?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_mapping">
            <input type="hidden" name="mapping_id" value="<?= $m['id'] ?>">
            <input type="hidden" name="objective_id" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
              <i class="bi bi-x" style="font-size:.8rem"></i>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    Brak powiązań. Dodaj umowy, granty lub działania, które przyczyniają się do realizacji tego celu.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- /row -->

<script>
function toggleEntityFields() {
  const t = document.getElementById('entity_type').value;
  document.getElementById('contract_fields').style.display = t === 'contract' ? '' : 'none';
  document.getElementById('grant_fields').style.display    = t === 'grant'    ? '' : 'none';
  document.getElementById('action_fields').style.display   = t === 'action'   ? '' : 'none';
  // Fix: wyczyść entity_id przy zmianie
  document.querySelectorAll('[name="entity_id"]').forEach(el => el.value = '');
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
