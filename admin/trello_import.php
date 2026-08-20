<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Import z Trello';

// ── Pomocnicza funkcja HTTP do Trello API ─────────────────────────────────────
function trello_http(string $path, string $key, string $token, array $params = []): array {
    $params['key']   = $key;
    $params['token'] = $token;
    $url = 'https://api.trello.com/1' . $path . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['header' => "Accept: application/json\r\n", 'timeout' => 20]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['_error' => 'Błąd połączenia z Trello API'];
    $data = json_decode($resp, true);
    if (!is_array($data)) return ['_error' => 'Nieprawidłowa odpowiedź JSON'];
    return $data;
}

// ── AJAX: pobierz tablice ─────────────────────────────────────────────────────
if (($_GET['_action'] ?? '') === 'get_boards') {
    header('Content-Type: application/json');
    csrf_check_json();
    $key   = trim($_POST['api_key']   ?? '');
    $token = trim($_POST['api_token'] ?? '');
    if (!$key || !$token) { echo json_encode(['error' => 'Podaj klucz i token API']); exit; }
    $boards = trello_http('/members/me/boards', $key, $token, ['fields' => 'id,name,desc,closed,prefs']);
    if (isset($boards['_error'])) { echo json_encode(['error' => $boards['_error']]); exit; }
    $open = array_values(array_filter($boards, fn($b) => !($b['closed'] ?? false)));
    echo json_encode(['boards' => $open]);
    exit;
}

// ── AJAX: podgląd tablicy (listy + liczba kart) ───────────────────────────────
if (($_GET['_action'] ?? '') === 'preview_board') {
    header('Content-Type: application/json');
    csrf_check_json();
    $key      = trim($_POST['api_key']    ?? '');
    $token    = trim($_POST['api_token']  ?? '');
    $board_id = trim($_POST['board_id']   ?? '');
    if (!$key || !$token || !$board_id) { echo json_encode(['error' => 'Brak danych']); exit; }
    $lists = trello_http("/boards/{$board_id}/lists", $key, $token, ['filter' => 'open', 'fields' => 'id,name,pos']);
    $cards = trello_http("/boards/{$board_id}/cards", $key, $token, ['filter' => 'open', 'fields' => 'id,idList']);
    $per_list = [];
    foreach ($cards as $c) { $per_list[$c['idList']] = ($per_list[$c['idList']] ?? 0) + 1; }
    foreach ($lists as &$l) { $l['card_count'] = $per_list[$l['id']] ?? 0; }
    $members = trello_http("/boards/{$board_id}/members", $key, $token, ['fields' => 'id,fullName,email,username']);
    echo json_encode(['lists' => $lists, 'members' => $members, 'total_cards' => count($cards)]);
    exit;
}

