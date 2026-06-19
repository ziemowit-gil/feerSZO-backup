<?php
/**
 * Partial HTML ładowany do offcanvas przez fetch() + createContextualFragment().
 * NIE zawiera DOCTYPE ani nagłówków strony.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/org.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { echo '<div class="alert alert-danger m-3">Brak ID zadania.</div>'; exit; }

$task = db_one(
    "SELECT t.*, tl.name AS list_name, tl.workspace_id
     FROM tasks t JOIN task_lists tl ON tl.id = t.list_id
     WHERE t.id=? AND t.deleted_at IS NULL",
    [$id]
);
if (!$task) { echo '<div class="alert alert-danger m-3">Zadanie nie istnieje lub zostało usunięte.</div>'; exit; }

task_require_workspace_access((int)$task['workspace_id']);
$my_role  = task_workspace_role((int)$task['workspace_id'], $uid);
$can_edit = in_array($my_role, ['admin', 'editor'], true);

$task_tags  = db_all(
    "SELECT tt.id, tt.name, tt.color, tt.text_color
     FROM task_task_tags ttt JOIN task_tags tt ON tt.id=ttt.tag_id
     WHERE ttt.task_id=? ORDER BY tt.name", [$id]);
$tag_ids    = array_column($task_tags, 'id');

$avail_tags = db_all(
    "SELECT * FROM task_tags WHERE is_active=1
     AND (workspace_id IS NULL OR workspace_id=?) ORDER BY name",
    [$task['workspace_id']]);

$assignees  = db_all(
    "SELECT u.id, u.name FROM task_assignments ta
     JOIN users u ON u.id=ta.user_id WHERE ta.task_id=? ORDER BY u.name", [$id]);
$assign_ids = array_column($assignees, 'id');

$all_users  = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");

$comments   = db_all(
    "SELECT tc.*, u.name AS author_name FROM task_comments tc
     JOIN users u ON u.id=tc.author_id
     WHERE tc.task_id=? AND tc.deleted_at IS NULL ORDER BY tc.created_at", [$id]);

$history    = db_all(
    "SELECT th.*, u.name AS actor_name FROM task_history th
     JOIN users u ON u.id=th.user_id
     WHERE th.task_id=? ORDER BY th.occurred_at DESC LIMIT 40", [$id]);

$files = [];
try { $files = db_all("SELECT * FROM task_files WHERE task_id=? ORDER BY created_at", [$id]); }
catch (\Throwable $e) {}

$subtasks = [];
try { $subtasks = db_all("SELECT * FROM task_subtasks WHERE task_id=? ORDER BY position, id", [$id]); }
catch (\Throwable $e) {}
$st_total = count($subtasks);
$st_done  = count(array_filter($subtasks, fn($s) => (bool)$s['is_done']));
$st_pct   = $st_total ? round($st_done / $st_total * 100) : 0;

$lists_in_ws = db_all(
    "SELECT id, name FROM task_lists WHERE workspace_id=? ORDER BY position",
    [$task['workspace_id']]);

$csrf    = csrf_token();
$is_done = (bool)$task['completed_at'];
$overdue = $task['due_date'] && !$is_done
           && strtotime($task['due_date']) < strtotime('today');

// ── Komórka organizacyjna aktora — do „Poproś o przejęcie" ───────────────
$_actor_is_sys_admin = (db_one("SELECT role FROM users WHERE id=?", [$uid])['role'] ?? '') === 'admin';

// Czy bieżący user jest liderem (admin/editor) w obszarze tego zadania
$_tsk_is_leader_here = $_actor_is_sys_admin || in_array(
    task_workspace_role((int)$task['workspace_id'], $uid),
    ['admin', 'editor'],
    true
);

// Jednostki, do których należy bieżący użytkownik
$_actor_unit_ids = [];
try {
    $_au = db_all(
        "SELECT unit_id FROM org_members WHERE user_id=? AND status='active'",
        [$uid]
    );
    $_actor_unit_ids = array_column($_au, 'unit_id');
} catch (\Throwable $e) {}

// Osoby z tych samych jednostek (bez siebie)
$_unit_members = [];
if ($_actor_is_sys_admin) {
    // Admin widzi wszystkich aktywnych
    try {
        $_unit_members = db_all(
            "SELECT u.id, u.name,
                    COALESCE(ou.name,'') AS unit_name,
                    COALESCE(om.position_name,'') AS position_name
             FROM users u
             LEFT JOIN org_members om ON om.user_id=u.id AND om.status='active' AND om.is_primary=1
             LEFT JOIN org_units ou ON ou.id=om.unit_id
             WHERE u.is_active=1 AND u.id != ?
             ORDER BY ou.name, u.name",
            [$uid]
        );
    } catch (\Throwable $e) {}
} elseif ($_actor_unit_ids) {
    try {
        $ph = implode(',', array_fill(0, count($_actor_unit_ids), '?'));
        $_unit_members = db_all(
            "SELECT DISTINCT u.id, u.name,
                    ou.name AS unit_name,
                    COALESCE(om2.position_name,'') AS position_name
             FROM org_members om
             JOIN users u ON u.id=om.user_id AND u.is_active=1 AND u.id != ?
             JOIN org_units ou ON ou.id=om.unit_id
             LEFT JOIN org_members om2 ON om2.user_id=u.id AND om2.unit_id=om.unit_id AND om2.status='active'
             WHERE om.unit_id IN ({$ph}) AND om.status='active'
             ORDER BY ou.name, u.name",
            array_merge([$uid], $_actor_unit_ids)
        );
    } catch (\Throwable $e) {}
}

// Pogrupuj wg jednostki
$_members_by_unit = [];
foreach ($_unit_members as $m) {
    $_members_by_unit[$m['unit_name'] ?: 'Bez jednostki'][] = $m;
}

$cur_user     = current_user();
$has_ms       = !empty($cur_user['microsoft_id']);
[, $ms_app_id] = _ms_creds();
$ms_available  = $ms_app_id !== '';

function td_render_mentions(string $text, array $users): string {
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (empty($users)) return $html;
    $names = array_map(fn($u) => $u['name'], $users);
    usort($names, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
    foreach ($names as $name) {
        if (!$name) continue;
        $esc = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace('@' . $esc,
            '<mark class="td-mention">@' . $esc . '</mark>', $html);
    }
    return $html;
}
?>
<style>
/* ══════════════════════════════════════════════════════════════
   Szczegóły zadania — offcanvas
   Zasady: żadnych inline width/margin; siatka przez CSS Grid/Flex
   ══════════════════════════════════════════════════════════════ */

/* ── Tokeny ─────────────────────────────────────────────────── */
#td-root {
  --c-border : #e2e8f0;
  --c-soft   : #f8fafc;
  --c-accent : #2563eb;
  --c-text   : #0f172a;
  --c-muted  : #64748b;
  --c-radius : .5rem;
  --c-section: .85rem 1rem;
  font-size: .875rem;
  color: var(--c-text);
  line-height: 1.55;
}

/* ── Reset offcanvas body padding ───────────────────────────── */
.offcanvas-body { padding: 0 !important; }

/* ── Sekcje ─────────────────────────────────────────────────── */
.td-section {
  padding: var(--c-section);
  border-bottom: 1px solid var(--c-border);
}
.td-section:last-child { border-bottom: none; }

.td-label {
  display: flex; align-items: center; gap: .35rem;
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .09em;
  color: var(--c-muted); margin-bottom: .5rem;
}
.td-label i { font-size: .72rem; }

/* ── Nagłówek ───────────────────────────────────────────────── */
.td-header {
  padding: .9rem 1rem .7rem;
  border-bottom: 2px solid var(--c-border);
  background: #fff;
}

