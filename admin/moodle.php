<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/moodle.php';

require_login();
if (!can_read('admin')) { http_response_code(403); die('Brak dostępu.'); }
require_module_enabled('moodle_enabled', 'Moduł Moodle');

$PAGE_TITLE = 'Integracja Moodle';
_moodle_init();

$tab = $_GET['tab'] ?? 'settings';

// ── Akcje POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_write('admin')) { http_response_code(403); die(); }

    $action = $_POST['action'] ?? '';

    // ── Ustawienia ────────────────────────────────────────────────────────────
    if ($action === 'save_settings') {
        $fields = [
            'moodle_enabled'      => isset($_POST['moodle_enabled']) ? '1' : '0',
            'moodle_url'          => rtrim(trim($_POST['moodle_url'] ?? ''), '/'),
            'moodle_token'        => trim($_POST['moodle_token'] ?? ''),
            'moodle_default_role' => trim($_POST['moodle_default_role'] ?? '5'),
            'moodle_email_source' => ($_POST['moodle_email_source'] ?? 'upn') === 'client' ? 'client' : 'upn',
        ];
        $stmt = db()->prepare(
            "INSERT INTO settings (key_, value) VALUES (?, ?)
             ON CONFLICT(key_) DO UPDATE SET value = excluded.value"
        );
        foreach ($fields as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        flash_set('success', 'Ustawienia Moodle zapisane.');
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=settings'); exit;
    }

    // ── Test połączenia ───────────────────────────────────────────────────────
    if ($action === 'test_connection') {
        try {
            $info = (new MoodleAPI())->site_info();
            flash_set('success', 'Połączenie OK. Serwis: ' . ($info['sitename'] ?? '?') . ' (Moodle ' . ($info['release'] ?? '?') . ')');
        } catch (\Throwable $e) {
            flash_set('error', 'Błąd połączenia: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=settings'); exit;
    }

    // ── Sync kursów z Moodle ──────────────────────────────────────────────────
    if ($action === 'sync_courses') {
        try {
            $courses = (new MoodleAPI())->get_courses();
            $added = 0;
            foreach ($courses as $c) {
                if (($c['id'] ?? 0) <= 1) continue; // pomiń kurs #1 (Site)
                $exists = db_one("SELECT id FROM moodle_courses WHERE moodle_course_id=?", [(int)$c['id']]);
                if (!$exists) {
                    db_insert('moodle_courses', [
                        'moodle_course_id' => (int)$c['id'],
                        'shortname'        => $c['shortname'] ?? '',
                        'fullname'         => $c['fullname']  ?? 'Kurs #' . $c['id'],
                        'summary'          => strip_tags($c['summary'] ?? ''),
                        'category'         => $c['categoryname'] ?? '',
                        'visible'          => ($c['visible'] ?? 1) ? 1 : 0,
                    ]);
                    $added++;
                }
            }
            flash_set('success', "Synchronizacja zakończona. Dodano $added nowych kursów.");
        } catch (\Throwable $e) {
            flash_set('error', 'Błąd synchronizacji: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=courses'); exit;
    }

    // ── Zapis kursu (dodaj / edytuj) ─────────────────────────────────────────
    if ($action === 'save_course') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            'moodle_course_id'  => (int)($_POST['moodle_course_id'] ?? 0),
            'shortname'         => trim($_POST['shortname'] ?? ''),
            'fullname'          => trim($_POST['fullname'] ?? ''),
            'summary'           => trim($_POST['summary'] ?? ''),
            'category'          => trim($_POST['category'] ?? ''),
            'visible'           => isset($_POST['visible']) ? 1 : 0,
            'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
            'max_participants'  => (int)($_POST['max_participants'] ?? 0),
            'sort_order'        => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($data['fullname']) {
            $id ? db_update('moodle_courses', $id, $data) : db_insert('moodle_courses', $data);
            flash_set('success', 'Kurs zapisany.');
        }
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=courses'); exit;
    }

    if ($action === 'delete_course') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db()->prepare("DELETE FROM moodle_courses WHERE id=?")->execute([$id]);
        flash_set('success', 'Kurs usunięty.');
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=courses'); exit;
    }

    // ── Zapisy użytkowników ───────────────────────────────────────────────────
    if ($action === 'approve_enrollment') {
        $id = (int)($_POST['enrollment_id'] ?? 0);
        if (!moodle_configured()) {
            // Bez API — zatwierdź ręcznie
            db_update('moodle_enrollments', $id, ['status' => 'zatwierdzony', 'enrolled_at' => date('Y-m-d H:i:s')]);
            flash_set('success', 'Zapis zatwierdzony (bez automatycznego zapisu w Moodle — brak konfiguracji API).');
        } else {
            try {
                moodle_approve_enrollment($id);
                flash_set('success', 'Użytkownik zapisany na kurs w Moodle.');
            } catch (\Throwable $e) {
                flash_set('error', 'Błąd: ' . $e->getMessage());
            }
        }
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=enrollments'); exit;
    }

    if ($action === 'reject_enrollment') {
        $id   = (int)($_POST['enrollment_id'] ?? 0);
        $note = trim($_POST['admin_note'] ?? '');
        if ($id) db_update('moodle_enrollments', $id, ['status' => 'odrzucony', 'admin_note' => $note]);
        flash_set('success', 'Zapis odrzucony.');
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=enrollments'); exit;
    }

    if ($action === 'set_enrollment_status') {
        $id     = (int)($_POST['enrollment_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed = ['oczekuje', 'zatwierdzony', 'odrzucony', 'anulowany'];
        if ($id && in_array($status, $allowed, true)) {
            db_update('moodle_enrollments', $id, ['status' => $status]);
        }
        header('Location: ' . APP_URL . '/admin/moodle.php?tab=enrollments'); exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$settings = [
    'enabled'      => moodle_setting('enabled'),
    'url'          => moodle_setting('url'),
    'token'        => moodle_setting('token'),
    'default_role' => moodle_setting('default_role') ?: '5',
    'email_source' => moodle_setting('email_source') ?: 'upn',
];
$courses     = moodle_courses_all();
$enrollments = moodle_enrollments_all();

$enr_counts  = ['oczekuje' => 0, 'zatwierdzony' => 0, 'odrzucony' => 0, 'anulowany' => 0];
foreach ($enrollments as $e) {
    if (isset($enr_counts[$e['status']])) $enr_counts[$e['status']]++;
}

$edit_course_id = (int)($_GET['edit_course'] ?? 0);
$edit_course    = $edit_course_id ? moodle_course_get($edit_course_id) : null;
$filter_status  = $_GET['status'] ?? '';
$filtered_enr   = $filter_status ? array_filter($enrollments, fn($e) => $e['status'] === $filter_status) : $enrollments;

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-mortarboard text-primary fs-4"></i>
  </div>
  <div class="flex-grow-1">
    <h4 class="mb-0">Integracja Moodle</h4>
    <div class="text-muted small">Zarządzaj kursami i zapisami użytkowników na platformie e-learningowej</div>
  </div>
  <span class="badge bg-<?= moodle_enabled() ? 'success' : 'secondary' ?> fs-6">
    <?= moodle_enabled() ? 'Aktywna' : 'Nieaktywna' ?>
  </span>
</div>

<?= flash_html() ?>

<!-- ── Taby ───────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link<?= $tab === 'settings' ? ' active' : '' ?>" href="?tab=settings">
      <i class="bi bi-gear me-1"></i>Ustawienia
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $tab === 'courses' ? ' active' : '' ?>" href="?tab=courses">
      <i class="bi bi-book me-1"></i>Kursy
      <span class="badge bg-secondary ms-1"><?= count($courses) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $tab === 'enrollments' ? ' active' : '' ?>" href="?tab=enrollments">
      <i class="bi bi-people me-1"></i>Zapisy
      <?php if ($enr_counts['oczekuje']): ?>
      <span class="badge bg-danger ms-1"><?= $enr_counts['oczekuje'] ?></span>
      <?php else: ?>
      <span class="badge bg-secondary ms-1"><?= count($enrollments) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $tab === 'plugin' ? ' active' : '' ?>" href="?tab=plugin">
      <i class="bi bi-puzzle me-1"></i>Wtyczka FEER
    </a>
  </li>
</ul>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'settings'): ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-sliders me-2"></i>Konfiguracja połączenia</div>
      <div class="card-body">
        <form method="post" novalidate>
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_settings">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="moodle_enabled" id="moodle_enabled"
                   <?= $settings['enabled'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="moodle_enabled">
              Włącz moduł Moodle
            </label>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Adres URL Moodle <span class="text-danger">*</span></label>
            <input type="text" name="moodle_url" class="form-control font-monospace"
                   value="<?= h($settings['url']) ?>"
                   placeholder="https://moodle.twoja-organizacja.pl">
            <div class="form-text">Bez ukośnika na końcu. Np. https://moodle.example.com</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Token Web Service <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" name="moodle_token" class="form-control font-monospace"
                     value="<?= h($settings['token']) ?>"
                     placeholder="32-znakowy token z Moodle" id="moodle-token-input">
              <button type="button" class="btn btn-outline-secondary"
                      onclick="var i=document.getElementById('moodle-token-input');i.type=i.type==='password'?'text':'password'">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div class="form-text">
              Utwórz w Moodle: <em>Administracja → Wtyczki → Usługi sieciowe → Zarządzaj tokenami</em>.
              Wymagane funkcje: <code>core_course_get_courses</code>, <code>core_user_get_users</code>,
              <code>core_user_create_users</code>, <code>enrol_manual_enrol_users</code>,
              <code>core_webservice_get_site_info</code>.
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold">Domyślna rola przy zapisie</label>
            <select name="moodle_default_role" class="form-select w-auto">
              <option value="5" <?= $settings['default_role'] === '5' ? 'selected' : '' ?>>Student (5)</option>
              <option value="4" <?= $settings['default_role'] === '4' ? 'selected' : '' ?>>Non-editing teacher (4)</option>
              <option value="3" <?= $settings['default_role'] === '3' ? 'selected' : '' ?>>Teacher (3)</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold">Adres e-mail kont (powiadomienia Moodle)</label>
            <select name="moodle_email_source" class="form-select w-auto">
              <option value="upn"    <?= $settings['email_source'] === 'upn'    ? 'selected' : '' ?>>UPN konta Microsoft (domyślnie)</option>
              <option value="client" <?= $settings['email_source'] === 'client' ? 'selected' : '' ?>>Rzeczywisty e-mail beneficjenta</option>
            </select>
            <div class="form-text">
              Adres e-mail nadawany kontom kursantów TI zakładanym z panelu — decyduje, gdzie trafiają
              powiadomienia Moodle. „Rzeczywisty e-mail" pobiera adres beneficjenta (kartoteka klienta);
              login konta i tak pozostaje UPN konta Microsoft.
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i>Zapisz ustawienia
            </button>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="test_connection">
              <button type="submit" class="btn btn-outline-secondary"
                      <?= !moodle_configured() ? 'disabled' : '' ?>>
                <i class="bi bi-wifi me-1"></i>Testuj połączenie
              </button>
            </form>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle me-2"></i>Status</div>
      <div class="card-body">
        <dl class="mb-0 row g-2">
          <dt class="col-5 text-muted small">Moduł</dt>
          <dd class="col-7 mb-0">
            <span class="badge bg-<?= moodle_enabled() ? 'success' : 'secondary' ?>">
              <?= moodle_enabled() ? 'Włączony' : 'Wyłączony' ?>
            </span>
          </dd>
          <dt class="col-5 text-muted small">API</dt>
          <dd class="col-7 mb-0">
            <span class="badge bg-<?= moodle_configured() ? 'success' : 'warning text-dark' ?>">
              <?= moodle_configured() ? 'Skonfigurowane' : 'Nieskonfigurowane' ?>
            </span>
          </dd>
          <dt class="col-5 text-muted small">Kursy</dt>
          <dd class="col-7 mb-0"><?= count($courses) ?></dd>
          <dt class="col-5 text-muted small">Zapisy</dt>
          <dd class="col-7 mb-0"><?= count($enrollments) ?> (<?= $enr_counts['oczekuje'] ?> oczekuje)</dd>
        </dl>
      </div>
    </div>
    <div class="card shadow-sm border-info border-opacity-50">
      <div class="card-body small text-muted">
        <strong class="text-dark d-block mb-1"><i class="bi bi-lightbulb me-1"></i>Jak skonfigurować?</strong>
        <ol class="mb-0 ps-3">
          <li>W Moodle włącz Web Services (<em>Administracja → Zaawansowane funkcje</em>)</li>
          <li>Dodaj zewnętrzną usługę z wymaganymi funkcjami</li>
          <li>Utwórz token dla administratora</li>
          <li>Wklej URL i token powyżej</li>
          <li>Kliknij „Testuj połączenie"</li>
          <li>Zsynchronizuj kursy z Moodle lub dodaj ręcznie</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'courses'): ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="d-flex gap-2">
    <?php if (moodle_configured()): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="sync_courses">
      <button type="submit" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-arrow-repeat me-1"></i>Synchronizuj z Moodle
      </button>
    </form>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#form-add-course">
      <i class="bi bi-plus-lg me-1"></i>Dodaj kurs ręcznie
    </button>
  </div>
</div>

<!-- Formularz dodawania / edycji kursu -->
<div class="collapse<?= ($edit_course || !$courses) ? ' show' : '' ?> mb-4" id="form-add-course">
  <div class="card shadow-sm border-primary">
    <div class="card-header fw-semibold text-primary">
      <i class="bi bi-<?= $edit_course ? 'pencil' : 'plus-circle' ?> me-2"></i>
      <?= $edit_course ? 'Edycja: ' . h($edit_course['fullname']) : 'Nowy kurs' ?>
    </div>
    <div class="card-body">
      <form method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_course">
        <input type="hidden" name="id" value="<?= $edit_course['id'] ?? 0 ?>">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Pełna nazwa kursu <span class="text-danger">*</span></label>
            <input type="text" name="fullname" class="form-control"
                   value="<?= h($edit_course['fullname'] ?? '') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Skrót (shortname)</label>
            <input type="text" name="shortname" class="form-control font-monospace"
                   value="<?= h($edit_course['shortname'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">ID kursu w Moodle</label>
            <input type="number" name="moodle_course_id" class="form-control"
                   value="<?= h($edit_course['moodle_course_id'] ?? 0) ?>">
            <div class="form-text">Wymagane do auto-zapisu przez API.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Kategoria</label>
            <input type="text" name="category" class="form-control"
                   value="<?= h($edit_course['category'] ?? '') ?>" placeholder="np. Szkolenia podstawowe">
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Maks. uczestników</label>
            <input type="number" name="max_participants" class="form-control"
                   value="<?= $edit_course['max_participants'] ?? 0 ?>" min="0">
            <div class="form-text">0 = brak limitu</div>
          </div>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Kolejność</label>
            <input type="number" name="sort_order" class="form-control"
                   value="<?= $edit_course['sort_order'] ?? 0 ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Opis kursu</label>
            <textarea name="summary" class="form-control" rows="2"><?= h($edit_course['summary'] ?? '') ?></textarea>
          </div>
          <div class="col-12 d-flex gap-4">
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="visible" id="visible"
                     <?= ($edit_course['visible'] ?? 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="visible">Widoczny dla użytkowników</label>
            </div>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="requires_approval" id="req_approval"
                     <?= ($edit_course['requires_approval'] ?? 0) ? 'checked' : '' ?>>
              <label class="form-check-label" for="req_approval">Wymaga zatwierdzenia przez admina</label>
            </div>
          </div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Zapisz kurs</button>
          <a href="?tab=courses" class="btn btn-outline-secondary">Anuluj</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if (!$courses): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-book fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak kursów. Zsynchronizuj z Moodle lub dodaj ręcznie.</p>
  </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="px-3">Kurs</th>
          <th>Kategoria</th>
          <th class="text-center">Moodle ID</th>
          <th class="text-center">Limit</th>
          <th class="text-center">Zatw.</th>
          <th class="text-center">Widoczny</th>
          <th class="text-end px-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($courses as $c):
          $enr_count = count(array_filter($enrollments, fn($e) => $e['course_id'] == $c['id']));
      ?>
        <tr>
          <td class="px-3">
            <div class="fw-semibold"><?= h($c['fullname']) ?></div>
            <?php if ($c['shortname']): ?>
            <code class="small text-muted"><?= h($c['shortname']) ?></code>
            <?php endif; ?>
            <?php if ($c['summary']): ?>
            <div class="text-muted small text-truncate" style="max-width:280px"><?= h($c['summary']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= h($c['category'] ?: '—') ?></td>
          <td class="text-center">
            <?= $c['moodle_course_id'] ? '<code class="small">' . $c['moodle_course_id'] . '</code>' : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-center small">
            <?= $enr_count ?><?= $c['max_participants'] ? '/' . $c['max_participants'] : '' ?>
          </td>
          <td class="text-center">
            <?= $c['requires_approval']
              ? '<i class="bi bi-shield-check text-warning" title="Wymaga zatwierdzenia"></i>'
              : '<i class="bi bi-check-circle text-success" title="Auto-zapis"></i>' ?>
          </td>
          <td class="text-center">
            <?= $c['visible']
              ? '<i class="bi bi-eye text-success"></i>'
              : '<i class="bi bi-eye-slash text-muted"></i>' ?>
          </td>
          <td class="text-end px-3">
            <div class="d-flex justify-content-end gap-1">
              <a href="?tab=courses&edit_course=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edytuj">
                <i class="bi bi-pencil"></i>
              </a>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Usunąć kurs „<?= h(addslashes($c['fullname'])) ?>"?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_course">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'enrollments'): ?>

<!-- Statystyki -->
<div class="row g-3 mb-4">
  <?php
  $enr_meta = ['oczekuje'=>['Oczekujące','warning'],'zatwierdzony'=>['Zatwierdzone','success'],'odrzucony'=>['Odrzucone','danger'],'anulowany'=>['Anulowane','secondary']];
  foreach ($enr_meta as $s => [$lbl, $cls]): ?>
  <div class="col-6 col-sm-3">
    <a href="?tab=enrollments&status=<?= $s ?>"
       class="card shadow-sm text-decoration-none h-100 <?= $filter_status === $s ? 'border-' . $cls . ' border-2' : '' ?>">
      <div class="card-body text-center py-3">
        <div class="fs-3 fw-bold text-<?= $cls ?>"><?= $enr_counts[$s] ?></div>
        <div class="text-muted small"><?= $lbl ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($filter_status): ?>
<div class="mb-3">
  <a href="?tab=enrollments" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-x-lg me-1"></i>Wyczyść filtr
  </a>
</div>
<?php endif; ?>

<?php if (!$filtered_enr): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak zapisów<?= $filter_status ? ' dla wybranego statusu' : '' ?>.</p>
  </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="px-3">Użytkownik</th>
          <th>Kurs</th>
          <th class="text-center">Status</th>
          <th>Uwagi</th>
          <th class="text-nowrap">Data zapisu</th>
          <th class="text-end px-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $st_cls2 = ['oczekuje'=>'warning text-dark','zatwierdzony'=>'success','odrzucony'=>'danger','anulowany'=>'secondary'];
      $st_lbl2 = ['oczekuje'=>'Oczekuje','zatwierdzony'=>'Zatwierdzony','odrzucony'=>'Odrzucony','anulowany'=>'Anulowany'];
      foreach ($filtered_enr as $e): ?>
        <tr class="<?= $e['status'] === 'oczekuje' ? 'table-warning bg-opacity-25' : '' ?>">
          <td class="px-3">
            <div class="fw-semibold small"><?= h($e['user_name']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= h($e['user_email']) ?></div>
          </td>
          <td>
            <div class="small fw-semibold"><?= h($e['course_name']) ?></div>
            <?php if ($e['moodle_course_id']): ?>
            <code class="text-muted" style="font-size:.7rem">ID <?= $e['moodle_course_id'] ?></code>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <span class="badge bg-<?= $st_cls2[$e['status']] ?? 'secondary' ?>">
              <?= $st_lbl2[$e['status']] ?? $e['status'] ?>
            </span>
            <?php if ($e['moodle_enrolled']): ?>
            <div><span class="badge bg-info text-dark" style="font-size:.65rem"><i class="bi bi-mortarboard me-1"></i>W Moodle</span></div>
            <?php endif; ?>
          </td>
          <td class="small text-muted">
            <?= h($e['note'] ?: '—') ?>
            <?php if ($e['admin_note']): ?>
            <div class="text-danger"><?= h($e['admin_note']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-nowrap small text-muted"><?= h(substr($e['created_at'], 0, 10)) ?></td>
          <td class="text-end px-3">
            <div class="d-flex justify-content-end gap-1 flex-wrap">
              <?php if ($e['status'] === 'oczekuje'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="approve_enrollment">
                <input type="hidden" name="enrollment_id" value="<?= $e['id'] ?>">
                <button type="submit" class="btn btn-sm btn-success" title="Zatwierdź">
                  <i class="bi bi-check2"></i>
                </button>
              </form>
              <button class="btn btn-sm btn-outline-danger" title="Odrzuć"
                      data-bs-toggle="collapse" data-bs-target="#reject-<?= $e['id'] ?>">
                <i class="bi bi-x-lg"></i>
              </button>
              <?php endif; ?>
              <!-- Zmiana statusu -->
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_enrollment_status">
                <input type="hidden" name="enrollment_id" value="<?= $e['id'] ?>">
                <select name="status" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                  <?php foreach ($st_lbl2 as $sv => $sl): ?>
                  <option value="<?= $sv ?>"<?= $e['status'] === $sv ? ' selected' : '' ?>><?= $sl ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </div>
            <!-- Formularz odrzucenia -->
            <div class="collapse mt-2 text-start" id="reject-<?= $e['id'] ?>">
              <form method="post">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reject_enrollment">
                <input type="hidden" name="enrollment_id" value="<?= $e['id'] ?>">
                <div class="input-group input-group-sm">
                  <input type="text" name="admin_note" class="form-control" placeholder="Powód odrzucenia...">
                  <button type="submit" class="btn btn-danger">Odrzuć</button>
                </div>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'plugin'): ?>

<?php
// Zbierz dane do instrukcji
$feer_url   = rtrim(APP_URL, '/');
$api_key_ex = '••••••••••••••••••••';
$moodle_url_ex = rtrim(moodle_setting('url'), '/') ?: 'https://moodle.twojafundacja.pl';
$plugin_dir = 'local/feer_sync';
?>

<div class="row g-4">
  <div class="col-lg-8">

    <!-- Intro -->
    <div class="alert alert-primary d-flex gap-3 align-items-start mb-4">
      <i class="bi bi-puzzle-fill fs-4 flex-shrink-0 mt-1"></i>
      <div>
        <div class="fw-semibold mb-1">Wtyczka Moodle — Synchronizacja wolontariuszy</div>
        Wtyczka automatycznie tworzy konta Moodle dla wolontariuszy z aktywnymi umowami,
        zapisuje ich na kursy i zawiesza konta gdy umowa się kończy.
        Działa codziennie o 03:00 lub ręcznie z panelu Moodle.
      </div>
    </div>

    <!-- Krok 1 -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white">
        <span class="badge bg-primary me-2">1</span>Pobierz i zainstaluj wtyczkę
      </div>
      <div class="card-body">
        <p class="mb-2" style="font-size:.9rem">
          Wtyczka znajduje się w katalogu <code>moodle-plugin/local/feer_sync/</code>
          w repozytorium systemu FEER. Skopiuj ją do Moodle:
        </p>
        <pre class="bg-light border rounded p-3" style="font-size:.82rem">cp -r moodle-plugin/local/feer_sync /var/www/html/moodle/local/</pre>
        <p class="mt-2 mb-0 text-muted" style="font-size:.83rem">
          Następnie zaloguj się jako administrator Moodle i wejdź w
          <strong>Admin → Powiadomienia</strong> — Moodle wykryje nową wtyczkę
          i zainstaluje tabele bazy danych.
        </p>
      </div>
    </div>

    <!-- Krok 2 -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white">
        <span class="badge bg-primary me-2">2</span>Utwórz klucz API w tym systemie
      </div>
      <div class="card-body">
        <p style="font-size:.9rem;margin-bottom:.75rem">
          Przejdź do
          <a href="<?= APP_URL ?>/admin/api_keys.php" class="fw-semibold">
            Admin → Klucze API
          </a>
          i utwórz nowy klucz z uprawnieniem <code class="bg-light px-1 rounded">volunteers:read</code>.
        </p>
        <div class="d-flex align-items-center gap-2 bg-light border rounded p-2 mb-2" style="font-size:.83rem">
          <i class="bi bi-info-circle text-primary"></i>
          Klucz jest widoczny <strong>tylko raz</strong> po utworzeniu — skopiuj go od razu.
        </div>
        <a href="<?= APP_URL ?>/admin/api_keys.php" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-key me-1"></i>Otwórz zarządzanie kluczami API
        </a>
      </div>
    </div>

    <!-- Krok 3 -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white">
        <span class="badge bg-primary me-2">3</span>Skonfiguruj wtyczkę w Moodle
      </div>
      <div class="card-body" style="font-size:.88rem">
        <p class="mb-3">
          W Moodle: <strong>Admin → Wtyczki → Lokalne → FEER NGO — Synchronizacja wolontariuszy</strong>
        </p>
        <table class="table table-sm table-bordered mb-3">
          <thead class="table-light"><tr><th>Pole</th><th>Wartość</th></tr></thead>
          <tbody>
            <tr>
              <td class="fw-semibold">Adres URL systemu FEER</td>
              <td>
                <code><?= h($feer_url) ?></code>
                <button class="btn btn-sm btn-link p-0 ms-1" onclick="navigator.clipboard.writeText(<?= json_encode($feer_url) ?>);this.textContent='✓'" title="Kopiuj">
                  <i class="bi bi-copy"></i>
                </button>
              </td>
            </tr>
            <tr>
              <td class="fw-semibold">Klucz API</td>
              <td><em class="text-muted">klucz wygenerowany w kroku 2</em></td>
            </tr>
            <tr class="table-warning">
              <td class="fw-semibold">
                <i class="bi bi-arrow-return-left me-1 text-warning"></i>
                Callback URL (writeback)
              </td>
              <td>
                <code id="callbackUrl"><?= h($feer_url) ?>/api/v1/moodle_user_update.php</code>
                <button class="btn btn-sm btn-link p-0 ms-1"
                        onclick="navigator.clipboard.writeText(<?= json_encode($feer_url . '/api/v1/moodle_user_update.php') ?>);this.innerHTML='<i class=\'bi bi-check-lg text-success\'></i>'"
                        title="Kopiuj URL">
                  <i class="bi bi-copy"></i>
                </button>
                <div class="text-muted mt-1" style="font-size:.76rem">
                  Wklej ten adres w ustawieniu <strong>„Callback URL (writeback)"</strong>
                  w panelu wtyczki Moodle. Wtyczka wyśle tu login Moodle po utworzeniu konta —
                  wolontariusz zobaczy go w swoim panelu.
                </div>
              </td>
            </tr>
            <tr>
              <td class="fw-semibold">Utwórz konto jeśli brak</td>
              <td><code>✓ włączone</code> (zalecane)</td>
            </tr>
            <tr>
              <td class="fw-semibold">Zawieś konto gdy umowa się kończy</td>
              <td><code>✓ włączone</code> (zalecane)</td>
            </tr>
            <tr>
              <td class="fw-semibold">Synchronizuj wolontariuszy bez umowy</td>
              <td>Opcjonalnie — gdy używasz kont bez umów</td>
            </tr>
            <tr>
              <td class="fw-semibold">Automatyczny zapis na kursy</td>
              <td>ID kursów Moodle po przecinku, np. <code>3,7</code></td>
            </tr>
            <tr>
              <td class="fw-semibold">Mapowanie Działanie → Kurs</td>
              <td><code>{"42": 5, "43": 8}</code> — action_id z FEER → course_id w Moodle</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Krok 4 -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white">
        <span class="badge bg-primary me-2">4</span>Utwórz token Web Service w Moodle
      </div>
      <div class="card-body" style="font-size:.88rem">
        <p class="mb-2">
          Wtyczka używa <strong>Moodle Web Services</strong> do tworzenia kont i zapisów.
          Token potrzebny w ustawieniach zakładki <a href="?tab=settings">Ustawienia</a> tego widoku.
        </p>
        <ol class="mb-2">
          <li>Admin → Wtyczki → Web services → Zarządzaj tokenami</li>
          <li>Utwórz token dla użytkownika z rolą administratora</li>
          <li>Upewnij się że serwis <code>moodle_mobile_app</code> lub dedykowany jest aktywny</li>
          <li>Wklej token w polu <strong>Token Web Service</strong> w zakładce Ustawienia</li>
        </ol>
        <div class="alert alert-warning py-2 px-3 mb-0" style="font-size:.82rem">
          <i class="bi bi-shield-exclamation me-1"></i>
          Token Web Service daje szeroki dostęp do Moodle — przechowuj go bezpiecznie,
          nie umieszczaj w kodzie ani publicznych repozytoriach.
        </div>
      </div>
    </div>

    <!-- Krok 5 - uruchomienie -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white">
        <span class="badge bg-success me-2">5</span>Uruchom synchronizację
      </div>
      <div class="card-body" style="font-size:.88rem">
        <p class="mb-2"><strong>Ręcznie (test):</strong></p>
        <p class="mb-3">
          W Moodle: <strong>Admin → Wtyczki → Lokalne → FEER NGO → Synchronizuj teraz</strong>
          — pojawi się log z wynikami.
        </p>
        <p class="mb-1"><strong>Z linii poleceń:</strong></p>
        <pre class="bg-light border rounded p-2 mb-3" style="font-size:.8rem">php admin/cli/scheduled_task.php --execute='\local_feer_sync\task\sync_task'</pre>
        <p class="mb-1"><strong>Automatycznie (CRON):</strong></p>
        <p class="mb-0 text-muted">
          Zadanie uruchamia się codziennie o 03:00 przez standardowy CRON Moodle.
          Upewnij się że CRON jest skonfigurowany:
        </p>
        <pre class="bg-light border rounded p-2 mt-1 mb-0" style="font-size:.8rem">* * * * * /usr/bin/php /var/www/html/moodle/admin/cli/cron.php >> /dev/null</pre>
      </div>
    </div>

  </div>

  <!-- Prawy panel: szybki status i linki -->
  <div class="col-lg-4">

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white"><i class="bi bi-lightning-fill text-warning me-1"></i>Szybki start</div>
      <div class="card-body d-flex flex-column gap-2">
        <a href="<?= APP_URL ?>/admin/api_keys.php" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-key me-1"></i>1. Wygeneruj klucz API
        </a>
        <a href="?tab=settings" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-gear me-1"></i>2. Konfiguracja Moodle (token WS)
        </a>
        <div class="text-muted" style="font-size:.78rem;padding:.2rem .4rem">
          3. W Moodle: Wtyczki → FEER NGO → wklej URL + klucz API
        </div>
        <div class="text-muted" style="font-size:.78rem;padding:.2rem .4rem">
          4. Kliknij „Synchronizuj teraz" w Moodle
        </div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold bg-white"><i class="bi bi-link-45deg me-1"></i>Endpointy API</div>
      <div class="card-body" style="font-size:.8rem">
        <?php foreach ([
            ['GET', '/api/v1/moodle_sync.php', 'Lista wolontariuszy'],
            ['GET', '/api/v1/moodle_sync.php?include_standalone=1', '+ konta bez umowy'],
            ['POST', '/api/v1/moodle_user_update.php', 'Writeback loginu Moodle'],
        ] as [$m, $p, $d]): ?>
        <div class="d-flex gap-1 align-items-start mb-2">
          <span class="badge bg-<?= $m === 'GET' ? 'success' : 'warning text-dark' ?> flex-shrink-0" style="font-size:.65rem"><?= $m ?></span>
          <div>
            <code style="font-size:.75rem;word-break:break-all"><?= h($feer_url . $p) ?></code>
            <div class="text-muted" style="font-size:.72rem"><?= $d ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="alert alert-light border py-1 px-2 mb-0 mt-1" style="font-size:.75rem">
          <i class="bi bi-lock me-1"></i>Nagłówek: <code>Authorization: Bearer &lt;klucz&gt;</code>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header fw-semibold bg-white"><i class="bi bi-folder2-open me-1"></i>Struktura wtyczki</div>
      <div class="card-body" style="font-size:.78rem;font-family:monospace;line-height:1.7;color:#475569">
        local/feer_sync/<br>
        ├── version.php<br>
        ├── settings.php<br>
        ├── db/install.xml<br>
        ├── db/tasks.php<br>
        ├── classes/<br>
        │&nbsp;&nbsp; ├── api/feer_client.php<br>
        │&nbsp;&nbsp; └── task/sync_task.php<br>
        ├── admin/sync.php<br>
        └── lang/{en,pl}/
      </div>
    </div>

  </div>
</div>

<?php endif; // tab ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