// ── AJAX: wykonaj import ──────────────────────────────────────────────────────
if (($_GET['_action'] ?? '') === 'run_import') {
    header('Content-Type: application/json');
    csrf_check_json();

    $key           = trim($_POST['api_key']      ?? '');
    $token         = trim($_POST['api_token']    ?? '');
    $board_id      = trim($_POST['board_id']     ?? '');
    $workspace_id  = (int)($_POST['workspace_id'] ?? 0);
    $dry_run       = ($_POST['dry_run']     ?? '') === '1';
    $with_comments = ($_POST['with_comments'] ?? '') === '1';
    $with_subtasks = ($_POST['with_subtasks'] ?? '') === '1';
    $with_archived = ($_POST['with_archived'] ?? '') === '1';
    $match_emails  = ($_POST['match_emails']  ?? '') === '1';
    $owner_id      = (int)current_user()['id'];

    if (!$key || !$token || !$board_id) {
        echo json_encode(['error' => 'Brak wymaganych danych']); exit;
    }

    $label_priority_map = [
        'red'=>4,'orange'=>3,'yellow'=>3,'blue'=>2,'green'=>2,
        'purple'=>1,'pink'=>1,'sky'=>2,'lime'=>2,'black'=>4,
    ];
    $log   = [];
    $stats = ['lists'=>0,'tasks'=>0,'subtasks'=>0,'comments'=>0,'tags'=>0,'assignments'=>0,'skipped'=>0];

    // ── Pobierz dane tablicy ────────────────────────────────────────────────
    $board_info = trello_http("/boards/{$board_id}", $key, $token, ['fields' => 'id,name,desc']);
    $board_name = $board_info['name'] ?? 'Trello Import';

    // ── Workspace ───────────────────────────────────────────────────────────
    $ws_id = $workspace_id;
    if (!$ws_id) {
        $existing_ws = $dry_run ? null : db_one("SELECT id FROM task_workspaces WHERE name=?", [$board_name]);
        if ($existing_ws) {
            $ws_id = (int)$existing_ws['id'];
            $log[] = ['type'=>'skip', 'msg'=>"Workspace '$board_name' już istnieje (ID=$ws_id)"];
        } else {
            if (!$dry_run) {
                $slug = preg_replace('/[^a-z0-9]+/','-',strtolower($board_name)) . '-' . substr($board_id,-4);
                $ws_id = db_insert('task_workspaces', [
                    'slug'=>$slug,'name'=>$board_name,
                    'description'=>$board_info['desc']??'',
                    'color'=>'#2563eb','icon'=>'bi-kanban',
                    'is_active'=>1,'created_by'=>$owner_id,
                ]);
            } else { $ws_id = 9999; }
            $log[] = ['type'=>'ok', 'msg'=>"Workspace '$board_name' " . ($dry_run?'zostanie utworzony':'utworzony')];
        }
    } else {
        $ws_info = db_one("SELECT name FROM task_workspaces WHERE id=?", [$ws_id]);
        $log[] = ['type'=>'info', 'msg'=>"Używam workspace: '" . ($ws_info['name']??'#'.$ws_id) . "'"];
    }

    // ── Listy ───────────────────────────────────────────────────────────────
    $lists = trello_http("/boards/{$board_id}/lists", $key, $token, ['filter'=>'open','fields'=>'id,name,pos']);
    $list_id_map = [];
    $pos = 1;
    foreach ($lists as $list) {
        $existing = $dry_run ? null : db_one("SELECT id FROM task_lists WHERE workspace_id=? AND name=?", [$ws_id, $list['name']]);
        if ($existing) {
            $list_id_map[$list['id']] = (int)$existing['id'];
            $log[] = ['type'=>'skip','msg'=>"Lista '" . $list['name'] . "' już istnieje"];
        } else {
            $is_done = (int)(preg_match('/done|gotowe|zakończon|archiv/i', $list['name']) > 0);
            if (!$dry_run) {
                $lid = db_insert('task_lists', [
                    'workspace_id'=>$ws_id,'name'=>$list['name'],
                    'position'=>$pos*1000,'is_done_state'=>$is_done,
                ]);
                $list_id_map[$list['id']] = $lid;
            } else { $list_id_map[$list['id']] = $pos; }
            $log[] = ['type'=>'ok','msg'=>"Lista '" . $list['name'] . "'" . ($is_done?' [lista zakończona]':'')];
            $stats['lists']++;
            $pos++;
        }
    }

    // ── Etykiety → tagi ─────────────────────────────────────────────────────
    $board_labels = trello_http("/boards/{$board_id}/labels", $key, $token, ['fields'=>'id,name,color']);
    $tag_id_map = [];
    foreach ($board_labels as $lbl) {
        if (empty($lbl['name'])) continue;
        $hex = match($lbl['color']??'') {
            'red'=>'ef4444','orange'=>'f97316','yellow'=>'eab308','green'=>'22c55e',
            'blue'=>'3b82f6','purple'=>'a855f7','pink'=>'ec4899','sky'=>'0ea5e9',
            'lime'=>'84cc16','black'=>'1e293b',default=>'94a3b8'
        };
        if (!$dry_run) {
            $et = db_one("SELECT id FROM task_tags WHERE workspace_id=? AND name=?", [$ws_id, $lbl['name']]);
            if ($et) { $tag_id_map[$lbl['id']] = (int)$et['id']; }
            else {
                $tag_id_map[$lbl['id']] = db_insert('task_tags', [
                    'workspace_id'=>$ws_id,'name'=>$lbl['name'],
                    'color'=>'#'.$hex,'text_color'=>'#ffffff',
                    'is_active'=>1,'created_by'=>$owner_id,
                ]);
                $stats['tags']++;
            }
        }
    }

    // ── Dopasowanie członków ─────────────────────────────────────────────────
    $trello_members = [];
    if ($match_emails) {
        $brd_members = trello_http("/boards/{$board_id}/members", $key, $token, ['fields'=>'id,email,fullName']);
        $sys_users   = db_all("SELECT id, email FROM users WHERE is_active=1");
        $email_map   = [];
        foreach ($sys_users as $u) $email_map[strtolower($u['email'])] = (int)$u['id'];
        foreach ($brd_members as $m) {
            $em = strtolower($m['email'] ?? '');
            if ($em && isset($email_map[$em])) {
                $trello_members[$m['id']] = $email_map[$em];
                $log[] = ['type'=>'info','msg'=>"Dopasowano: {$m['fullName']} → user #{$email_map[$em]}"];
            }
        }
    }

    // ── Karty ────────────────────────────────────────────────────────────────
    $card_params = [
        'filter'     => $with_archived ? 'all' : 'open',
        'fields'     => 'id,name,desc,due,start,closed,pos,idList,idMembers,idLabels',
        'checklists' => $with_subtasks  ? 'all' : 'none',
        'attachments'=> 'false',
    ];
    if ($with_comments) $card_params['actions'] = 'commentCard';
    $cards = trello_http("/boards/{$board_id}/cards", $key, $token, $card_params);

    $pos_counter = [];
    foreach ($cards as $card) {
        $list_sys_id = $list_id_map[$card['idList']] ?? null;
        if (!$list_sys_id) { $stats['skipped']++; continue; }

        if (!$dry_run) {
            $exists = db_one("SELECT id FROM tasks WHERE workspace_id=? AND list_id=? AND title=? AND deleted_at IS NULL",
                [$ws_id, $list_sys_id, $card['name']]);
            if ($exists) { $stats['skipped']++; $log[] = ['type'=>'skip','msg'=>"Pominięto (istnieje): '" . $card['name'] . "'"]; continue; }
        }

        $priority = 2;
        foreach ($card['idLabels'] as $lid) {
            $lbl = array_values(array_filter($board_labels, fn($l) => $l['id']===$lid))[0] ?? null;
            if ($lbl) $priority = max($priority, $label_priority_map[$lbl['color']??'']??2);
        }

        $pos_counter[$list_sys_id] = ($pos_counter[$list_sys_id] ?? 0) + 1000;

        if (!$dry_run) {
            $task_id = db_insert('tasks', [
                'workspace_id'=>$ws_id,'list_id'=>$list_sys_id,
                'title'=>$card['name'],'description'=>$card['desc']??'',
                'position'=>$pos_counter[$list_sys_id],'priority'=>$priority,
                'due_date'=>$card['due']?substr($card['due'],0,10):null,
                'start_date'=>$card['start']?substr($card['start'],0,10):null,
                'created_by'=>$owner_id,
                'completed_at'=>$card['closed']?date('Y-m-d H:i:s'):null,
                'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),
            ]);

            foreach ($card['idLabels'] as $lid) {
                if (isset($tag_id_map[$lid])) {
                    try { db()->prepare("INSERT OR IGNORE INTO task_task_tags (task_id, tag_id) VALUES (?,?)")->execute([$task_id, $tag_id_map[$lid]]); } catch (\Throwable $e) {}
                }
            }
            foreach ($card['idMembers'] as $mid) {
                if (isset($trello_members[$mid])) {
                    try { db()->prepare("INSERT OR IGNORE INTO task_assignments (task_id,user_id,assigned_by,assigned_at) VALUES (?,?,?,datetime('now','localtime'))")->execute([$task_id,$trello_members[$mid],$owner_id]); $stats['assignments']++; } catch (\Throwable $e) {}
                }
            }
            if ($with_subtasks && !empty($card['checklists'])) {
                $sp = 0;
                foreach ($card['checklists'] as $cl) {
                    foreach ($cl['checkItems']??[] as $item) {
                        db_insert('task_subtasks', ['task_id'=>$task_id,'title'=>($cl['name']!=='Checklist'?"[{$cl['name']}] ":'').$item['name'],'is_done'=>($item['state']==='complete')?1:0,'position'=>++$sp*1000,'created_by'=>$owner_id,'completed_at'=>($item['state']==='complete')?date('Y-m-d H:i:s'):null]);
                        $stats['subtasks']++;
                    }
                }
            }
            if ($with_comments && !empty($card['actions'])) {
                foreach ($card['actions'] as $action) {
                    if ($action['type']!=='commentCard') continue;
                    $body = $action['data']['text']??''; if (!$body) continue;
                    $author = $action['memberCreator']['fullName']??'Trello';
                    $at = substr($action['date']??'',0,19);
                    db_insert('task_comments', ['task_id'=>$task_id,'author_id'=>$owner_id,'body'=>"[Trello — {$author}]\n{$body}",'created_at'=>$at?:date('Y-m-d H:i:s'),'updated_at'=>$at?:date('Y-m-d H:i:s')]);
                    $stats['comments']++;
                }
            }
        }

        $log[] = ['type'=>'task','msg'=>$card['name'],'list'=>$lists[array_search($card['idList'], array_column($lists,'id'))]['name']??'','priority'=>$priority,'dry'=>$dry_run];
        $stats['tasks']++;
    }

    echo json_encode(['ok'=>true,'log'=>$log,'stats'=>$stats,'dry_run'=>$dry_run,'workspace_id'=>$ws_id,'board_name'=>$board_name]);
    exit;
}

