<?php
/**
 * Panel kursanta TI — dashboard: moje lekcje, rozliczenia, VLab.
 * UI: Bootstrap 5.3 (motyw ciemny) + WCAG 2.1 AA.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Wylogowanie (przed jakimkolwiek wyjściem)
if (isset($_GET['logout'])) { student_logout(); header('Location: login.php'); exit; }

$student    = student_require();
$tab        = $_GET['tab'] ?? 'lekcje';
$vlab_token = student_token();

// Dane kursanta
$account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$student['id']]);
// Konto usunięte/zablokowane w trakcie sesji → wyloguj
if (!$account || empty($account['is_active'])) { student_logout(); header('Location: login.php'); exit; }
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];

// ── Odwołanie / przywrócenie udziału w lekcji przez Beneficjenta ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op  = $_POST['_op'] ?? '';
    $tok = $_POST['_token'] ?? '';
    if (!hash_equals(student_token(), (string)$tok)) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }

    if ($op === 'cancel_lesson' || $op === 'uncancel_lesson') {
        $sid = (int)($_POST['session_id'] ?? 0);
        // Lekcja musi należeć do kursu, do którego kursant jest aktywnie zapisany, i być zaplanowana
        $own = db_one(
            "SELECT s.id, s.status FROM k30_ti_sessions s
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
             WHERE s.id=?",
            [$student['client_id'], $sid]
        );
        if ($own && $own['status'] === 'planned') {
            if ($op === 'cancel_lesson') {
                $reason = trim($_POST['reason'] ?? '');
                k30_ti_cancel_attendance(
                    $sid, $student['client_id'],
                    $reason !== '' ? $reason : 'Odwołane przez beneficjenta',
                    'beneficjent', $client['name'] ?? ''
                );
            } else {
                k30_ti_uncancel_attendance($sid, $student['client_id']);
            }
        }
        header('Location: index.php?tab=lekcje'); exit;
    }
}

// Kursy i lekcje kursanta
$courses = k30_ti_client_courses($student['client_id']);
$lessons = k30_ti_client_lessons($student['client_id'], 40);

// Statystyki
$total_lessons  = count($lessons);
$attended_count = count(array_filter($lessons, fn($l) => $l['attended']));
$pct = $total_lessons > 0 ? round($attended_count / $total_lessons * 100) : 0;

$org        = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$is_minor   = !empty($account['is_minor']);
$months_pl  = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
               7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];

$KP_TITLE  = 'Panel kursanta';
$KP_TOPBAR = [
    'brand'  => $org,
    'icon'   => 'pc-display',
    'user'   => $client['name'] ?? $account['login'],
    'logout' => 'index.php?logout=1',
];
include __DIR__ . '/_layout_head.php';
?>

<nav class="container-xl px-3 pt-3" aria-label="Sekcje panelu">
  <ul class="nav nav-tabs">
    <li class="nav-item">
      <a class="nav-link <?= $tab==='lekcje'?'active':'' ?>" href="?tab=lekcje" <?= $tab==='lekcje'?'aria-current="page"':'' ?>>
        <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Moje lekcje
      </a>
    </li>
    <?php if (!$is_minor): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='rozliczenia'?'active':'' ?>" href="?tab=rozliczenia" <?= $tab==='rozliczenia'?'aria-current="page"':'' ?>>
        <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='vlab'?'active':'' ?>" href="?tab=vlab" <?= $tab==='vlab'?'aria-current="page"':'' ?>>
        <i class="bi bi-code-square me-1" aria-hidden="true"></i>VLab
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='online'?'active':'' ?>" href="?tab=online" <?= $tab==='online'?'aria-current="page"':'' ?>>
        <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Szkolenia online
      </a>
    </li>
  </ul>
</nav>

<main id="main" class="container-xl px-3 py-4">

<?php if ($tab === 'lekcje'): ?>

  <h1 class="h5 fw-bold mb-3">Moje lekcje</h1>

  <!-- Statystyki -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $total_lessons ?></div>
        <div class="text-body-secondary small mt-1">Wszystkich lekcji</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1 text-success"><?= $attended_count ?></div>
        <div class="text-body-secondary small mt-1">Obecności</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $pct ?>%</div>
        <div class="text-body-secondary small mt-1 mb-1">Frekwencja</div>
        <div class="progress" role="progressbar" aria-label="Frekwencja"
             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:6px">
          <div class="progress-bar" style="width:<?= $pct ?>%"></div>
        </div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= count($courses) ?></div>
        <div class="text-body-secondary small mt-1">Kursów/grup</div>
      </div></div>
    </div>
  </div>

  <!-- Moje kursy -->
  <?php if ($courses): ?>
  <div class="mb-3 d-flex flex-wrap gap-2" aria-label="Moje kursy">
    <?php foreach ($courses as $c): ?>
    <span class="badge text-bg-primary fs-6 fw-normal">
      <i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($c['course_name']) ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Lista lekcji -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Lista ostatnich lekcji z obecnością</caption>
        <thead>
          <tr>
            <th scope="col">Data</th>
            <th scope="col">Kurs</th>
            <th scope="col">Godziny</th>
            <th scope="col">Temat</th>
            <th scope="col" class="text-center">Obecność</th>
            <th scope="col">Uwagi</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$lessons): ?>
          <tr><td colspan="7" class="text-center text-body-secondary py-4">Brak lekcji.</td></tr>
          <?php endif; ?>
          <?php foreach ($lessons as $l):
            $d   = new DateTime($l['lesson_date']);
            $dow = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'][(int)$d->format('w')];
          ?>
          <tr>
            <td class="text-nowrap">
              <span class="text-body-secondary small"><?= $dow ?></span>
              <span class="fw-semibold"><?= $d->format('d') ?></span>
              <span class="text-body-secondary small"><?= $months_pl[(int)$d->format('n')] ?> <?= $d->format('Y') ?></span>
            </td>
            <td class="text-body-secondary small"><?= h($l['course_name']) ?></td>
            <td class="text-nowrap small">
              <?= $l['time_from'] ? h($l['time_from']).'–'.h($l['time_to']) : ((int)$l['duration_min']).' min' ?>
            </td>
            <td style="max-width:240px">
              <?php if ($l['topic']): ?>
              <?= h($l['topic']) ?>
              <?php if ($l['has_homework'] ?? 0): ?>
              <span class="badge text-bg-warning ms-1"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>zadanie</span>
              <?php endif; ?>
              <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
            </td>
            <?php $att_cancelled = (int)($l['att_cancelled'] ?? 0) === 1; ?>
            <td class="text-center">
              <?php if ($att_cancelled): ?>
              <span class="badge text-bg-danger" title="<?= h($l['att_cancel_reason'] ?? '') ?>"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>odwołane</span>
              <?php elseif ($l['status'] !== 'held'): ?>
              <span class="badge text-bg-secondary"><?= $l['status']==='planned'?'planowana':h($l['status']) ?></span>
              <?php elseif ($l['attended']): ?>
              <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>obecny</span>
              <?php else: ?>
              <span class="badge text-bg-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>nieobecny</span>
              <?php endif; ?>
            </td>
            <td class="small text-body-secondary">
              <?php if ($att_cancelled && !empty($l['att_cancel_reason'])): ?>
              <span class="text-danger">Powód odwołania: <?= h(mb_substr($l['att_cancel_reason'],0,60)) ?></span>
              <?php else: ?>
              <?= $l['ind_notes'] ? h(mb_substr($l['ind_notes'],0,60)) : '' ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($l['status'] === 'planned' && !$att_cancelled): ?>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      data-cancel-session="<?= (int)$l['id'] ?>"
                      data-lesson-label="<?= h($l['course_name'].' — '.(new DateTime($l['lesson_date']))->format('d.m.Y')) ?>">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odwołaj
              </button>
              <?php elseif ($l['status'] === 'planned' && $att_cancelled): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć odwołanie i potwierdzić udział?')">
                <input type="hidden" name="_token"     value="<?= h($vlab_token) ?>">
                <input type="hidden" name="_op"         value="uncancel_lesson">
                <input type="hidden" name="session_id"  value="<?= (int)$l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Cofnij
                </button>
              </form>
              <?php else: ?>
              <span class="text-body-secondary">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p class="text-body-secondary small mt-2">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    Zaplanowaną lekcję możesz odwołać, podając powód — odwołany udział nie jest liczony do ceny.
  </p>

  <!-- Modal: odwołanie udziału przez beneficjenta -->
  <div class="modal fade" id="cancelLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token"      value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"          value="cancel_lesson">
        <input type="hidden" name="session_id"   id="cl_session_id" value="">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-x-circle text-danger me-2" aria-hidden="true"></i>Odwołanie lekcji</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Lekcja: <strong id="cl_lesson_label"></strong></p>
          <p class="text-body-secondary small mb-2">Odwołany udział nie zostanie policzony do ceny. Podaj powód odwołania.</p>
          <label class="form-label fw-semibold" for="cl_reason">Powód odwołania</label>
          <textarea class="form-control" id="cl_reason" name="reason" rows="3" required
                    placeholder="np. choroba, kolizja z innymi obowiązkami…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odwołaj lekcję</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function(){
    var modalEl = document.getElementById('cancelLessonModal');
    if (!modalEl) return;
    document.querySelectorAll('[data-cancel-session]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('cl_session_id').value = btn.getAttribute('data-cancel-session');
        document.getElementById('cl_lesson_label').textContent = btn.getAttribute('data-lesson-label') || '';
        document.getElementById('cl_reason').value = '';
        new bootstrap.Modal(modalEl).show();
      });
    });
  })();
  </script>

<?php elseif ($tab === 'rozliczenia' && !$is_minor):
  $rv_client_id    = $student['client_id'];
  $rv_show_lessons = false;
  include __DIR__ . '/_rozliczenia_view.php';
?>

<?php elseif ($tab === 'vlab'): ?>

  <div id="vlab-root" data-token="<?= h($vlab_token) ?>">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-hdd-stack text-primary" aria-hidden="true"></i>VLab — Twoje maszyny
    </h1>
    <p class="text-body-secondary small mb-3">
      Twórz własne środowiska (kontenery Docker) do ćwiczeń. Dostęp przez terminal w przeglądarce lub po SSH.
    </p>
    <div id="vlab-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">Ładowanie…</div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('vlab-root');
    const box  = document.getElementById('vlab-content');
    const token = root.dataset.token;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function api(action, params){
      const body = new URLSearchParams(Object.assign({action, _token: token}, params || {}));
      const r = await fetch('vlab_api.php', {method:'POST', headers:{'X-CSRF-Token':token}, body});
      return r.json();
    }
    const stMap = {running:['success','działa'], stopped:['secondary','zatrzymana'], error:['danger','błąd'], provisioning:['warning','tworzenie']};
    const lastHostCreds = {}; // hasło SSH pokazywane jednorazowo po utworzeniu: {id: password}

    function render(d){
      if (!d.enabled){
        box.innerHTML = '<div class="alert alert-warning d-flex align-items-center gap-2" role="alert">'
          + '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>'
          + '<span>Moduł VLab nie został jeszcze skonfigurowany przez administratora.</span></div>';
        return;
      }
      let html = '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-pc-display me-2" aria-hidden="true"></i>'
        + 'Moje maszyny <span class="badge text-bg-secondary ms-2">'+d.count+' / '+d.max+'</span></h2>';

      if (!d.machines.length){
        html += '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary mb-4">'
          + 'Nie masz jeszcze żadnej maszyny. Utwórz ją z szablonu poniżej.</div>';
      } else {
        for (const m of d.machines){
          const [col,lbl] = stMap[m.status] || ['secondary', m.status];
          html += '<div class="card mb-3"><div class="card-body">'
            + '<div class="d-flex align-items-center gap-2 mb-2">'
            + '<span class="fw-semibold">'+esc(m.label)+'</span>'
            + '<span class="badge text-bg-'+col+'">'+lbl+'</span></div>';
          if (m.status === 'error' && m.error){
            html += '<p class="text-danger small mb-2">'+esc(m.error)+'</p>';
          }
          // Efektywne dane SSH: konto hosta (preferowane) lub fallback na bezpośredni port kontenera.
          const sshUser = m.host_user || m.ssh_user || '';
          const sshPort = m.host_user ? m.host_port : (m.ssh_port || 0);
          const sshOk   = m.status === 'running' && m.ssh_host && sshUser && sshPort;
          if (sshOk){
            const pwd = lastHostCreds[m.id];
            html += '<div class="bg-body-tertiary border rounded p-2 mb-2 small font-monospace">'
              + '<div><span class="text-body-secondary">SSH:</span> ssh '+esc(sshUser)+'@'+esc(m.ssh_host)+' -p '+sshPort+'</div>';
            if (pwd){
              html += '<div class="d-flex align-items-center gap-2 mt-1"><span><span class="text-body-secondary">hasło (pokazywane tylko raz):</span> <span class="fw-bold">'+esc(pwd)+'</span></span>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-copy="'+esc(pwd)+'" aria-label="Kopiuj hasło SSH"><i class="bi bi-clipboard" aria-hidden="true"></i></button></div>';
            } else if (m.host_user){
              html += '<div class="mt-1 text-body-secondary" style="font-family:inherit"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Hasło wysłaliśmy e-mailem przy tworzeniu maszyny.</div>';
            } else {
              html += '<div class="mt-1 text-body-secondary" style="font-family:inherit"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Dane logowania zgodne z obrazem maszyny (hasło lub klucz SSH).</div>';
            }
            html += '</div>';
          }
          html += '<div class="d-flex flex-wrap gap-2">';
          if (m.ttyd_url && m.status === 'running'){
            html += '<a class="btn btn-primary btn-sm" href="'+esc(m.ttyd_url)+'" target="_blank" rel="noopener"><i class="bi bi-terminal me-1" aria-hidden="true"></i>Otwórz terminal</a>';
          }
          if (sshOk){
            html += '<a class="btn btn-outline-primary btn-sm" href="ssh://'+esc(sshUser)+'@'+esc(m.ssh_host)+':'+sshPort+'" title="Otwiera klienta SSH zainstalowanego w systemie"><i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Połącz po SSH</a>';
          }
          if (m.status === 'running'){
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="stop" data-id="'+m.id+'"><i class="bi bi-stop-circle me-1" aria-hidden="true"></i>Zatrzymaj</button>';
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="restart" data-id="'+m.id+'"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Restart</button>';
          } else if (m.status === 'stopped'){
            html += '<button type="button" class="btn btn-outline-success btn-sm" data-act="start" data-id="'+m.id+'"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Uruchom</button>';
          }
          html += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="remove" data-id="'+m.id+'"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń</button>';
          html += '</div></div></div>';
        }
      }

      html += '<h2 class="h6 fw-bold d-flex align-items-center mt-4 mb-2"><i class="bi bi-collection me-2" aria-hidden="true"></i>Utwórz nową maszynę</h2>';
      const canCreate = d.count < d.max;
      if (!canCreate){
        html += '<p class="text-body-secondary small">Osiągnięto limit maszyn ('+d.max+'). Usuń istniejącą, aby utworzyć nową.</p>';
      }
      if (!d.templates.length){
        html += '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">Brak dostępnych szablonów.</div>';
      } else {
        html += '<div class="row g-3">';
        for (const t of d.templates){
          html += '<div class="col-12 col-md-6 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
            + '<h3 class="h6 mb-1">'+esc(t.name)+'</h3>'
            + '<p class="text-body-secondary small flex-grow-1">'+esc(t.description||'')+'</p>'
            + '<button type="button" class="btn btn-primary btn-sm" data-create="'+t.id+'" '+(canCreate?'':'disabled')+'>'
            + '<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Utwórz</button>'
            + '</div></div></div>';
        }
        html += '</div>';
      }
      box.innerHTML = html;
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    box.addEventListener('click', async (e)=>{
      const copyBtn = e.target.closest('[data-copy]');
      if (copyBtn){ navigator.clipboard?.writeText(copyBtn.dataset.copy); copyBtn.innerHTML='<i class="bi bi-check2" aria-hidden="true"></i>'; return; }

      const createBtn = e.target.closest('[data-create]');
      if (createBtn){
        const label = prompt('Nazwa maszyny (litery, cyfry, myślniki):', 'lab');
        if (label === null) return;
        createBtn.disabled = true; createBtn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Tworzę…';
        const r = await api('create', {template_id: createBtn.dataset.create, label});
        if (r.id && r.host_password) lastHostCreds[r.id] = r.host_password; // pokaż hasło raz
        if (!r.ok) alert(r.msg || 'Błąd.');
        if (r.data) render(r.data); else reload();
        return;
      }

      const actBtn = e.target.closest('[data-act]');
      if (actBtn){
        const act = actBtn.dataset.act;
        if (act === 'remove' && !confirm('Usunąć maszynę? Tej operacji nie można cofnąć.')) return;
        actBtn.disabled = true;
        const r = await api(act, {id: actBtn.dataset.id});
        if (!r.ok) alert(r.msg || 'Błąd.');
        if (r.data) render(r.data); else reload();
      }
    });

    reload();
  })();
  </script>

<?php elseif ($tab === 'online'): ?>

  <div id="online-root" data-token="<?= h($vlab_token) ?>">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-camera-video text-primary" aria-hidden="true"></i>Szkolenia online
    </h1>
    <p class="text-body-secondary small mb-3">
      Twoje konto szkoleniowe Microsoft&nbsp;365, dostęp do platformy e-learningowej oraz linki do nadchodzących szkoleń (Zoom / MS&nbsp;Teams).
    </p>
    <div id="online-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">Ładowanie…</div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('online-root');
    const box  = document.getElementById('online-content');
    const token = root.dataset.token;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function api(action, params){
      const body = new URLSearchParams(Object.assign({action, _token: token}, params || {}));
      const r = await fetch('ti_online_api.php', {method:'POST', headers:{'X-CSRF-Token':token}, body});
      return r.json();
    }

    function fmtDate(s){
      if (!s) return '';
      const d = new Date(s.replace(' ', 'T'));
      if (isNaN(d)) return esc(s);
      return d.toLocaleString('pl-PL', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
    }
    const platMap = {zoom:['primary','camera-video','Zoom'], teams:['info','microsoft-teams','MS Teams'], other:['secondary','link-45deg','Link']};

    let lastCreds = null; // jednorazowe dane konta MS po utworzeniu

    function cardMS(d){
      let inner;
      if (!d.ms_enabled){
        inner = '<p class="text-body-secondary small mb-0">Moduł kont Microsoft nie został skonfigurowany przez administratora.</p>';
      } else if (d.ms_active){
        inner = '<p class="small mb-2">Twój login (działa też w Moodle):<br><span class="font-monospace fw-semibold">'+esc(d.ms_upn)+'</span></p>';
        if (lastCreds && lastCreds.upn === d.ms_upn){
          inner += '<div class="alert alert-warning small py-2"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>'
            + 'Hasło tymczasowe (zapisz teraz, zmienisz przy pierwszym logowaniu): <span class="font-monospace fw-bold">'+esc(lastCreds.password)+'</span></div>';
        }
        inner += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="ms_delete"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto</button>';
      } else {
        inner = '<p class="text-body-secondary small mb-2">Nie masz jeszcze konta szkoleniowego. Utwórz je, aby korzystać z usług Microsoft i platformy e-learningowej.</p>'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="ms_create"><i class="bi bi-microsoft me-1" aria-hidden="true"></i>Utwórz konto</button>';
      }
      return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-microsoft me-2 text-primary" aria-hidden="true"></i>Konto Microsoft 365</h2>'
        + inner + '</div></div></div>';
    }

    function cardMoodle(d){
      let inner;
      if (!d.moodle_enabled){
        inner = '<p class="text-body-secondary small mb-0">Integracja z platformą e-learningową nie została skonfigurowana.</p>';
      } else if (d.moodle_active){
        inner = '<p class="small mb-2">Login: <span class="font-monospace fw-semibold">'+esc(d.moodle_login)+'</span><br>'
          + '<span class="text-body-secondary">Hasło: domyślnie takie samo jak do konta Microsoft — możesz ustawić własne poniżej.</span></p>'
          + (d.moodle_url ? '<a class="btn btn-success btn-sm mb-2" href="'+esc(d.moodle_url)+'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz platformę</a>' : '')
          + '<details class="mt-1">'
          + '<summary class="small text-primary" style="cursor:pointer"><i class="bi bi-key me-1" aria-hidden="true"></i>Ustaw własne hasło do platformy</summary>'
          + '<div class="mt-2" style="max-width:340px">'
          + '<label class="form-label small mb-1" for="moodle-pwd">Nowe hasło</label>'
          + '<input type="password" class="form-control form-control-sm mb-2" id="moodle-pwd" autocomplete="new-password" minlength="8" placeholder="min. 8 znaków, A-z, cyfra, znak specjalny">'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="moodle_password"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz hasło</button>'
          + '<p class="form-text small mb-0">Hasło musi mieć min. 8 znaków oraz zawierać małą i wielką literę, cyfrę i znak specjalny.</p>'
          + '</div></details>';
      } else if (!d.ms_active){
        inner = '<p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Najpierw utwórz konto Microsoft — jego login posłuży jako login do platformy.</p>';
      } else {
        inner = '<p class="text-body-secondary small mb-2">Utwórz konto na platformie e-learningowej (login = Twój adres Microsoft).</p>'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="moodle_create"><i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Utwórz konto Moodle</button>';
      }
      return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-mortarboard me-2 text-primary" aria-hidden="true"></i>Platforma e-learning</h2>'
        + inner + '</div></div></div>';
    }

    function cardMeetings(d){
      let body;
      if (!d.meetings || !d.meetings.length){
        body = '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">Brak zaplanowanych szkoleń online.</div>';
      } else {
        body = '<div class="list-group">';
        for (const m of d.meetings){
          const [col,icon,lbl] = platMap[m.platform] || platMap.other;
          body += '<div class="list-group-item d-flex align-items-center gap-3 flex-wrap">'
            + '<span class="badge text-bg-'+col+'"><i class="bi bi-'+icon+' me-1" aria-hidden="true"></i>'+lbl+'</span>'
            + '<span class="flex-grow-1"><span class="fw-semibold">'+esc(m.title)+'</span>'
            + (m.starts_at ? ' <span class="text-body-secondary small d-block d-sm-inline">'+fmtDate(m.starts_at)+'</span>' : '')+'</span>'
            + '<a class="btn btn-outline-primary btn-sm" href="'+esc(m.join_url)+'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Dołącz</a>'
            + '</div>';
        }
        body += '</div>';
      }
      return '<div class="col-12"><div class="card"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-3"><i class="bi bi-calendar-event me-2 text-primary" aria-hidden="true"></i>Nadchodzące szkolenia</h2>'
        + body + '</div></div></div>';
    }

    function render(d){
      box.innerHTML = '<div class="row g-3">' + cardMS(d) + cardMoodle(d) + cardMeetings(d) + '</div>';
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    box.addEventListener('click', async (e)=>{
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const act = btn.dataset.act;
      if (act === 'ms_delete' && !confirm('Usunąć konto Microsoft? Stracisz dostęp do powiązanych usług.')) return;
      let params = {};
      if (act === 'moodle_password'){
        const inp = box.querySelector('#moodle-pwd');
        const pwd = inp ? inp.value : '';
        if (!pwd){ if (inp) inp.focus(); return; }
        params = {password: pwd};
      }
      btn.disabled = true;
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Pracuję…';
      const r = await api(act, params);
      if (act === 'ms_create' && r.ok && r.password) lastCreds = {upn: r.upn, password: r.password};
      if (act === 'moodle_password' && r.ok) alert(r.msg || 'Hasło zmienione.');
      if (!r.ok) alert(r.msg || 'Błąd.');
      if (r.data) render(r.data); else { btn.disabled = false; btn.innerHTML = orig; reload(); }
    });

    reload();
  })();
  </script>

<?php endif; ?>

</main>

<?php include __DIR__ . '/_layout_foot.php'; ?>
