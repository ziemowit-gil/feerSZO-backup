<?php
/**
 * import_trello.php — Migracja tablic Trello do modułu Zadania.
 *
 * UŻYCIE:
 *   php cli/import_trello.php --key=TRELLO_KEY --token=TRELLO_TOKEN [opcje]
 *
 * OPCJE:
 *   --key=KEY          Trello API Key   (https://trello.com/power-ups/admin)
 *   --token=TOKEN      Trello API Token (generowany z klucza)
 *   --board=ID         ID konkretnej tablicy (jeśli pominięty: lista wszystkich)
 *   --workspace=ID     ID istniejącego workspace w systemie (jeśli pominięty: tworzy nowy)
 *   --user=ID          ID użytkownika-właściciela importu (domyślnie: 1)
 *   --dry-run          Tylko pokaż co zostanie zaimportowane, nic nie zapisuj
 *   --with-archived    Importuj też zarchiwizowane karty
 *   --with-comments    Importuj komentarze
 *   --with-subtasks    Importuj checklisty jako podzadania
 *   --match-emails     Dopasuj członków Trello do użytkowników systemu po e-mail
 *
 * PRZYKŁAD:
 *   php cli/import_trello.php --key=abc123 --token=xyz789 --dry-run
 *   php cli/import_trello.php --key=abc123 --token=xyz789 --board=BOARD_ID --with-comments --match-emails
 */

if (php_sapi_name() !== 'cli') {
    die("Uruchamiaj tylko z CLI: php cli/import_trello.php\n");
}

// ── Argumenty ─────────────────────────────────────────────────────────────────
$opts = getopt('', [
    'key:', 'token:', 'board:', 'workspace:', 'user:',
    'dry-run', 'with-archived', 'with-comments', 'with-subtasks', 'match-emails',
]);

$api_key      = $opts['key']       ?? '';
$api_token    = $opts['token']     ?? '';
$board_id_arg = $opts['board']     ?? '';
$workspace_id = isset($opts['workspace']) ? (int)$opts['workspace'] : 0;
$owner_id     = isset($opts['user'])      ? (int)$opts['user']      : 1;
$dry_run      = isset($opts['dry-run']);
$with_archived  = isset($opts['with-archived']);
$with_comments  = isset($opts['with-comments']);
$with_subtasks  = isset($opts['with-subtasks']);
$match_emails   = isset($opts['match-emails']);

