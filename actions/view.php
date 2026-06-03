<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/grants.php';

// CRM — jeśli moduł włączony
$crm_enabled = false;
try {
    require_once dirname(__DIR__) . '/includes/crm.php';
    crm_migrate();
    $crm_enabled = true;
} catch (\Throwable $e) {}

require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);
$action = action_by_id($id);
if (!$action) {
    flash_set('danger', 'Działanie nie istnieje.');
    header('Location: ' . APP_URL . '/actions/index.php');
    exit;
}

// ── POST: zarządzanie uczestnikami CRM ───────────────────────────────────────
if ($crm_enabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'crm_add_participant') {
        $cid  = (int)($_POST['contact_id'] ?? 0);
        $rola = trim($_POST['rola'] ?? 'uczestnik');
        $nota = trim($_POST['nota'] ?? '');
        if ($cid) {
            CrmManager::linkToAction($cid, $id, $rola, $nota);
            $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=?", [$cid]);
            flash_set('success', ($c['imie_nazwisko'] ?? 'Kontakt') . ' dodany jako uczestnik.');
        }
    }

    if ($act === 'crm_remove_participant') {
        $cid = (int)($_POST['contact_id'] ?? 0);
        if ($cid) {
            CrmManager::unlinkFromAction($cid, $id);
            flash_set('success', 'Uczestnik usunięty z działania.');
        }
    }

    header('Location: ' . APP_URL . '/actions/view.php?id=' . $id . '#crm-participants');
    exit;
}

$PAGE_TITLE = h($action['nazwa']);

$linked_grants = action_grants_for($id);
$indicators    = grant_action_indicators_for($id);

// Uczestnicy CRM
$crm_participants   = $crm_enabled ? CrmManager::getActionContacts($id)         : [];
$crm_not_in_action  = $crm_enabled ? CrmManager::getContactsNotInAction($id)    : [];

$action_statuses = [
    'planowane'       => ['label' => 'Planowane',       'class' => 'secondary'],
    'w_przygotowaniu' => ['label' => 'W przygotowaniu', 'class' => 'info'],
    'w_trakcie'       => ['label' => 'W trakcie',       'class' => 'success'],
    'zawieszone'      => ['label' => 'Zawieszone',      'class' => 'warning'],
    'zakończone'      => ['label' => 'Zakończone',      'class' => 'dark'],
    'anulowane'       => ['label' => 'Anulowane',       'class' => 'danger'],
];

$action_types = [
    'warsztat'             => ['label' => 'Warsztat',          'icon' => 'bi-easel'],
    'konferencja'          => ['label' => 'Konferencja',       'icon' => 'bi-mic'],
    'kampania'             => ['label' => 'Kampania',          'icon' => 'bi-megaphone'],
    'wsparcie_indywidualne'=> ['label' => 'Wsparcie indyw.',   'icon' => 'bi-person-heart'],
    'szkolenie'            => ['label' => 'Szkolenie',         'icon' => 'bi-mortarboard'],
    'spotkanie'            => ['label' => 'Spotkanie',         'icon' => 'bi-people'],
    'inne'                 => ['label' => 'Inne',              'icon' => 'bi-three-dots'],
];

$status_info = $action_statuses[$action['status']] ?? ['label' => $action['status'], 'class' => 'secondary'];
$type_info   = $action_types[$action['typ']]       ?? ['label' => $action['typ'],    'icon'  => 'bi-three-dots'];

$no_grant = empty($linked_grants) && !$action['wlasne_dzialanie'];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi <?= $type_info['icon'] ?> text-primary"></i>
    <?= h($action['nazwa']) ?>
    <span class="badge bg-<?= $status_info['class'] ?> ms-1"><?= h($status_info['label']) ?></span>
    <?php if ($action['wlasne_dzialanie']): ?>
    <span class="badge bg-warning text-dark ms-1"><i class="bi bi-star-fill"></i> Własne</span>
    <?php endif; ?>
  </h4>
  <div class="d-flex gap-2">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edytuj</a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Lista</a>
  </div>