.td-title-input {
  display: block; width: 100%;
  font-size: 1rem; font-weight: 700; line-height: 1.4;
  border: none; padding: .15rem .25rem;
  box-shadow: none !important; background: transparent;
  color: var(--c-text); border-radius: .3rem;
}
.td-title-input:hover { background: var(--c-soft); }
.td-title-input:focus { outline: 2px solid var(--c-accent); outline-offset: 0; background: #fff; }

.td-title-static {
  font-size: 1rem; font-weight: 700; line-height: 1.4;
  color: var(--c-text); padding: .15rem 0;
}

/* Status badges pod tytułem */
.td-status-row {
  display: flex; flex-wrap: wrap; gap: .35rem;
  align-items: center; margin: .5rem 0 .4rem;
}

/* ── Belka akcji ─────────────────────────────────────────────── */
.td-action-bar {
  display: flex; flex-wrap: wrap; align-items: center;
  gap: .25rem;
  padding-top: .5rem;
  border-top: 1px solid var(--c-border);
}

/* Przycisk akcji — jeden spójny komponent */
.td-ab-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .7rem;
  border-radius: .4rem; border: 1.5px solid #e2e8f0;
  font-size: .78rem; font-weight: 600;
  background: #fff; color: #374151;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background .1s, border-color .1s, color .1s;
  line-height: 1.3;
}
.td-ab-btn i { font-size: .8rem; flex-shrink: 0; }
.td-ab-btn:focus-visible { outline: 2px solid var(--c-accent); outline-offset: 2px; }
.td-ab-btn:hover        { background: #f9fafb; border-color: #94a3b8; color: #0f172a; }

/* Warianty */
.td-ab-primary { background: #16a34a; color: #fff; border-color: #16a34a; }
.td-ab-primary:hover { background: #15803d; border-color: #15803d; color: #fff; }

.td-ab-danger { color: #dc2626; border-color: #fecaca; }
.td-ab-danger:hover { background: #fef2f2; border-color: #dc2626; }

/* Separator pionowy */
.td-ab-sep { width: 1px; height: 1.3rem; background: var(--c-border); flex-shrink: 0; margin: 0 .1rem; }

/* ── Siatka właściwości ─────────────────────────────────────── */
.td-props-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .55rem;
}

/* ── Przypisani ─────────────────────────────────────────────── */
.td-users { display: flex; flex-wrap: wrap; gap: .35rem; }
.td-user-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .18rem .6rem; border-radius: 2rem;
  font-size: .77rem; font-weight: 500;
  border: 1.5px solid #e2e8f0; background: var(--c-soft); color: var(--c-muted);
  cursor: pointer; transition: all .12s;
}
.td-user-btn.active { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
.td-user-btn:hover  { border-color: #93c5fd; color: #1d4ed8; }
.td-user-btn:focus-visible { outline: 2px solid var(--c-accent); outline-offset: 2px; }
.td-user-btn:disabled { opacity: .55; cursor: not-allowed; }

/* ── Tagi ────────────────────────────────────────────────────── */
.td-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.td-tag-btn {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: .16rem .5rem; border-radius: 2rem;
  font-size: .73rem; font-weight: 700;
  border: 1.5px solid transparent;
  cursor: pointer; transition: opacity .12s;
}
.td-tag-btn:focus-visible { outline: 2px solid var(--c-accent); outline-offset: 2px; }
.td-tag-btn:disabled { opacity: .55; cursor: not-allowed; }

/* ── Podzadania ─────────────────────────────────────────────── */
.td-st-row {
  display: flex; align-items: center; gap: .45rem;
  padding: .38rem .55rem;
  border-radius: .4rem; border: 1px solid var(--c-border);
  background: var(--c-soft); margin-bottom: .28rem;
  transition: background .1s;
}
.td-st-row:hover { background: #f1f5f9; }
.td-st-done .td-st-title { opacity: .6; text-decoration: line-through; color: var(--c-muted); }

/* ── Pasek postępu ──────────────────────────────────────────── */
.td-progress-wrap { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; }
.td-progress-track {
  flex: 1; height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden;
}
.td-progress-fill { height: 100%; border-radius: 3px; transition: width .25s; }
.td-pct-label { font-size: .69rem; color: var(--c-muted); min-width: 2.6rem; text-align: right; }

/* ── Komentarze ─────────────────────────────────────────────── */
.td-comment {
  background: var(--c-soft); border: 1px solid var(--c-border);
  border-radius: var(--c-radius); padding: .55rem .75rem; margin-bottom: .45rem;
}
.td-comment-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: .25rem; }
.td-comment-author { font-weight: 700; font-size: .79rem; }
.td-comment-date   { font-size: .69rem; color: var(--c-muted); }
.td-comment-body   { font-size: .84rem; white-space: pre-wrap; word-break: break-word; line-height: 1.55; }

/* ── Pliki ──────────────────────────────────────────────────── */
.td-file-row {
  display: flex; align-items: center; gap: .5rem;
  padding: .38rem .55rem;
  border-radius: .4rem; border: 1px solid var(--c-border);
  background: var(--c-soft); margin-bottom: .28rem;
}
.td-file-name {
  flex-grow: 1; min-width: 0;
  background: none; border: none; padding: 0;
  text-align: left; color: var(--c-link, #2563eb);
  text-decoration: none; cursor: pointer;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  font-size: .82rem;
}
.td-file-name:hover { text-decoration: underline; }
.td-file-dl {
  flex-shrink: 0; color: var(--c-muted);
  display: inline-flex; align-items: center;
  text-decoration: none; font-size: .9rem;
}
.td-file-dl:hover { color: var(--c-link, #2563eb); }

/* ── Podgląd plików (lightbox) ──────────────────────────────── */
.td-preview-overlay {
  position: fixed; inset: 0; z-index: 2000;
  background: rgba(15,23,42,.82);
  display: flex; flex-direction: column;
  padding: clamp(.5rem, 2vw, 2rem);
}
.td-preview-bar {
  display: flex; align-items: center; gap: .75rem;
  color: #fff; margin-bottom: .6rem; flex-shrink: 0;
}
.td-preview-title {
  flex-grow: 1; min-width: 0;
  font-size: .9rem; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.td-preview-bar a, .td-preview-bar button {
  flex-shrink: 0; color: #fff; background: rgba(255,255,255,.14);
  border: 1px solid rgba(255,255,255,.3); border-radius: .4rem;
  padding: .3rem .7rem; font-size: .8rem; cursor: pointer;
  text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
}
.td-preview-bar a:hover, .td-preview-bar button:hover { background: rgba(255,255,255,.28); color: #fff; }
.td-preview-body {
  flex-grow: 1; min-height: 0;
  display: flex; align-items: center; justify-content: center;
  overflow: auto; border-radius: .5rem; background: #fff;
}
.td-preview-body img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.td-preview-body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
.td-preview-body pre {
  width: 100%; height: 100%; margin: 0; padding: 1rem;
  overflow: auto; font-size: .8rem; line-height: 1.5;
  white-space: pre-wrap; word-break: break-word; color: #0f172a;
}
.td-preview-fallback { text-align: center; color: var(--c-muted); padding: 2rem; }
.td-preview-fallback .bi { font-size: 3rem; display: block; margin-bottom: .75rem; color: #94a3b8; }

/* ── Historia ───────────────────────────────────────────────── */
.td-history-item {
  display: flex; gap: .55rem; align-items: flex-start;
  padding: .4rem 0; border-bottom: 1px solid #f1f5f9;
  font-size: .77rem; color: var(--c-muted);
}
.td-history-item:last-child { border-bottom: none; }
.td-hi-icon {
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; flex-shrink: 0; margin-top: .1rem;
}
.td-hi-badge {
  display: inline-flex; align-items: center; gap: .2rem;
  font-size: .68rem; font-weight: 700;
  padding: .08rem .42rem; border-radius: 2rem; white-space: nowrap;
}
.td-hi-from {
  display: inline-block; background: #fef2f2; color: #dc2626;
  border-radius: .2rem; padding: 0 .3rem;
  font-size: .71rem; text-decoration: line-through;
}
.td-hi-to {
  display: inline-block; background: #f0fdf4; color: #16a34a;
  border-radius: .2rem; padding: 0 .3rem;
  font-size: .71rem; font-weight: 600;
}
.td-hi-val {
  display: inline-block; background: #f1f5f9; color: #475569;
  border-radius: .2rem; padding: 0 .3rem; font-size: .71rem;
  max-width: 160px; vertical-align: middle;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.td-hi-meta { color: #94a3b8; font-size: .69rem; margin-top: .1rem; }

/* ── Wzmianki ───────────────────────────────────────────────── */
.td-mention { background:#dbeafe;color:#1e40af;border-radius:3px;padding:0 3px;font-weight:500; }
.td-mention-dd {
  position:fixed;background:#fff;border:1px solid #d1d5db;border-radius:.5rem;
  box-shadow:0 6px 18px rgba(0,0,0,.15);z-index:10050;
  min-width:180px;max-height:200px;overflow-y:auto;display:none;padding:.25rem 0;
}
.td-mention-dd .mi-item {
  padding:.3rem .65rem;cursor:pointer;font-size:.82rem;
  display:flex;align-items:center;gap:.5rem;
}
.td-mention-dd .mi-item:hover,
.td-mention-dd .mi-item.mi-active { background:#eff6ff;color:#1e40af; }
.td-mention-av {
  display:inline-flex;align-items:center;justify-content:center;
  width:22px;height:22px;border-radius:50%;background:var(--c-accent);
  color:#fff;font-size:.62rem;font-weight:700;flex-shrink:0;
}

/* ── Picker Przekaż ─────────────────────────────────────────── */
.td-takeover-wrap { position: relative; }
.td-takeover-panel {
  display: none; position: absolute;
  top: calc(100% + 5px); left: 0;
  width: 290px; max-width: calc(100vw - 2rem);
  background: #fff; border: 1.5px solid #e2e8f0;
  border-radius: .6rem; box-shadow: 0 8px 24px rgba(0,0,0,.13);
  z-index: 9000; overflow: hidden;
}
.td-takeover-panel.open { display: block; }
.td-tp-head {
  padding: .6rem .8rem .45rem;
  border-bottom: 1px solid #f1f5f9; background: var(--c-soft);
}
.td-tp-title {
  font-size: .7rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: #64748b; margin: 0 0 .3rem;
}
.td-tp-search {
  width: 100%; font-size: .82rem;
  border: 1px solid #e2e8f0; border-radius: .35rem;
  padding: .28rem .5rem; background: #fff;
}
.td-tp-search:focus { outline: 2px solid var(--c-accent); border-color: transparent; }
.td-tp-list { max-height: 220px; overflow-y: auto; }
.td-tp-group {
  padding: .28rem .8rem .1rem;
  font-size: .66rem; font-weight: 700;
  text-transform: uppercase; color: #94a3b8;
}
.td-tp-person {
  display: flex; align-items: center; gap: .55rem;
  padding: .42rem .8rem; cursor: pointer;
  border: none; background: none; width: 100%; text-align: left;
  transition: background .1s;
}
.td-tp-person:hover,.td-tp-person:focus-visible { background: #eff6ff; }
.td-tp-person:focus-visible { outline: 2px solid var(--c-accent); outline-offset: -2px; }
.td-tp-av {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--c-accent); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .6rem; font-weight: 700; flex-shrink: 0;
}
.td-tp-info { flex: 1; min-width: 0; text-align: left; }
.td-tp-name { font-size: .83rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-tp-pos  { font-size: .7rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-tp-msg { padding: .45rem .8rem; border-top: 1px solid #f1f5f9; }
.td-tp-msg textarea {
  width: 100%; font-size: .8rem;
  border: 1px solid #e2e8f0; border-radius: .35rem;
  padding: .3rem .5rem; resize: none;
}
.td-tp-msg textarea:focus { outline: 2px solid var(--c-accent); border-color: transparent; }
.td-tp-empty { text-align: center; padding: 1rem; color: #94a3b8; font-size: .82rem; }
.td-tp-selected {
  background: #eff6ff; border-top: 1px solid #dbeafe;
  padding: .42rem .8rem;
  display: none; align-items: center; gap: .45rem;
  font-size: .81rem; color: #1d4ed8;
}
.td-tp-selected.show { display: flex; }
</style>

<div id="td-root" data-task-id="<?= $id ?>">

<!-- ══ NAGŁÓWEK ════════════════════════════════════════════════════════════ -->
<div class="td-header">

  <!-- Tytuł -->
  <div class="mb-2">
    <?php if ($can_edit): ?>
    <input type="text"
           id="td-title"
           class="td-title-input form-control"
           value="<?= h($task['title']) ?>"
           aria-label="Tytuł zadania"
           onblur="tdPatch({title:this.value.trim()||<?= json_encode($task['title']) ?>})"
           onkeydown="if(event.key==='Enter')this.blur()">
    <?php else: ?>
    <div class="td-title-static"><?= h($task['title']) ?></div>
    <?php endif; ?>
  </div>

  <!-- Statusy inline -->
  <div class="td-status-row mb-2" aria-label="Status zadania">
    <?= task_priority_badge((int)$task['priority']) ?>
    <?php if ($is_done): ?>
    <span class="badge bg-success">
      <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Ukończone<?= $task['completed_at'] ? ' ' . substr($task['completed_at'],0,10) : '' ?>
    </span>
    <?php endif; ?>
    <?php if ($overdue): ?>
    <span class="badge bg-danger">
      <i class="bi bi-alarm me-1" aria-hidden="true"></i>Po terminie
    </span>
    <?php endif; ?>
    <span class="badge bg-light text-secondary border">
      <i class="bi bi-columns-gap me-1" aria-hidden="true"></i><?= h($task['list_name']) ?>
    </span>
  </div>

  <!-- ── Belka przycisków ──────────────────────────────────────────────── -->
  <div class="td-action-bar" role="toolbar" aria-label="Akcje zadania">

    <!-- Zakończ / Wznów — główna akcja -->
    <?php if ($can_edit): ?>
    <?php if (!$is_done): ?>
    <button type="button"
            class="td-ab-btn td-ab-primary"
            id="td-btn-done"
            onclick="tdMarkDone()"
            aria-label="Oznacz zadanie jako ukończone">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>
      <span>Zakończ</span>
    </button>
    <?php else: ?>
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            onclick="tdReopen()"
            aria-label="Wznów zadanie">
      <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
      <span>Wznów</span>
    </button>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Przekaż zadanie — lider/admin + komórka -->
    <?php if ($can_edit && !$is_done && $_unit_members): ?>
    <div class="td-takeover-wrap" id="td-takeover-wrap">
      <button type="button"
              class="td-ab-btn td-ab-ghost"
              id="td-takeover-btn"
              onclick="tdToggleTakeover()"
              aria-expanded="false"
              aria-controls="td-takeover-panel"
              aria-haspopup="true"
              aria-label="Przekaż zadanie osobie z komórki organizacyjnej">
        <i class="bi bi-person-up" aria-hidden="true"></i>
        <span>Przekaż</span>
      </button>

      <!-- Panel wyboru osoby -->
      <div id="td-takeover-panel"
           class="td-takeover-panel"
           role="dialog"
           aria-modal="false"
           aria-label="Wybierz osobę do przekazania zadania">
        <div class="td-tp-head">
          <p class="td-tp-title">Przekaż zadanie</p>
          <label class="visually-hidden" for="td-tp-search">Szukaj osoby</label>
          <input type="search" id="td-tp-search" class="td-tp-search"
                 placeholder="Szukaj po imieniu…" autocomplete="off"
                 oninput="tdTpFilter(this.value)">
        </div>
        <div class="td-tp-selected" id="td-tp-selected">
          <span id="td-tp-sel-av" class="td-tp-av" aria-hidden="true"></span>
          <span id="td-tp-sel-name" style="flex:1;font-weight:600"></span>
          <button type="button" class="btn-close" style="font-size:.55rem"
                  onclick="tdTpClearSelection()" aria-label="Anuluj wybór"></button>
        </div>
        <div class="td-tp-list" id="td-tp-list" role="listbox" aria-label="Osoby z komórki">
          <?php foreach ($_members_by_unit as $unit_name => $members): ?>
          <div class="td-tp-group" role="presentation"><?= h($unit_name) ?></div>
          <?php foreach ($members as $m):
            $initials = '';
            foreach (preg_split('/\s+/', trim($m['name'])) as $w) $initials .= mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8');
            $initials = mb_substr($initials,0,2,'UTF-8') ?: '?';
            $colors   = ['#2563eb','#7c3aed','#059669','#dc2626','#d97706','#0891b2'];
            $bg       = $colors[crc32($m['name']) % count($colors)];
          ?>
          <button type="button" class="td-tp-person" role="option"
                  data-uid="<?= $m['id'] ?>" data-name="<?= h($m['name']) ?>"
                  data-initials="<?= h($initials) ?>" data-bg="<?= h($bg) ?>"
                  data-search="<?= h(mb_strtolower($m['name'],'UTF-8')) ?>"
                  onclick="tdTpSelect(this)"
                  aria-label="<?= h($m['name']) ?><?= $m['position_name'] ? ', '.h($m['position_name']) : '' ?>">
            <span class="td-tp-av" style="background:<?= h($bg) ?>" aria-hidden="true"><?= h($initials) ?></span>
            <span class="td-tp-info">
              <span class="td-tp-name"><?= h($m['name']) ?></span>
              <?php if ($m['position_name'] || $m['unit_name']): ?>
              <span class="td-tp-pos"><?= h($m['position_name'] ?: $m['unit_name']) ?></span>
              <?php endif; ?>
            </span>
          </button>
          <?php endforeach; endforeach; ?>
          <div id="td-tp-no-results" class="td-tp-empty" style="display:none">Nie znaleziono osoby.</div>
        </div>
        <div class="td-tp-msg" id="td-tp-send-wrap" style="display:none">
          <label class="visually-hidden" for="td-tp-msg-ta">Wiadomość (opcjonalnie)</label>
          <textarea id="td-tp-msg-ta" rows="2"
                    placeholder="Dodaj wiadomość (opcjonalnie)…"
                    maxlength="500"></textarea>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-primary btn-sm flex-grow-1"
                    id="td-tp-send-btn" onclick="tdTpSend()">
              <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="tdTpClearSelection()">Anuluj</button>
          </div>
          <div id="td-tp-ok"  class="alert alert-success  small py-1 mt-2 mb-0 d-none" role="status"></div>
          <div id="td-tp-err" class="alert alert-danger   small py-1 mt-2 mb-0 d-none" role="alert"></div>
        </div>
      </div><!-- /td-takeover-panel -->
    </div>
    <?php endif; ?>

    <!-- Duplikuj -->
    <?php if ($can_edit): ?>
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            onclick="tdDuplicate()"
            aria-label="Duplikuj to zadanie">
      <i class="bi bi-copy" aria-hidden="true"></i>
      <span>Duplikuj</span>
    </button>
    <?php endif; ?>

    <!-- Nowy obszar ze struktury — tylko lider/admin -->
    <?php if ($_actor_is_sys_admin || $_tsk_is_leader_here): ?>
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            onclick="tdOpenNewWsModal()"
            aria-label="Utwórz nowy obszar roboczy na podstawie tego zadania"
            aria-haspopup="dialog">
      <i class="bi bi-grid-plus" aria-hidden="true"></i>
      <span>Nowy obszar</span>
    </button>
    <?php endif; ?>

    <!-- Powiadom lidera — dostępne dla wszystkich -->
    <button type="button"
            class="td-ab-btn td-ab-ghost"
            id="td-notify-open-btn"
            onclick="tdOpenNotifyModal()"
            aria-haspopup="dialog"
            aria-label="Zgłoś problem liderowi obszaru">
      <i class="bi bi-megaphone" aria-hidden="true"></i>
      <span>Problem</span>
    </button>

    <!-- Separator -->
    <div class="td-ab-sep" aria-hidden="true"></div>

    <!-- Usuń — tylko lider/admin -->
    <?php if ($can_edit): ?>
    <button type="button"
            class="td-ab-btn td-ab-danger"
            onclick="tdDelete()"
            aria-label="Usuń to zadanie bezpowrotnie">
      <i class="bi bi-trash" aria-hidden="true"></i>
      <span>Usuń</span>
    </button>
    <?php endif; ?>

  </div><!-- /td-action-bar -->

</div>

<!-- ══ WŁAŚCIWOŚCI ══════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-sliders" aria-hidden="true"></i>Właściwości
  </div>

  <?php if ($can_edit): ?>
  <div class="td-props-grid">
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-due">
        <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Termin
      </label>
      <input type="date" id="td-due" class="form-control form-control-sm"
             value="<?= h($task['due_date'] ?? '') ?>"
             onchange="tdPatch({due_date:this.value||null})"
             aria-label="Data terminu zadania">
    </div>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-start">
        <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Start
      </label>
      <input type="date" id="td-start" class="form-control form-control-sm"
             value="<?= h($task['start_date'] ?? '') ?>"
             onchange="tdPatch({start_date:this.value||null})"
             aria-label="Data rozpoczęcia zadania">
    </div>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-priority">
        <i class="bi bi-flag me-1" aria-hidden="true"></i>Priorytet
      </label>
      <select id="td-priority" class="form-select form-select-sm"
              onchange="tdPatch({priority:parseInt(this.value)})"
              aria-label="Priorytet zadania">
        <option value="1" <?= $task['priority']==1?'selected':'' ?>>Niski</option>
        <option value="2" <?= $task['priority']==2?'selected':'' ?>>Normalny</option>
        <option value="3" <?= $task['priority']==3?'selected':'' ?>>Wysoki</option>
        <option value="4" <?= $task['priority']==4?'selected':'' ?>>Krytyczny</option>
      </select>
    </div>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-list">
        <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Kolumna
      </label>
      <select id="td-list" class="form-select form-select-sm"
              onchange="tdMoveToList(parseInt(this.value))"
              aria-label="Przenieś zadanie do kolumny">
        <?php foreach ($lists_in_ws as $l): ?>
        <option value="<?= $l['id'] ?>" <?= $l['id']==$task['list_id']?'selected':'' ?>><?= h($l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
      $_all_units_det = [];
      try { $_all_units_det = db_all("SELECT id, name, short_name FROM org_units WHERE status='active' ORDER BY name"); }
      catch (\Throwable $e) {}
    ?>
    <?php if ($_all_units_det): ?>
    <div>
      <label class="form-label small fw-semibold text-muted mb-1" for="td-unit">
        <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Jednostka org
      </label>
      <select id="td-unit" class="form-select form-select-sm"
              onchange="tdSetUnit(this)"
              data-prev="<?= (int)($task['unit_id'] ?? 0) ?>"
              aria-label="Przypisz zadanie do jednostki organizacyjnej">
        <option value="0">— brak —</option>
        <?php foreach ($_all_units_det as $ou): ?>
        <option value="<?= $ou['id'] ?>" <?= (int)($task['unit_id']??0)==$ou['id']?'selected':'' ?>>
          <?= h($ou['name']) ?><?= $ou['short_name'] ? ' (' . h($ou['short_name']) . ')' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <dl class="row g-1 small text-muted mb-0">
    <?php if ($task['due_date']): ?>
    <dt class="col-auto"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Termin:</dt>
    <dd class="col mb-0"><?= date_pl($task['due_date']) ?></dd>
    <?php endif; ?>
    <dt class="col-auto"><i class="bi bi-columns-gap me-1" aria-hidden="true"></i>Kolumna:</dt>
    <dd class="col mb-0"><?= h($task['list_name']) ?></dd>
    <?php if (!empty($task['unit_id'])): ?>
    <dt class="col-auto"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Jednostka:</dt>
    <dd class="col mb-0"><?= task_unit_badge((int)$task['unit_id']) ?></dd>
    <?php endif; ?>
  </dl>
  <?php endif; ?>
</div>

<!-- ══ PRZYPISANI ══════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-people" aria-hidden="true"></i>Przypisani
  </div>
  <div class="td-users" id="td-users" role="group" aria-label="Przypisani użytkownicy">
    <?php foreach ($all_users as $u):
      $on = in_array($u['id'], $assign_ids, true);
    ?>
    <button type="button"
            class="td-user-btn <?= $on ? 'active' : '' ?>"
            data-user-id="<?= $u['id'] ?>"
            data-active="<?= $on ? 1 : 0 ?>"
            aria-pressed="<?= $on ? 'true' : 'false' ?>"
            <?= $can_edit ? 'onclick="tdToggleUser(' . (int)$u['id'] . ', this)"' : 'disabled aria-disabled="true"' ?>>
      <?= task_avatar_initials($u['name'], $on ? '#2563eb' : '#e2e8f0', $on ? '#fff' : '#64748b') ?>
      <span><?= h($u['name']) ?></span>
      <?php if ($on): ?>
      <i class="bi bi-check2 text-primary" style="font-size:.65rem" aria-hidden="true"></i>
      <?php endif; ?>
    </button>
    <?php endforeach; ?>
    <?php if (!$all_users): ?>
    <p class="text-muted small mb-0">Brak użytkowników w systemie.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ══ TAGI ════════════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-tags" aria-hidden="true"></i>Tagi
  </div>
  <div class="td-tags" id="td-tags" role="group" aria-label="Tagi zadania">
    <?php foreach ($avail_tags as $tag):
      $on = in_array($tag['id'], $tag_ids, true);
    ?>
    <button type="button"
            class="td-tag-btn"
            style="background:<?= $on ? h($tag['color']) : '#f1f5f9' ?>;
                   color:<?= $on ? h($tag['text_color']) : '#64748b' ?>;
                   border-color:<?= $on ? h($tag['color']) : '#e2e8f0' ?>"
            data-tag-id="<?= $tag['id'] ?>"
            data-active="<?= $on ? 1 : 0 ?>"
            aria-pressed="<?= $on ? 'true' : 'false' ?>"
            <?= $can_edit ? 'onclick="tdToggleTag(' . (int)$tag['id'] . ', this)"' : 'disabled aria-disabled="true"' ?>>
      <?php if ($on): ?><i class="bi bi-check2" style="font-size:.65rem" aria-hidden="true"></i><?php endif; ?>
      <?= h($tag['name']) ?>
    </button>
    <?php endforeach; ?>
    <?php if (!$avail_tags): ?>
    <p class="text-muted small mb-0">
      Brak tagów<?php if (is_admin()): ?> — <a href="<?= APP_URL ?>/admin/tasks_tags.php" target="_blank">dodaj</a><?php endif; ?>.
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- ══ OPIS ════════════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-text-left" aria-hidden="true"></i>Opis
  </div>
  <?php if ($can_edit): ?>
  <label class="visually-hidden" for="td-desc">Opis zadania</label>
  <textarea id="td-desc"
            class="form-control form-control-sm"
            rows="4"
            placeholder="Dodaj opis zadania…"
            onblur="tdPatch({description:this.value})"><?= h($task['description'] ?? '') ?></textarea>
  <?php else: ?>
  <div class="small" style="white-space:pre-wrap;color:#374151;line-height:1.6">
    <?= $task['description'] ? nl2br(h($task['description'])) : '<em class="text-muted">Brak opisu.</em>' ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ PODZADANIA ══════════════════════════════════════════════════════════ -->
<div class="td-section" id="td-subtasks-section">

  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="td-label mb-0">
      <i class="bi bi-check2-square" aria-hidden="true"></i>Podzadania
      <span id="td-st-counter" class="fw-normal ms-1" aria-live="polite">
        <?= $st_total ? "($st_done/$st_total)" : '' ?>
      </span>
    </div>
  </div>

  <!-- Pasek postępu -->
  <div class="td-progress-wrap <?= !$st_total ? 'd-none' : '' ?>"
       id="td-st-progress-wrap"
       aria-hidden="<?= $st_total ? 'false' : 'true' ?>">
    <div class="td-progress-track"
         role="progressbar"
         aria-valuenow="<?= $st_pct ?>"
         aria-valuemin="0" aria-valuemax="100"
         aria-label="Postęp podzadań">
      <div id="td-st-progress"
           class="td-progress-fill <?= $st_done===$st_total && $st_total>0 ? 'bg-success' : 'bg-primary' ?>"
           style="width:<?= $st_pct ?>%"></div>
    </div>
    <span id="td-st-pct" class="td-pct-label"><?= $st_total ? $st_pct . '%' : '' ?></span>
  </div>

  <!-- Lista podzadań -->
  <div id="td-st-list" role="list" aria-label="Lista podzadań">
    <?php foreach ($subtasks as $st): ?>
    <div class="td-st-row <?= $st['is_done'] ? 'td-st-done' : '' ?>"
         id="strow-<?= $st['id'] ?>"
         role="listitem">
      <?php if ($can_edit): ?>
      <input type="checkbox"
             class="form-check-input flex-shrink-0 mt-0"
             <?= $st['is_done'] ? 'checked' : '' ?>
             onchange="tdStToggle(<?= $st['id'] ?>, this)"
             style="cursor:pointer;width:15px;height:15px"
             aria-label="<?= h($st['title']) ?>">
      <?php else: ?>
      <i class="bi bi-<?= $st['is_done'] ? 'check-square-fill text-success' : 'square text-muted' ?> flex-shrink-0"
         aria-hidden="true"></i>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <span class="flex-grow-1 small td-st-title"
            style="line-height:1.4;cursor:text"
            role="button" tabindex="0"
            aria-label="Edytuj podzadanie: <?= h($st['title']) ?>"
            onclick="tdStStartEdit(this,<?= (int)$st['id'] ?>)"
            onkeydown="if(event.key==='Enter'){event.preventDefault();tdStStartEdit(this,<?= (int)$st['id'] ?>)}">
        <?= h($st['title']) ?>
      </span>
      <?php else: ?>
      <span class="flex-grow-1 small td-st-title" style="line-height:1.4"><?= h($st['title']) ?></span>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <button type="button"
              class="btn-close flex-shrink-0"
              onclick="tdStDelete(<?= (int)$st['id'] ?>)"
              aria-label="Usuń podzadanie: <?= h($st['title']) ?>"
              style="font-size:.5rem;opacity:.5"></button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$subtasks): ?>
    <p class="text-muted small mb-1" id="td-st-empty">Brak podzadań.</p>
    <?php endif; ?>
  </div>

  <?php if ($can_edit): ?>
  <div class="d-flex gap-2 mt-2" id="td-st-add-row">
    <label class="visually-hidden" for="td-st-input">Nowe podzadanie</label>
    <input type="text"
           id="td-st-input"
           class="form-control form-control-sm"
           placeholder="Nowe podzadanie… (Enter aby dodać)"
           onkeydown="if(event.key==='Enter'){event.preventDefault();tdStAdd()}">
    <button type="button"
            class="btn btn-sm btn-outline-secondary px-2"
            onclick="tdStAdd()"
            aria-label="Dodaj podzadanie">
      <i class="bi bi-plus-lg" aria-hidden="true"></i>
    </button>
  </div>
  <?php endif; ?>

</div>

<!-- ══ CZAS PRACY ══════════════════════════════════════════════════════════ -->
<div class="td-section" id="td-time-section">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="td-label mb-0">
      <i class="bi bi-stopwatch" aria-hidden="true"></i>Czas pracy
      <span id="td-time-total" class="ms-1 fw-normal text-muted" style="font-size:.75rem"></span>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <!-- Szacowany czas -->
      <?php if ($can_edit): ?>
      <div class="d-flex align-items-center gap-1">
        <label for="td-est-hours" class="text-muted" style="font-size:.72rem;white-space:nowrap">
          Szacunek:
        </label>
        <input type="number" id="td-est-hours"
               class="form-control form-control-sm"
               style="width:70px;font-size:.78rem"
               min="0" max="999" step="0.5"
               value="<?= h($task['estimated_hours'] ?? '') ?>"
               placeholder="godz."
               aria-label="Szacowana liczba godzin"
               onblur="tdPatch({estimated_hours:parseFloat(this.value)||null})">
      </div>
      <?php elseif ($task['estimated_hours']): ?>
      <span class="text-muted" style="font-size:.76rem">
        Szacunek: <?= h($task['estimated_hours']) ?>h
      </span>
      <?php endif; ?>

      <!-- Timer start/stop -->
      <button type="button"
              class="btn btn-sm"
              id="td-timer-btn"
              onclick="tdTimerToggle()"
              aria-label="Uruchom lub zatrzymaj timer czasu pracy"
              style="font-size:.74rem;white-space:nowrap">
        <i class="bi bi-play-fill me-1" id="td-timer-icon" aria-hidden="true"></i>
        <span id="td-timer-label">Start</span>
      </button>
    </div>
  </div>

  <!-- Aktywny timer display -->
  <div id="td-timer-running" class="d-none mb-2 p-2 rounded"
       style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:.8rem;
              display:none!important;align-items:center;gap:.5rem">
    <span class="spinner-grow spinner-grow-sm text-success flex-shrink-0" aria-hidden="true"></span>
    <span>Timer aktywny: <strong id="td-timer-elapsed">00:00</strong></span>
    <input type="text" id="td-timer-note"
           class="form-control form-control-sm ms-auto"
           style="max-width:160px;font-size:.76rem"
           placeholder="Notatka (opcjonalnie)"
           aria-label="Notatka do wpisu czasu">
  </div>

  <!-- Log wpisów -->
  <div id="td-time-log" class="td-time-log" role="list" aria-label="Wpisy czasu pracy">
    <!-- ładowane przez JS -->
    <div class="text-muted text-center py-2" style="font-size:.8rem" id="td-time-loading">
      <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Ładowanie…
    </div>
  </div>

  <!-- Ręczny wpis -->
  <?php if ($can_edit): ?>
  <details class="mt-2" id="td-manual-time">
    <summary class="text-muted" style="font-size:.76rem;cursor:pointer">
      <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj ręczny wpis
    </summary>
    <div class="row g-2 mt-1">
      <div class="col-5">
        <label class="visually-hidden" for="td-mt-start">Początek</label>
        <input type="datetime-local" id="td-mt-start" class="form-control form-control-sm"
               aria-label="Czas rozpoczęcia pracy">
      </div>
      <div class="col-5">
        <label class="visually-hidden" for="td-mt-end">Koniec</label>
        <input type="datetime-local" id="td-mt-end" class="form-control form-control-sm"
               aria-label="Czas zakończenia pracy">
      </div>
      <div class="col-12">
        <label class="visually-hidden" for="td-mt-note">Notatka</label>
        <input type="text" id="td-mt-note" class="form-control form-control-sm"
               placeholder="Notatka (opcjonalnie)" maxlength="200"
               aria-label="Notatka do ręcznego wpisu">
      </div>
      <div class="col-12">
        <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="tdManualTime()">
          <i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz wpis
        </button>
      </div>
    </div>
  </details>
  <?php endif; ?>
</div>

<!-- ══ POWTARZALNOŚĆ ══════════════════════════════════════════════════════ -->
<?php if ($can_edit): ?>
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Powtarzanie
  </div>
  <div class="row g-2 align-items-end">
    <div class="col-sm-6">
      <label class="form-label small fw-semibold text-muted mb-1" for="td-recurrence">
        Cykl powtarzania
      </label>
      <select id="td-recurrence" class="form-select form-select-sm"
              onchange="tdPatch({recurrence:this.value||null})"
              aria-label="Wybierz cykl powtarzania zadania">
        <option value="" <?= !$task['recurrence']?'selected':'' ?>>Jednorazowe (brak)</option>
        <option value="daily"   <?= $task['recurrence']==='daily'  ?'selected':'' ?>>Codziennie</option>
        <option value="weekly"  <?= $task['recurrence']==='weekly' ?'selected':'' ?>>Co tydzień</option>
        <option value="monthly" <?= $task['recurrence']==='monthly'?'selected':'' ?>>Co miesiąc</option>
        <option value="yearly"  <?= $task['recurrence']==='yearly' ?'selected':'' ?>>Co rok</option>
      </select>
    </div>
    <?php if ($task['recurrence']): ?>
    <div class="col-sm-6">
      <label class="form-label small fw-semibold text-muted mb-1" for="td-rec-end">
        Zakończ powtarzanie
        <span class="fw-normal">(opcjonalnie)</span>
      </label>
      <input type="date" id="td-rec-end" class="form-control form-control-sm"
             value="<?= h($task['recurrence_end_date'] ?? '') ?>"
             onchange="tdPatch({recurrence_end_date:this.value||null})"
             aria-label="Data zakończenia powtarzania">
    </div>
    <?php endif; ?>
  </div>
  <?php if ($task['recurrence']): ?>
  <p class="text-muted small mt-2 mb-0">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    Po ukończeniu zadania system automatycznie utworzy kolejną instancję
    z przesuniętym terminem.
    <?php if ($task['recurrence_parent_id']): ?>
    <br><span class="badge bg-secondary" style="font-size:.68rem">
      <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
      Kopia zadania #<?= (int)$task['recurrence_parent_id'] ?>
    </span>
    <?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ ZAŁĄCZNIKI ══════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-paperclip" aria-hidden="true"></i>Załączniki
    <?php if ($files): ?>
    <span class="badge bg-secondary ms-1" style="font-size:.6rem"><?= count($files) ?></span>
    <?php endif; ?>
  </div>

  <div id="td-files">
    <?php foreach ($files as $f):
      $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
      $icon = match (true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp','svg','bmp'], true) => 'image',
        $ext === 'pdf'                                                      => 'pdf',
        in_array($ext, ['xls','xlsx','csv'], true)                          => 'spreadsheet',
        in_array($ext, ['doc','docx'], true)                               => 'word',
        $ext === 'zip'                                                      => 'zip',
        default                                                             => 'text',
      };
      $file_url = APP_URL . '/uploads/tasks/' . $f['stored_name'];
    ?>
    <div class="td-file-row" id="file-<?= $f['id'] ?>">
      <i class="bi bi-file-earmark-<?= $icon ?> text-primary flex-shrink-0" aria-hidden="true"></i>
      <button type="button" class="td-file-name"
              onclick="tdPreviewFile(<?= htmlspecialchars(json_encode($file_url), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($ext), ENT_QUOTES) ?>)"
              aria-label="Podgląd: <?= h($f['original_name']) ?>">
        <?= h($f['original_name']) ?>
      </button>
      <span class="text-muted flex-shrink-0" style="font-size:.7rem"><?= round($f['file_size']/1024) ?> KB</span>
      <a href="<?= h($file_url) ?>" download
         class="td-file-dl"
         title="Pobierz" aria-label="Pobierz <?= h($f['original_name']) ?>">
        <i class="bi bi-download" aria-hidden="true"></i>
      </a>
      <?php if ($can_edit): ?>
      <button type="button"
              class="btn-close flex-shrink-0"
              onclick="tdDeleteFile(<?= $f['id'] ?>)"
              aria-label="Usuń plik <?= h($f['original_name']) ?>"
              style="font-size:.55rem"></button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($can_edit): ?>
  <div class="d-flex flex-wrap gap-2 mt-2">
    <label class="btn btn-sm btn-outline-secondary" style="cursor:pointer">
      <i class="bi bi-upload me-1" aria-hidden="true"></i>Z dysku
      <span class="text-muted fw-normal small">(max 10 MB)</span>
      <input type="file"
             id="td-file-input"
             class="visually-hidden"
             accept=".pdf,.jpg,.jpeg,.png,.gif,.docx,.doc,.xlsx,.xls,.pptx,.ppt,.zip,.txt,.csv"
             aria-label="Wybierz plik do uploadu">
    </label>

    <?php if ($ms_available && $has_ms): ?>
    <button type="button"
            class="btn btn-sm"
            style="background:#0078d4;color:#fff;border:none"
            onclick="tdOpenOneDrive()"
            aria-label="Wybierz plik z Microsoft OneDrive lub SharePoint">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="me-1" aria-hidden="true">
        <path d="M10.5 18.5H6a4.5 4.5 0 0 1-.95-8.9A6 6 0 0 1 16.7 7.6a4 4 0 0 1 2.8 6.9H10.5zm5-4.5-3.5-3.5-3.5 3.5h2.5v4h2v-4z"/>
      </svg>
      OneDrive / SharePoint
    </button>
    <?php elseif ($ms_available && !$has_ms): ?>
    <span class="btn btn-sm btn-outline-secondary disabled"
          aria-disabled="true"
          tabindex="-1"
          title="Zaloguj się przez Microsoft, aby importować z OneDrive">
      <i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>OneDrive
      <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">Wymagane konto MS</span>
    </span>
    <?php endif; ?>
  </div>

  <div id="td-upload-status" class="small text-muted mt-2 d-none" aria-live="polite">
    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
    <span id="td-upload-msg">Wysyłanie…</span>
  </div>
  <?php endif; ?>
</div>

<!-- ══ KOMENTARZE ══════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>Komentarze
    <?php if ($comments): ?>
    <span class="badge bg-secondary ms-1" style="font-size:.6rem"><?= count($comments) ?></span>
    <?php endif; ?>
  </div>

  <div id="td-comments" aria-live="polite" aria-label="Lista komentarzy">
    <?php foreach ($comments as $c): ?>
    <div class="td-comment" id="cmt-<?= $c['id'] ?>">
      <div class="td-comment-meta">
        <div>
          <span class="td-comment-author"><?= h($c['author_name']) ?></span>
          <span class="td-comment-date ms-2"><?= h(substr($c['created_at'],0,16)) ?></span>
        </div>
        <?php if ($can_edit || (int)$c['author_id'] === $uid): ?>
        <button type="button"
                class="btn-close"
                onclick="tdDeleteComment(<?= $c['id'] ?>)"
                aria-label="Usuń komentarz od <?= h($c['author_name']) ?>"
                style="font-size:.55rem"></button>
        <?php endif; ?>
      </div>
      <div class="td-comment-body"><?= td_render_mentions($c['body'], $all_users) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$comments): ?>
    <p class="text-muted small mb-2">Brak komentarzy.</p>
    <?php endif; ?>
  </div>

  <?php if ($can_edit): ?>
  <label class="visually-hidden" for="td-new-cmt">Nowy komentarz</label>
  <textarea id="td-new-cmt"
            class="form-control form-control-sm mt-1"
            rows="2"
            placeholder="Napisz komentarz… (Ctrl+Enter wysyła, @ dodaje wzmiankę)"
            onkeydown="tdCmtKeydown(event)"
            aria-describedby="td-cmt-hint"></textarea>
  <div id="td-mention-dd" class="td-mention-dd" role="listbox" aria-label="Sugestie wzmianek"></div>
  <p id="td-cmt-hint" class="visually-hidden">Użyj @ aby wspomnieć użytkownika. Ctrl+Enter aby wysłać.</p>
  <button type="button"
          class="btn btn-sm btn-primary mt-2"
          onclick="tdAddComment()">
    <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij komentarz
  </button>
  <?php endif; ?>
</div>

<!-- ══ HISTORIA ════════════════════════════════════════════════════════════ -->
<?php if ($history):

/**
 * Definicje typów zdarzeń:
 *   icon   — Bootstrap Icons klasa
 *   bg     — tło ikony
 *   color  — kolor ikony
 *   badge_bg / badge_color — kolorystyka etykiety badge
 *   label  — tekst etykiety
 *   desc   — funkcja generująca opis (opcjonalna)
 */
$ev_defs = [
  'created' => [
    'icon'        => 'bi-plus-lg',
    'bg'          => '#dcfce7', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Utworzono',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'moved' => [
    'icon'        => 'bi-arrow-right',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Przeniesiono',
    'desc'        => fn($e) => $e['from_value'] && $e['to_value']
      ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>'
        . ' <i class="bi bi-arrow-right" aria-hidden="true"></i> '
        . '<span class="td-hi-to">' . h($e['to_value']) . '</span>'
      : '',
  ],
  'assigned' => [
    'icon'        => 'bi-person-plus',
    'bg'          => '#ede9fe', 'color' => '#7c3aed',
    'badge_bg'    => '#f5f3ff', 'badge_color' => '#7c3aed',
    'label'       => 'Przypisano',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">👤 ' . h($e['to_value']) . '</span>' : '',
  ],
  'unassigned' => [
    'icon'        => 'bi-person-dash',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Odpięto',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">👤 ' . h($e['from_value']) . '</span>' : '',
  ],
  'completed' => [
    'icon'        => 'bi-check-circle-fill',
    'bg'          => '#dcfce7', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Ukończono',
    'desc'        => fn($e) => '',
  ],
  'reopened' => [
    'icon'        => 'bi-arrow-counterclockwise',
    'bg'          => '#fef9c3', 'color' => '#d97706',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#d97706',
    'label'       => 'Wznowiono',
    'desc'        => fn($e) => '',
  ],
  'tag_added' => [
    'icon'        => 'bi-tag-fill',
    'bg'          => '#f0fdf4', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Tag dodany',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-to">#' . h($e['to_value']) . '</span>' : '',
  ],
  'tag_removed' => [
    'icon'        => 'bi-tag',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Tag usunięty',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">#' . h($e['from_value']) . '</span>' : '',
  ],
  'comment_added' => [
    'icon'        => 'bi-chat-fill',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Komentarz',
    'desc'        => fn($e) => '',
  ],
  'priority_changed' => [
    'icon'        => 'bi-flag-fill',
    'bg'          => '#fef9c3', 'color' => '#d97706',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#d97706',
    'label'       => 'Priorytet',
    'desc'        => fn($e) => $e['from_value'] && $e['to_value']
      ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>'
        . ' → <span class="td-hi-to">' . h($e['to_value']) . '</span>'
      : ($e['to_value'] ? '<span class="td-hi-to">' . h($e['to_value']) . '</span>' : ''),
  ],
  'due_changed' => [
    'icon'        => 'bi-calendar3',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Termin',
    'desc'        => fn($e) => $e['from_value'] && $e['to_value']
      ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>'
        . ' → <span class="td-hi-to">' . h($e['to_value']) . '</span>'
      : ($e['to_value'] ? '<span class="td-hi-to">' . h($e['to_value']) . '</span>' : ''),
  ],
  'title_changed' => [
    'icon'        => 'bi-pencil',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Tytuł zmieniony',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'description_changed' => [
    'icon'        => 'bi-text-left',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Opis zmieniony',
    'desc'        => fn($e) => '',
  ],
  'uploaded_file' => [
    'icon'        => 'bi-paperclip',
    'bg'          => '#ede9fe', 'color' => '#7c3aed',
    'badge_bg'    => '#f5f3ff', 'badge_color' => '#7c3aed',
    'label'       => 'Plik dodany',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">📎 ' . h($e['to_value']) . '</span>' : '',
  ],
  'deleted_file' => [
    'icon'        => 'bi-paperclip',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Plik usunięty',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">📎 ' . h($e['from_value']) . '</span>' : '',
  ],
  'leader_notified' => [
    'icon'        => 'bi-megaphone-fill',
    'bg'          => '#fef9c3', 'color' => '#d97706',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#d97706',
    'label'       => 'Zgłoszono problem',
    'desc'        => fn($e) => $e['to_value']
      ? '<span class="td-hi-val" title="' . h($e['to_value']) . '">💬 ' . h(mb_substr($e['to_value'],0,50)) . (mb_strlen($e['to_value'])>50?'…':'') . '</span>'
      : '',
  ],
  'problem_resolved' => [
    'icon'        => 'bi-shield-check',
    'bg'          => '#dcfce7', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Problem rozwiązany',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">✓ ' . h($e['to_value']) . '</span>' : '',
  ],
  'takeover_requested' => [
    'icon'        => 'bi-person-up',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Prośba o przekazanie',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">→ 👤 ' . h($e['to_value']) . '</span>' : '',
  ],
  'transfer_rejected' => [
    'icon'        => 'bi-person-x',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Odrzucono przekazanie',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">👤 ' . h($e['from_value']) . '</span>' : '',
  ],
];
?>
<div class="td-section">
  <details>
    <summary class="td-label" style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:.35rem">
      <i class="bi bi-clock-history" aria-hidden="true"></i>
      Historia
      <span class="td-hi-badge ms-1"
            style="background:#f1f5f9;color:#64748b">
        <?= count($history) ?>
      </span>
    </summary>

    <div class="mt-2" role="list" aria-label="Historia zmian zadania">
      <?php foreach ($history as $he):
        $def   = $ev_defs[$he['event_type']] ?? null;
        $icon  = $def['icon']        ?? 'bi-circle';
        $bg    = $def['bg']          ?? '#f1f5f9';
        $color = $def['color']       ?? '#64748b';
        $bb    = $def['badge_bg']    ?? '#f1f5f9';
        $bc    = $def['badge_color'] ?? '#64748b';
        $label = $def['label']       ?? str_replace('_',' ', $he['event_type']);
        $desc  = $def ? ($def['desc'])($he) : '';
        $time  = substr($he['occurred_at'] ?? '', 0, 16);
        $actor = h($he['actor_name'] ?? '');
      ?>
      <div class="td-history-item" role="listitem">

        <!-- Ikona -->
        <span class="td-hi-icon" style="background:<?= $bg ?>;color:<?= $color ?>" aria-hidden="true">
          <i class="bi <?= $icon ?>"></i>
        </span>

        <!-- Treść -->
        <div class="flex-grow-1 min-width-0">
          <div class="d-flex align-items-center flex-wrap gap-1">
            <!-- Badge etykieta -->
            <span class="td-hi-badge" style="background:<?= $bb ?>;color:<?= $bc ?>">
              <?= h($label) ?>
            </span>
            <!-- Opis / wartości -->
            <?php if ($desc): ?>
            <span><?= $desc ?></span>
            <?php endif; ?>
          </div>
          <!-- Meta: czas · autor -->
          <div class="td-hi-meta">
            <time datetime="<?= h($he['occurred_at'] ?? '') ?>">
              <?= h($time) ?>
            </time>
            <?php if ($actor): ?>
            · <span><?= $actor ?></span>
            <?php endif; ?>
          </div>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
  </details>
</div>
<?php endif; ?>

<!-- ══ MODAL: Zgłoś problem liderowi ═══════════════════════════════════════ -->
<div id="td-notify-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9500;backdrop-filter:blur(2px)"
     aria-hidden="true"
     onclick="tdCloseNotifyModal()"></div>

<div id="td-notify-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="td-notify-modal-title"
     aria-describedby="td-notify-modal-desc"
     tabindex="-1"
     style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:9600;width:400px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;
            box-shadow:0 20px 48px rgba(0,0,0,.22);overflow:hidden">

  <!-- Nagłówek -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:.8rem 1.1rem;border-bottom:1px solid #e2e8f0;background:#fffbeb">
    <h2 id="td-notify-modal-title"
        style="font-size:.93rem;font-weight:700;margin:0;
               display:flex;align-items:center;gap:.45rem;color:#92400e">
      <span style="width:28px;height:28px;background:#fef9c3;border-radius:50%;
                   display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"
            aria-hidden="true">
        <i class="bi bi-megaphone-fill" style="color:#d97706;font-size:.85rem"></i>
      </span>
      Zgłoś problem liderowi
    </h2>
    <button type="button"
            id="td-notify-close-btn"
            class="btn-close"
            onclick="tdCloseNotifyModal()"
            aria-label="Zamknij dialog zgłoszenia problemu"
            style="font-size:.8rem"></button>
  </div>

  <!-- Treść -->
  <div style="padding:.9rem 1.1rem">
    <p id="td-notify-modal-desc"
       style="font-size:.82rem;color:#64748b;margin-bottom:.75rem;line-height:1.5">
      Opisz problem z realizacją zadania
      <strong style="color:#0f172a"><?= h(mb_substr($task['title'],0,50)) ?><?= mb_strlen($task['title'])>50?'…':'' ?></strong>.
      Wiadomość trafi e-mailem i do skrzynki wewnętrznej lidera obszaru.
    </p>

    <div style="margin-bottom:.65rem">
      <label for="td-notify-msg"
             style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem">
        Opis problemu
        <span style="color:#dc2626" aria-hidden="true">*</span>
        <span class="visually-hidden">(wymagane)</span>
      </label>
      <textarea id="td-notify-msg"
                style="width:100%;border:1.5px solid #e2e8f0;border-radius:.45rem;
                       padding:.5rem .7rem;font-size:.85rem;resize:vertical;min-height:100px;
                       font-family:inherit;line-height:1.55;color:#0f172a;
                       transition:border-color .12s"
                maxlength="1000"
                placeholder="np. Brak dostępu do materiałów, niejasne instrukcje, termin niemożliwy do dotrzymania…"
                aria-required="true"
                aria-describedby="td-notify-hint td-notify-count-label"
                oninput="tdNotifyInput(this)"
                onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();tdNotifyLeader()}"></textarea>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.3rem">
        <p id="td-notify-hint" style="font-size:.72rem;color:#94a3b8;margin:0">
          <kbd style="font-size:.68rem;background:#f1f5f9;border:1px solid #e2e8f0;
                      border-radius:3px;padding:0 .25rem">Ctrl+Enter</kbd> wysyła
        </p>
        <span id="td-notify-count-label"
              style="font-size:.72rem;color:#94a3b8"
              aria-live="polite"
              aria-label="Liczba znaków">0 / 1000</span>
      </div>
    </div>

    <div id="td-notify-ok"  class="alert alert-success  small py-2 d-none" role="status"  aria-live="polite"></div>
    <div id="td-notify-err" class="alert alert-danger   small py-2 d-none" role="alert"   aria-live="assertive"></div>
  </div>

  <!-- Stopka -->
  <div style="display:flex;justify-content:flex-end;gap:.5rem;
              padding:.65rem 1.1rem;border-top:1px solid #e2e8f0;background:#f8fafc">
    <button type="button"
            class="btn btn-outline-secondary btn-sm"
            onclick="tdCloseNotifyModal()"
            aria-label="Anuluj i zamknij dialog">
      Anuluj
    </button>
    <button type="button"
            class="btn btn-warning btn-sm"
            id="td-notify-btn"
            onclick="tdNotifyLeader()"
            aria-label="Wyślij zgłoszenie problemu do lidera obszaru">
      <i class="bi bi-megaphone me-1" aria-hidden="true"></i>Wyślij zgłoszenie
    </button>
  </div>

</div>

<!-- ══ MODAL: Nowy obszar ══════════════════════════════════════════════════ -->
<div id="td-ws-modal-backdrop"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9500"
     onclick="tdCloseNewWsModal()"
     aria-hidden="true"></div>

<div id="td-ws-modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="td-ws-modal-title"
     style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:9600;width:340px;max-width:calc(100vw - 2rem);
            background:#fff;border-radius:.75rem;box-shadow:0 16px 40px rgba(0,0,0,.22);
            overflow:hidden">

  <div style="padding:.8rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
              display:flex;align-items:center;justify-content:space-between">
    <h2 id="td-ws-modal-title" style="font-size:.9rem;font-weight:700;margin:0;color:#0f172a">
      <i class="bi bi-grid-plus me-1 text-primary" aria-hidden="true"></i>Nowy obszar roboczy
    </h2>
    <button type="button" class="btn-close" onclick="tdCloseNewWsModal()"
            aria-label="Zamknij" style="font-size:.75rem"></button>
  </div>

  <div style="padding:.9rem 1rem">
    <p style="font-size:.8rem;color:#64748b;margin-bottom:.8rem">
      Utwórz obszar roboczy na podstawie tego zadania.
      Zadanie zostanie przeniesione do nowego obszaru.
    </p>

    <div style="margin-bottom:.65rem">
      <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem"
             for="td-ws-name">
        Nazwa obszaru <span style="color:#dc2626" aria-hidden="true">*</span>
      </label>
      <input type="text" id="td-ws-name" class="form-control form-control-sm"
             maxlength="120" placeholder="np. Projekt FEER 2026"
             aria-required="true">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem;margin-bottom:.65rem">
      <div>
        <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem"
               for="td-ws-color">Kolor</label>
        <input type="color" id="td-ws-color" class="form-control form-control-sm form-control-color"
               value="#2563eb" style="height:34px;width:100%">
      </div>
      <div>
        <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem"
               for="td-ws-icon">Ikona <span style="color:#94a3b8;font-weight:400">(bez bi-)</span></label>
        <input type="text" id="td-ws-icon" class="form-control form-control-sm"
               value="kanban" placeholder="kanban">
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem .6rem;
                background:#fffbeb;border:1px solid #fcd34d;border-radius:.4rem;
                font-size:.78rem;color:#92400e;margin-bottom:.7rem">
      <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
      Zadanie <strong id="td-ws-task-label" style="max-width:160px;white-space:nowrap;
               overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:bottom">
        <?= h(mb_substr($task['title'],0,40)) ?>
      </strong> zostanie przeniesione do nowego obszaru.
    </div>

    <div id="td-ws-err" class="alert alert-danger small py-2 d-none" role="alert"></div>
  </div>

  <div style="padding:.65rem 1rem;border-top:1px solid #e2e8f0;display:flex;gap:.5rem;justify-content:flex-end">
    <button type="button" class="btn btn-outline-secondary btn-sm"
            onclick="tdCloseNewWsModal()">Anuluj</button>
    <button type="button" class="btn btn-primary btn-sm" id="td-ws-submit"
            onclick="tdSubmitNewWs()">
      <i class="bi bi-grid-plus me-1" aria-hidden="true"></i>Utwórz obszar
    </button>
  </div>
</div>

<!-- Strefa niebezpieczna przeniesiona do belki przycisków w nagłówku -->

</div><!-- /td-root -->

<script>
(function(){
'use strict';
const CSRF      = <?= json_encode($csrf) ?>;
const BASE      = <?= json_encode(rtrim(APP_URL,'/')) ?>;
const TID       = <?= (int)$id ?>;
const MS_APP_ID = <?= json_encode($ms_app_id) ?>;
const HAS_MS    = <?= $has_ms ? 'true' : 'false' ?>;
const ALL_USERS = <?= json_encode(array_values(array_map(
    fn($u) => ['id' => (int)$u['id'], 'name' => $u['name']],
    $all_users
))) ?>;

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function getInitials(n) {
    return n.trim().split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase()||'?';
}

function api(url, data) {
    return fetch(BASE + url, {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, ...data})
    }).then(r => r.json());
}

function srAnnounce(msg) {
    const el = document.getElementById('kb-sr-announce');
    if (el) { el.textContent = ''; setTimeout(() => { el.textContent = msg; }, 50); }
}

window.tdPatch = function(data) {
    api('/tasks/api/task.php', {action:'update', id:TID, ...data})
        .then(r => { if (!r.ok) alert('Błąd zapisu: ' + r.error); });
};

window.tdSetUnit = function(sel) {
    const newVal = parseInt(sel.value) || null;
    const prev   = parseInt(sel.dataset.prev) || null;
    if (!newVal) {
        sel.dataset.prev = '0';
        tdPatch({unit_id: null});
        return;
    }
    const unitName   = sel.options[sel.selectedIndex].text.trim();
    const hasAssignees = document.querySelectorAll('.td-user-btn.active').length > 0;

    const proceed = (clearAssignees) => {
        sel.dataset.prev = sel.value;
        if (clearAssignees) {
            // Wyczyść UI przypisanych
            document.querySelectorAll('.td-user-btn.active').forEach(b => {
                b.classList.remove('active');
                b.dataset.active = '0';
                b.setAttribute('aria-pressed', 'false');
            });
            tdPatch({unit_id: newVal, clear_assignees: true});
        } else {
            tdPatch({unit_id: newVal});
        }
    };

    if (hasAssignees) {
        if (!confirm('Przypisać zadanie do jednostki „' + unitName + '"?\n\nZadanie ma przypisane osoby — usunąć przypisania osobiste?')) {
            sel.value = prev || '0';
            return;
        }
        proceed(true);
    } else {
        if (!confirm('Przypisać zadanie do jednostki „' + unitName + '"?\n(Osoba preferowana — wróć do trybu osoby, gdy znasz konkretnego wykonawcę)')) {
            sel.value = prev || '0';
            return;
        }
        proceed(false);
    }
};

window.tdMoveToList = function(listId) {
    api('/tasks/api/move.php', {task_id:TID, list_id:listId, position:9999, ordered_ids:[]})
        .then(r => { if (r.ok) openTask(TID); else alert(r.error); });
};

window.tdToggleTag = function(tagId, btn) {
    const act = btn.dataset.active === '1' ? 'remove' : 'add';
    btn.disabled = true;
    api('/tasks/api/tag.php', {task_id:TID, tag_id:tagId, action:act})
        .then(r => {
            btn.disabled = false;
            if (r.ok) openTask(TID);
            else alert(r.error);
        });
};

window.tdToggleUser = function(userId, btn) {
    const act = btn.dataset.active === '1' ? 'remove' : 'add';
    btn.disabled = true;
    api('/tasks/api/assign.php', {task_id:TID, user_id:userId, action:act})
        .then(r => {
            btn.disabled = false;
            if (r.ok) openTask(TID);
            else alert(r.error);
        });
};

window.tdAddComment = function() {
    const ta   = document.getElementById('td-new-cmt');
    const body = (ta ? ta.value : '').trim();
    if (!body) { if (ta) ta.focus(); return; }
    tdMentionHide();
    const btn = document.querySelector('#td-new-cmt ~ button');
    if (btn) btn.disabled = true;
    ta.disabled = true;
    api('/tasks/api/comment.php', {action:'add', task_id:TID, body})
        .then(r => {
            ta.disabled = false;
            if (btn) btn.disabled = false;
            if (r.ok) { srAnnounce('Komentarz dodany.'); openTask(TID); }
            else alert(r.error);
        });
};

/* @mentions */
var _mFiltered = [], _mActive = -1;

function tdMentionQuery() {
    const ta  = document.getElementById('td-new-cmt');
    if (!ta) return null;
    const pos = ta.selectionStart;
    const val = ta.value.substring(0, pos);
    const m   = val.match(/@([^\n@]*)$/);
    if (!m) return null;
    return { query: m[1], atPos: pos - m[0].length };
}

function tdMentionHide() {
    const dd = document.getElementById('td-mention-dd');
    if (dd) dd.style.display = 'none';
    _mFiltered = []; _mActive = -1;
}

function tdMentionSetActive(idx) {
    const dd = document.getElementById('td-mention-dd');
    if (!dd) return;
    dd.querySelectorAll('.mi-item').forEach(function(el, i) {
        el.classList.toggle('mi-active', i === idx);
        if (i === idx) el.scrollIntoView({block:'nearest'});
    });
    _mActive = idx;
}

function tdMentionInsert(name, atPos) {
    const ta = document.getElementById('td-new-cmt');
    if (!ta) return;
    const pos = ta.selectionStart;
    ta.value  = ta.value.substring(0, atPos) + '@' + name + ' ' + ta.value.substring(pos);
    const np  = atPos + 1 + name.length + 1;
    ta.selectionStart = ta.selectionEnd = np;
    tdMentionHide();
    ta.focus();
}

function tdMentionShow(users, atPos) {
    const dd = document.getElementById('td-mention-dd');
    const ta = document.getElementById('td-new-cmt');
    if (!dd || !ta) return;
    if (!users.length) { tdMentionHide(); return; }
    _mFiltered = users; _mActive = -1;
    dd.innerHTML = '';

    users.forEach(function(u, i) {
        const item = document.createElement('div');
        item.className = 'mi-item';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', 'false');
        item.innerHTML = '<span class="td-mention-av">' + escHtml(getInitials(u.name)) + '</span>'
                       + '<span>' + escHtml(u.name) + '</span>';
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const q = tdMentionQuery();
            if (q) tdMentionInsert(u.name, q.atPos);
        });
        dd.appendChild(item);
    });

    const r = ta.getBoundingClientRect();
    dd.style.top   = (r.bottom + 3) + 'px';
    dd.style.left  = r.left + 'px';
    dd.style.width = Math.max(r.width, 200) + 'px';
    dd.style.display = 'block';
}

window.tdCmtKeydown = function(e) {
    const dd     = document.getElementById('td-mention-dd');
    const ddOpen = dd && dd.style.display !== 'none';

    if (ddOpen) {
        if (e.key === 'ArrowDown')  { e.preventDefault(); tdMentionSetActive(Math.min(_mActive + 1, _mFiltered.length - 1)); return; }
        if (e.key === 'ArrowUp')    { e.preventDefault(); tdMentionSetActive(Math.max(_mActive - 1, 0)); return; }
        if (e.key === 'Escape')     { tdMentionHide(); return; }
        if ((e.key === 'Enter' || e.key === 'Tab') && _mActive >= 0 && _mFiltered[_mActive]) {
            e.preventDefault();
            const q = tdMentionQuery();
            if (q) tdMentionInsert(_mFiltered[_mActive].name, q.atPos);
            return;
        }
    }

    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        tdAddComment();
    }
};

(function(){
    const ta = document.getElementById('td-new-cmt');
    if (!ta) return;

    ta.addEventListener('input', function() {
        const q = tdMentionQuery();
        if (!q) { tdMentionHide(); return; }
        const query   = q.query.toLowerCase();
        const matched = ALL_USERS.filter(u => {
            const n = u.name.toLowerCase();
            return n.startsWith(query) || n.includes(' ' + query);
        }).slice(0, 7);
        tdMentionShow(matched, q.atPos);
    });

    ta.addEventListener('blur', () => setTimeout(tdMentionHide, 160));

    const oc = document.querySelector('.offcanvas-body');
    if (oc) oc.addEventListener('scroll', tdMentionHide, {passive: true});
})();

window.tdDeleteComment = function(cid) {
    if (!confirm('Usunąć ten komentarz?')) return;
    api('/tasks/api/comment.php', {action:'delete', task_id:TID, comment_id:cid})
        .then(r => {
            if (r.ok) { document.getElementById('cmt-' + cid)?.remove(); srAnnounce('Komentarz usunięty.'); }
            else alert(r.error);
        });
};

/* Upload pliku */
const fileInput = document.getElementById('td-file-input');
if (fileInput) {
    fileInput.addEventListener('change', function() {
        if (!this.files.length) return;
        const f = this.files[0];
        if (f.size > 10 * 1024 * 1024) {
            alert('Plik za duży (max 10 MB).');
            this.value = '';
            return;
        }
        const st = document.getElementById('td-upload-status');
        if (st) st.classList.remove('d-none');

        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('task_id', TID);
        fd.append('file', f);

        fetch(BASE + '/tasks/api/upload.php', {method:'POST', body:fd})
            .then(r => r.json())
            .then(r => {
                if (st) st.classList.add('d-none');
                this.value = '';
                if (r.ok) { srAnnounce('Plik dodany.'); openTask(TID); }
                else alert('Błąd uploadu: ' + r.error);
            });
    });
}

/* OneDrive */
window.tdOpenOneDrive = function() {
    if (!MS_APP_ID) { alert('Brak konfiguracji Microsoft (brak Client ID).'); return; }

    function _doOpen() {
        const opts = {
            clientId:   MS_APP_ID,
            action:     'download',
            multiSelect: true,
            openInNewWindow: true,
            advanced: {
                redirectUri:     BASE + '/auth/microsoft.php',
                filter:          '.pdf,.docx,.doc,.xlsx,.xls,.pptx,.ppt,.jpg,.jpeg,.png,.zip,.txt,.csv',
                queryParameters: 'select=id,name,size,file,@microsoft.graph.downloadUrl',
            },
            success: function(result) {
                const items = result.value || [];
                (function next(i) {
                    if (i >= items.length) return;
                    const item  = items[i];
                    const dlUrl = item['@microsoft.graph.downloadUrl'];
                    if (dlUrl) tdImportFromUrl(dlUrl, item.name || 'plik', () => next(i + 1));
                    else next(i + 1);
                })(0);
            },
            cancel: function() {},
            error:  function(e) { alert('Błąd OneDrive: ' + (e.message || e)); },
        };
        OneDrive.open(opts);
    }

    if (typeof OneDrive !== 'undefined') {
        _doOpen();
    } else {
        const s = document.createElement('script');
        s.src   = 'https://js.live.net/v7.2/OneDrive.js';
        s.onload  = _doOpen;
        s.onerror = () => alert('Nie udało się załadować SDK OneDrive.');
        document.head.appendChild(s);
    }
};

window.tdImportFromUrl = function(url, filename, onDone) {
    const st  = document.getElementById('td-upload-status');
    const msg = document.getElementById('td-upload-msg');
    if (st)  st.classList.remove('d-none');
    if (msg) msg.textContent = 'Importuję: ' + filename + '…';

    fetch(BASE + '/tasks/api/import_url.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, task_id: TID, url, filename}),
    })
    .then(r => r.json())
    .then(r => {
        if (st) st.classList.add('d-none');
        if (r.ok) { srAnnounce('Plik ' + filename + ' importowany.'); openTask(TID); }
        else alert('Błąd importu „' + filename + '": ' + r.error);
        if (onDone) onDone();
    })
    .catch(() => {
        if (st) st.classList.add('d-none');
        alert('Błąd sieci przy imporcie pliku.');
        if (onDone) onDone();
    });
};

window.tdDeleteFile = function(fid) {
    if (!confirm('Usunąć ten plik?')) return;
    api('/tasks/api/upload.php', {action:'delete', file_id:fid, task_id:TID})
        .then(r => { if (r.ok) { openTask(TID); srAnnounce('Plik usunięty.'); } else alert(r.error); });
};

/* Podgląd pliku — lightbox dla obrazów / PDF / tekstu, fallback + pobieranie dla reszty */
window.tdPreviewFile = function(url, name, ext) {
    ext = (ext || '').toLowerCase();
    const IMG = ['jpg','jpeg','png','gif','webp','svg','bmp'];
    const TXT = ['txt','csv','log','md','json'];

    tdClosePreview();

    const ov = document.createElement('div');
    ov.className = 'td-preview-overlay';
    ov.id = 'td-preview-overlay';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Podgląd pliku: ' + name);

    ov.innerHTML =
        '<div class="td-preview-bar">'
        + '<span class="td-preview-title">' + escHtml(name) + '</span>'
        + '<a href="' + escHtml(url) + '" download title="Pobierz"><i class="bi bi-download" aria-hidden="true"></i>Pobierz</a>'
        + '<button type="button" onclick="tdClosePreview()" aria-label="Zamknij podgląd"><i class="bi bi-x-lg" aria-hidden="true"></i>Zamknij</button>'
        + '</div>'
        + '<div class="td-preview-body" id="td-preview-body"></div>';

    document.body.appendChild(ov);
    const bodyEl = ov.querySelector('#td-preview-body');

    if (IMG.includes(ext)) {
        const img = document.createElement('img');
        img.src = url; img.alt = name;
        bodyEl.appendChild(img);
    } else if (ext === 'pdf') {
        const ifr = document.createElement('iframe');
        ifr.src = url; ifr.title = name;
        bodyEl.appendChild(ifr);
    } else if (TXT.includes(ext)) {
        bodyEl.innerHTML = '<pre>Wczytywanie…</pre>';
        fetch(url)
            .then(r => r.ok ? r.text() : Promise.reject(r.status))
            .then(t => {
                const pre = document.createElement('pre');
                pre.textContent = t.length > 200000 ? t.slice(0, 200000) + '\n\n… (plik skrócony)' : t;
                bodyEl.innerHTML = ''; bodyEl.appendChild(pre);
            })
            .catch(() => { bodyEl.innerHTML = '<div class="td-preview-fallback">Nie udało się wczytać pliku.</div>'; });
    } else {
        bodyEl.innerHTML =
            '<div class="td-preview-fallback">'
            + '<i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>'
            + 'Podgląd tego typu pliku nie jest dostępny.<br>'
            + '<a href="' + escHtml(url) + '" download class="btn btn-sm btn-primary mt-3">'
            + '<i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz plik</a>'
            + '</div>';
    }

    // Zamknięcie: klik w tło + Escape
    ov.addEventListener('click', function(e) { if (e.target === ov) tdClosePreview(); });
    document.addEventListener('keydown', tdPreviewKeydown);
    ov.querySelector('button').focus();
};

window.tdClosePreview = function() {
    const ov = document.getElementById('td-preview-overlay');
    if (ov) ov.remove();
    document.removeEventListener('keydown', tdPreviewKeydown);
};

function tdPreviewKeydown(e) { if (e.key === 'Escape') tdClosePreview(); }

window.tdMarkDone = function() {
    const btn = document.getElementById('td-btn-done');
    if (btn) { btn.disabled = true; btn.textContent = 'Zapisuję…'; }
    api('/tasks/api/task.php', {action:'complete', id:TID})
        .then(r => {
            if (r.ok) {
                srAnnounce('Zadanie oznaczone jako ukończone.');
                openTask(TID);
                const card = document.querySelector('[data-task-id="<?= $id ?>"]');
                if (card) card.classList.add('opacity-50');
                if (typeof tkAjaxLoad === 'function') tkAjaxLoad();
                else setTimeout(() => location.reload(), 500);
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zakończ';
                }
                alert(r.error);
            }
        });
};

window.tdReopen = function() {
    api('/tasks/api/task.php', {action:'reopen', id:TID})
        .then(r => {
            if (r.ok) { srAnnounce('Zadanie wznowione.'); openTask(TID); if (typeof tkAjaxLoad === 'function') tkAjaxLoad(); else setTimeout(() => location.reload(), 400); }
            else alert(r.error);
        });
};

/* Podzadania */
function tdStApiUrl() { return BASE + '/tasks/api/subtask.php'; }

function tdStRefreshUI() {
    const rows  = document.querySelectorAll('#td-st-list .td-st-row');
    const done  = document.querySelectorAll('#td-st-list .td-st-row.td-st-done').length;
    const total = rows.length;
    const pct   = total ? Math.round(done / total * 100) : 0;

    const bar   = document.getElementById('td-st-progress');
    const wrap  = document.getElementById('td-st-progress-wrap');
    const pctEl = document.getElementById('td-st-pct');
    const ctr   = document.getElementById('td-st-counter');
    const empty = document.getElementById('td-st-empty');

    if (ctr)   ctr.textContent   = total ? '(' + done + '/' + total + ')' : '';
    if (pctEl) pctEl.textContent = total ? pct + '%' : '';
    if (bar) {
        bar.style.width = pct + '%';
        bar.className   = 'td-progress-fill ' + (done === total && total > 0 ? 'bg-success' : 'bg-primary');
        bar.parentElement?.setAttribute('aria-valuenow', pct);
    }
    if (wrap) {
        wrap.classList.toggle('d-none', !total);
        wrap.setAttribute('aria-hidden', total ? 'false' : 'true');
    }
    if (empty) empty.style.display = total ? 'none' : '';
}

function tdStBuildRow(st) {
    const row = document.createElement('div');
    row.id    = 'strow-' + st.id;
    row.className = 'td-st-row' + (st.is_done ? ' td-st-done' : '');
    row.setAttribute('role', 'listitem');

    const cb  = document.createElement('input');
    cb.type   = 'checkbox';
    cb.className = 'form-check-input flex-shrink-0 mt-0';
    cb.style.cssText = 'cursor:pointer;width:15px;height:15px';
    cb.checked = !!st.is_done;
    cb.setAttribute('aria-label', st.title);
    cb.addEventListener('change', function() { tdStToggle(st.id, cb); });

    const span = document.createElement('span');
    span.className = 'flex-grow-1 small td-st-title';
    span.style.cssText = 'line-height:1.4;cursor:text';
    span.textContent   = st.title;
    span.setAttribute('role', 'button');
    span.setAttribute('tabindex', '0');
    span.setAttribute('aria-label', 'Edytuj podzadanie: ' + st.title);
    span.addEventListener('click', function() { tdStStartEdit(span, st.id); });
    span.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); tdStStartEdit(span, st.id); }
    });

    const del = document.createElement('button');
    del.type  = 'button';
    del.className = 'btn-close flex-shrink-0';
    del.style.cssText = 'font-size:.5rem;opacity:.5';
    del.setAttribute('aria-label', 'Usuń podzadanie: ' + st.title);
    del.addEventListener('click', function() { tdStDelete(st.id); });

    row.append(cb, span, del);
    return row;
}

window.tdStToggle = function(stId, cb) {
    const is_done = cb.checked ? 1 : 0;
    const row  = document.getElementById('strow-' + stId);
    const span = row?.querySelector('.td-st-title');
    if (row)  row.classList.toggle('td-st-done', !!is_done);
    tdStRefreshUI();
    fetch(tdStApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'toggle', task_id: TID, subtask_id: stId, is_done})
    }).then(r => r.json()).then(r => {
        if (!r.ok) {
            cb.checked = !cb.checked;
            if (row) row.classList.toggle('td-st-done', !is_done);
            tdStRefreshUI();
            alert(r.error);
        }
    });
};

window.tdStAdd = function() {
    const inp   = document.getElementById('td-st-input');
    if (!inp) return;
    const title = inp.value.trim();
    if (!title) { inp.focus(); return; }
    const btn   = inp.nextElementSibling;
    inp.disabled = true;
    if (btn) btn.disabled = true;
    fetch(tdStApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'add', task_id: TID, title})
    }).then(r => r.json()).then(r => {
        inp.disabled = false;
        if (btn) btn.disabled = false;
        if (r.ok) {
            const list = document.getElementById('td-st-list');
            if (list) list.appendChild(tdStBuildRow(r.data));
            inp.value = '';
            tdStRefreshUI();
            srAnnounce('Podzadanie dodane.');
        } else alert(r.error);
        inp.focus();
    });
};

window.tdStDelete = function(stId) {
    if (!confirm('Usunąć podzadanie?')) return;
    fetch(tdStApiUrl(), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_csrf: CSRF, action: 'delete', task_id: TID, subtask_id: stId})
    }).then(r => r.json()).then(r => {
        if (r.ok) { document.getElementById('strow-' + stId)?.remove(); tdStRefreshUI(); srAnnounce('Podzadanie usunięte.'); }
        else alert(r.error);
    });
};

