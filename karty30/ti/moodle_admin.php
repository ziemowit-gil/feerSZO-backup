<?php
/**
 * karty30/ti/moodle_admin.php — Integracja Moodle (wieloserwerowa, niezależna od SZO).
 * Serwery Moodle + przypinanie istniejących kursów do grup TI + zapis kursantów.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_moodle.php';

k30_require_access();
karty30_migrate();
ti_moodle_migrate();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();
$PAGE_TITLE = 'Moodle — TI';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_server') {
        $sid  = (int)($_POST['server_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $url  = rtrim(trim($_POST['base_url'] ?? ''), '/');
        $tok  = trim($_POST['token'] ?? '');
        $act  = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || $url === '') { flash_set('danger','Podaj nazwę i adres URL serwera.'); header('Location: moodle_admin.php'); exit; }
        if ($sid) {
            if ($tok !== '') {
                db()->prepare("UPDATE k30_ti_moodle_servers SET name=?, base_url=?, token=?, is_active=? WHERE id=?")
                   ->execute([$name, $url, $tok, $act, $sid]);
            } else {
                db()->prepare("UPDATE k30_ti_moodle_servers SET name=?, base_url=?, is_active=? WHERE id=?")
                   ->execute([$name, $url, $act, $sid]);
            }
            flash_set('success','Serwer zaktualizowany.');
        } else {
            db_insert('k30_ti_moodle_servers', ['name'=>$name,'base_url'=>$url,'token'=>$tok,'is_active'=>1,'created_by'=>current_user()['id']??null]);
            flash_set('success','Serwer dodany.');
        }
        header('Location: moodle_admin.php'); exit;
    }

    if ($op === 'toggle_server') {
        $sid = (int)($_POST['server_id'] ?? 0);
        if ($sid) db()->prepare("UPDATE k30_ti_moodle_servers SET is_active=1-is_active WHERE id=?")->execute([$sid]);
        header('Location: moodle_admin.php'); exit;
    }

    if ($op === 'delete_server') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $sid = (int)($_POST['server_id'] ?? 0);
        if ($sid) db()->prepare("DELETE FROM k30_ti_moodle_servers WHERE id=?")->execute([$sid]);
        flash_set('success','Serwer usunięty (wraz z przypięciami).');
        header('Location: moodle_admin.php'); exit;
    }

    if ($op === 'test_server') {
        $srv = ti_moodle_server_get((int)($_POST['server_id'] ?? 0));
        if ($srv) { $r = ti_moodle_test($srv); flash_set($r['ok'] ? 'success' : 'danger', $r['msg']); }
        header('Location: moodle_admin.php?srv=' . (int)($_POST['server_id'] ?? 0)); exit;
    }

    if ($op === 'attach_course') {
        $ti_course_id = (int)($_POST['ti_course_id'] ?? 0);
        $server_id    = (int)($_POST['server_id'] ?? 0);
        // Pole pochodzi z dropdownu (id|fullname|shortname) lub z ręcznego id
        $picked = trim($_POST['picked'] ?? '');
        $mcid = 0; $fn = ''; $sn = '';
        if ($picked !== '') {
            $parts = explode('|', $picked, 3);
            $mcid = (int)$parts[0]; $fn = $parts[1] ?? ''; $sn = $parts[2] ?? '';
        }
        if ($mcid <= 0) { $mcid = (int)($_POST['manual_id'] ?? 0); $fn = trim($_POST['manual_name'] ?? ''); }
        if (!$ti_course_id || !$server_id || $mcid <= 0) { flash_set('danger','Wybierz grupę TI, serwer i kurs Moodle (lub podaj ID kursu).'); header('Location: moodle_admin.php?srv='.$server_id); exit; }
        try {
            db_insert('k30_ti_moodle_courses', [
                'ti_course_id'=>$ti_course_id, 'server_id'=>$server_id, 'moodle_course_id'=>$mcid,
                'fullname'=>mb_substr($fn,0,250), 'shortname'=>mb_substr($sn,0,120), 'created_by'=>current_user()['id']??null,
            ]);
            flash_set('success','Kurs Moodle przypięty do grupy TI.');
        } catch (\Throwable $e) {
            flash_set('warning','To przypięcie już istnieje.');
        }
        header('Location: moodle_admin.php?srv='.$server_id); exit;
    }

    if ($op === 'detach_course') {
        $mid = (int)($_POST['mapping_id'] ?? 0);
        if ($mid) db()->prepare("DELETE FROM k30_ti_moodle_courses WHERE id=?")->execute([$mid]);
        flash_set('success','Kurs odpięty.');
        header('Location: moodle_admin.php'); exit;
    }

    if ($op === 'enroll_course') {
        $mid = (int)($_POST['mapping_id'] ?? 0);
        try {
            $r = ti_moodle_enroll_ti_course($mid);
            $msg = "Zapisano: {$r['enrolled']}, utworzono kont: {$r['created']}, pominięto (brak e-mail): {$r['skipped']}.";
            if ($r['errors']) $msg .= ' Błędy: ' . implode('; ', array_slice($r['errors'], 0, 5));
            flash_set($r['errors'] ? 'warning' : 'success', $msg);
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: moodle_admin.php'); exit;
    }
}

$servers   = ti_moodle_servers(false);
$ti_courses = k30_ti_courses(false);
$mappings  = ti_moodle_courses_all();

// Edycja serwera
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_srv = $edit_id ? ti_moodle_server_get($edit_id) : null;
$ef = $edit_srv ?: ['id'=>0,'name'=>'','base_url'=>'','token'=>'','is_active'=>1];

// Wybrany serwer do pobrania listy kursów (do przypięcia)
$srv_id  = (int)($_GET['srv'] ?? 0);
$srv_sel = $srv_id ? ti_moodle_server_get($srv_id) : null;
$remote_courses = []; $remote_err = '';
if ($srv_sel) {
    try { $remote_courses = ti_moodle_remote_courses($srv_sel); }
    catch (\Throwable $e) { $remote_err = $e->getMessage(); }
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Moodle</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-mortarboard text-primary me-2"></i>Integracja Moodle</h4>
  <span class="text-muted small">wiele serwerów · przypinanie istniejących kursów do grup TI</span>
</div>

<?= flash_html() ?>

<div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div><strong>Platforma Moodle zostanie wyłączona od 6.07.2026.</strong> Nie zakładaj nowych kont/kursów na Moodle — materiały i zadania przenoszą się do modułu Dydaktyka / eLearning.</div>
</div>

<div class="row g-4">
  <!-- Serwery -->
  <div class="col-lg-5">
    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-<?= $edit_srv ? 'pencil' : 'plus-lg' ?> me-2"></i><?= $edit_srv ? 'Edytuj serwer' : 'Dodaj serwer Moodle' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"        value="save_server">
          <input type="hidden" name="server_id"  value="<?= (int)$ef['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" value="<?= h($ef['name']) ?>" required placeholder="np. Moodle szkoleniowy">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Adres URL <span class="text-danger">*</span></label>
            <input type="url" class="form-control font-monospace" name="base_url" value="<?= h($ef['base_url']) ?>" required placeholder="https://moodle.example.org">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Token Web Services</label>
            <input type="password" class="form-control font-monospace" name="token"
                   placeholder="<?= $edit_srv && $ef['token']!=='' ? '(zapisany — zostaw puste by nie zmieniać)' : 'token z Moodle' ?>">
            <div class="form-text">Moodle → Administracja → Wtyczki → Usługi sieciowe → Zarządzaj tokenami.</div>
          </div>
          <?php if ($edit_srv): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="srv_act" <?= $ef['is_active']?'checked':'' ?>>
            <label class="form-check-label" for="srv_act">Aktywny</label>
          </div>
          <?php endif; ?>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_srv ? 'Zapisz' : 'Dodaj' ?></button>
            <?php if ($edit_srv): ?><a href="moodle_admin.php" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-hdd-network me-2"></i>Serwery <span class="badge bg-secondary ms-1"><?= count($servers) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
          <thead class="table-light"><tr><th>Serwer</th><th>Status</th><th class="text-end">Akcje</th></tr></thead>
          <tbody>
            <?php if (!$servers): ?><tr><td colspan="3" class="text-center text-muted py-3">Brak serwerów. Dodaj pierwszy.</td></tr><?php endif; ?>
            <?php foreach ($servers as $s): ?>
            <tr class="<?= $s['is_active'] ? '' : 'opacity-50' ?>">
              <td>
                <div class="fw-semibold"><?= h($s['name']) ?></div>
                <div class="text-muted small font-monospace text-truncate" style="max-width:240px"><?= h($s['base_url']) ?></div>
              </td>
              <td><span class="badge <?= $s['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $s['is_active'] ? 'aktywny' : 'wyłączony' ?></span></td>
              <td class="text-end text-nowrap">
                <?php if ($can_write): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="test_server">
                  <input type="hidden" name="server_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Testuj połączenie"><i class="bi bi-wifi"></i></button>
                </form>
                <a href="?srv=<?= (int)$s['id'] ?>#attach" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Przypnij kursy z tego serwera"><i class="bi bi-link-45deg"></i></a>
                <a href="?edit=<?= (int)$s['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć serwer i jego przypięcia?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="delete_server">
                  <input type="hidden" name="server_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Przypinanie kursów -->
  <div class="col-lg-7" id="attach">
    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2 text-success"></i>Przypnij istniejący kurs Moodle do grupy TI</div>
      <div class="card-body">
        <!-- Krok 1: wybór serwera (przeładuj, by pobrać listę kursów) -->
        <form method="get" class="row g-2 align-items-end mb-3">
          <div class="col-sm-8">
            <label class="form-label small fw-semibold">Serwer Moodle</label>
            <select class="form-select form-select-sm" name="srv" onchange="this.form.submit()">
              <option value="">— wybierz serwer, aby pobrać kursy —</option>
              <?php foreach ($servers as $s): if (!$s['is_active']) continue; ?>
              <option value="<?= (int)$s['id'] ?>" <?= $srv_id===(int)$s['id']?'selected':'' ?>><?= h($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4"><span class="form-text">Po wyborze pobierzemy listę kursów.</span></div>
        </form>

        <?php if ($srv_sel): ?>
          <?php if ($remote_err): ?>
          <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Nie udało się pobrać kursów: <?= h($remote_err) ?>. Możesz podać ID kursu ręcznie poniżej.</div>
          <?php endif; ?>
          <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"         value="attach_course">
            <input type="hidden" name="server_id"   value="<?= (int)$srv_sel['id'] ?>">
            <div class="col-sm-5">
              <label class="form-label small fw-semibold">Grupa TI <span class="text-danger">*</span></label>
              <select class="form-select form-select-sm" name="ti_course_id" required>
                <option value="">— wybierz —</option>
                <?php foreach ($ti_courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-7">
              <label class="form-label small fw-semibold">Kurs Moodle</label>
              <?php if ($remote_courses): ?>
              <select class="form-select form-select-sm" name="picked">
                <option value="">— wybierz kurs —</option>
                <?php foreach ($remote_courses as $rc): ?>
                <option value="<?= h($rc['id'].'|'.$rc['fullname'].'|'.$rc['shortname']) ?>"><?= h($rc['fullname']) ?><?= $rc['shortname']?' ('.h($rc['shortname']).')':'' ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <div class="row g-1">
                <div class="col-4"><input type="number" class="form-control form-control-sm" name="manual_id" placeholder="ID kursu"></div>
                <div class="col-8"><input type="text" class="form-control form-control-sm" name="manual_name" placeholder="Nazwa kursu (opc.)"></div>
              </div>
              <?php endif; ?>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-link-45deg me-1"></i>Przypnij kurs</button>
              <span class="form-text ms-2">Serwer: <strong><?= h($srv_sel['name']) ?></strong><?= $remote_courses ? ' · kursów: '.count($remote_courses) : '' ?></span>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-diagram-3 me-2"></i>Przypięte kursy <span class="badge bg-secondary ms-1"><?= count($mappings) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
          <thead class="table-light"><tr><th>Grupa TI</th><th>Kurs Moodle</th><th>Serwer</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$mappings): ?><tr><td colspan="4" class="text-center text-muted py-3">Brak przypięć.</td></tr><?php endif; ?>
            <?php foreach ($mappings as $m): ?>
            <tr>
              <td class="fw-semibold"><?= h($m['ti_course_name']) ?></td>
              <td>
                <a href="<?= h(ti_moodle_course_url($m['base_url'], (int)$m['moodle_course_id'])) ?>" target="_blank" rel="noopener">
                  <?= $m['fullname'] ? h($m['fullname']) : ('kurs #'.(int)$m['moodle_course_id']) ?>
                </a>
                <span class="text-muted small">#<?= (int)$m['moodle_course_id'] ?></span>
              </td>
              <td class="small text-muted"><?= h($m['server_name']) ?></td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline" onsubmit="return confirm('Zapisać aktywnych kursantów tej grupy do kursu Moodle (po e-mailu)?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="enroll_course">
                  <input type="hidden" name="mapping_id" value="<?= (int)$m['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Zapisz kursantów (po e-mailu)"><i class="bi bi-person-check"></i></button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Odpiąć kurs?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="detach_course">
                  <input type="hidden" name="mapping_id" value="<?= (int)$m['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Odepnij"><i class="bi bi-x-lg"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted">
        <i class="bi bi-info-circle me-1"></i>„Zapisz kursantów" znajduje konta w Moodle po adresie e-mail (a gdy brak — tworzy je) i zapisuje na kurs. Wymaga tokenu z uprawnieniami do tworzenia użytkowników i zapisów.
      </div>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
