#!/usr/bin/env php
<?php
/**
 * cli/seed_tasks.php — Dane testowe modułu Zadania
 *
 * Użycie:
 *   php cli/seed_tasks.php [--clean] [--quiet]
 *
 *   --clean   usuń istniejące dane testowe przed seedowaniem
 *   --quiet   mniej komunikatów
 *
 * Tworzy:
 *   • 3 konta testowe (wolontariusz, lider, obserwator)
 *   • 2 obszary robocze
 *   • 4 listy (kolumny) w każdym obszarze
 *   • 15 zadań z różnymi statusami, priorytetami, terminami
 *   • Tagi, przypisania, podzadania, komentarze, zgłoszenia problemów
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Uruchom z linii poleceń: php " . basename(__FILE__) . "\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

$opts  = getopt('', ['clean', 'quiet']);
$clean = isset($opts['clean']);
$quiet = isset($opts['quiet']);

// ── Helpers ─────────────────────────────────────────────────────────────────

$ok = $err = 0;

function log_ok(string $msg): void {
    global $ok, $quiet;
    $ok++;
    if (!$quiet) echo "\033[32m✓\033[0m $msg\n";
}

function log_err(string $msg): void {
    global $err;
    $err++;
    echo "\033[31m✗\033[0m $msg\n";
}

function log_info(string $msg): void {
    global $quiet;
    if (!$quiet) echo "\033[34m→\033[0m $msg\n";
}

function seed_user(string $email, string $name, string $role, string $password): int {
    try {
        $existing = db_one("SELECT id FROM users WHERE email=?", [$email]);
        if ($existing) {
            // Zaktualizuj hasło i dane
            db()->prepare("UPDATE users SET name=?, role=?, password=?, is_active=1 WHERE email=?")
                ->execute([$name, $role, password_hash($password, PASSWORD_BCRYPT), $email]);
            log_ok("Użytkownik (zaktualizowany): $name <$email> [$role] / hasło: $password");
            return (int)$existing['id'];
        }
        $id = db_insert('users', [
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'role'       => $role,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        log_ok("Użytkownik: $name <$email> [$role] / hasło: $password");
        return $id;
    } catch (\Throwable $e) {
        log_err("Użytkownik $email: " . $e->getMessage());
        return 0;
    }
}

// ── Opcja --clean ─────────────────────────────────────────────────────────────

if ($clean) {
    log_info("Czyszczenie danych testowych…");
    try {
        $pdo = db();
        $test_ws = db_all("SELECT id FROM task_workspaces WHERE slug LIKE 'test-%'");
        foreach ($test_ws as $w) {
            $wid = (int)$w['id'];
            $tids = array_column(db_all("SELECT id FROM tasks WHERE workspace_id=?", [$wid]), 'id');
            if ($tids) {
                $ph = implode(',', array_fill(0, count($tids), '?'));
                foreach (['task_task_tags','task_subtasks','task_comments','task_files','task_list_time','task_history','task_assignments'] as $t) {
                    db()->prepare("DELETE FROM $t WHERE task_id IN ($ph)")->execute($tids);
                }
                db()->prepare("DELETE FROM tasks WHERE workspace_id=?")->execute([$wid]);
            }
            db()->prepare("DELETE FROM task_lists             WHERE workspace_id=?")->execute([$wid]);
            db()->prepare("DELETE FROM task_workspace_members WHERE workspace_id=?")->execute([$wid]);
            db()->prepare("DELETE FROM task_tags              WHERE workspace_id=?")->execute([$wid]);
            db()->prepare("DELETE FROM task_workspaces WHERE id=?")->execute([$wid]);
        }
        // Usuń konta testowe
        foreach (['vol.test@feer.test','leader.test@feer.test','viewer.test@feer.test'] as $e) {
            db()->prepare("DELETE FROM users WHERE email=?")->execute([$e]);
        }
        log_ok("Dane testowe wyczyszczone.");
    } catch (\Throwable $e) {
        log_err("Błąd czyszczenia: " . $e->getMessage());
    }
}

echo "\n\033[1m═══ Seeder zadań — dane testowe ═══\033[0m\n\n";

// ════════════════════════════════════════════════════════════════════════════
// 1. KONTA UŻYTKOWNIKÓW
// ════════════════════════════════════════════════════════════════════════════

log_info("Tworzenie kont testowych…");

$uid_vol    = seed_user('vol.test@feer.test',    'Anna Wolontariusz (TEST)', 'viewer', 'Test1234!');
$uid_leader = seed_user('leader.test@feer.test', 'Marek Lider (TEST)',      'editor', 'Leader99!');
$uid_viewer = seed_user('viewer.test@feer.test', 'Zofia Obserwator (TEST)', 'viewer', 'View5678!');

if (!$uid_vol || !$uid_leader) {
    log_err("Nie udało się utworzyć kont. Przerywam.");
    exit(1);
}

// ════════════════════════════════════════════════════════════════════════════
// 2. OBSZARY ROBOCZE
// ════════════════════════════════════════════════════════════════════════════

log_info("Tworzenie obszarów roboczych…");

function seed_workspace(string $slug, string $name, string $color, string $icon, int $creator_uid): int {
    try {
        $existing = db_one("SELECT id FROM task_workspaces WHERE slug=?", [$slug]);
        if ($existing) {
            log_ok("Obszar (już istnieje): $name");
            return (int)$existing['id'];
        }
        $id = db_insert('task_workspaces', [
            'slug'        => $slug,
            'name'        => $name,
            'description' => "Testowy obszar: $name",
            'color'       => $color,
            'icon'        => $icon,
            'is_active'   => 1,
            'created_by'  => $creator_uid,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        log_ok("Obszar: $name (ID: $id)");
        return $id;
    } catch (\Throwable $e) {
        log_err("Obszar $name: " . $e->getMessage());
        return 0;
    }
}

$ws1 = seed_workspace('test-wolontariat', 'TEST — Wolontariat FEER', '#059669', 'bi-people-fill', $uid_leader);
$ws2 = seed_workspace('test-projekty',    'TEST — Projekty Specjalne', '#7c3aed', 'bi-star-fill',  $uid_leader);

// ── Listy (kolumny) ──────────────────────────────────────────────────────────

function seed_lists(int $ws_id): array {
    $lists = [
        ['Nowe zadania',    1, 0, '#e2e8f0'],
        ['W realizacji',    2, 0, '#2563eb'],
        ['Do sprawdzenia',  3, 0, '#f59e0b'],
        ['Ukończone',       4, 1, '#16a34a'],
    ];
    $ids = [];
    foreach ($lists as [$name, $pos, $done, $color]) {
        try {
            $ex = db_one("SELECT id FROM task_lists WHERE workspace_id=? AND name=?", [$ws_id, $name]);
            if ($ex) { $ids[$name] = (int)$ex['id']; continue; }
            $id = db_insert('task_lists', [
                'workspace_id'  => $ws_id, 'name' => $name,
                'position'      => $pos, 'color' => $color,
                'is_done_state' => $done,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $ids[$name] = $id;
        } catch (\Throwable $e) { log_err("Lista $name: " . $e->getMessage()); }
    }
    return $ids;
}

$lists1 = seed_lists($ws1);
$lists2 = seed_lists($ws2);
log_ok("Listy (kolumny) dla obu obszarów.");

// ── Członkowie obszarów ──────────────────────────────────────────────────────

function seed_member(int $ws_id, int $user_id, string $role, int $added_by): void {
    try {
        db()->prepare(
            "INSERT OR REPLACE INTO task_workspace_members
             (workspace_id, user_id, role, added_by, added_at)
             VALUES (?,?,?,?,datetime('now','localtime'))"
        )->execute([$ws_id, $user_id, $role, $added_by]);
    } catch (\Throwable $e) { log_err("Member: " . $e->getMessage()); }
}

foreach ([$ws1, $ws2] as $ws) {
    seed_member($ws, $uid_leader, 'admin',  $uid_leader);
    seed_member($ws, $uid_vol,    'editor', $uid_leader);
    seed_member($ws, $uid_viewer, 'viewer', $uid_leader);
}
log_ok("Członkowie obszarów przypisani.");

// ════════════════════════════════════════════════════════════════════════════
// 3. TAGI
// ════════════════════════════════════════════════════════════════════════════

log_info("Tworzenie tagów…");

function seed_tag(string $name, string $color, string $text_color, ?int $ws_id, int $uid): int {
    try {
        $ex = db_one("SELECT id FROM task_tags WHERE name=? AND (workspace_id=? OR (workspace_id IS NULL AND ? IS NULL))", [$name, $ws_id, $ws_id]);
        if ($ex) return (int)$ex['id'];
        return db_insert('task_tags', [
            'workspace_id' => $ws_id, 'name' => $name,
            'color'        => $color, 'text_color' => $text_color,
            'is_active'    => 1, 'created_by' => $uid,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { log_err("Tag $name: " . $e->getMessage()); return 0; }
}

$tag_pilne   = seed_tag('Pilne',       '#dc2626', '#ffffff', null,  $uid_leader);
$tag_bug     = seed_tag('Do poprawy',  '#f59e0b', '#ffffff', null,  $uid_leader);
$tag_nowe    = seed_tag('Nowe',        '#2563eb', '#ffffff', null,  $uid_leader);
$tag_event   = seed_tag('Wydarzenie',  '#7c3aed', '#ffffff', $ws1,  $uid_leader);
$tag_dok     = seed_tag('Dokumenty',   '#059669', '#ffffff', $ws1,  $uid_leader);
log_ok("Tagi: Pilne, Do poprawy, Nowe, Wydarzenie, Dokumenty.");

// ════════════════════════════════════════════════════════════════════════════
// 4. ZADANIA
// ════════════════════════════════════════════════════════════════════════════

log_info("Tworzenie zadań…");

$now = date('Y-m-d H:i:s');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$next_week = date('Y-m-d', strtotime('+7 days'));
$last_week = date('Y-m-d', strtotime('-7 days'));

function seed_task(array $data): int {
    global $uid_leader, $now;
    try {
        return db_insert('tasks', array_merge([
            'created_by'  => $uid_leader,
            'created_at'  => $now,
            'updated_at'  => $now,
            'deleted_at'  => null,
            'completed_at'=> null,
            'description' => null,
            'due_date'    => null,
            'start_date'  => null,
            'priority'    => 2,
            'position'    => 1,
        ], $data));
    } catch (\Throwable $e) {
        log_err("Zadanie \"{$data['title']}\": " . $e->getMessage());
        return 0;
    }
}

function assign(int $task_id, int $user_id, int $by): void {
    global $now;
    try {
        db()->prepare("INSERT OR IGNORE INTO task_assignments (task_id,user_id,assigned_by,assigned_at) VALUES (?,?,?,?)")
            ->execute([$task_id, $user_id, $by, $now]);
    } catch (\Throwable $e) {}
}

function tag_task(int $task_id, int $tag_id): void {
    try {
        db()->prepare("INSERT OR IGNORE INTO task_task_tags (task_id,tag_id) VALUES (?,?)")->execute([$task_id,$tag_id]);
    } catch (\Throwable $e) {}
}

function add_subtask(int $task_id, string $title, bool $done, int $uid): void {
    global $now;
    try {
        db_insert('task_subtasks', ['task_id'=>$task_id,'title'=>$title,'is_done'=>$done?1:0,'created_by'=>$uid,'created_at'=>$now]);
    } catch (\Throwable $e) {}
}

function add_comment(int $task_id, int $author, string $body): void {
    global $now;
    try {
        db_insert('task_comments', ['task_id'=>$task_id,'author_id'=>$author,'body'=>$body,'created_at'=>$now]);
    } catch (\Throwable $e) {}
}

function log_history(int $task_id, int $uid, string $event, ?string $from=null, ?string $to=null): void {
    global $now;
    try {
        db()->prepare("INSERT INTO task_history (task_id,user_id,event_type,from_value,to_value,occurred_at) VALUES (?,?,?,?,?,?)")
            ->execute([$task_id,$uid,$event,$from,$to,$now]);
    } catch (\Throwable $e) {}
}

// Obszar 1: Wolontariat ──────────────────────────────────────────────────────

// [1] Wolne, krytyczne, po terminie
$t1 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['Nowe zadania'],'title'=>'Potwierdzenie obecności na szkoleniu BHP','priority'=>4,'due_date'=>$yesterday,'description'=>'Każdy wolontariusz musi potwierdzić odbycie szkolenia BHP do końca tygodnia. Skontaktuj się z koordynatorem.']);
tag_task($t1, $tag_pilne);
log_history($t1, $uid_leader, 'created', null, 'Nowe zadania');

// [2] Wolne, normalny
$t2 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['Nowe zadania'],'title'=>'Przygotowanie materiałów informacyjnych','priority'=>2,'due_date'=>$next_week,'description'=>'Zebranie i opracowanie materiałów do nowej ulotki.']);
tag_task($t2, $tag_nowe);
log_history($t2, $uid_leader, 'created', null, 'Nowe zadania');

// [3] Zajęte (przypisane do wola), wysoki priorytet
$t3 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['W realizacji'],'title'=>'Rejestracja uczestników – majowy piknik','priority'=>3,'due_date'=>$next_week,'description'=>'Prowadzenie listy uczestników i potwierdzanie zgłoszeń mailowych.']);
assign($t3, $uid_vol, $uid_leader);
tag_task($t3, $tag_event);
add_subtask($t3, 'Przygotuj arkusz Excel', true, $uid_vol);
add_subtask($t3, 'Wyślij potwierdzenia mailem', false, $uid_vol);
add_subtask($t3, 'Zaktualizuj listę po RSVP', false, $uid_vol);
add_comment($t3, $uid_leader, 'Pamiętaj o wersji dla osób z ograniczeniami ruchowymi.');
add_comment($t3, $uid_vol, 'Już pracuję nad arkuszem, jutro będzie gotowe.');
log_history($t3, $uid_leader, 'created');
log_history($t3, $uid_leader, 'assigned', null, 'Anna Wolontariusz (TEST)');

// [4] Zajęte, lider je sobie wziął
$t4 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['W realizacji'],'title'=>'Koordynacja harmonogramu dyżurów','priority'=>3,'due_date'=>$tomorrow,'description'=>'Przygotowanie grafiku dyżurów na czerwiec dla wszystkich wolontariuszy.']);
assign($t4, $uid_leader, $uid_leader);
add_subtask($t4, 'Zebrać dostępność od wolontariuszy', true, $uid_leader);
add_subtask($t4, 'Ułożyć grafik', false, $uid_leader);
log_history($t4, $uid_leader, 'created');

// [5] Do sprawdzenia
$t5 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['Do sprawdzenia'],'title'=>'Raport z akcji charytatywnej','priority'=>2,'due_date'=>$next_week,'description'=>'Przygotowanie raportu końcowego z lipcowej akcji.']);
assign($t5, $uid_vol, $uid_leader);
tag_task($t5, $tag_dok);
add_comment($t5, $uid_vol, 'Raport gotowy, czeka na akceptację.');
log_history($t5, $uid_leader, 'created');
log_history($t5, $uid_leader, 'assigned', null, 'Anna Wolontariusz (TEST)');

// [6] Ukończone
$t6 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['Ukończone'],'title'=>'Wdrożenie nowego formularza zgłoszeń','priority'=>2,'completed_at'=>$yesterday,'description'=>'Nowy formularz online dla wolontariuszy.']);
assign($t6, $uid_vol, $uid_leader);
add_comment($t6, $uid_vol, 'Formularz działa, można rejestrować zgłoszenia.');
log_history($t6, $uid_leader, 'created');
log_history($t6, $uid_leader, 'completed', null, 'Ukończone');

// [7] Wolne, niski priorytet
$t7 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['Nowe zadania'],'title'=>'Aktualizacja bazy kontaktów wolontariuszy','priority'=>1,'description'=>'Przegląd i aktualizacja danych teleadresowych w rejestrze.']);
tag_task($t7, $tag_dok);
log_history($t7, $uid_leader, 'created');

// [8] Zgłoszony problem (leader_notified)
$t8 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['W realizacji'],'title'=>'Organizacja transportu na event','priority'=>3,'due_date'=>$next_week,'description'=>'Zamówienie busów dla wolontariuszy na wydarzenie w Gdańsku.']);
assign($t8, $uid_vol, $uid_leader);
log_history($t8, $uid_leader, 'created');
log_history($t8, $uid_vol, 'leader_notified', null, 'Nie mam dostępu do systemu rezerwacji autobusów. Proszę o dane logowania.');
log_history($t8, $uid_leader, 'assigned', null, 'Anna Wolontariusz (TEST)');

// Obszar 2: Projekty Specjalne ──────────────────────────────────────────────

// [9] Wolne, krytyczne
$t9 = seed_task(['workspace_id'=>$ws2,'list_id'=>$lists2['Nowe zadania'],'title'=>'Wniosek o dofinansowanie — termin 30.06','priority'=>4,'due_date'=>$next_week,'description'=>'Wypełnienie i złożenie wniosku do funduszu europejskiego.']);
tag_task($t9, $tag_pilne);
log_history($t9, $uid_leader, 'created');

// [10] Zajęte, viewer przypisany (do podglądu)
$t10 = seed_task(['workspace_id'=>$ws2,'list_id'=>$lists2['W realizacji'],'title'=>'Analiza raportów kwartalnych','priority'=>2,'due_date'=>$next_week,'description'=>'Przegląd wyników Q1 i Q2 2026.']);
assign($t10, $uid_viewer, $uid_leader);
add_comment($t10, $uid_leader, 'Skup się na wskaźnikach zatrudnienia.');
log_history($t10, $uid_leader, 'created');

// [11] Wolne, wysoki
$t11 = seed_task(['workspace_id'=>$ws2,'list_id'=>$lists2['Nowe zadania'],'title'=>'Przygotowanie prezentacji dla zarządu','priority'=>3,'due_date'=>$tomorrow]);
tag_task($t11, $tag_pilne);
log_history($t11, $uid_leader, 'created');

// [12] Do sprawdzenia, z podzadaniami
$t12 = seed_task(['workspace_id'=>$ws2,'list_id'=>$lists2['Do sprawdzenia'],'title'=>'Wdrożenie systemu ewidencji czasu pracy','priority'=>3,'description'=>'Implementacja modułu timesheets dla wolontariuszy.']);
add_subtask($t12, 'Projekt techiczny', true, $uid_leader);
add_subtask($t12, 'Implementacja backendu', true, $uid_leader);
add_subtask($t12, 'Testy użytkowników', true, $uid_leader);
add_subtask($t12, 'Wdrożenie produkcyjne', false, $uid_leader);
add_comment($t12, $uid_leader, 'Zostało tylko wdrożenie na produkcji, czeka na akceptację IT.');
log_history($t12, $uid_leader, 'created');

// [13] Ukończone, z historią zmian
$t13 = seed_task(['workspace_id'=>$ws2,'list_id'=>$lists2['Ukończone'],'title'=>'Przegląd umów wolontariackich 2025','priority'=>2,'completed_at'=>date('Y-m-d H:i:s', strtotime('-3 days'))]);
log_history($t13, $uid_leader, 'created');
log_history($t13, $uid_leader, 'priority_changed', 'Niski', 'Normalny');
log_history($t13, $uid_leader, 'completed', null, 'Ukończone');

// [14] Wolne, do wzięcia
$t14 = seed_task(['workspace_id'=>$ws2,'list_id'=>$lists2['Nowe zadania'],'title'=>'Tłumaczenie materiałów na język angielski','priority'=>2,'due_date'=>$next_week,'description'=>'Przetłumacz ulotki i regulaminy na angielski dla gości zagranicznych.']);
log_history($t14, $uid_leader, 'created');

// [15] Transfer (prosba o przejęcie) — do testowania skrzynki
$t15 = seed_task(['workspace_id'=>$ws1,'list_id'=>$lists1['Nowe zadania'],'title'=>'Kontakt z mediami — konferencja prasowa','priority'=>3,'due_date'=>$tomorrow,'description'=>'Nawiązanie kontaktu z lokalnymi mediami i zaproszenie na konferencję.']);
log_history($t15, $uid_leader, 'created');
log_history($t15, $uid_leader, 'takeover_requested', null, 'Anna Wolontariusz (TEST)');

log_ok("15 zadań utworzono.");

// ════════════════════════════════════════════════════════════════════════════
// 5. WIADOMOŚCI WEWNĘTRZNE (skrzynka tasks)
// ════════════════════════════════════════════════════════════════════════════

log_info("Tworzenie wiadomości testowych…");

function seed_msg(int $task_id, int $sender_id, string $sender_name, int $recipient_id, string $subject, string $body): void {
    global $now;
    try {
        db_insert('messages', [
            'context_type'   => 'task',
            'context_id'     => $task_id,
            'contract_type'  => 'task',
            'sender_type'    => 'admin',
            'sender_id'      => $sender_id,
            'sender_name'    => $sender_name,
            'body'           => $body,
            'created_at'     => $now,
            'is_read'        => 0,
            'recipient_type' => 'user',
            'recipient_id'   => $recipient_id,
            'subject'        => $subject,
        ]);
    } catch (\Throwable $e) { log_err("Wiadomość: " . $e->getMessage()); }
}

// Prośba o przejęcie t15
seed_msg($t15, $uid_leader, 'Marek Lider (TEST)', $uid_vol,
    'transfer:' . $t15 . ':Kontakt z mediami — konferencja prasowa',
    "Chce przekazac Ci zadanie: **Kontakt z mediami — konferencja prasowa**\nObszar: TEST — Wolontariat FEER\n\nMoj komentarz:\nMasz świetny kontakt z lokalnymi mediami, będziesz idealna do tego zadania!\n\nZaakceptuj lub odrzuc prosbe korzystajac z przyciskow ponizej."
);

// Problem z t8
seed_msg($t8, $uid_vol, 'Anna Wolontariusz (TEST)', $uid_leader,
    'Problem: Organizacja transportu na event',
    "Zgłoszenie problemu z zadaniem: **Organizacja transportu na event**\nObszar: TEST — Wolontariat FEER\n\nTreść:\nNie mam dostępu do systemu rezerwacji autobusów. Proszę o dane logowania."
);

// Odpowiedź lidera na problem
seed_msg($t8, $uid_leader, 'Marek Lider (TEST)', $uid_vol,
    'Re: Problem — Organizacja transportu na event',
    "Wysyłam Ci dane logowania na Twoją skrzynkę FEER. Hasło startowe: Bus2026#\nZadzwoń jeśli będą problemy."
);

log_ok("3 wiadomości wewnętrzne.");

// ════════════════════════════════════════════════════════════════════════════
// PODSUMOWANIE
// ════════════════════════════════════════════════════════════════════════════

echo "\n\033[1m═══ Podsumowanie ═══\033[0m\n";
echo "\033[32m  OK : $ok\033[0m\n";
if ($err) echo "\033[31m  ERR: $err\033[0m\n";

echo "\n\033[1mKonta testowe:\033[0m\n";
echo "  Wolontariusz : vol.test@feer.test        / Test1234!\n";
echo "  Lider        : leader.test@feer.test     / Leader99!\n";
echo "  Obserwator   : viewer.test@feer.test     / View5678!\n";

echo "\n\033[1mObszary:\033[0m\n";
echo "  TEST — Wolontariat FEER    (ID: $ws1)\n";
echo "  TEST — Projekty Specjalne  (ID: $ws2)\n";

echo "\n\033[1mZadania:\033[0m 15 (mix: wolne, zajęte, po terminie, ukończone, problem, transfer)\n";
echo "\n\033[33mAby wyczyścić dane testowe: php " . basename(__FILE__) . " --clean\033[0m\n\n";

exit($err > 0 ? 1 : 0);
