<?php
/**
 * api/search_quick.php — JSON endpoint dla live-search w topbarze.
 * Zwraca moduły + wyniki danych pasujące do ?q=
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['modules' => [], 'results' => []]);
    exit;
}

$lq   = mb_strtolower($q);
$like = '%' . $q . '%';

// ── Definicja modułów (z kontrolą uprawnień) ──────────────────────────────────
$all_modules = [
    // Ludzie
    ['label'=>'Wolontariusze',   'icon'=>'bi-heart-fill',          'url'=>'/contracts/wolontariat/list.php',  'color'=>'success',   'kw'=>['wolontariat','wolontariusz','porozumienie']],
    ['label'=>'Osoby',           'icon'=>'bi-person-fill',          'url'=>'/persons/index.php',               'color'=>'info',      'kw'=>['osoba','osoby','rejestr']],
    ['label'=>'CRM',             'icon'=>'bi-diagram-2-fill',       'url'=>'/crm/dashboard.php',               'color'=>'success',   'kw'=>['crm','kontakt','klient']],

    // Umowy
    ['label'=>'Umowy zlecenia',  'icon'=>'bi-file-earmark-person-fill','url'=>'/contracts/zlecenie/list.php', 'color'=>'primary',   'kw'=>['zlecenie','umowa']],
    ['label'=>'Umowy o dzieło',  'icon'=>'bi-file-earmark-text-fill','url'=>'/contracts/dzielo/list.php',     'color'=>'warning',   'kw'=>['dzieło','umowa']],
    ['label'=>'Rekrutacja',      'icon'=>'bi-megaphone-fill',       'url'=>'/contracts/rekrutacja/index.php',  'color'=>'primary',   'kw'=>['rekrutacja','kandydat']],

    // Dokumenty i sprawy
    ['label'=>'Zaświadczenia',   'icon'=>'bi-award-fill',           'url'=>'/admin/certificates.php',         'color'=>'warning',   'kw'=>['zaświadczenie','zawol','certyfikat']],
    ['label'=>'Rejestr RODO',    'icon'=>'bi-shield-lock-fill',     'url'=>'/rodo/index.php',                  'color'=>'primary',   'kw'=>['rodo','upoważnienie','przetwarzanie','dane osobowe']],
    ['label'=>'Log usunięć RODO','icon'=>'bi-journal-x',            'url'=>'/rodo/deletion_log.php',           'color'=>'danger',    'kw'=>['rodo','log','usuniecia'], 'admin'=>true],
    ['label'=>'Helpdesk IT',     'icon'=>'bi-headset',              'url'=>'/helpdesk/index.php',              'color'=>'primary',   'kw'=>['helpdesk','zgłoszenie','it','wsparcie']],

    // Finanse
    ['label'=>'Zwroty kosztów',  'icon'=>'bi-receipt-cutoff',       'url'=>'/admin/zwroty.php',                'color'=>'success',   'kw'=>['zwrot','koszty','refundacja']],

    // Zadania i projekty
    ['label'=>'Tablica zadań',   'icon'=>'bi-kanban-fill',          'url'=>'/tasks/index.php',                 'color'=>'secondary', 'kw'=>['zadania','tablica','kanban']],
    ['label'=>'Działania',       'icon'=>'bi-calendar-event-fill',  'url'=>'/actions/index.php',               'color'=>'danger',    'kw'=>['działanie','projekt','event']],
    ['label'=>'Granty',          'icon'=>'bi-coin',                 'url'=>'/grants/index.php',                'color'=>'warning',   'kw'=>['grant','dotacja','projekt']],

    // Admin
    ['label'=>'Wiadomości',      'icon'=>'bi-chat-dots-fill',       'url'=>'/admin/messages.php',              'color'=>'primary',   'kw'=>['wiadomość','czat','message']],
    ['label'=>'Użytkownicy',     'icon'=>'bi-people-fill',          'url'=>'/admin/users.php',                 'color'=>'secondary', 'kw'=>['użytkownik','konto','admin'], 'admin'=>true],
    ['label'=>'Ustawienia RODO', 'icon'=>'bi-gear-fill',            'url'=>'/admin/rodo_settings.php',         'color'=>'secondary', 'kw'=>['ustawienia','rodo','org'], 'admin'=>true],

    // Szukajka pełna
    ['label'=>'Wyszukiwarka globalna','icon'=>'bi-search',          'url'=>'/search.php',                      'color'=>'dark',      'kw'=>['szukaj','wyszukaj','search']],
];

$is_admin = function_exists('is_admin') && is_admin();

// Filtruj po zapytaniu
$matched_modules = [];
foreach ($all_modules as $m) {
    if (!empty($m['admin']) && !$is_admin) continue;
    $text = mb_strtolower($m['label'] . ' ' . implode(' ', $m['kw']));
    if (str_contains($text, $lq)) {
        $matched_modules[] = [
            'label' => $m['label'],
            'icon'  => $m['icon'],
            'url'   => APP_URL . $m['url'],
            'color' => $m['color'],
            'type'  => 'module',
        ];
    }
}
// Ogranicz do 6
$matched_modules = array_slice($matched_modules, 0, 6);

// ── Dane — szybkie wyniki ─────────────────────────────────────────────────────
$data_results = [];

// Wolontariusze
try {
    $rows = db_all(
        "SELECT id, imie_nazwisko, numer_umowy, email FROM umowy_wolontariat
         WHERE (imie_nazwisko LIKE ? OR numer_umowy LIKE ? OR email LIKE ?) LIMIT 4",
        [$like, $like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label'    => $r['imie_nazwisko'],
            'sub'      => ($r['numer_umowy'] ?: '') . ($r['email'] ? ' · ' . $r['email'] : ''),
            'icon'     => 'bi-heart',
            'color'    => 'success',
            'badge'    => 'Wolontariat',
            'url'      => APP_URL . '/contracts/wolontariat/view.php?id=' . (int)$r['id'],
            'type'     => 'data',
        ];
    }
} catch (\Throwable $e) {}

// Osoby
try {
    $rows = db_all(
        "SELECT id, imie_nazwisko, email FROM persons
         WHERE imie_nazwisko LIKE ? OR email LIKE ? LIMIT 3",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label' => $r['imie_nazwisko'],
            'sub'   => $r['email'] ?? '',
            'icon'  => 'bi-person',
            'color' => 'info',
            'badge' => 'Osoba',
            'url'   => APP_URL . '/persons/view.php?id=' . (int)$r['id'],
            'type'  => 'data',
        ];
    }
} catch (\Throwable $e) {}

// CRM
try {
    $rows = db_all(
        "SELECT id, imie_nazwisko, email FROM crm_contacts
         WHERE imie_nazwisko LIKE ? OR email LIKE ? LIMIT 3",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label' => $r['imie_nazwisko'],
            'sub'   => $r['email'] ?? '',
            'icon'  => 'bi-diagram-2',
            'color' => 'success',
            'badge' => 'CRM',
            'url'   => APP_URL . '/crm/contacts/view.php?id=' . (int)$r['id'],
            'type'  => 'data',
        ];
    }
} catch (\Throwable $e) {}

// Helpdesk
try {
    $rows = db_all(
        "SELECT id, number, title FROM helpdesk_tickets
         WHERE (number LIKE ? OR title LIKE ?) LIMIT 3",
        [$like, $like]
    );
    foreach ($rows as $r) {
        $data_results[] = [
            'label' => $r['title'],
            'sub'   => $r['number'],
            'icon'  => 'bi-headset',
            'color' => 'primary',
            'badge' => 'Helpdesk',
            'url'   => APP_URL . '/helpdesk/view.php?id=' . (int)$r['id'],
            'type'  => 'data',
        ];
    }
} catch (\Throwable $e) {}

// Ogranicz dane do 8
$data_results = array_slice($data_results, 0, 8);

echo json_encode([
    'modules' => $matched_modules,
    'results' => $data_results,
    'query'   => $q,
    'search_url' => APP_URL . '/search.php?q=' . urlencode($q),
], JSON_UNESCAPED_UNICODE);
