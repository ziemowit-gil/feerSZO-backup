<?php
/**
 * karty30/ti/online_admin.php — Administracja „nauki online" dla Zajęć TI:
 * konfiguracja tenanta szkoleniowego Microsoft 365, integracji Zoom oraz
 * katalogu ręcznych linków do szkoleń (k30_ti_meetings).
 *
 * Konta MS/Moodle tworzone są per kursant w karty30/ti/kursant/accounts.php
 * oraz samoobsługą w panelu kursanta (?tab=online).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_online.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) {
    flash_set('danger', 'Brak uprawnień.'); header('Location: index.php'); exit;
}

$PAGE_TITLE  = 'Nauka online — Zajęcia TI';
$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_ms') {
        // Konfiguracja połączenia M365 jest w „Dydaktyka 3 → M365". Tu zapisujemy tylko
        // ustawienie specyficzne dla Nauki online: kalendarz szkoleń Teams.
        m365_save_setting('m365t_meetings_user', trim($_POST['m365t_meetings_user'] ?? ''));
        flash_set('success', 'Zapisano.');
        header('Location: online_admin.php'); exit;
    }

    if ($op === 'save_zoom') {
        m365_save_setting('zoom_enabled',    isset($_POST['zoom_enabled']) ? '1' : '0');
        m365_save_setting('zoom_account_id', trim($_POST['zoom_account_id'] ?? ''));
        m365_save_setting('zoom_client_id',  trim($_POST['zoom_client_id'] ?? ''));
        m365_save_setting('zoom_user_id',    trim($_POST['zoom_user_id'] ?? ''));
        if (($_POST['zoom_client_secret'] ?? '') !== '') {
            m365_save_setting('zoom_client_secret', $_POST['zoom_client_secret']);
        }
        flash_set('success', 'Konfiguracja Zoom zapisana.');
        header('Location: online_admin.php'); exit;
    }

    if ($op === 'test_ms') {
        if (!ti_ms_enabled()) {
            $test_result = ['ok' => false, 'msg' => 'Microsoft 365 nie jest skonfigurowane — uzupełnij dane w „Dydaktyka 3 → M365".'];
        } else {
            $r = m365_training()->test_connection();
            $test_result = $r['ok']
                ? ['ok' => true,  'msg' => 'Microsoft OK. Organizacja: ' . ($r['org_name'] ?? '?') . ' — domeny: ' . implode(', ', $r['domains'] ?? [])]
                : ['ok' => false, 'msg' => 'Microsoft: ' . ($r['error'] ?? 'błąd')];
        }
    }

    if ($op === 'test_zoom') {
        $r = (new ZoomAPI())->test_connection();
        $test_result = ['ok' => $r['ok'], 'msg' => 'Zoom: ' . $r['msg']];
    }

    if ($op === 'meeting_save') {
        $mid  = (int)($_POST['meeting_id'] ?? 0);
        $data = [
            'title'     => trim($_POST['title'] ?? ''),
            'platform'  => in_array($_POST['platform'] ?? '', ['zoom','teams','other'], true) ? $_POST['platform'] : 'other',
            'join_url'  => trim($_POST['join_url'] ?? ''),
            'course_id' => ($cid = (int)($_POST['course_id'] ?? 0)) ?: null,
            'starts_at' => ($s = trim($_POST['starts_at'] ?? '')) !== '' ? str_replace('T', ' ', $s) . ':00' : null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($data['title'] === '' || $data['join_url'] === '') {
            flash_set('danger', 'Tytuł i link są wymagane.');
        } elseif ($mid) {
            db_update('k30_ti_meetings', $data, $mid);
            flash_set('success', 'Szkolenie zaktualizowane.');
        } else {
            $data['created_by'] = (int)(current_user()['id'] ?? 0) ?: null;
            db_insert('k30_ti_meetings', $data);
            flash_set('success', 'Szkolenie dodane.');
        }
        header('Location: online_admin.php'); exit;
    }

    if ($op === 'meeting_delete') {
        $mid = (int)($_POST['meeting_id'] ?? 0);
        if ($mid) db()->prepare("DELETE FROM k30_ti_meetings WHERE id=?")->execute([$mid]);
        flash_set('success', 'Szkolenie usunięte.');
        header('Location: online_admin.php'); exit;
    }
}

// Dane do widoku
$courses  = db_all("SELECT id, name FROM k30_ti_courses ORDER BY name");
$meetings = db_all(
    "SELECT m.*, c.name AS course_name FROM k30_ti_meetings m
     LEFT JOIN k30_ti_courses c ON c.id=m.course_id
     ORDER BY m.is_active DESC, m.starts_at"
);
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_m   = $edit_id ? db_one("SELECT * FROM k30_ti_meetings WHERE id=?", [$edit_id]) : null;
$show_form = isset($_GET['new']) || $edit_m;

$ms_set = fn($k) => org_setting($k);
$plat_badge = ['zoom' => ['primary','Zoom'], 'teams' => ['info','MS Teams'], 'other' => ['secondary','Link']];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Nauka online</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-camera-video text-primary me-2"></i>Nauka online — Microsoft 365, Zoom, szkolenia</h4>
  <span class="badge <?= ti_ms_enabled() ? 'bg-success' : 'bg-secondary' ?>">MS <?= ti_ms_enabled() ? 'on' : 'off' ?></span>
  <span class="badge <?= zoom_enabled() ? 'bg-success' : 'bg-secondary' ?>">Zoom <?= zoom_enabled() ? 'on' : 'off' ?></span>
  <span class="badge <?= ti_moodle_enabled() ? 'bg-success' : 'bg-secondary' ?>">Moodle <?= ti_moodle_enabled() ? 'on' : 'off' ?></span>
</div>

<?= flash_html() ?>

<?php if ($test_result): ?>
<div class="alert <?= $test_result['ok'] ? 'alert-success' : 'alert-danger' ?>">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle' : 'x-circle' ?> me-1"></i><?= h($test_result['msg']) ?>
</div>
<?php endif; ?>

<p class="text-muted small">
  Konta Microsoft i Moodle tworzone są dla pojedynczych kursantów w
  <a href="kursant/accounts.php">Kontach kursantów</a> oraz samoobsługą w panelu kursanta.
  Loginem do Moodle jest UPN konta Microsoft (spójna tożsamość).
</p>

<div class="row g-4">
  <!-- Microsoft 365 — używa konfiguracji z „Dydaktyka 3 → M365" -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-microsoft me-1"></i>Microsoft 365 (konta kursantów)</div>
      <div class="card-body">
        <p class="small mb-2">
          <i class="bi bi-info-circle me-1"></i>
          Połączenie z Microsoft 365 (tenant, aplikacja, domena, licencje) konfigurujesz raz w
          <a href="<?= APP_URL ?>/karty30/admin/m365.php">Dydaktyka 3 → M365</a>.
          Nauka online korzysta z tej samej konfiguracji — nie trzeba jej tu powielać.
        </p>
        <p class="small mb-3">
          Status:
          <span class="badge <?= ti_ms_enabled() ? 'bg-success' : 'bg-secondary' ?>">
            <?= ti_ms_enabled() ? 'skonfigurowane' : 'nieskonfigurowane' ?>
          </span>
        </p>
        <hr>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="save_ms">
          <div class="mb-2"><label class="form-label small">Kalendarz szkoleń Teams — UPN/ID użytkownika <span class="text-muted">(opcjonalnie)</span></label>
            <input class="form-control form-control-sm" name="m365t_meetings_user" value="<?= h($ms_set('m365t_meetings_user')) ?>" placeholder="szkolenia@... (kalendarz z wydarzeniami Teams)">
            <div class="form-text">Spotkania online z kalendarza tego konta pojawią się kursantom w zakładce „Szkolenia online".</div></div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
        </form>
        <form method="post" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="test_ms">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1"></i>Test połączenia MS</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Zoom -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-camera-video me-1"></i>Zoom (Server-to-Server OAuth)</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="save_zoom">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="zoom_enabled" id="zen" <?= $ms_set('zoom_enabled')==='1'?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="zen">Pokazuj spotkania Zoom kursantom</label>
          </div>
          <div class="mb-2"><label class="form-label small">Account ID</label>
            <input class="form-control form-control-sm" name="zoom_account_id" value="<?= h($ms_set('zoom_account_id')) ?>"></div>
          <div class="mb-2"><label class="form-label small">Client ID</label>
            <input class="form-control form-control-sm" name="zoom_client_id" value="<?= h($ms_set('zoom_client_id')) ?>"></div>
          <div class="mb-2"><label class="form-label small">Client secret <span class="text-muted">(puste = bez zmian)</span></label>
            <input class="form-control form-control-sm" type="password" name="zoom_client_secret" value="" placeholder="<?= $ms_set('zoom_client_secret') !== '' ? '••••••••' : '' ?>"></div>
          <div class="mb-3"><label class="form-label small">Użytkownik (e-mail lub „me")</label>
            <input class="form-control form-control-sm" name="zoom_user_id" value="<?= h($ms_set('zoom_user_id')) ?>" placeholder="me"></div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
        </form>
        <form method="post" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="test_zoom">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1"></i>Test połączenia Zoom</button>
        </form>
        <div class="form-text mt-2">Wymaga aplikacji „Server-to-Server OAuth" w Zoom Marketplace (scopes: meeting:read, user:read).</div>
      </div>
    </div>
  </div>
</div>

<!-- Ręczne szkolenia / linki -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold d-flex align-items-center">
    <span><i class="bi bi-calendar-event me-1"></i>Szkolenia / linki ręczne</span>
    <span class="badge bg-secondary ms-2"><?= count($meetings) ?></span>
    <?php if (!$show_form): ?>
    <a href="?new=1" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus-lg me-1"></i>Dodaj</a>
    <?php endif; ?>
  </div>
  <div class="card-body">

    <?php if ($show_form): ?>
    <form method="post" class="border rounded p-3 mb-3 bg-body-tertiary">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="meeting_save">
      <input type="hidden" name="meeting_id" value="<?= (int)($edit_m['id'] ?? 0) ?>">
      <div class="row g-2">
        <div class="col-md-5"><label class="form-label small">Tytuł <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" name="title" value="<?= h($edit_m['title'] ?? '') ?>" required></div>
        <div class="col-md-3"><label class="form-label small">Platforma</label>
          <select class="form-select form-select-sm" name="platform">
            <?php foreach (['zoom'=>'Zoom','teams'=>'MS Teams','other'=>'Inny link'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($edit_m['platform'] ?? 'other')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="col-md-4"><label class="form-label small">Termin <span class="text-muted">(opcjonalnie)</span></label>
          <input class="form-control form-control-sm" type="datetime-local" name="starts_at"
                 value="<?= $edit_m && $edit_m['starts_at'] ? h(date('Y-m-d\TH:i', strtotime($edit_m['starts_at']))) : '' ?>"></div>
        <div class="col-md-8"><label class="form-label small">Link „dołącz" <span class="text-danger">*</span></label>
          <input class="form-control form-control-sm" name="join_url" value="<?= h($edit_m['join_url'] ?? '') ?>" placeholder="https://..." required></div>
        <div class="col-md-4"><label class="form-label small">Kurs <span class="text-muted">(opcjonalnie)</span></label>
          <select class="form-select form-select-sm" name="course_id">
            <option value="">— wszystkie —</option>
            <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($edit_m['course_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="form-check form-switch mt-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="mact" <?= !$edit_m || $edit_m['is_active'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="mact">Aktywne (widoczne dla kursantów)</label>
      </div>
      <div class="mt-2 d-flex gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
        <a href="online_admin.php" class="btn btn-outline-secondary btn-sm">Anuluj</a>
      </div>
    </form>
    <?php endif; ?>

    <?php if (!$meetings): ?>
    <div class="text-muted">Brak ręcznych szkoleń. Linki z Teams/Zoom dociągane są automatycznie z API.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
        <thead class="table-light">
          <tr><th>Tytuł</th><th>Platforma</th><th>Termin</th><th>Kurs</th><th>Status</th><th class="text-end">Akcje</th></tr>
        </thead>
        <tbody>
          <?php foreach ($meetings as $m):
            [$bc,$bl] = $plat_badge[$m['platform']] ?? $plat_badge['other']; ?>
          <tr class="<?= $m['is_active'] ? '' : 'opacity-50' ?>">
            <td class="fw-semibold"><a href="<?= h($m['join_url']) ?>" target="_blank" rel="noopener"><?= h($m['title']) ?></a></td>
            <td><span class="badge text-bg-<?= $bc ?>"><?= $bl ?></span></td>
            <td class="text-muted"><?= $m['starts_at'] ? date('d.m.Y H:i', strtotime($m['starts_at'])) : '—' ?></td>
            <td class="text-muted"><?= h($m['course_name'] ?? '') ?: 'wszystkie' ?></td>
            <td><span class="badge <?= $m['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $m['is_active'] ? 'aktywne' : 'ukryte' ?></span></td>
            <td class="text-end">
              <a href="?edit=<?= (int)$m['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 me-1"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć szkolenie?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="meeting_delete">
                <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