window.tdStStartEdit = function(span, stId) {
    if (span.querySelector('input')) return;
    const old = span.textContent.trim();
    const inp = document.createElement('input');
    inp.type  = 'text';
    inp.value = old;
    inp.className = 'form-control form-control-sm py-0 px-1';
    inp.style.cssText = 'font-size:.84rem;height:auto;border-radius:3px';
    inp.setAttribute('aria-label', 'Edytuj podzadanie');

    function commit() {
        const val = inp.value.trim() || old;
        span.textContent = val;
        if (val !== old) {
            fetch(tdStApiUrl(), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({_csrf: CSRF, action: 'rename', task_id: TID, subtask_id: stId, title: val})
            }).then(r => r.json()).then(r => {
                if (!r.ok) { span.textContent = old; alert(r.error); }
            });
        }
    }

    inp.addEventListener('blur', commit);
    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter')  { e.preventDefault(); inp.blur(); }
        if (e.key === 'Escape') { inp.value = old; inp.blur(); }
    });

    span.textContent = '';
    span.appendChild(inp);
    inp.focus();
    inp.select();
};

// ── Czas pracy — timer i log ──────────────────────────────────────────────
let _timerInterval = null;
let _timerStart    = null;
let _timerLogId    = null;

function fmtSec(s) {
    const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
    return (h?h+'h ':'') + (m?m+'min ':'') + ((!h&&!m)||sec?sec+'s':'');
}
function fmtDur(s) {
    const h=Math.floor(s/3600),m=Math.floor((s%3600)/60);
    return h?`${h}h ${m}min`:`${m}min`;
}
function pad2(n){return String(n).padStart(2,'0');}

