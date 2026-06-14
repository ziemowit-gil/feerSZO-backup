<?php
/**
 * Panel kursanta TI — dashboard: moje lekcje + zakładka VLab.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

$student = student_require();
$tab     = $_GET['tab'] ?? 'lekcje';
$vlab_token = student_token();

// Dane kursanta
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]);
$account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$student['id']]);

// Kursy i lekcje kursanta
$courses = k30_ti_client_courses($student['client_id']);

// Lekcje z obecnością (ostatnie 20)
$lessons = db_all(
    "SELECT s.*, c.name AS course_name,
            a.attended, a.ind_notes
     FROM k30_ti_sessions s
     JOIN k30_ti_courses c ON c.id=s.course_id
     LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=?
     WHERE s.course_id IN (
         SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'
     )
     ORDER BY s.lesson_date DESC, s.time_from DESC
     LIMIT 40",
    [$student['client_id'], $student['client_id']]
);

// Statystyki
$total_lessons  = count($lessons);
$attended_count = count(array_filter($lessons, fn($l) => $l['attended']));
$pct = $total_lessons > 0 ? round($attended_count / $total_lessons * 100) : 0;

$org = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$months_pl = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
              7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel kursanta — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --bg:#0f172a; --card:#1e293b; --border:#334155; --text:#f1f5f9; --muted:#94a3b8; --accent:#2563eb; --purple:#7c3aed; }
* { box-sizing: border-box; }
body { background: var(--bg); color: var(--text); font-family: 'Segoe UI',Arial,sans-serif; margin: 0; min-height: 100vh; }

/* Topbar */
.topbar { background: var(--card); border-bottom: 1px solid var(--border); padding: .75rem 1.5rem; display: flex; align-items: center; gap: 1rem; }
.topbar-brand { font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: .5rem; color: var(--text); text-decoration: none; }
.topbar-brand i { color: var(--accent); font-size: 1.2rem; }
.topbar-user { margin-left: auto; display: flex; align-items: center; gap: .75rem; font-size: .83rem; color: var(--muted); }
.btn-logout { background: none; border: 1px solid var(--border); color: var(--muted); border-radius: 6px; padding: .3rem .75rem; font-size: .78rem; cursor: pointer; }
.btn-logout:hover { border-color: #ef4444; color: #ef4444; }

/* Tabs */
.tabs { background: var(--card); border-bottom: 1px solid var(--border); display: flex; padding: 0 1.5rem; gap: 0; }
.tab { display: flex; align-items: center; gap: .4rem; padding: .85rem 1.25rem; font-size: .9rem; font-weight: 600; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; transition: color .15s; }
.tab:hover { color: var(--text); }
.tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab .badge-wip { background: #f59e0b22; color: #f59e0b; border: 1px solid #f59e0b44; border-radius: 4px; font-size: .65rem; padding: .1em .4em; font-weight: 700; }

/* Content */
.content { padding: 1.5rem; max-width: 1000px; margin: 0 auto; }

/* Stats */
.stats-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; }
.stat-val  { font-size: 1.8rem; font-weight: 800; line-height: 1; }
.stat-lbl  { color: var(--muted); font-size: .78rem; margin-top: .3rem; }

/* Lesson table */
.lesson-table { background: var(--card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.lesson-table table { width: 100%; border-collapse: collapse; }
.lesson-table th { background: #0f172a; color: var(--muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; padding: .6rem 1rem; font-weight: 700; }
.lesson-table td { padding: .65rem 1rem; border-top: 1px solid var(--border); font-size: .86rem; vertical-align: middle; }
.att-yes { color: #22c55e; font-weight: 700; }
.att-no  { color: #ef4444; }
.att-unk { color: var(--muted); }

/* Progress bar */
.prog-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin-top: .4rem; }
.prog-fill { height: 100%; background: linear-gradient(90deg,#2563eb,#7c3aed); border-radius: 3px; transition: width .4s; }

/* VLab */
.vlab-wip { text-align: center; padding: 4rem 1rem; }
.vlab-icon { font-size: 4rem; margin-bottom: 1rem; }
.vlab-badge { background: #f59e0b22; color: #f59e0b; border: 1px solid #f59e0b55; border-radius: 8px; display: inline-block; padding: .4em 1em; font-weight: 700; font-size: .85rem; margin-bottom: 1.5rem; }
.vlab-section-title { font-size: .95rem; font-weight: 700; color: var(--text); margin: 1.5rem 0 .75rem; display: flex; align-items: center; gap: .5rem; }
.vlab-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap: 1rem; }
.vlab-tpl { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; display: flex; flex-direction: column; }
.vlab-tpl h4 { font-size: .92rem; margin: 0 0 .35rem; color: var(--text); }
.vlab-tpl p { font-size: .78rem; color: var(--muted); margin: 0 0 .9rem; flex: 1; line-height: 1.4; }
.vlab-machine { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: .85rem; }
.vlab-machine-head { display: flex; align-items: center; gap: .6rem; margin-bottom: .6rem; }
.vlab-machine-head .name { font-weight: 700; font-size: .95rem; }
.vlab-st { font-size: .7rem; font-weight: 700; padding: .15em .6em; border-radius: 4px; text-transform: uppercase; letter-spacing: .04em; }
.vlab-st.running  { background:#22c55e22; color:#22c55e; border:1px solid #22c55e44; }
.vlab-st.stopped  { background:#94a3b822; color:#cbd5e1; border:1px solid #94a3b844; }
.vlab-st.error    { background:#ef444422; color:#ef4444; border:1px solid #ef444444; }
.vlab-st.provisioning { background:#f59e0b22; color:#f59e0b; border:1px solid #f59e0b44; }
.vlab-ssh { background:#0f172a; border:1px solid var(--border); border-radius:8px; padding:.6rem .8rem; margin-top:.6rem; font-size:.78rem; font-family:'SFMono-Regular',Consolas,monospace; color:#93c5fd; }
.vlab-ssh code { color:#93c5fd; }
.vlab-ssh .lbl { color:var(--muted); }
.vlab-actions { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.7rem; }
.vlab-btn { border:1px solid var(--border); background:#0f172a; color:var(--text); border-radius:6px; padding:.32rem .7rem; font-size:.76rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:.3rem; text-decoration:none; }
.vlab-btn:hover { border-color:var(--accent); color:#fff; }
.vlab-btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.vlab-btn.danger:hover { border-color:#ef4444; color:#ef4444; }
.vlab-btn:disabled { opacity:.4; cursor:not-allowed; }
.vlab-empty { color:var(--muted); font-size:.85rem; padding:1.5rem; text-align:center; border:1px dashed var(--border); border-radius:10px; }
.vlab-disabled { background:#f59e0b11; border:1px solid #f59e0b44; color:#fbbf24; border-radius:10px; padding:1rem 1.25rem; font-size:.85rem; }
.vlab-limit { font-size:.78rem; color:var(--muted); margin-bottom:.5rem; }
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
  <a href="index.php" class="topbar-brand">
    <i class="bi bi-pc-display"></i><?= h($org) ?>
  </a>
  <div class="topbar-user">
    <i class="bi bi-person-circle"></i>
    <span><?= h($client['name'] ?? $account['login']) ?></span>
    <a href="login.php?logout=1" class="btn-logout">
      <i class="bi bi-box-arrow-right me-1"></i>Wyloguj
    </a>
  </div>
</div>

<!-- Tabs -->
<div class="tabs">
  <a href="?tab=lekcje" class="tab <?= $tab==='lekcje'?'active':'' ?>">
    <i class="bi bi-calendar-check"></i> Moje lekcje
  </a>
  <?php if (empty($account['is_minor'])): ?>
  <a href="?tab=rozliczenia" class="tab <?= $tab==='rozliczenia'?'active':'' ?>">
    <i class="bi bi-receipt"></i> Rozliczenia
  </a>
  <?php endif; ?>
  <a href="?tab=vlab" class="tab <?= $tab==='vlab'?'active':'' ?>">
    <i class="bi bi-code-square"></i> VLab
  </a>
</div>

<div class="content">

<?php if ($tab === 'lekcje'): ?>

  <!-- Statystyki -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-val"><?= $total_lessons ?></div>
      <div class="stat-lbl">Wszystkich lekcji</div>
    </div>
    <div class="stat-card">
      <div class="stat-val att-yes"><?= $attended_count ?></div>
      <div class="stat-lbl">Byłem/am obecny</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $pct ?>%</div>
      <div class="stat-lbl">Frekwencja</div>
      <div class="prog-bar mt-2"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= count($courses) ?></div>
      <div class="stat-lbl">Kursów/grup</div>
    </div>
  </div>

  <!-- Moje kursy -->
  <?php if ($courses): ?>
  <div class="mb-3 d-flex flex-wrap gap-2">
    <?php foreach ($courses as $c): ?>
    <span class="badge" style="background:#1e3a5f;color:#93c5fd;border:1px solid #1e40af44;font-size:.8rem;padding:.4em .8em;border-radius:6px">
      <i class="bi bi-pc-display me-1"></i><?= h($c['course_name']) ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Lista lekcji -->
  <div class="lesson-table">
    <table>
      <thead>
        <tr>
          <th>Data</th>
          <th>Kurs</th>
          <th>Godziny</th>
          <th>Temat</th>
          <th class="text-center">Obecność</th>
          <th>Uwagi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$lessons): ?>
        <tr><td colspan="6" style="color:var(--muted);text-align:center;padding:2rem">Brak lekcji.</td></tr>
        <?php endif; ?>
        <?php foreach ($lessons as $l):
          $d   = new DateTime($l['lesson_date']);
          $dow = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'][(int)$d->format('w')];
          $att = $l['attended'];
        ?>
        <tr>
          <td class="text-nowrap" style="color:var(--muted)">
            <span style="font-size:.72rem"><?= $dow ?></span><br>
            <strong style="font-size:.9rem;color:var(--text)"><?= $d->format('d') ?></strong>
            <span style="font-size:.8rem"><?= $months_pl[(int)$d->format('n')] ?> <?= $d->format('Y') ?></span>
          </td>
          <td style="color:var(--muted);font-size:.82rem"><?= h($l['course_name']) ?></td>
          <td class="text-nowrap" style="font-size:.82rem">
            <?= $l['time_from'] ? h($l['time_from']).'–'.h($l['time_to']) : ((int)$l['duration_min']).' min' ?>
          </td>
          <td style="max-width:220px">
            <?php if ($l['topic']): ?>
            <span style="font-size:.85rem"><?= h($l['topic']) ?></span>
            <?php if ($l['has_homework'] ?? 0): ?>
            <span title="Zadanie domowe" style="font-size:.7rem;margin-left:.3rem;color:#f59e0b">📝</span>
            <?php endif; ?>
            <?php else: ?>
            <span style="color:var(--muted);font-size:.8rem">—</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($l['status'] !== 'held'): ?>
            <span class="att-unk" style="font-size:.8rem"><?= $l['status'] === 'planned' ? 'planowana' : h($l['status']) ?></span>
            <?php elseif ($att): ?>
            <span class="att-yes"><i class="bi bi-check-circle-fill"></i></span>
            <?php else: ?>
            <span class="att-no"><i class="bi bi-x-circle-fill"></i></span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;color:var(--muted)">
            <?= $l['ind_notes'] ? h(mb_substr($l['ind_notes'],0,60)) : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'rozliczenia' && empty($account['is_minor'])):
  $rv_client_id = $student['client_id'];
  $rv_show_lessons = false;
  include __DIR__ . '/_rozliczenia_view.php';
?>

<?php elseif ($tab === 'vlab'): ?>

  <div id="vlab-root" data-token="<?= h($vlab_token) ?>">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem">
      <i class="bi bi-hdd-stack" style="font-size:1.3rem;color:var(--accent)"></i>
      <h3 style="margin:0;font-size:1.15rem">VLab — Twoje maszyny</h3>
    </div>
    <p style="color:var(--muted);font-size:.85rem;margin:0 0 1rem">
      Twórz własne środowiska (kontenery Docker) do ćwiczeń. Dostęp przez terminal w przeglądarce lub po SSH.
    </p>
    <div id="vlab-content"><div class="vlab-empty">Ładowanie…</div></div>
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

    function stLabel(s){ return {running:'działa', stopped:'zatrzymana', error:'błąd', provisioning:'tworzenie'}[s] || s; }

    function render(d){
      if (!d.enabled){
        box.innerHTML = '<div class="vlab-disabled"><i class="bi bi-exclamation-triangle me-1"></i>'
          + 'Moduł VLab nie został jeszcze skonfigurowany przez administratora.</div>';
        return;
      }
      let html = '';

      // Moje maszyny
      html += '<div class="vlab-section-title"><i class="bi bi-pc-display"></i> Moje maszyny <span class="vlab-limit" style="margin-left:auto">'
        + d.count + ' / ' + d.max + '</span></div>';
      if (!d.machines.length){
        html += '<div class="vlab-empty">Nie masz jeszcze żadnej maszyny. Utwórz ją z szablonu poniżej.</div>';
      } else {
        for (const m of d.machines){
          html += '<div class="vlab-machine" data-id="'+m.id+'">'
            + '<div class="vlab-machine-head"><span class="name">'+esc(m.label)+'</span>'
            + '<span class="vlab-st '+esc(m.status)+'">'+stLabel(m.status)+'</span></div>';
          if (m.status === 'error' && m.error){
            html += '<div style="color:#ef4444;font-size:.78rem">'+esc(m.error)+'</div>';
          }
          if (m.status === 'running' && m.ssh_port && m.ssh_host){
            html += '<div class="vlab-ssh">'
              + '<div><span class="lbl">SSH:</span> <code>ssh '+esc(m.ssh_user)+'@'+esc(m.ssh_host)+' -p '+m.ssh_port+'</code></div>'
              + '<div><span class="lbl">hasło:</span> <code>'+esc(m.ssh_pass)+'</code> '
              + '<button class="vlab-btn" style="padding:.1rem .4rem;font-size:.68rem" data-copy="'+esc(m.ssh_pass)+'"><i class="bi bi-clipboard"></i></button></div>'
              + '</div>';
          }
          html += '<div class="vlab-actions">';
          if (m.ttyd_url && m.status === 'running'){
            html += '<a class="vlab-btn primary" href="'+esc(m.ttyd_url)+'" target="_blank" rel="noopener"><i class="bi bi-terminal"></i> Otwórz terminal</a>';
          }
          if (m.status === 'running'){
            html += '<button class="vlab-btn" data-act="stop" data-id="'+m.id+'"><i class="bi bi-stop-circle"></i> Zatrzymaj</button>';
            html += '<button class="vlab-btn" data-act="restart" data-id="'+m.id+'"><i class="bi bi-arrow-clockwise"></i> Restart</button>';
          } else if (m.status === 'stopped'){
            html += '<button class="vlab-btn" data-act="start" data-id="'+m.id+'"><i class="bi bi-play-circle"></i> Uruchom</button>';
          }
          html += '<button class="vlab-btn danger" data-act="remove" data-id="'+m.id+'"><i class="bi bi-trash"></i> Usuń</button>';
          html += '</div></div>';
        }
      }

      // Katalog szablonów
      html += '<div class="vlab-section-title"><i class="bi bi-collection"></i> Utwórz nową maszynę</div>';
      const canCreate = d.count < d.max;
      if (!canCreate){
        html += '<div class="vlab-limit">Osiągnięto limit maszyn ('+d.max+'). Usuń istniejącą, aby utworzyć nową.</div>';
      }
      if (!d.templates.length){
        html += '<div class="vlab-empty">Brak dostępnych szablonów.</div>';
      } else {
        html += '<div class="vlab-grid">';
        for (const t of d.templates){
          html += '<div class="vlab-tpl"><h4>'+esc(t.name)+'</h4><p>'+esc(t.description||'')+'</p>'
            + '<button class="vlab-btn primary" data-create="'+t.id+'" '+(canCreate?'':'disabled')+'>'
            + '<i class="bi bi-plus-circle"></i> Utwórz</button></div>';
        }
        html += '</div>';
      }
      box.innerHTML = html;
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    box.addEventListener('click', async (e)=>{
      const copyBtn = e.target.closest('[data-copy]');
      if (copyBtn){ navigator.clipboard?.writeText(copyBtn.dataset.copy); copyBtn.innerHTML='<i class="bi bi-check2"></i>'; return; }

      const createBtn = e.target.closest('[data-create]');
      if (createBtn){
        const label = prompt('Nazwa maszyny (litery, cyfry, myślniki):', 'lab');
        if (label === null) return;
        createBtn.disabled = true; createBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Tworzę…';
        const r = await api('create', {template_id: createBtn.dataset.create, label});
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

<?php endif; ?>

</div><!-- /content -->
</body>
</html>
<?php
// Wylogowanie
if (isset($_GET['logout'])) { student_logout(); header('Location: login.php'); exit; }
?>