</div>

<?php if ($no_grant): ?>
<div class="alert alert-danger d-flex align-items-center gap-2">
  <i class="bi bi-exclamation-triangle-fill"></i>
  Działanie nie ma przypisanego grantu i nie jest oznaczone jako własne.
  <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger ms-auto">Uzupełnij</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Szczegóły</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Typ</dt>
          <dd class="col-sm-8"><i class="bi <?= $type_info['icon'] ?>"></i> <?= h($type_info['label']) ?></dd>

          <dt class="col-sm-4">Koordynator</dt>
          <dd class="col-sm-8"><?= $action['koordynator_name'] ? h($action['koordynator_name']) : '<span class="text-muted">—</span>' ?></dd>

          <dt class="col-sm-4">Forma</dt>
          <dd class="col-sm-8">
            <?= h(ucfirst($action['forma'] ?? '—')) ?>
            <?php if ($action['link_online']): ?>
            <a href="<?= h($action['link_online']) ?>" target="_blank" class="ms-2 small">
              <i class="bi bi-link-45deg"></i> Link
            </a>
            <?php endif; ?>
          </dd>

          <?php if ($action['lokalizacja']): ?>
          <dt class="col-sm-4">Lokalizacja</dt>
          <dd class="col-sm-8"><?= h($action['lokalizacja']) ?></dd>
          <?php endif; ?>

          <?php if ($action['cykliczne']): ?>
          <dt class="col-sm-4">Cykliczne</dt>
          <dd class="col-sm-8">Tak <?= $action['czestotliwosc'] ? '(' . h($action['czestotliwosc']) . ')' : '' ?></dd>
          <?php endif; ?>

          <?php if ($action['opis']): ?>
          <dt class="col-sm-4">Opis</dt>
          <dd class="col-sm-8"><?= nl2br(h($action['opis'])) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-calendar3"></i> Harmonogram</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-6">Data od</dt>
          <dd class="col-6 text-end"><?= date_pl($action['data_od']) ?></dd>
          <dt class="col-6">Data do</dt>
          <dd class="col-6 text-end"><?= date_pl($action['data_do']) ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<!-- Financing -->
<h5 class="mb-2"><i class="bi bi-currency-euro text-success"></i> Finansowanie</h5>
<?php if ($linked_grants): ?>
<div class="card shadow-sm mb-3">
<div class="table-responsive">
<table class="table table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Grant</th>
      <th>Donator</th>
      <th class="text-end">Udział %</th>
      <th class="text-end">Kwota</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($linked_grants as $g): ?>
  <tr>
    <td><a href="<?= APP_URL ?>/grants/view.php?id=<?= $g['grant_id'] ?>" class="fw-semibold text-decoration-none"><?= h($g['nazwa']) ?></a></td>
    <td><?= h($g['donator']) ?></td>
    <td class="text-end"><?= $g['udzial_procent'] !== null ? h($g['udzial_procent']) . '%' : '—' ?></td>
    <td class="text-end"><?= $g['kwota'] !== null ? money((float)$g['kwota'], $g['waluta']) : '—' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php elseif ($action['wlasne_dzialanie']): ?>
<div class="alert alert-info mb-3"><i class="bi bi-star-fill text-warning"></i> Działanie własne — finansowane ze środków własnych organizacji.</div>
<?php else: ?>
<div class="alert alert-warning mb-3">Brak przypisanych grantów.</div>
<?php endif; ?>