(function loadTimeLog(){
    fetch(BASE + '/tasks/api/time.php?task_id=' + TID)
        .then(r=>r.json())
        .then(r=>{
            if (!r.ok) return;
            renderTimeLog(r.data);
            if (r.data.active) startTimerDisplay(r.data.active.started_at, r.data.active.id);
        })
        .catch(()=>{
            const l=document.getElementById('td-time-loading');
            if(l) l.textContent='Błąd ładowania logów.';
        });
})();

function renderTimeLog(data) {
    const container = document.getElementById('td-time-log');
    const totalEl   = document.getElementById('td-time-total');
    if (!container) return;

    const total = data.total_seconds || 0;
    if (totalEl) totalEl.textContent = total ? '— łącznie: ' + fmtDur(total) : '';

    const logs = data.logs || [];
    if (!logs.length) {
        container.innerHTML = '<p class="text-muted small mb-0" style="font-size:.78rem">Brak wpisów czasu.</p>';
        return;
    }
    container.innerHTML = logs.map(l => {
        const dur  = l.duration_seconds ? fmtDur(parseInt(l.duration_seconds)) : '(aktywny)';
        const time = (l.started_at||'').substring(0,16);
        return `<div class="d-flex align-items-center gap-2 py-1 border-bottom" style="font-size:.78rem" role="listitem">
          <i class="bi bi-clock text-muted flex-shrink-0"></i>
          <span class="fw-semibold" style="min-width:55px">${escHtml(dur)}</span>
          <span class="text-muted">${escHtml(time)}</span>
          <span class="text-muted flex-grow-1 text-truncate">${l.note ? escHtml(l.note) : ''}</span>
          <span class="text-muted" style="font-size:.7rem">${escHtml(l.user_name||'')}</span>
          <button onclick="tdDeleteTimeLog(${l.id})" class="btn-close" style="font-size:.5rem;opacity:.4"
                  aria-label="Usuń wpis"></button>
        </div>`;
    }).join('');
}

