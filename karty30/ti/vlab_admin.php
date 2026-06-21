<?php
/**
 * karty30/ti/vlab_admin.php — Administracja VLAB: konfiguracja hosta Dockera (SSH),
 * katalog szablonów (obrazów) i przegląd kontenerów kursantów.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/vlab.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) {
    flash_set('danger', 'Brak uprawnień.'); header('Location: index.php'); exit;
}

$PAGE_TITLE = 'VLAB / Docker — Zajęcia TI';
$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $uid = (int)(current_user()['id'] ?? 0);

    if ($op === 'save_config') {
        $set = [
            'ssh_host'        => trim($_POST['ssh_host'] ?? ''),
            'ssh_port'        => max(1, (int)($_POST['ssh_port'] ?? 22)),
            'ssh_user'        => trim($_POST['ssh_user'] ?? ''),
            'ssh_auth'        => ($_POST['ssh_auth'] ?? 'key') === 'password' ? 'password' : 'key',
            'ssh_key_path'    => trim($_POST['ssh_key_path'] ?? ''),
            'public_host'     => trim($_POST['public_host'] ?? ''),
            'ttyd_enabled'    => isset($_POST['ttyd_enabled']) ? 1 : 0,
            'ttyd_scheme'     => ($_POST['ttyd_scheme'] ?? 'http') === 'https' ? 'https' : 'http',
            'max_per_student' => max(1, (int)($_POST['max_per_student'] ?? 3)),
            'default_cpus'    => trim($_POST['default_cpus'] ?? ''),
            'default_mem'     => trim($_POST['default_mem'] ?? ''),
            'is_enabled'      => isset($_POST['is_enabled']) ? 1 : 0,
            'force_pw_first_login' => isset($_POST['force_pw_first_login']) ? 1 : 0,
            'is_disabled'     => isset($_POST['is_disabled']) ? 1 : 0,
            'disabled_notice' => trim($_POST['disabled_notice'] ?? ''),
            'updated_by'      => $uid ?: null,
        ];
        // Hasło SSH zmieniamy tylko jeśli podane (puste = bez zmian)
        if (($_POST['ssh_password'] ?? '') !== '') $set['ssh_password'] = $_POST['ssh_password'];
        db_update('k30_ti_vlab_config', $set, 1);
        flash_set('success', 'Konfiguracja zapisana.');
        header('Location: vlab_admin.php'); exit;
    }

    if ($op === 'test') {
        $r = vlab_docker_ping();
        $test_result = $r['ok']
            ? ['ok' => true,  'msg' => 'Połączenie OK. Docker server: ' . $r['out']]
            : ['ok' => false, 'msg' => 'Błąd: ' . ($r['err'] ?: 'brak odpowiedzi')];
    }

    if ($op === 'tpl_save') {
        $data = [
            'name'         => trim($_POST['name'] ?? ''),
            'description'  => trim($_POST['description'] ?? ''),
            'docker_image' => trim($_POST['docker_image'] ?? ''),
            'run_cmd'      => trim($_POST['run_cmd'] ?? ''),
            'cpus'         => trim($_POST['cpus'] ?? ''),
            'mem'          => trim($_POST['mem'] ?? ''),
            'expose_ssh'   => isset($_POST['expose_ssh']) ? 1 : 0,
            'expose_ttyd'  => isset($_POST['expose_ttyd']) ? 1 : 0,
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            'sort'         => (int)($_POST['sort'] ?? 0),
        ];
        if ($data['name'] === '' || $data['docker_image'] === '') {
            flash_set('danger', 'Nazwa i obraz Docker są wymagane.');
        } else {
            $tid = (int)($_POST['tpl_id'] ?? 0);
            if ($tid) {
                $s = []; $p = [];
                foreach ($data as $k => $v) { $s[] = "$k=?"; $p[] = $v; }
                $p[] = $tid;
                db()->prepare("UPDATE k30_ti_vlab_templates SET " . implode(',', $s) . " WHERE id=?")->execute($p);
                flash_set('success', 'Szablon zaktualizowany.');
            } else {
                $data['created_by'] = $uid ?: null;
                $data['created_at'] = date('Y-m-d H:i:s');
                db_insert('k30_ti_vlab_templates', $data);
                flash_set('success', 'Szablon dodany.');
            }
        }
        header('Location: vlab_admin.php'); exit;
    }

    if ($op === 'tpl_delete') {
        db()->prepare("DELETE FROM k30_ti_vlab_templates WHERE id=?")->execute([(int)($_POST['tpl_id'] ?? 0)]);
        flash_set('success', 'Szablon usunięty.');
        header('Location: vlab_admin.php'); exit;
    }

    if ($op === 'force_remove') {
        $cid = (int)($_POST['container_id'] ?? 0);
        $row = $cid ? db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=?", [$cid]) : null;
        if ($row && $row['status'] !== 'removed') {
            $r = vlab_action($cid, (int)$row['student_id'], 'remove');
            flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        }
        header('Location: vlab_admin.php'); exit;
    }

    // Odtworzenie / reset hasła konta SSH kursanta na hoście (pokazujemy je RAZ).
    if ($op === 'host_pass_reset') {
        $cid = (int)($_POST['container_id'] ?? 0);
        $row = $cid ? db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=?", [$cid]) : null;
        if (!$row || $row['status'] === 'removed') {
            flash_set('danger', 'Maszyna nie istnieje.');
        } else {
            $c2    = vlab_config();
            $force = !isset($c2['force_pw_first_login']) || (int)$c2['force_pw_first_login'] === 1;
            $hu = vlab_host_user_create($row, $force); // ponowne wywołanie ustawia nowe hasło (chpasswd)
            if ($hu['ok']) {
                db_update('k30_ti_vlab_containers', ['host_user' => $hu['user'], 'force_pw_pending' => $force ? 1 : 0], $cid);
                vlab_log($cid, (int)$row['student_id'], 'host_pass_reset', true, $hu['user']);
                // Wyślij nowe dane logowania także kursantowi (e-mail) — żeby je znał.
                $row['host_user'] = $hu['user'];
                $mailed = vlab_email_credentials($row, $hu['user'], $hu['password'], $force);
                // Dane do wyświetlenia w wyskakującym okienku (modal) po przeładowaniu — jednorazowo.
                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                $_SESSION['vlab_reset_creds'] = [
                    'label'     => (string)($row['label'] ?? ''),
                    'user'      => $hu['user'],
                    'password'  => $hu['password'],
                    'host'      => (string)($c2['public_host'] ?? ''),
                    'port'      => (int)($c2['ssh_port'] ?: 22),
                    'force'     => $force,
                    'mailed'    => $mailed,
                    'ttyd_url'  => vlab_ttyd_url($row),
                    'ttyd_user' => (string)($row['ttyd_user'] ?? ''),
                    'ttyd_pass' => (string)($row['ttyd_password'] ?? ''),
                ];
                flash_set('success', 'Hasło konta SSH „' . $hu['user'] . '" zostało zresetowane.'
                    . ($mailed ? ' Dane wysłano też e-mailem do kursanta.' : ' (Nie udało się wysłać e-maila do kursanta — przekaż dane ręcznie.)'));
            } else {
                flash_set('danger', 'Nie udało się ustawić hasła: ' . $hu['msg']);
            }
        }
        header('Location: vlab_admin.php'); exit;
    }

    // Wymuszenie zmiany hasła SSH przy następnym logowaniu (bez zmiany hasła).
    if ($op === 'host_pass_force') {
        $cid = (int)($_POST['container_id'] ?? 0);
        $row = $cid ? db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=?", [$cid]) : null;
        if (!$row || $row['status'] === 'removed') {
            flash_set('danger', 'Maszyna nie istnieje.');
        } elseif (empty($row['host_user'])) {
            flash_set('danger', 'Ta maszyna nie ma konta SSH na hoście.');
        } else {
            $r = vlab_host_user_force_pwchange($row);
            if ($r['ok']) db_update('k30_ti_vlab_containers', ['force_pw_pending' => 1], $cid);
            vlab_log($cid, (int)$row['student_id'], 'host_pass_force', $r['ok'], $row['host_user']);
            flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        }
        header('Location: vlab_admin.php'); exit;
    }
}

$cfg       = vlab_config();
$templates = db_all("SELECT * FROM k30_ti_vlab_templates ORDER BY sort, name");
$edit_tpl  = ($eid = (int)($_GET['edit_tpl'] ?? 0)) ? db_one("SELECT * FROM k30_ti_vlab_templates WHERE id=?", [$eid]) : null;
$show_tpl  = isset($_GET['new_tpl']) || $edit_tpl;
$containers = db_all(
    "SELECT c.*, a.login AS student_login, cl.name AS client_name, t.name AS tpl_name
     FROM k30_ti_vlab_containers c
     LEFT JOIN k30_ti_student_accounts a ON a.id=c.student_id
     LEFT JOIN k30_clients cl ON cl.id=c.client_id
     LEFT JOIN k30_ti_vlab_templates t ON t.id=c.template_id
     WHERE c.status!='removed'
     ORDER BY c.created_at DESC LIMIT 200"
);
// Dane logowania do pokazania w modalu (jednorazowo po resecie hasła)
$reset_creds = $_SESSION['vlab_reset_creds'] ?? null;
unset($_SESSION['vlab_reset_creds']);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">VLAB / Docker</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-hdd-stack text-primary me-2"></i>VLAB — wirtualne maszyny (Docker / SSH)</h4>
  <?php if (!empty($cfg['is_disabled'])): ?>
  <span class="badge bg-warning text-dark ms-auto"><i class="bi bi-pause-circle me-1"></i>Przerwa (wyłączony dla kursantów)</span>
  <?php else: ?>
  <span class="badge <?= $cfg['is_enabled'] ? 'bg-success' : 'bg-secondary' ?> ms-auto">
    <?= $cfg['is_enabled'] ? 'Włączony' : 'Wyłączony' ?>
  </span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($test_result): ?>
<div class="alert <?= $test_result['ok'] ? 'alert-success' : 'alert-danger' ?>">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle' : 'x-circle' ?> me-1"></i><?= h($test_result['msg']) ?>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Konfiguracja hosta -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold"><i class="bi bi-server me-1"></i>Konfiguracja hosta Dockera (SSH)</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="save_config">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="is_enabled" id="en" <?= $cfg['is_enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="en">Moduł VLAB włączony (widoczny dla kursantów)</label>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-8"><label class="form-label small">Host SSH</label>
              <input class="form-control form-control-sm" name="ssh_host" value="<?= h($cfg['ssh_host']) ?>" placeholder="10.0.0.5 / docker.example.org"></div>
            <div class="col-4"><label class="form-label small">Port</label>
              <input class="form-control form-control-sm" type="number" name="ssh_port" value="<?= (int)$cfg['ssh_port'] ?>"></div>
          </div>
          <div class="mb-2"><label class="form-label small">Użytkownik SSH</label>
            <input class="form-control form-control-sm" name="ssh_user" value="<?= h($cfg['ssh_user']) ?>" placeholder="dockeruser"></div>
          <div class="row g-2 mb-2">
            <div class="col-5"><label class="form-label small">Uwierzytelnianie</label>
              <select class="form-select form-select-sm" name="ssh_auth">
                <option value="key" <?= $cfg['ssh_auth']==='key'?'selected':'' ?>>Klucz prywatny</option>
                <option value="password" <?= $cfg['ssh_auth']==='password'?'selected':'' ?>>Hasło</option>
              </select></div>
            <div class="col-7"><label class="form-label small">Ścieżka do klucza (na serwerze www)</label>
              <input class="form-control form-control-sm" name="ssh_key_path" value="<?= h($cfg['ssh_key_path']) ?>" placeholder="/var/www/.ssh/vlab_id_ed25519"></div>
          </div>
          <div class="mb-2"><label class="form-label small">Hasło SSH <span class="text-muted">(zostaw puste, by nie zmieniać)</span></label>
            <input class="form-control form-control-sm" type="password" name="ssh_password" value="" placeholder="<?= $cfg['ssh_password'] !== '' ? '••••••••' : '' ?>"></div>
          <hr>
          <div class="row g-2 mb-2">
            <div class="col-8"><label class="form-label small">Publiczny host (dla linków ttyd i SSH kursanta)</label>
              <input class="form-control form-control-sm" name="public_host" value="<?= h($cfg['public_host']) ?>" placeholder="lab.example.org"></div>
            <div class="col-4"><label class="form-label small">Schemat ttyd</label>
              <select class="form-select form-select-sm" name="ttyd_scheme">
                <option value="http"  <?= $cfg['ttyd_scheme']==='http'?'selected':'' ?>>http</option>
                <option value="https" <?= $cfg['ttyd_scheme']==='https'?'selected':'' ?>>https</option>
              </select></div>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="ttyd_enabled" id="ttyd" <?= $cfg['ttyd_enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="ttyd">Terminal w przeglądarce (ttyd)</label>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="force_pw_first_login" id="fpw" <?= (!isset($cfg['force_pw_first_login']) || $cfg['force_pw_first_login']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="fpw">Wymuś zmianę hasła SSH przy pierwszym logowaniu</label>
            <div class="form-text small">Konto na hoście dostaje <code>chage -d 0</code> — kursant ustawi własne hasło przy pierwszym logowaniu (wymaga <code>UsePAM yes</code> na hoście).</div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-4"><label class="form-label small">Limit maszyn / kursant</label>
              <input class="form-control form-control-sm" type="number" name="max_per_student" value="<?= (int)$cfg['max_per_student'] ?>"></div>
            <div class="col-4"><label class="form-label small">Domyślne CPU</label>
              <input class="form-control form-control-sm" name="default_cpus" value="<?= h($cfg['default_cpus']) ?>" placeholder="1"></div>
            <div class="col-4"><label class="form-label small">Domyślna pamięć</label>
              <input class="form-control form-control-sm" name="default_mem" value="<?= h($cfg['default_mem']) ?>" placeholder="512m"></div>
          </div>
          <hr>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="is_disabled" id="dis" <?= !empty($cfg['is_disabled']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold text-danger" for="dis">Wyłącz VLab dla kursantów (tryb przerwy)</label>
            <div class="form-text small">Zakładka VLab pozostaje widoczna, ale kursanci zobaczą tylko komunikat poniżej; tworzenie i obsługa maszyn są zablokowane.</div>
          </div>
          <div class="mb-3"><label class="form-label small" for="disnote">Komunikat dla kursantów (gdy wyłączone)</label>
            <textarea class="form-control form-control-sm" name="disabled_notice" id="disnote" rows="2" placeholder="np. VLab jest niedostępny z powodu prac serwisowych do 18:00."><?= h($cfg['disabled_notice'] ?? '') ?></textarea></div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz konfigurację</button>
        </form>
        <form method="post" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="test">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1"></i>Test połączenia</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Szablony -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold d-flex align-items-center">
        <span><i class="bi bi-collection me-1"></i>Szablony (obrazy Docker)</span>
        <?php if (!$show_tpl): ?>
        <a href="?new_tpl=1" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus-lg"></i></a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($show_tpl):
          $t = $edit_tpl ?? ['id'=>0,'name'=>'','description'=>'','docker_image'=>'','run_cmd'=>'','cpus'=>'','mem'=>'','expose_ssh'=>1,'expose_ttyd'=>1,'is_active'=>1,'sort'=>0];
        ?>
        <form method="post" class="mb-3">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="tpl_save">
          <input type="hidden" name="tpl_id" value="<?= (int)$t['id'] ?>">
          <div class="mb-2"><label class="form-label small">Nazwa</label>
            <input class="form-control form-control-sm" name="name" value="<?= h($t['name']) ?>" required placeholder="Ubuntu 22.04 — sandbox"></div>
          <div class="mb-2"><label class="form-label small">Obraz Docker</label>
            <input class="form-control form-control-sm" name="docker_image" value="<?= h($t['docker_image']) ?>" required placeholder="feer/vlab-ubuntu:latest"></div>
          <div class="mb-2"><label class="form-label small">Opis</label>
            <input class="form-control form-control-sm" name="description" value="<?= h($t['description']) ?>"></div>
          <div class="mb-2"><label class="form-label small">Polecenie startowe (opcjonalnie)</label>
            <input class="form-control form-control-sm" name="run_cmd" value="<?= h($t['run_cmd']) ?>" placeholder="/entrypoint.sh"></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small">CPU</label>
              <input class="form-control form-control-sm" name="cpus" value="<?= h($t['cpus']) ?>" placeholder="(domyślne)"></div>
            <div class="col-6"><label class="form-label small">Pamięć</label>
              <input class="form-control form-control-sm" name="mem" value="<?= h($t['mem']) ?>" placeholder="(domyślne)"></div>
          </div>
          <div class="d-flex gap-3 mb-2">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="expose_ssh" id="es" <?= $t['expose_ssh']?'checked':'' ?>><label class="form-check-label small" for="es">SSH (22)</label></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="expose_ttyd" id="et" <?= $t['expose_ttyd']?'checked':'' ?>><label class="form-check-label small" for="et">ttyd (7681)</label></div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="ia" <?= $t['is_active']?'checked':'' ?>><label class="form-check-label small" for="ia">Aktywny</label></div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
            <a href="vlab_admin.php" class="btn btn-light btn-sm">Anuluj</a>
          </div>
        </form>
        <?php endif; ?>

        <?php if (!$templates): ?>
        <p class="text-muted small mb-0">Brak szablonów. Dodaj pierwszy obraz Docker dostępny dla kursantów.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Nazwa</th><th>Obraz</th><th class="text-center">SSH/ttyd</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($templates as $t): ?>
              <tr class="<?= $t['is_active'] ? '' : 'text-muted' ?>">
                <td><?= h($t['name']) ?><?= $t['is_active'] ? '' : ' <span class="badge bg-secondary">nieaktywny</span>' ?></td>
                <td><code class="small"><?= h($t['docker_image']) ?></code></td>
                <td class="text-center small"><?= $t['expose_ssh']?'✓':'—' ?> / <?= $t['expose_ttyd']?'✓':'—' ?></td>
                <td class="text-end text-nowrap">
                  <a href="?edit_tpl=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-pencil"></i></a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Usunąć szablon?')">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="_op" value="tpl_delete">
                    <input type="hidden" name="tpl_id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
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
  </div>
</div>

<!-- Przegląd kontenerów -->
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header fw-semibold"><i class="bi bi-pc-display me-1"></i>Maszyny kursantów (<?= count($containers) ?>)</div>
  <div class="card-body">
    <?php if (!$containers): ?>
    <p class="text-muted small mb-0">Brak aktywnych maszyn.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Kursant</th><th>Etykieta</th><th>Szablon</th><th>Status</th><th>Login SSH (host)</th><th>Porty (SSH/ttyd)</th><th>Utworzono</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($containers as $c):
          $stColor = ['running'=>'success','stopped'=>'secondary','error'=>'danger','provisioning'=>'warning'][$c['status']] ?? 'secondary';
        ?>
          <tr>
            <td><?= h($c['client_name'] ?? $c['student_login'] ?? '—') ?></td>
            <td><?= h($c['label']) ?></td>
            <td class="small text-muted"><?= h($c['tpl_name'] ?? '—') ?></td>
            <td><span class="badge bg-<?= $stColor ?>"><?= h($c['status']) ?></span>
              <?= $c['error_msg'] ? '<div class="small text-danger">'.h(mb_substr($c['error_msg'],0,80)).'</div>' : '' ?></td>
            <td class="small"><?= !empty($c['host_user']) ? '<code>'.h($c['host_user']).'</code>' : '<span class="text-muted">—</span>' ?>
              <?= !empty($c['force_pw_pending']) ? '<span class="badge bg-warning text-dark" title="Kursant musi zmienić hasło przy następnym logowaniu"><i class="bi bi-key-fill"></i> zmiana hasła</span>' : '' ?></td>
            <td class="small"><?= $c['ssh_port'] ? (int)$c['ssh_port'] : '—' ?> / <?= $c['ttyd_port'] ? (int)$c['ttyd_port'] : '—' ?></td>
            <td class="small text-muted"><?= h($c['created_at']) ?></td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline" onsubmit="return confirm('Ustawić NOWE hasło SSH dla tego konta? Stare przestanie działać.')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="host_pass_reset">
                <input type="hidden" name="container_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary py-0" title="Ustaw/odtwórz hasło SSH (pokazywane raz)"><i class="bi bi-key"></i></button>
              </form>
              <?php if (!empty($c['host_user'])): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Wymusić zmianę hasła SSH przy następnym logowaniu kursanta?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="host_pass_force">
                <input type="hidden" name="container_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-outline-warning py-0" title="Wymuś zmianę hasła przy następnym logowaniu"><i class="bi bi-key-fill"></i></button>
              </form>
              <?php endif; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Wymusić usunięcie maszyny kursanta? Konto SSH na hoście też zostanie skasowane.')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="force_remove">
                <input type="hidden" name="container_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i> Usuń</button>
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

<?php if ($reset_creds):
  $rc_ssh = 'ssh ' . $reset_creds['user'] . '@' . $reset_creds['host'] . ' -p ' . (int)$reset_creds['port'];
?>
<!-- Modal: nowe dane logowania po resecie hasła -->
<div class="modal fade" id="credsModal" tabindex="-1" aria-labelledby="credsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="credsModalLabel"><i class="bi bi-key-fill text-warning me-2"></i>Nowe dane logowania</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">Maszyna: <strong><?= h($reset_creds['label']) ?></strong>. Te dane pokazujemy <strong>tylko teraz</strong> — zapisz lub przekaż kursantowi.</p>
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">Połączenie SSH</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" readonly value="<?= h($rc_ssh) ?>" onclick="this.select()">
            <button type="button" class="btn btn-outline-secondary" data-copy="<?= h($rc_ssh) ?>"><i class="bi bi-clipboard"></i></button>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">Hasło SSH</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace fw-bold" readonly value="<?= h($reset_creds['password']) ?>" onclick="this.select()">
            <button type="button" class="btn btn-outline-secondary" data-copy="<?= h($reset_creds['password']) ?>"><i class="bi bi-clipboard"></i></button>
          </div>
        </div>
        <?php if (!empty($reset_creds['ttyd_url'])): ?>
        <div class="mb-2">
          <label class="form-label small text-muted mb-1">Terminal w przeglądarce</label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" readonly value="<?= h($reset_creds['ttyd_url']) ?>" onclick="this.select()">
            <button type="button" class="btn btn-outline-secondary" data-copy="<?= h($reset_creds['ttyd_url']) ?>"><i class="bi bi-clipboard"></i></button>
          </div>
          <?php if ($reset_creds['ttyd_user'] !== ''): ?>
          <div class="small text-muted mt-1">Login terminala: <code><?= h($reset_creds['ttyd_user']) ?> / <?= h($reset_creds['ttyd_pass']) ?></code></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($reset_creds['force'])): ?>
        <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-shield-lock me-1"></i>Kursant ustawi własne hasło przy pierwszym logowaniu SSH.</div>
        <?php endif; ?>
        <div class="alert <?= !empty($reset_creds['mailed']) ? 'alert-info' : 'alert-secondary' ?> py-2 small mb-0">
          <i class="bi bi-envelope me-1"></i>
          <?= !empty($reset_creds['mailed']) ? 'Te dane wysłano też e-mailem do kursanta.' : 'Nie udało się wysłać e-maila — przekaż dane kursantowi ręcznie.' ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('credsModal');
  if (el && window.bootstrap) new bootstrap.Modal(el).show();
  document.querySelectorAll('#credsModal [data-copy]').forEach(function(b){
    b.addEventListener('click', function(){
      navigator.clipboard && navigator.clipboard.writeText(b.dataset.copy);
      var h = b.innerHTML; b.innerHTML = '<i class="bi bi-check2"></i>';
      setTimeout(function(){ b.innerHTML = h; }, 1200);
    });
  });
})();
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
