<?php
/**
 * cli/seedZadaniaPowiadomienia.php — 3 przykładowe zadania z powiadomieniami, dokładnie jak od zwykłego użytkownika.
 *
 * Każde zadanie powstaje przez to samo API co formularz „Nowe zadanie” (tasks/api/task.php, action=create),
 * zalogowane jako wskazana osoba — więc idą te same powiadomienia: do przypisanych (task_notify_assigned)
 * i do liderów obszaru (task_notify_created), e-mail/SMS/push wg ich ustawień, wpisy w historii zadania.
 *
 *   php cli/seedZadaniaPowiadomienia.php --as=jan@feer.org.pl                              # podgląd (nic nie zapisuje)
 *   php cli/seedZadaniaPowiadomienia.php --as=jan@feer.org.pl --assign=ola@feer.org.pl --apply
 *   php cli/seedZadaniaPowiadomienia.php --as=… --ws=3 --assign=a@…,b@… --apply             # konkretny obszar, kilka osób
 *
 *   --as      autor zadań (musi być liderem/edytorem obszaru — jak w aplikacji)
 *   --ws      ID obszaru (domyślnie pierwszy, w którym autor może dodawać zadania)
 *   --assign  komu przypisać (e-maile po przecinku); domyślnie pierwszy inny członek obszaru
 *
 * Usuwanie przykładowych zadań (tylko z tego seedera — znacznik w opisie; usunięcie miękkie jak w aplikacji):
 *   php cli/seedZadaniaPowiadomienia.php --delete            # lista
 *   php cli/seedZadaniaPowiadomienia.php --delete --apply    # usunięcie
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
// Testy na kopii bazy: SEED_DB_PATH=/ścieżka/kopia.db (dziedziczą też procesy potomne)
if (($_seed_db = getenv('SEED_DB_PATH')) && !defined('DB_PATH')) define('DB_PATH', $_seed_db);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/config.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/functions.php';
require_once $ROOT . '/includes/tasks.php';

const SEED_MARK = '[przykład z CLI: seedZadaniaPowiadomienia]';

$o = getopt('', ['as:', 'ws:', 'assign:', 'apply', 'delete', 'child:']);

// ── Tryb potomny: jedno żądanie API jako zalogowany użytkownik (osobny proces, bo API kończy się exit) ──
if (isset($o['child'])) {
    $req = json_decode(base64_decode((string)$o['child']), true) ?: [];
    $u = db_one("SELECT * FROM users WHERE id=?", [(int)($req['uid'] ?? 0)]);
    if (!$u) { echo json_encode(['ok' => false, 'error' => 'Brak użytkownika.']); exit(1); }
    $_SERVER += ['HTTP_HOST' => parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'seedZadaniaPowiadomienia'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/tasks/api/task.php';
    $_SERVER['SCRIPT_NAME'] = '/tasks/api/task.php';
    auth_start(); // PRZED seedem sesji — inaczej session_start() ją wyczyści
    $_SESSION['user'] = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'],
                         'microsoft_id' => $u['microsoft_id'] ?? '', 'portal_scope' => $u['portal_scope'] ?? null];
    $_SESSION['ctx_decided'] = 1;
    $_SESSION['_webauthn_auth'] = 1;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $GLOBALS['TASK_CLI_JSON_BODY'] = $req['body'] + ['_csrf' => $_SESSION['csrf']];
    define('SKIP_CONSENT_CHECK', true);
    require $ROOT . '/tasks/api/task.php';
    exit;
}

// ── Usuwanie przykładowych zadań ──
if (isset($o['delete'])) {
    $rows = db_all("SELECT id, title, workspace_id FROM tasks WHERE deleted_at IS NULL AND description LIKE ? ORDER BY id", ['%' . SEED_MARK . '%']);
    if (!$rows) { echo "Brak przykładowych zadań do usunięcia.\n"; exit(0); }
    foreach ($rows as $r) echo "  #{$r['id']} {$r['title']} (obszar {$r['workspace_id']})\n";
    if (!isset($o['apply'])) { echo "\nPodgląd — nic nie usunięto. Dodaj --apply.\n"; exit(0); }
    db()->prepare("UPDATE tasks SET deleted_at=datetime('now','localtime') WHERE description LIKE ? AND deleted_at IS NULL")->execute(['%' . SEED_MARK . '%']);
    echo "Usunięte zadania: " . count($rows) . ".\n";
    exit(0);
}

// ── Autor ──
$as = mb_strtolower(trim((string)($o['as'] ?? '')));
if ($as === '') { fwrite(STDERR, "Podaj autora: --as=email (zob. nagłówek pliku).\n"); exit(1); }
$author = db_one("SELECT * FROM users WHERE LOWER(email)=? AND COALESCE(is_active,1)=1", [$as]);
if (!$author) { fwrite(STDERR, "Nie ma aktywnego konta {$as}.\n"); exit(1); }
$aid = (int)$author['id'];

// ── Obszar, w którym autor może dodawać (rola lider/edytor — jak task_require_workspace_access w API) ──
$wsList = task_user_workspaces($aid);
$canAdd = array_values(array_filter($wsList, fn($w) => in_array(task_workspace_role((int)$w['id'], $aid), ['admin', 'editor'], true)));
$ws = null;
if (isset($o['ws'])) {
    foreach ($canAdd as $w) if ((int)$w['id'] === (int)$o['ws']) $ws = $w;
    if (!$ws) { fwrite(STDERR, "{$as} nie może dodawać zadań w obszarze #{$o['ws']}.\n"); exit(1); }
} else {
    $ws = $canAdd[0] ?? null;
    if (!$ws) { fwrite(STDERR, "{$as} nie jest liderem ani edytorem żadnego obszaru — w aplikacji też nie dodałby zadania.\n"); exit(1); }
}
$wsId = (int)$ws['id'];
$list = db_one("SELECT id, name FROM task_lists WHERE workspace_id=? AND COALESCE(is_done_state,0)=0 ORDER BY position LIMIT 1", [$wsId]);
if (!$list) { fwrite(STDERR, "Obszar #{$wsId} nie ma żadnej otwartej kolumny.\n"); exit(1); }

// ── Przypisani ──
$assign = [];
if (isset($o['assign'])) {
    foreach (array_filter(array_map('trim', explode(',', mb_strtolower((string)$o['assign'])))) as $em) {
        $u = db_one("SELECT id, name, email FROM users WHERE LOWER(email)=? AND COALESCE(is_active,1)=1", [$em]);
        if (!$u) { fwrite(STDERR, "Nie ma aktywnego konta {$em}.\n"); exit(1); }
        $assign[] = $u;
    }
} else {
    $u = db_one("SELECT u.id, u.name, u.email FROM task_workspace_members m JOIN users u ON u.id=m.user_id
                  WHERE m.workspace_id=? AND u.id<>? AND COALESCE(u.is_active,1)=1 ORDER BY m.user_id LIMIT 1", [$wsId, $aid]);
    if ($u) $assign[] = $u;
}

$tasks = [
    ['title' => 'Przygotować zestawienie godzin za ' . ['','styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'][(int)date('n')],
     'description' => "Zebrać godziny z ewidencji i przesłać do księgowości.", 'priority' => 3, 'due_date' => date('Y-m-d', strtotime('+3 days'))],
    ['title' => 'Sprawdzić skrzynkę organizacji i rozdzielić korespondencję',
     'description' => "Przejrzeć nowe wiadomości i przekazać sprawy właściwym osobom.", 'priority' => 2, 'due_date' => date('Y-m-d', strtotime('+7 days'))],
    ['title' => 'Pilne: oddzwonić do kontrahenta w sprawie umowy',
     'description' => "Kontrahent prosi o kontakt przed podpisaniem aneksu.", 'priority' => 4, 'due_date' => date('Y-m-d', strtotime('+1 day'))],
];

echo "Autor:      {$author['name']} <{$author['email']}>\n";
echo "Obszar:     {$ws['name']} (#{$wsId}), kolumna „{$list['name']}”\n";
echo "Przypisani: " . ($assign ? implode(', ', array_map(fn($u) => "{$u['name']} <{$u['email']}>", $assign)) : '— nikt (powiadomienie tylko do liderów obszaru)') . "\n";
foreach ($tasks as $i => $t) echo '  ' . ($i + 1) . ". {$t['title']} (priorytet {$t['priority']}, termin {$t['due_date']})\n";
if (!isset($o['apply'])) { echo "\nPodgląd — nic nie zapisano ani nie wysłano. Dodaj --apply.\n"; exit(0); }

echo "\nTworzę zadania i wysyłam powiadomienia…\n";
$fail = 0;
foreach ($tasks as $t) {
    $body = ['action' => 'create', 'workspace_id' => $wsId, 'list_id' => (int)$list['id'],
             'title' => $t['title'], 'description' => $t['description'] . "\n\n" . SEED_MARK,
             'priority' => $t['priority'], 'due_date' => $t['due_date'], 'assignees' => array_map(fn($u) => (int)$u['id'], $assign)];
    $arg = base64_encode(json_encode(['uid' => $aid, 'body' => $body], JSON_UNESCAPED_UNICODE));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child=' . escapeshellarg($arg) . ' 2>&1');
    $json = json_decode((string)substr((string)$out, (int)strpos((string)$out, '{"ok"')), true);
    if (!empty($json['ok'])) {
        echo "  ✓ #{$json['data']['id']} {$t['title']}\n";
    } else {
        $fail++;
        echo "  ✗ {$t['title']}: " . ($json['error'] ?? trim((string)$out)) . "\n";
    }
}
echo $fail ? "\nNie udało się: {$fail}.\n" : "\nGotowe. Usunięcie: php cli/seedZadaniaPowiadomienia.php --delete --apply\n";
exit($fail ? 1 : 0);