function startTimerDisplay(startedAt, logId) {
    _timerStart  = new Date(startedAt).getTime();
    _timerLogId  = logId;
    const btn    = document.getElementById('td-timer-btn');
    const icon   = document.getElementById('td-timer-icon');
    const label  = document.getElementById('td-timer-label');
    const runDiv = document.getElementById('td-timer-running');

    if (btn)    btn.style.cssText    = 'background:#dc2626;color:#fff;border:none;font-size:.74rem';
    if (icon)   icon.className       = 'bi bi-stop-fill me-1';
    if (label)  label.textContent    = 'Stop';
    if (runDiv) { runDiv.style.display='flex'; runDiv.classList.remove('d-none'); }

    clearInterval(_timerInterval);
    _timerInterval = setInterval(() => {
        const el = document.getElementById('td-timer-elapsed');
        if (!el) return;
        const elapsed = Math.floor((Date.now() - _timerStart) / 1000);
        const h=Math.floor(elapsed/3600), m=Math.floor((elapsed%3600)/60), s=elapsed%60;
        el.textContent = (h?pad2(h)+':':'') + pad2(m) + ':' + pad2(s);
    }, 1000);
}

function stopTimerDisplay() {
    clearInterval(_timerInterval);
    _timerStart = _timerLogId = null;
    const btn    = document.getElementById('td-timer-btn');
    const icon   = document.getElementById('td-timer-icon');
    const label  = document.getElementById('td-timer-label');
    const runDiv = document.getElementById('td-timer-running');
    if (btn)    btn.style.cssText = '';
    if (icon)   icon.className    = 'bi bi-play-fill me-1';
    if (label)  label.textContent = 'Start';
    if (runDiv) runDiv.style.display = 'none';
}