<!-- Indicators -->
<h5 class="mb-2"><i class="bi bi-bar-chart text-primary"></i> Wskaźniki (<?= count($indicators) ?>)</h5>
<?php if ($indicators): ?>
<div class="card shadow-sm mb-3">
<div class="table-responsive">
<table class="table table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Wskaźnik</th>
      <th class="text-end">Planowana</th>
      <th class="text-end">Zrealizowana</th>
      <th style="min-width:120px">Postęp</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($indicators as $ind):
    $pct = ($ind['wartosc_planowana'] > 0)
         ? min(100, round($ind['wartosc_realizowana'] / $ind['wartosc_planowana'] * 100))
         : 0;
    $bar_class = $pct >= 100 ? 'success' : ($pct >= 50 ? 'info' : 'warning');
  ?>
  <tr>
    <td><?= h($ind['nazwa']) ?></td>
    <td class="text-end"><?= $ind['wartosc_planowana'] !== null ? h($ind['wartosc_planowana']) : '—' ?></td>
    <td class="text-end"><?= h($ind['wartosc_realizowana']) ?></td>
    <td>
      <div class="d-flex align-items-center gap-1">
        <div class="progress flex-grow-1" style="height:8px">
          <div class="progress-bar bg-<?= $bar_class ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <small class="text-muted"><?= $pct ?>%</small>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php else: ?>
<div class="alert alert-secondary mb-3">Brak wskaźników. <a href="edit.php?id=<?= $id ?>">Dodaj wskaźniki</a>.</div>
<?php endif; ?>

<div class="text-muted small mt-1 mb-4">
  Dodano: <?= date_pl($action['created_at']) ?> &nbsp;|&nbsp; Zaktualizowano: <?= date_pl($action['updated_at']) ?>
</div>

<?php if ($crm_enabled): ?>
<!-- ══ UCZESTNICY CRM ══════════════════════════════════════════════════════════ -->
<div id="crm-participants">
<h5 class="mb-3">
  <i class="bi bi-diagram-2-fill me-1" style="color:#2E844A"></i>
  Uczestnicy CRM
  <span class="badge bg-light text-dark border ms-1 fs-6"><?= count($crm_participants) ?></span>
</h5>