if (!$api_key || !$api_token) {
    die("Błąd: podaj --key i --token.\nWygeneruj je na: https://trello.com/power-ups/admin\n");
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function trello_get(string $path, array $params = []): array {
    global $api_key, $api_token;
    $params['key']   = $api_key;
    $params['token'] = $api_token;
    $url = 'https://api.trello.com/1' . $path . '?' . http_build_query($params);

    $ctx = stream_context_create(['http' => [
        'header'  => "Accept: application/json\r\n",
        'timeout' => 30,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        // Sprawdź 429
        $hdrs = $http_response_header ?? [];
        foreach ($hdrs as $h) {
            if (str_contains($h, '429')) {
                echo "  [RATE LIMIT] Czekam 10s…\n";
                sleep(10);
                $resp = file_get_contents($url, false, $ctx);
                break;
            }
        }
    }
    if ($resp === false) throw new RuntimeException("Błąd HTTP: GET {$path}");
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new RuntimeException("Nieprawidłowa odpowiedź JSON: {$path}");
    return $data;
}

function log_ok(string $msg): void  { echo "\033[32m  ✓\033[0m {$msg}\n"; }
function log_skip(string $msg): void { echo "\033[33m  –\033[0m {$msg}\n"; }
function log_info(string $msg): void { echo "  · {$msg}\n"; }
function log_err(string $msg): void  { echo "\033[31m  ✗\033[0m {$msg}\n"; }
function log_head(string $msg): void { echo "\n\033[1;34m{$msg}\033[0m\n"; }

// ── Mapowanie priorytetów (wg etykiet Trello) ─────────────────────────────────
// Kolory etykiet Trello → priorytety systemu (1=low, 2=medium, 3=high, 4=critical)
$label_priority_map = [
    'red'    => 4, // critical
    'orange' => 3, // high
    'yellow' => 3, // high
    'blue'   => 2, // medium
    'green'  => 2, // medium
    'purple' => 1, // low
    'pink'   => 1,
    'sky'    => 2,
    'lime'   => 2,
    'black'  => 4,
];

// ── START ──────────────────────────────────────────────────────────────────────
echo "\n";
echo "╔═══════════════════════════════════════════════════════╗\n";
echo "║   Import Trello → Moduł Zadania                       ║\n";
if ($dry_run) {
echo "║   TRYB: DRY RUN — nic nie zostanie zapisane           ║\n";
}
echo "╚═══════════════════════════════════════════════════════╝\n\n";

// ── 1. Lista tablic ───────────────────────────────────────────────────────────
log_head("Krok 1: Pobieranie tablic Trello");

$all_boards = trello_get('/members/me/boards', ['fields' => 'id,name,desc,closed']);
$open_boards = array_filter($all_boards, fn($b) => !$b['closed']);

if (!$board_id_arg) {
    echo "\nDostępne tablice:\n";
    foreach ($open_boards as $b) {
        echo "  [{$b['id']}] {$b['name']}\n";
    }
    echo "\nPodaj --board=ID aby zaimportować konkretną tablicę.\n";
    echo "Lub użyj --board=ALL aby zaimportować wszystkie.\n\n";
    exit(0);
}

// Filtruj tablice
if ($board_id_arg === 'ALL') {
    $boards_to_import = array_values($open_boards);
} else {
    $board = array_filter($all_boards, fn($b) => $b['id'] === $board_id_arg);
    if (empty($board)) die("Błąd: nie znaleziono tablicy ID={$board_id_arg}\n");
    $boards_to_import = [array_values($board)[0]];
}

log_ok(count($boards_to_import) . ' tablice do importu: ' . implode(', ', array_column($boards_to_import, 'name')));

// ── 2. Mapowanie e-mail → user_id (opcjonalne) ────────────────────────────────
$email_to_user_id = [];
if ($match_emails) {
    log_head("Krok 2: Dopasowanie członków Trello do użytkowników systemu");
    $sys_users = db_all("SELECT id, email FROM users WHERE is_active=1");
    foreach ($sys_users as $u) {
        $email_to_user_id[strtolower($u['email'])] = (int)$u['id'];
    }
    log_info(count($sys_users) . ' użytkowników w systemie');
}

// ── 3. Import tablic ──────────────────────────────────────────────────────────
$stats = ['boards' => 0, 'lists' => 0, 'tasks' => 0, 'subtasks' => 0, 'comments' => 0, 'tags' => 0, 'assignments' => 0, 'skipped' => 0];

foreach ($boards_to_import as $board) {
    log_head("Tablica: {$board['name']} [{$board['id']}]");

    // ── 3a. Utwórz/użyj workspace ─────────────────────────────────────────────
    $ws_id = $workspace_id;
    if (!$ws_id) {
        // Sprawdź czy workspace o tej nazwie już istnieje
        $existing_ws = db_one("SELECT id FROM task_workspaces WHERE name=?", [$board['name']]);
        if ($existing_ws) {
            $ws_id = (int)$existing_ws['id'];
            log_skip("Workspace \"{$board['name']}\" już istnieje (ID={$ws_id}), dodaję do niego");
        } else {
            if (!$dry_run) {
                $ws_id = db_insert('task_workspaces', [
                    'slug'        => preg_replace('/[^a-z0-9]+/', '-', strtolower($board['name'])) . '-' . substr($board['id'], -4),
                    'name'        => $board['name'],
                    'description' => $board['desc'] ?? '',
                    'color'       => '#2563eb',
                    'icon'        => 'bi-kanban',
                    'is_active'   => 1,
                    'created_by'  => $owner_id,
                ]);
            } else {
                $ws_id = 9999;
            }
            log_ok("Workspace \"{$board['name']}\" utworzony (ID={$ws_id})");
            $stats['boards']++;
        }
    } else {
        log_info("Używam istniejącego workspace ID={$ws_id}");
    }

    // ── 3b. Pobierz listy Trello ──────────────────────────────────────────────
    $lists = trello_get("/boards/{$board['id']}/lists", ['filter' => 'open', 'fields' => 'id,name,pos,closed']);
    log_info(count($lists) . ' list pobranych');

    // Mapowanie listId Trello → task_list id systemu
    $list_id_map = [];
    $pos = 1;
    foreach ($lists as $list) {
        if ($list['closed']) continue;

        $existing_list = $dry_run ? null : db_one(
            "SELECT id FROM task_lists WHERE workspace_id=? AND name=?",
            [$ws_id, $list['name']]
        );
        if ($existing_list) {
            $list_id_map[$list['id']] = (int)$existing_list['id'];
            log_skip("  Lista \"{$list['name']}\" już istnieje");
        } else {
            if (!$dry_run) {
                $list_db_id = db_insert('task_lists', [
                    'workspace_id' => $ws_id,
                    'name'         => $list['name'],
                    'position'     => $pos * 1000,
                    'is_done_state' => (int)(
                        str_contains(strtolower($list['name']), 'done') ||
                        str_contains(strtolower($list['name']), 'gotowe') ||
                        str_contains(strtolower($list['name']), 'zakończone')
                    ),
                ]);
                $list_id_map[$list['id']] = $list_db_id;
            } else {
                $list_id_map[$list['id']] = $pos;
            }
            log_ok("  Lista \"{$list['name']}\"");
            $stats['lists']++;
            $pos++;
        }
    }

    // ── 3c. Pobierz etykiety tablicy ──────────────────────────────────────────
    $board_labels = trello_get("/boards/{$board['id']}/labels", ['fields' => 'id,name,color']);
    $tag_id_map = []; // trello label id → task_tag id

    foreach ($board_labels as $lbl) {
        if (empty($lbl['name'])) continue;
        $tag_name = $lbl['name'];
        $tag_color = '#' . ltrim(match($lbl['color'] ?? '') {
            'red'    => 'ef4444', 'orange' => 'f97316', 'yellow' => 'eab308',
            'green'  => '22c55e', 'blue'   => '3b82f6', 'purple' => 'a855f7',
            'pink'   => 'ec4899', 'sky'    => '0ea5e9', 'lime'   => '84cc16',
            'black'  => '1e293b', default  => '94a3b8',
        }, '#');

        if (!$dry_run) {
            $existing_tag = db_one("SELECT id FROM task_tags WHERE workspace_id=? AND name=?", [$ws_id, $tag_name]);
            if ($existing_tag) {
                $tag_id_map[$lbl['id']] = (int)$existing_tag['id'];
            } else {
                $tag_db_id = db_insert('task_tags', [
                    'workspace_id' => $ws_id,
                    'name'         => $tag_name,
                    'color'        => $tag_color,
                    'text_color'   => '#ffffff',
                    'is_active'    => 1,
                    'created_by'   => $owner_id,
                ]);
                $tag_id_map[$lbl['id']] = $tag_db_id;
                $stats['tags']++;
            }
        }
    }

    // ── 3d. Pobierz karty ────────────────────────────────────────────────────
    $cards_filter = $with_archived ? 'all' : 'open';
    $card_params = [
        'filter'      => $cards_filter,
        'fields'      => 'id,name,desc,due,start,closed,pos,idList,idMembers,idLabels',
        'checklists'  => $with_subtasks  ? 'all'     : 'none',
        'attachments' => 'false',
        'members'     => 'false',
    ];
    if ($with_comments) $card_params['actions'] = 'commentCard';

    $cards = trello_get("/boards/{$board['id']}/cards", $card_params);
    log_info(count($cards) . ' kart pobranych');

    // Mapowanie member Trello → user_id (dla assignments)
    $trello_members = [];
    if ($match_emails) {
        $brd_members = trello_get("/boards/{$board['id']}/members", ['fields' => 'id,email,fullName,username']);
        foreach ($brd_members as $m) {
            $em = strtolower($m['email'] ?? '');
            if ($em && isset($email_to_user_id[$em])) {
                $trello_members[$m['id']] = $email_to_user_id[$em];
                log_info("  Dopasowano: {$m['fullName']} → user_id={$trello_members[$m['id']]}");
            }
        }
    }

    // ── 3e. Import kart ───────────────────────────────────────────────────────
    $pos_counter = [];
    foreach ($cards as $card) {
        $list_sys_id = $list_id_map[$card['idList']] ?? null;
        if (!$list_sys_id) {
            log_skip("  Karta \"{$card['name']}\" — lista nieznana, pomijam");
            $stats['skipped']++;
            continue;
        }

        // Sprawdź czy karta już istnieje (po tytule w tym workspace)
        if (!$dry_run) {
            $exists = db_one(
                "SELECT id FROM tasks WHERE workspace_id=? AND list_id=? AND title=? AND deleted_at IS NULL",
                [$ws_id, $list_sys_id, $card['name']]
            );
            if ($exists) {
                log_skip("  Karta \"{$card['name']}\" już istnieje");
                $stats['skipped']++;
                continue;
            }
        }

        // Wyznacz priorytet na podstawie etykiet
        $priority = 2; // medium default
        foreach ($card['idLabels'] as $lid) {
            $lbl_data = array_filter($board_labels, fn($l) => $l['id'] === $lid);
            if ($lbl_data) {
                $l = array_values($lbl_data)[0];
                $p = $label_priority_map[$l['color'] ?? ''] ?? 2;
                $priority = max($priority, $p);
            }
        }

        $pos_counter[$list_sys_id] = ($pos_counter[$list_sys_id] ?? 0) + 1000;

        $task_data = [
            'workspace_id' => $ws_id,
            'list_id'      => $list_sys_id,
            'title'        => $card['name'],
            'description'  => $card['desc'] ?? '',
            'position'     => $pos_counter[$list_sys_id],
            'priority'     => $priority,
            'due_date'     => $card['due'] ? substr($card['due'], 0, 10) : null,
            'start_date'   => $card['start'] ? substr($card['start'], 0, 10) : null,
            'created_by'   => $owner_id,
            'completed_at' => $card['closed'] ? date('Y-m-d H:i:s') : null,
            'deleted_at'   => null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        if ($dry_run) {
            log_ok("  [DRY] Karta: \"{$card['name']}\" → lista {$list_sys_id} (priorytet={$priority})");
            $stats['tasks']++;
            continue;
        }

        $task_id = db_insert('tasks', $task_data);
        log_ok("  Karta: \"{$card['name']}\" (task_id={$task_id})");
        $stats['tasks']++;

        // ── Etykiety → tagi ──────────────────────────────────────────────────
        foreach ($card['idLabels'] as $lid) {
            if (isset($tag_id_map[$lid])) {
                try {
                    db()->prepare("INSERT OR IGNORE INTO task_task_tags (task_id, tag_id) VALUES (?, ?)")
                        ->execute([$task_id, $tag_id_map[$lid]]);
                } catch (\Throwable $e) {}
            }
        }

        // ── Przypisania (members) ────────────────────────────────────────────
        foreach ($card['idMembers'] as $mid) {
            $uid = $trello_members[$mid] ?? null;
            if ($uid) {
                try {
                    db()->prepare("INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at) VALUES (?, ?, ?, datetime('now','localtime'))")
                        ->execute([$task_id, $uid, $owner_id]);
                    $stats['assignments']++;
                } catch (\Throwable $e) {}
            }
        }

        // ── Checklists → podzadania ──────────────────────────────────────────
        if ($with_subtasks && !empty($card['checklists'])) {
            $sub_pos = 0;
            foreach ($card['checklists'] as $cl) {
                foreach ($cl['checkItems'] ?? [] as $item) {
                    db_insert('task_subtasks', [
                        'task_id'      => $task_id,
                        'title'        => ($cl['name'] !== 'Checklist' ? "[{$cl['name']}] " : '') . $item['name'],
                        'is_done'      => ($item['state'] === 'complete') ? 1 : 0,
                        'position'     => ++$sub_pos * 1000,
                        'created_by'   => $owner_id,
                        'completed_at' => ($item['state'] === 'complete') ? date('Y-m-d H:i:s') : null,
                    ]);
                    $stats['subtasks']++;
                }
            }
        }

        // ── Komentarze ───────────────────────────────────────────────────────
        if ($with_comments && !empty($card['actions'])) {
            foreach ($card['actions'] as $action) {
                if ($action['type'] !== 'commentCard') continue;
                $body   = $action['data']['text'] ?? '';
                $author = $action['memberCreator']['fullName'] ?? 'Trello';
                $at     = substr($action['date'] ?? '', 0, 19);
                if (!$body) continue;

                // Znajdź user_id po e-mailu lub użyj właściciela
                $uid = $owner_id;
                $em  = strtolower($action['memberCreator']['username'] ?? '');
                if ($em && isset($email_to_user_id[$em])) $uid = $email_to_user_id[$em];

                db_insert('task_comments', [
                    'task_id'    => $task_id,
                    'author_id'  => $uid,
                    'body'       => "[Import Trello — {$author}]\n{$body}",
                    'created_at' => $at ?: date('Y-m-d H:i:s'),
                    'updated_at' => $at ?: date('Y-m-d H:i:s'),
                ]);
                $stats['comments']++;
            }
        }
    }
}

// ── Podsumowanie ───────────────────────────────────────────────────────────────
echo "\n";
echo "═══════════════════════════════════════════════════════\n";
if ($dry_run) {
    echo "  DRY RUN — nic nie zostało zapisane do bazy\n";
    echo "  Aby wykonać import, usuń flagę --dry-run\n";
} else {
    echo "  Import zakończony!\n";
}
echo "───────────────────────────────────────────────────────\n";
echo "  Workspace'y: {$stats['boards']}\n";
echo "  Listy:       {$stats['lists']}\n";
echo "  Zadania:     {$stats['tasks']}\n";
echo "  Podzadania:  {$stats['subtasks']}\n";
echo "  Komentarze:  {$stats['comments']}\n";
echo "  Tagi:        {$stats['tags']}\n";
echo "  Przypisania: {$stats['assignments']}\n";
echo "  Pominięte:   {$stats['skipped']}\n";
echo "═══════════════════════════════════════════════════════\n\n";

if (!$dry_run && $stats['tasks'] > 0) {
    echo "  Zadania dostępne w systemie:\n";
    echo "  " . APP_URL . "/tasks/index.php\n\n";
}