window.tdTimerToggle = function() {
    const btn = document.getElementById('td-timer-btn');
    btn.disabled = true;

    if (_timerLogId) {
        // Stop
        const note = document.getElementById('td-timer-note')?.value?.trim() || '';
        api('/tasks/api/time.php', {action:'stop', task_id:TID, note})
            .then(r => {
                btn.disabled = false;
                if (r.ok) { stopTimerDisplay(); openTask(TID); }
                else alert(r.error);
            });
    } else {
        // Start
        api('/tasks/api/time.php', {action:'start', task_id:TID})
            .then(r => {
                btn.disabled = false;
                if (r.ok) {
                    startTimerDisplay(r.data.started_at, r.data.log_id);
                    srAnnounce('Timer uruchomiony.');
                } else alert(r.error);
            });
    }
};

window.tdDeleteTimeLog = function(logId) {
    if (!confirm('Usunąć ten wpis czasu?')) return;
    api('/tasks/api/time.php', {action:'delete', task_id:TID, log_id:logId})
        .then(r => {
            if (r.ok) openTask(TID);
            else alert(r.error);
        });
};

window.tdManualTime = function() {
    const start = document.getElementById('td-mt-start')?.value;
    const end   = document.getElementById('td-mt-end')?.value;
    const note  = document.getElementById('td-mt-note')?.value?.trim() || '';
    if (!start || !end) { alert('Podaj czas rozpoczęcia i zakończenia.'); return; }
    if (new Date(end) <= new Date(start)) { alert('Czas zakończenia musi być późniejszy niż rozpoczęcia.'); return; }
    api('/tasks/api/time.php', {action:'manual', task_id:TID,
        started_at: start.replace('T',' '), ended_at: end.replace('T',' '), note})
        .then(r => {
            if (r.ok) { openTask(TID); srAnnounce('Wpis czasu dodany.'); }
            else alert(r.error);
        });
};