// ── Pobierz istniejące workspace'y ───────────────────────────────────────────
$workspaces = db_all("SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name");

// ── Zapisz klucze API w sesji (convenience) ───────────────────────────────────
auth_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_credentials'])) {
    csrf_check();
    $_SESSION['trello_api_key']   = trim($_POST['api_key']   ?? '');
    $_SESSION['trello_api_token'] = trim($_POST['api_token'] ?? '');
    header('Location: trello_import.php'); exit;
}
// ── Zapisz URL workspace Trello (kafel w Tożsamości) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_workspace_url'])) {
    csrf_check();
    $wu = trim($_POST['trello_workspace_url'] ?? '');
    if ($wu && !filter_var($wu, FILTER_VALIDATE_URL)) {
        flash_set('error', 'Nieprawidłowy URL workspace Trello.');
    } else {
        org_setting_set('trello_workspace_url', $wu);
        flash_set('success', 'URL workspace Trello zapisany.');
    }
    header('Location: trello_import.php'); exit;
}
$saved_key         = $_SESSION['trello_api_key']   ?? '';
$saved_token       = $_SESSION['trello_api_token'] ?? '';
$trello_ws_setting = org_setting('trello_workspace_url');

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.ti-step { display: flex; align-items: center; gap: .5rem; margin-bottom: 1.5rem; }
.ti-step-num {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; font-weight: 700; background: #E2E8F0; color: #64748B;
}
.ti-step.active .ti-step-num { background: #1E6DFF; color: #fff; box-shadow: 0 0 0 4px rgba(30,109,255,.18); }
.ti-step.done   .ti-step-num { background: #16A34A; color: #fff; }
.ti-step-label { font-size: .88rem; font-weight: 600; color: #64748B; }
.ti-step.active .ti-step-label { color: #1E6DFF; }
.ti-step.done   .ti-step-label { color: #16A34A; }
.ti-step-sep { flex: 1; height: 2px; background: #E2E8F0; border-radius: 1px; }

.board-card {
  border: 2px solid #E2E8F0; border-radius: 12px; padding: .85rem 1rem;
  cursor: pointer; transition: border-color .12s, background .12s;
  background: #fff; position: relative;
}
.board-card:hover { border-color: #1E6DFF; background: #F8FBFF; }
.board-card.selected { border-color: #1E6DFF; background: #EFF6FF; }
.board-card input[type=checkbox] { display: none; }
.board-color-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

.log-item { display: flex; align-items: flex-start; gap: .5rem; font-size: .82rem; padding: .3rem 0; border-bottom: 1px solid #F8FAFC; }
.log-item:last-child { border-bottom: none; }
.log-icon { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

.stat-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .35rem .75rem; border-radius: 20px; font-size: .82rem; font-weight: 600; }

.panel-card { background: #fff; border: 1px solid #E2E8F0; border-radius: 14px; padding: 1.25rem 1.4rem; margin-bottom: 1rem; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.panel-card-head { font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94A3B8; margin-bottom: 1rem; display: flex; align-items: center; gap: .4rem; }
</style>

<div class="d-flex align-items-center gap-3 mb-3">
  <div style="width:40px;height:40px;border-radius:10px;background:#EBF4F9;display:flex;align-items:center;justify-content:center">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="#0079BF">
      <path d="M21 0H3C1.343 0 0 1.343 0 3v18c0 1.657 1.343 3 3 3h18c1.657 0 3-1.343 3-3V3c0-1.657-1.343-3-3-3zM10.44 18.18H5.56c-.98 0-1.78-.8-1.78-1.78V5.56c0-.98.8-1.78 1.78-1.78h4.88c.98 0 1.78.8 1.78 1.78V16.4c0 .98-.8 1.78-1.78 1.78zm8 -7.5h-4.88c-.98 0-1.78-.8-1.78-1.78V5.56c0-.98.8-1.78 1.78-1.78h4.88c.98 0 1.78.8 1.78 1.78V8.9c0 .98-.8 1.78-1.78 1.78z"/>
    </svg>
  </div>
  <div>
    <h4 class="mb-0">Import z Trello</h4>
    <div class="text-muted small">Przenieś tablice, listy i zadania do modułu Zadania</div>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Pasek kroków ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-0 mb-4" id="stepBar">
  <div class="ti-step" id="step-ind-1">
    <div class="ti-step-num">1</div>
    <div class="ti-step-label">Połączenie API</div>
  </div>
  <div class="ti-step-sep mx-2"></div>
  <div class="ti-step" id="step-ind-2">
    <div class="ti-step-num">2</div>
    <div class="ti-step-label">Wybierz tablice</div>
  </div>
  <div class="ti-step-sep mx-2"></div>
  <div class="ti-step" id="step-ind-3">
    <div class="ti-step-num">3</div>
    <div class="ti-step-label">Opcje i import</div>
  </div>
  <div class="ti-step-sep mx-2"></div>
  <div class="ti-step" id="step-ind-4">
    <div class="ti-step-num">4</div>
    <div class="ti-step-label">Wyniki</div>
  </div>
</div>

<!-- ══ KROK 1: Połączenie ═════════════════════════════════════════════════════ -->
<div id="step-1">
  <div class="panel-card">
    <div class="panel-card-head"><i class="bi bi-key-fill"></i> Klucz API Trello</div>
    <div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:.84rem">
      <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
      <div>
        Klucz i token pobierzesz na
        <a href="https://trello.com/power-ups/admin" target="_blank"><strong>trello.com/power-ups/admin</strong></a>.
        Kliknij <em>API Key</em> → skopiuj klucz → kliknij <em>Token</em> → autoryzuj → skopiuj token.
      </div>
    </div>
    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label fw-semibold small">API Key</label>
        <input type="text" id="apiKey" class="form-control font-monospace"
               placeholder="abc123def456…" value="<?= h($saved_key) ?>">
      </div>
      <div class="col-md-5">
        <label class="form-label fw-semibold small">API Token</label>
        <input type="password" id="apiToken" class="form-control font-monospace"
               placeholder="xxxxxxxx…" value="<?= h($saved_token) ?>">
        <div class="form-text">Token nie jest przesyłany poza Twój serwer.</div>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100" onclick="connectTrello()">
          <i class="bi bi-plug me-1"></i>Połącz
        </button>
      </div>
    </div>
    <div id="connect-status" class="mt-2"></div>
  </div>
</div>

<!-- ══ KROK 2: Wybierz tablice ═══════════════════════════════════════════════ -->
<div id="step-2" style="display:none">
  <div class="panel-card">
    <div class="panel-card-head"><i class="bi bi-layout-three-columns"></i> Dostępne tablice Trello</div>
    <div id="boards-grid" class="row g-2 mb-3"></div>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary" onclick="goStep(1)">
        <i class="bi bi-arrow-left me-1"></i>Wróć
      </button>
      <button class="btn btn-primary" id="btn-step2" onclick="goStep(3)" disabled>
        Dalej: Opcje <i class="bi bi-arrow-right ms-1"></i>
      </button>
    </div>
  </div>
</div>

<!-- ══ KROK 3: Opcje importu ══════════════════════════════════════════════════ -->
<div id="step-3" style="display:none">
  <div class="row g-3">

    <!-- Opcje -->
    <div class="col-lg-5">
      <div class="panel-card">
        <div class="panel-card-head"><i class="bi bi-sliders"></i> Opcje importu</div>

        <div class="mb-3">
          <label class="form-label fw-semibold small">Docelowy Workspace</label>
          <select id="workspaceId" class="form-select form-select-sm">
            <option value="0">✦ Utwórz nowy workspace (nazwa = tablica Trello)</option>
            <?php foreach ($workspaces as $ws): ?>
            <option value="<?= $ws['id'] ?>"><?= h($ws['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Jeśli importujesz wiele tablic, każda dostanie osobny workspace (chyba że wybierzesz jeden).</div>
        </div>

        <div class="mb-3">
          <div class="form-label fw-semibold small">Co importować</div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="opt_subtasks" checked>
            <label class="form-check-label small" for="opt_subtasks">
              <i class="bi bi-check2-square text-primary me-1"></i>Checklisty → podzadania
            </label>
          </div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="opt_comments">
            <label class="form-check-label small" for="opt_comments">
              <i class="bi bi-chat-text text-primary me-1"></i>Komentarze
            </label>
          </div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="opt_archived">
            <label class="form-check-label small" for="opt_archived">
              <i class="bi bi-archive text-secondary me-1"></i>Zarchiwizowane karty
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="opt_emails" checked>
            <label class="form-check-label small" for="opt_emails">
              <i class="bi bi-person-check text-success me-1"></i>Dopasuj członków po e-mail
            </label>
          </div>
        </div>

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" id="opt_dryrun" role="switch" checked>
          <label class="form-check-label fw-semibold small" for="opt_dryrun">
            Dry run (podgląd bez zapisu)
          </label>
          <div class="form-text">Odznacz aby faktycznie zapisać dane do bazy.</div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-outline-secondary" onclick="goStep(2)">
            <i class="bi bi-arrow-left me-1"></i>Wróć
          </button>
          <button class="btn btn-primary flex-fill" id="btn-run" onclick="runImport()">
            <i class="bi bi-play-fill me-1"></i>Uruchom
          </button>
        </div>
      </div>
    </div>

    <!-- Podgląd wybranej tablicy -->
    <div class="col-lg-7">
      <div class="panel-card">
        <div class="panel-card-head"><i class="bi bi-eye"></i> Podgląd tablicy</div>
        <div id="preview-content">
          <div class="text-muted small">Wybierz tablicę, aby zobaczyć podgląd.</div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ══ KROK 4: Wyniki ═════════════════════════════════════════════════════════ -->
<div id="step-4" style="display:none">
  <div class="panel-card">
    <div class="panel-card-head d-flex justify-content-between" id="result-head">
      <span><i class="bi bi-check-circle-fill text-success me-1"></i> Wyniki importu</span>
    </div>
    <div id="result-stats" class="d-flex flex-wrap gap-2 mb-3"></div>
    <div id="result-log" style="max-height:400px;overflow-y:auto;border:1px solid #E2E8F0;border-radius:10px;padding:.75rem 1rem"></div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-outline-secondary" onclick="goStep(3)">
        <i class="bi bi-arrow-left me-1"></i>Wróć
      </button>
      <a id="btn-go-tasks" href="<?= APP_URL ?>/tasks/index.php" class="btn btn-success" style="display:none">
        <i class="bi bi-kanban me-1"></i>Otwórz Zadania
      </a>
      <button class="btn btn-outline-primary" onclick="goStep(1)">
        <i class="bi bi-plus me-1"></i>Importuj kolejną
      </button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= csrf_token() ?>';
let selectedBoards = [];
let allBoards      = [];
let currentStep    = 1;

// ── Nawigacja kroków ─────────────────────────────────────────────────────────
function goStep(n) {
  for (let i = 1; i <= 4; i++) {
    document.getElementById('step-' + i).style.display = i === n ? '' : 'none';
    const ind = document.getElementById('step-ind-' + i);
    ind.classList.toggle('active', i === n);
    ind.classList.toggle('done',   i < n);
  }
  currentStep = n;
}

// ── Krok 1: Połącz z Trello ──────────────────────────────────────────────────
async function connectTrello() {
  const key   = document.getElementById('apiKey').value.trim();
  const token = document.getElementById('apiToken').value.trim();
  const status = document.getElementById('connect-status');
  if (!key || !token) { setStatus(status, 'danger', 'Wpisz klucz i token API.'); return; }

  setStatus(status, 'info', '<span class="spinner-border spinner-border-sm me-1"></span>Łączenie z Trello…');

  const res = await apiCall('get_boards', { api_key: key, api_token: token });
  if (res.error) { setStatus(status, 'danger', '✗ ' + res.error); return; }

  allBoards = res.boards;
  setStatus(status, 'success', `✓ Połączono. Znaleziono ${allBoards.length} tablic.`);
  renderBoards();
  goStep(2);
}

// ── Krok 2: Renderuj tablice ─────────────────────────────────────────────────
function renderBoards() {
  const grid = document.getElementById('boards-grid');
  grid.innerHTML = '';
  allBoards.forEach(b => {
    const bgColor = b.prefs?.backgroundTopColor || b.prefs?.backgroundColor || '#0079BF';
    const col = document.createElement('div');
    col.className = 'col-sm-6 col-lg-4';
    col.innerHTML = `
      <label class="board-card d-block" data-id="${b.id}">
        <input type="checkbox" value="${b.id}" onchange="toggleBoard('${b.id}')">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="board-color-dot" style="background:${bgColor}"></span>
          <strong class="small">${esc(b.name)}</strong>
        </div>
        ${b.desc ? `<div class="text-muted" style="font-size:.75rem">${esc(b.desc.slice(0,80))}${b.desc.length>80?'…':''}</div>` : ''}
      </label>`;
    grid.appendChild(col);
  });
}

function toggleBoard(id) {
  const card = document.querySelector(`.board-card[data-id="${id}"]`);
  const idx  = selectedBoards.indexOf(id);
  if (idx === -1) { selectedBoards.push(id); card.classList.add('selected'); }
  else            { selectedBoards.splice(idx,1); card.classList.remove('selected'); }
  document.getElementById('btn-step2').disabled = selectedBoards.length === 0;
  if (selectedBoards.length === 1) loadPreview(selectedBoards[0]);
}

// ── Krok 3: Podgląd tablicy ───────────────────────────────────────────────────
async function loadPreview(boardId) {
  const key   = document.getElementById('apiKey').value.trim();
  const token = document.getElementById('apiToken').value.trim();
  const wrap  = document.getElementById('preview-content');
  wrap.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ładowanie podglądu…';
  const res = await apiCall('preview_board', { api_key: key, api_token: token, board_id: boardId });
  if (res.error) { wrap.innerHTML = `<span class="text-danger">${esc(res.error)}</span>`; return; }
  const board = allBoards.find(b => b.id === boardId);
  let html = `<div class="fw-semibold mb-2">${esc(board?.name||'')}</div>`;
  html += `<div class="text-muted small mb-3">Łącznie: <strong>${res.total_cards}</strong> kart w ${res.lists.length} listach</div>`;
  html += '<div class="row g-2">';
  res.lists.forEach(l => {
    const done = /done|gotowe|zakończon/i.test(l.name);
    html += `<div class="col-sm-6">
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:.5rem .75rem;font-size:.82rem">
        <span class="fw-semibold">${esc(l.name)}</span>
        ${done?'<span class="badge bg-success ms-1" style="font-size:.65rem">zakończona</span>':''}
        <span class="badge bg-light text-secondary border ms-1" style="font-size:.65rem">${l.card_count} kart</span>
      </div>
    </div>`;
  });
  html += '</div>';
  if (res.members?.length) {
    html += `<div class="mt-3 text-muted small"><i class="bi bi-people me-1"></i>Członkowie: ${res.members.map(m=>esc(m.fullName)).join(', ')}</div>`;
  }
  wrap.innerHTML = html;
}

// Przy przejściu do kroku 3 załaduj podgląd pierwszej wybranej tablicy
document.getElementById('step-ind-3') && document.getElementById('btn-step2').addEventListener && null;
const _origGoStep3 = window.goStep;

// ── Krok 3 → Krok 4: Uruchom import ─────────────────────────────────────────
async function runImport() {
  const key   = document.getElementById('apiKey').value.trim();
  const token = document.getElementById('apiToken').value.trim();
  const wsId  = document.getElementById('workspaceId').value;
  const dry   = document.getElementById('opt_dryrun').checked;
  const btn   = document.getElementById('btn-run');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importuję…';

  const logEl   = document.getElementById('result-log');
  const statsEl = document.getElementById('result-stats');
  logEl.innerHTML  = '';
  statsEl.innerHTML = '';

  goStep(4);
  document.getElementById('result-head').querySelector('span').innerHTML =
    dry ? '<i class="bi bi-eye text-primary me-1"></i>Podgląd (Dry run)' :
          '<i class="bi bi-check-circle-fill text-success me-1"></i>Wyniki importu';

  for (const boardId of selectedBoards) {
    const res = await apiCall('run_import', {
      api_key: key, api_token: token, board_id: boardId,
      workspace_id: wsId, dry_run: dry?'1':'0',
      with_subtasks:  document.getElementById('opt_subtasks').checked ?'1':'0',
      with_comments:  document.getElementById('opt_comments').checked ?'1':'0',
      with_archived:  document.getElementById('opt_archived').checked ?'1':'0',
      match_emails:   document.getElementById('opt_emails').checked   ?'1':'0',
    });

    if (res.error) {
      logEl.innerHTML += logItem('err', 'Błąd: ' + res.error);
      continue;
    }

    // Statystyki
    const s = res.stats;
    const sdry = res.dry_run;
    statsEl.innerHTML += `
      <span class="stat-badge" style="background:#EFF6FF;color:#1E6DFF">📋 ${s.tasks} zadań${sdry?' (podgląd)':''}</span>
      <span class="stat-badge" style="background:#F0FDF4;color:#16A34A">📁 ${s.lists} list</span>
      ${s.subtasks?`<span class="stat-badge" style="background:#F8FAFC;color:#64748B">✓ ${s.subtasks} podzadań</span>`:''}
      ${s.comments?`<span class="stat-badge" style="background:#F8FAFC;color:#64748B">💬 ${s.comments} komentarzy</span>`:''}
      ${s.skipped?`<span class="stat-badge" style="background:#FFF7ED;color:#EA580C">⏭ ${s.skipped} pominięto</span>`:''}
    `;

    // Log
    const boardLabel = `<div style="font-size:.78rem;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.06em;padding:.5rem 0 .3rem;margin-top:.5rem">📋 ${esc(res.board_name)}</div>`;
    logEl.innerHTML += boardLabel;
    (res.log||[]).forEach(entry => {
      logEl.innerHTML += logItem(entry.type, entry.msg, entry.list, entry.priority);
    });
  }

  if (!dry) {
    document.getElementById('btn-go-tasks').style.display = '';
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Uruchom';
}

function logItem(type, msg, list, priority) {
  const icons = { ok:'<i class="bi bi-check-circle-fill text-success"></i>', skip:'<i class="bi bi-dash-circle text-warning"></i>', err:'<i class="bi bi-x-circle-fill text-danger"></i>', info:'<i class="bi bi-info-circle text-primary"></i>', task:'<i class="bi bi-card-text" style="color:#64748B"></i>' };
  const prio  = ['','<span style="color:#94A3B8;font-size:.7rem">▪ low</span>','<span style="color:#3B82F6;font-size:.7rem">▪ mid</span>','<span style="color:#F59E0B;font-size:.7rem">▪ high</span>','<span style="color:#EF4444;font-size:.7rem">▪ crit</span>'];
  const listTag = list ? `<span class="text-muted ms-1" style="font-size:.74rem">[${esc(list)}]</span>` : '';
  const prioTag = priority ? (prio[priority]||'') : '';
  return `<div class="log-item"><span class="log-icon">${icons[type]||icons.info}</span><span>${esc(msg)}${listTag}${prioTag}</span></div>`;
}

// ── Pomocnicze ───────────────────────────────────────────────────────────────
async function apiCall(action, data) {
  try {
    const body = new URLSearchParams({ _csrf: CSRF, ...data });
    const res  = await fetch('?_action=' + action, { method: 'POST', body });
    return await res.json();
  } catch (e) { return { error: e.message }; }
}
function setStatus(el, type, html) {
  el.innerHTML = `<div class="alert alert-${type} py-2 small mt-2">${html}</div>`;
}
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Inicjalizacja — jeśli klucze już zapisane, oznacz krok 1 jako gotowy
document.addEventListener('DOMContentLoaded', () => {
  goStep(1);
  <?php if ($saved_key && $saved_token): ?>
  document.getElementById('connect-status').innerHTML = '<div class="alert alert-success py-2 small mt-2">✓ Klucze API wczytane z sesji — kliknij Połącz.</div>';
  <?php endif; ?>
});

// Przy przejściu do kroku 3 załaduj podgląd
function goStep(n) {
  for (let i = 1; i <= 4; i++) {
    const s = document.getElementById('step-' + i);
    if (s) s.style.display = i === n ? '' : 'none';
    const ind = document.getElementById('step-ind-' + i);
    if (ind) {
      ind.classList.toggle('active', i === n);
      ind.classList.toggle('done',   i < n);
      const num = ind.querySelector('.ti-step-num');
      if (num) num.innerHTML = i < n ? '<i class="bi bi-check-lg" style="font-size:.75rem"></i>' : i;
    }
  }
  currentStep = n;
  if (n === 3 && selectedBoards.length === 1) {
    loadPreview(selectedBoards[0]);
  }
}
</script>

<?php
/* ── Ustawienia Trello (kafel w module Tożsamości) ── */
?>
<div class="card mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-gear me-1"></i>Ustawienia Trello — kafel w module Tożsamości</div>
  <div class="card-body">
    <p class="text-muted small mb-3">Podaj URL przestrzeni roboczej (workspace) Trello, do której mają być prowadzeni użytkownicy przez kafel w module Tożsamości.</p>
    <?= flash_display() ?>
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="save_workspace_url" value="1">
      <div class="col-md-9">
        <label for="trello_ws_url" class="form-label small fw-semibold mb-1">URL workspace Trello</label>
        <input type="url" id="trello_ws_url" name="trello_workspace_url" class="form-control form-control-sm"
               value="<?= h($trello_ws_setting) ?>" placeholder="https://trello.com/w/twoj-workspace">
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-save me-1"></i>Zapisz URL
        </button>
      </div>
    </form>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