<div class="row g-3">

  <!-- Lista uczestników -->
  <div class="col-lg-8">
    <?php if ($crm_participants): ?>
    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Kontakt</th>
              <th>Rola</th>
              <th class="d-none d-md-table-cell">Status CRM</th>
              <th class="d-none d-lg-table-cell">Dodano</th>
              <th><span class="visually-hidden">Akcje</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($crm_participants as $p):
            $is_org  = $p['type'] === 'organizacja';
            $ini     = $p['avatar_initials'] ?: CrmManager::makeInitials($p['imie_nazwisko']);
            $sc      = crm_statuses()[$p['status']] ?? ['label' => $p['status']];
            $badge_colors = [
              'prospect'   => '#0176D3','aktywny'  => '#2E844A','partner'   => '#7F2B8B',
              'darczyńca'  => '#FE9339','klient'   => '#032D60','nieaktywny'=> '#939393',
            ];
            $bc = $badge_colors[$p['status']] ?? '#939393';
          ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div style="width:32px;height:32px;border-radius:<?= $is_org ? '7px' : '50%' ?>;
                            background:<?= $is_org ? '#032D60' : '#2E844A' ?>;color:#fff;
                            display:flex;align-items:center;justify-content:center;
                            font-size:.7rem;font-weight:700;flex-shrink:0">
                  <?= h($ini) ?>
                </div>
                <div>
                  <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$p['id'] ?>"
                     class="fw-semibold text-decoration-none text-dark">
                    <?= h($p['imie_nazwisko']) ?>
                  </a>
                  <?php if ($p['organizacja']): ?>
                  <div class="text-muted" style="font-size:.75rem"><?= h($p['organizacja']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <span class="badge rounded-pill" style="background:#f1f5f9;color:#475569;font-weight:600;font-size:.75rem">
                <?= h($p['rola']) ?>
              </span>
              <?php if ($p['nota']): ?>
              <div class="text-muted" style="font-size:.72rem" title="<?= h($p['nota']) ?>">
                <?= h(mb_substr($p['nota'], 0, 40)) ?><?= mb_strlen($p['nota']) > 40 ? '…' : '' ?>
              </div>
              <?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell">
              <span class="badge rounded-pill" style="background:<?= h($bc) ?>22;color:<?= h($bc) ?>;border:1px solid <?= h($bc) ?>44;font-size:.75rem">
                <?= h($sc['label']) ?>
              </span>
            </td>
            <td class="d-none d-lg-table-cell text-muted" style="font-size:.78rem">
              <?= date_pl($p['added_at']) ?>
              <?php if ($p['added_by_name']): ?>
              <div style="font-size:.7rem"><?= h($p['added_by_name']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$p['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-2" title="Otwórz kartę CRM">
                <i class="bi bi-person-circle" style="font-size:.8rem"></i>
              </a>
              <form method="post" class="d-inline ms-1">
                <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"     value="crm_remove_participant">
                <input type="hidden" name="contact_id"  value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                        title="Usuń z działania"
                        onclick="return confirm('Usunąć <?= h(addslashes($p['imie_nazwisko'])) ?> z uczestników?')">
                  <i class="bi bi-person-dash" style="font-size:.8rem"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-light border d-flex align-items-center gap-2">
      <i class="bi bi-people text-muted fs-5"></i>
      <span>Brak uczestników CRM. Dodaj kontakty z panelu obok.</span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Panel dodawania uczestnika -->
  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.88rem">
        <i class="bi bi-person-plus me-1 text-success"></i>Dodaj uczestnika z CRM
      </div>
      <div class="card-body">
        <?php if ($crm_not_in_action): ?>
        <form method="post" aria-label="Dodaj uczestnika CRM do działania">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="crm_add_participant">

          <div class="mb-2">
            <label class="form-label form-label-sm fw-semibold" for="crm_contact_sel">Kontakt</label>
            <select name="contact_id" id="crm_contact_sel" class="form-select form-select-sm" required>
              <option value="">— wybierz kontakt —</option>
              <?php foreach ($crm_not_in_action as $nc): ?>
              <option value="<?= (int)$nc['id'] ?>">
                <?= h($nc['imie_nazwisko']) ?>
                <?= $nc['organizacja'] ? ' (' . h($nc['organizacja']) . ')' : '' ?>
                <?= $nc['type'] === 'organizacja' ? ' 🏢' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-2">
            <label class="form-label form-label-sm fw-semibold" for="crm_rola_sel">Rola</label>
            <select name="rola" id="crm_rola_sel" class="form-select form-select-sm">
              <option value="uczestnik">Uczestnik</option>
              <option value="wolontariusz">Wolontariusz</option>
              <option value="prelegent">Prelegent</option>
              <option value="koordynator">Koordynator</option>
              <option value="beneficjent">Beneficjent</option>
              <option value="inny">Inny</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label form-label-sm" for="crm_nota">Notatka (opcjonalnie)</label>
            <input type="text" name="nota" id="crm_nota" class="form-control form-control-sm"
                   placeholder="np. prelegent główny…" maxlength="200">
          </div>

          <button type="submit" class="btn btn-success btn-sm w-100">
            <i class="bi bi-person-plus me-1"></i>Dodaj uczestnika
          </button>
        </form>
        <?php else: ?>
        <div class="text-center py-3 text-muted">
          <i class="bi bi-check-circle-fill text-success fs-4"></i>
          <div class="mt-2 small">Wszystkie aktywne kontakty CRM są już uczestnikami.</div>
        </div>
        <?php endif; ?>

        <?php if ($crm_participants): ?>
        <div class="border-top mt-3 pt-3">
          <a href="<?= APP_URL ?>/crm/communicate.php?<?= http_build_query(['group_id' => 0]) ?>"
             class="btn btn-outline-success btn-sm w-100 mb-1"
             title="Wyślij wiadomość do wszystkich uczestników przez CRM">
            <i class="bi bi-send me-1"></i>Wyślij wiadomość do uczestników
          </a>
          <a href="<?= APP_URL ?>/crm/index.php"
             class="btn btn-outline-secondary btn-sm w-100" style="font-size:.8rem">
            <i class="bi bi-diagram-2-fill me-1" style="color:#2E844A"></i>Otwórz CRM
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->
</div><!-- /#crm-participants -->
<?php endif; // $crm_enabled ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