// ── Nowy obszar roboczy z zadania ─────────────────────────────────────────
window.tdOpenNewWsModal = function() {
    const m = document.getElementById('td-ws-modal');
    const b = document.getElementById('td-ws-modal-backdrop');
    if (!m || !b) return;
    m.style.display = 'block';
    b.style.display = 'block';
    b.removeAttribute('aria-hidden');
    document.getElementById('td-ws-name')?.focus();
};

window.tdCloseNewWsModal = function() {
    document.getElementById('td-ws-modal').style.display        = 'none';
    document.getElementById('td-ws-modal-backdrop').style.display = 'none';
    document.getElementById('td-ws-modal-backdrop').setAttribute('aria-hidden','true');
    document.getElementById('td-ws-err')?.classList.add('d-none');
};

window.tdSubmitNewWs = function() {
    const name = document.getElementById('td-ws-name')?.value?.trim();
    if (!name) { document.getElementById('td-ws-name')?.focus(); return; }

    const btn = document.getElementById('td-ws-submit');
    btn.disabled = true; btn.textContent = 'Tworzę…';

    const color = document.getElementById('td-ws-color')?.value || '#2563eb';
    const icon  = document.getElementById('td-ws-icon')?.value?.trim() || 'kanban';

    api('/tasks/api/create_workspace.php', {
        name, color, icon, task_id: TID
    })
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-grid-plus me-1" aria-hidden="true"></i>Utwórz obszar';
        if (r.ok) {
            tdCloseNewWsModal();
            srAnnounce('Obszar „' + r.data.name + '" utworzony. Zadanie przeniesione.');
            // Otwórz nowy obszar w nowej karcie
            window.open(r.data.workspace_url, '_blank');
            // Odśwież offcanvas
            openTask(TID);
        } else {
            const err = document.getElementById('td-ws-err');
            err.textContent = r.error || 'Błąd tworzenia obszaru.';
            err.classList.remove('d-none');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-grid-plus me-1" aria-hidden="true"></i>Utwórz obszar';
        const err = document.getElementById('td-ws-err');
        err.textContent = 'Błąd połączenia.';
        err.classList.remove('d-none');
    });
};

// Zamknij modal klawiszem Esc
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('td-ws-modal')?.style.display !== 'none') {
        tdCloseNewWsModal();
    }
});

window.tdDuplicate = function() {
    api('/tasks/api/task.php', {action: 'duplicate', id: TID})
        .then(r => {
            if (r.ok && r.data) {
                srAnnounce('Zadanie zduplikowane.');
                // Otwórz duplikat w offcanvasie
                openTask(r.data.id);
            } else {
                alert(r.error || 'Błąd duplikowania.');
            }
        });
};

window.tdDelete = function() {
    if (!confirm('Na pewno usunąć to zadanie? Tej operacji nie można cofnąć.')) return;
    api('/tasks/api/task.php', {action:'delete', id:TID})
        .then(r => {
            if (r.ok) {
                bootstrap.Offcanvas.getInstance(
                    document.getElementById('taskOffcanvas')
                )?.hide();
                const card = document.querySelector('[data-task-id="' + TID + '"]');
                if (card) {
                    const col = card.closest('.kanban-cards');
                    card.closest('li')?.remove() || card.remove();
                    if (col && typeof updateColCount === 'function')
                        updateColCount(col.id.replace('col-', ''), col.querySelectorAll('.task-card').length);
                }
                srAnnounce('Zadanie usunięte.');
            } else alert(r.error);
        });
};

// ── Przekaż zadanie ───────────────────────────────────────────────────────
var _tpSelectedUid  = 0;
var _tpSelectedName = '';

window.tdToggleTakeover = function() {
    const panel = document.getElementById('td-takeover-panel');
    const btn   = document.getElementById('td-takeover-btn');
    if (!panel) return;

    const isOpen = panel.classList.toggle('open');
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    if (isOpen) {
        // Zamknij kliknięciem poza / klawiszem Escape
        setTimeout(() => {
            document.addEventListener('click', _tpOutsideClick, {once: false});
            document.addEventListener('keydown', _tpKeydown);
        }, 10);
        document.getElementById('td-tp-search')?.focus();
    } else {
        _tpCleanup();
    }
};

function _tpClose(returnFocus) {
    document.getElementById('td-takeover-panel')?.classList.remove('open');
    const btn = document.getElementById('td-takeover-btn');
    btn?.setAttribute('aria-expanded', 'false');
    _tpCleanup();
    if (returnFocus) btn?.focus();
}

function _tpCleanup() {
    document.removeEventListener('click', _tpOutsideClick);
    document.removeEventListener('keydown', _tpKeydown);
}

function _tpKeydown(e) {
    if (e.key === 'Escape') { e.preventDefault(); _tpClose(true); }
}

function _tpOutsideClick(e) {
    const wrap = document.getElementById('td-takeover-wrap');
    if (wrap && !wrap.contains(e.target)) _tpClose(false);
}

window.tdTpFilter = function(q) {
    const lq   = q.toLowerCase();
    const btns = document.querySelectorAll('.td-tp-person');
    let   vis  = 0;
    btns.forEach(b => {
        const match = !lq || (b.dataset.search || '').includes(lq);
        b.style.display = match ? '' : 'none';
        if (match) vis++;
    });
    // Pokaż/ukryj nagłówki sekcji
    document.querySelectorAll('.td-tp-group').forEach(g => {
        const next = g.nextElementSibling;
        // Sprawdź czy chociaż jeden button w tej grupie jest widoczny
        let anyVis = false;
        let el = g.nextElementSibling;
        while (el && !el.classList.contains('td-tp-group')) {
            if (el.classList.contains('td-tp-person') && el.style.display !== 'none') anyVis = true;
            el = el.nextElementSibling;
        }
        g.style.display = anyVis ? '' : 'none';
    });
    const noRes = document.getElementById('td-tp-no-results');
    if (noRes) noRes.style.display = vis === 0 ? '' : 'none';
};

window.tdTpSelect = function(btn) {
    _tpSelectedUid  = parseInt(btn.dataset.uid);
    _tpSelectedName = btn.dataset.name;

    // Pokaż wybraną osobę
    const sel = document.getElementById('td-tp-selected');
    if (sel) {
        document.getElementById('td-tp-sel-av').style.background = btn.dataset.bg;
        document.getElementById('td-tp-sel-av').textContent = btn.dataset.initials;
        document.getElementById('td-tp-sel-name').textContent = _tpSelectedName;
        sel.classList.add('show');
    }

    // Pokaż pole wiadomości i przycisk
    const sw = document.getElementById('td-tp-send-wrap');
    if (sw) sw.style.display = '';

    // Ukryj listę, wyczyść szukanie
    document.getElementById('td-tp-list').style.display = 'none';
    const srch = document.getElementById('td-tp-search');
    if (srch) srch.style.display = 'none';

    // Focus na pole wiadomości
    setTimeout(() => document.getElementById('td-tp-msg-ta')?.focus(), 50);
};

window.tdTpClearSelection = function() {
    _tpSelectedUid  = 0;
    _tpSelectedName = '';

    const sel = document.getElementById('td-tp-selected');
    if (sel) sel.classList.remove('show');

    const sw = document.getElementById('td-tp-send-wrap');
    if (sw) sw.style.display = 'none';

    document.getElementById('td-tp-list').style.display = '';
    const srch = document.getElementById('td-tp-search');
    if (srch) { srch.style.display = ''; srch.value = ''; }

    tdTpFilter('');

    const ok  = document.getElementById('td-tp-ok');
    const err = document.getElementById('td-tp-err');
    if (ok)  ok.classList.add('d-none');
    if (err) err.classList.add('d-none');
};

window.tdTpSend = function() {
    if (!_tpSelectedUid) return;
    const btn = document.getElementById('td-tp-send-btn');
    const msg = document.getElementById('td-tp-msg-ta')?.value?.trim() || '';
    const ok  = document.getElementById('td-tp-ok');
    const err = document.getElementById('td-tp-err');

    btn.disabled    = true;
    btn.textContent = 'Wysyłam…';
    if (ok)  ok.classList.add('d-none');
    if (err) err.classList.add('d-none');

    api('/tasks/api/request_takeover.php', {
        task_id:        TID,
        target_user_id: _tpSelectedUid,
        message:        msg
    })
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij prośbę';
        if (r.ok) {
            if (r.ok) {
                ok.textContent = 'Prośba o przejęcie wysłana do ' + (r.data?.target_name || _tpSelectedName) + '. Czeka na akceptację.';
                ok.classList.remove('d-none');
            }
            srAnnounce('Prośba o przejęcie wysłana do ' + _tpSelectedName + '.');
            // Zamknij panel po 2s
            setTimeout(() => {
                document.getElementById('td-takeover-panel')?.classList.remove('open');
                document.getElementById('td-takeover-btn')?.setAttribute('aria-expanded','false');
                tdTpClearSelection();
            }, 2200);
        } else {
            if (err) { err.textContent = r.error || 'Błąd wysyłania.'; err.classList.remove('d-none'); }
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij prośbę';
        if (err) { err.textContent = 'Błąd połączenia z serwerem.'; err.classList.remove('d-none'); }
    });
};

// Zamknij panel klawiszem Esc
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const panel = document.getElementById('td-takeover-panel');
        if (panel?.classList.contains('open')) {
            panel.classList.remove('open');
            document.getElementById('td-takeover-btn')?.setAttribute('aria-expanded','false');
            document.getElementById('td-takeover-btn')?.focus();
        }
    }
});

// ── Powiadom lidera — modal ────────────────────────────────────────────────
var _notifyPrevFocus = null;   // zapamiętaj focus przed otwarciem

window.tdOpenNotifyModal = function() {
    const modal    = document.getElementById('td-notify-modal');
    const backdrop = document.getElementById('td-notify-backdrop');
    if (!modal || !backdrop) return;

    // Reset stanu
    const ta = document.getElementById('td-notify-msg');
    if (ta) { ta.value = ''; ta.style.borderColor = ''; }
    const cnt = document.getElementById('td-notify-count-label');
    if (cnt) cnt.textContent = '0 / 1000';
    document.getElementById('td-notify-ok')?.classList.add('d-none');
    document.getElementById('td-notify-err')?.classList.add('d-none');

    // Pokaż
    _notifyPrevFocus = document.activeElement;
    backdrop.style.display = 'block';
    modal.style.display    = 'block';
    backdrop.removeAttribute('aria-hidden');

    // Focus na textarea po animacji
    requestAnimationFrame(() => {
        document.getElementById('td-notify-msg')?.focus();
    });

    // Trap focus wewnątrz modala
    modal.addEventListener('keydown', _notifyTrapFocus);
};

window.tdCloseNotifyModal = function() {
    const modal    = document.getElementById('td-notify-modal');
    const backdrop = document.getElementById('td-notify-backdrop');
    if (!modal || !backdrop) return;

    modal.style.display    = 'none';
    backdrop.style.display = 'none';
    backdrop.setAttribute('aria-hidden', 'true');
    modal.removeEventListener('keydown', _notifyTrapFocus);

    // Przywróć focus do przycisku który otworzył modal
    (_notifyPrevFocus || document.getElementById('td-notify-open-btn'))?.focus();
    _notifyPrevFocus = null;
};

function _notifyTrapFocus(e) {
    if (e.key !== 'Tab' && e.key !== 'Escape') return;
    if (e.key === 'Escape') { e.preventDefault(); tdCloseNotifyModal(); return; }

    const modal      = document.getElementById('td-notify-modal');
    const focusable  = Array.from(modal.querySelectorAll(
        'button:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
    )).filter(el => el.offsetParent !== null);
    if (!focusable.length) return;

    const first = focusable[0];
    const last  = focusable[focusable.length - 1];

    if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
    } else {
        if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
    }
}

window.tdNotifyInput = function(ta) {
    const n   = ta.value.length;
    const cnt = document.getElementById('td-notify-count-label');
    if (cnt) {
        cnt.textContent = n + ' / 1000';
        cnt.style.color = n > 900 ? '#dc2626' : '#94a3b8';
    }
    ta.style.borderColor = '';  // usuń błąd walidacji przy pisaniu
};

window.tdNotifyLeader = function() {
    const ta  = document.getElementById('td-notify-msg');
    const btn = document.getElementById('td-notify-btn');
    const ok  = document.getElementById('td-notify-ok');
    const err = document.getElementById('td-notify-err');

    const msg = (ta ? ta.value : '').trim();
    if (!msg) {
        if (ta) {
            ta.style.borderColor = '#dc2626';
            ta.setAttribute('aria-invalid', 'true');
            ta.focus();
        }
        return;
    }
    if (ta) { ta.style.borderColor = ''; ta.removeAttribute('aria-invalid'); }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Wysyłanie…';
    ok?.classList.add('d-none');
    err?.classList.add('d-none');

    api('/tasks/api/notify_leader.php', { task_id: TID, message: msg })
        .then(r => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-megaphone me-1" aria-hidden="true"></i>Wyślij zgłoszenie';
            if (r.ok) {
                if (ta) { ta.value = ''; }
                const cnt = document.getElementById('td-notify-count-label');
                if (cnt) cnt.textContent = '0 / 1000';
                if (ok) {
                    ok.textContent = '✓ Zgłoszenie wysłane — lider otrzymał powiadomienie.';
                    ok.classList.remove('d-none');
                }
                srAnnounce('Zgłoszenie problemu wysłane do lidera.');
                // Zamknij modal po 2.5s
                setTimeout(tdCloseNotifyModal, 2500);
            } else {
                if (err) { err.textContent = r.error || 'Błąd wysyłania.'; err.classList.remove('d-none'); }
                ta?.focus();
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-megaphone me-1" aria-hidden="true"></i>Wyślij zgłoszenie';
            if (err) { err.textContent = 'Błąd połączenia z serwerem.'; err.classList.remove('d-none'); }
        });
};

})();
</script>
