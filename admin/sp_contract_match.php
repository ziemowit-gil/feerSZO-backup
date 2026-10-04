<?php
/**
 * admin/sp_contract_match.php — skany z SharePointa → umowy bez pliku: propozycje do akceptacji.
 *
 * Umowy wgrane do systemu bez pliku (albo z plikiem utraconym) mają często skan położony na SharePoincie ręcznie.
 * Ekran buduje lokalny indeks plików biblioteki (SpIndex), porównuje z nim takie umowy (SpMatcher) i pokazuje propozycje
 * z oceną pewności i uzasadnieniem. NIC nie jest przypisywane automatycznie: administrator zaznacza plik przy umowie
 * i klika „Przypisz” — wtedy plik jest pobierany, sprawdzany i zapisywany jako plik umowy. Odrzucone propozycje nie wracają.
 *
 * Logika: modules/sp_files/logic (spIndex, spMatcher), widok: modules/sp_files/views/matchView.php.
 * To samo z konsoli (podgląd): cli/sp_match_contracts.php. Odzyskiwanie plików NADANYCH przez aplikację
 * (znana nazwa i ścieżka) robi admin/sp_contract_files.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/modules/sp_files/logic/spMatcher.php';
require_once dirname(__DIR__) . '/modules/sp_connection/logic/spConnection.php';

require_role('admin');
ika_require(APP_URL . '/admin/sp_contract_match.php', 3600);
$PAGE_TITLE = 'Skany z SharePointa — umowy bez pliku';

const SPM_PAGE         = 15;   // umów na stronę
const SPM_ATTACH_BATCH = 10;   // przypisań na jedno kliknięcie (każde to pobranie pliku z SharePointa)

// Bez połączenia z SharePointem nie da się zbudować indeksu ani pobrać skanów — zamiast formularzy panel blokady z linkiem do ustawień
if (!SpConnection::isConnected()) {
    include dirname(__DIR__) . '/includes/header.php';
    echo '<h1 class="h4 mb-3"><i class="bi bi-file-earmark-arrow-down text-primary" aria-hidden="true"></i> Skany z SharePointa — umowy bez pliku</h1>';
    SpConnection::renderBlocked('Skany z SharePointa → umowy bez pliku', 'Bez połączenia nie da się zbudować indeksu plików biblioteki ani pobrać skanów do umów.');
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$self    = 'sp_contract_match.php';
$labels  = defined('CONTRACT_TYPES') ? CONTRACT_TYPES : [];
$in       = fn(string $k): string => (string)($_POST[$k] ?? $_GET[$k] ?? '');          // POST przed GET; bez $_REQUEST (mogą w nim być ciasteczka)
$pick    = fn(string $k, array $allowed, string $def): string => in_array($in($k), $allowed, true) ? $in($k) : $def;
$scope   = $pick('scope', ['empty', 'lost', 'all'], 'empty');
$type    = isset($labels[$in('type')]) ? $in('type') : '';
$min     = $pick('min', ['high', 'medium', 'low'], 'medium');
$page    = max(1, (int)$in('page'));
$filters = ['scope' => $scope, 'type' => $type, 'min' => $min];
$url     = fn(array $extra = []): string => $self . '?' . http_build_query(array_merge($filters, $extra));
$ready   = SpFiles::canRestore();
$uid     = (int)(current_user()['id'] ?? 0);
$fmtSize = fn(int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' MB' : ($b >= 1024 ? number_format($b / 1024, 0, ',', ' ') . ' KB' : $b . ' B');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['_action'] ?? '');
    $lines  = [];                                  // wyniki pojedynczych pozycji: ['ok' => bool, 'text' => string]
    $flash  = ['info', ''];
    $needsSp = in_array($action, ['reindex', 'reindex_continue', 'attach'], true);

    if ($needsSp && !$ready) {
        $flash = ['danger', 'Integracja SharePoint jest wyłączona lub niekompletna — włącz ją w Administracja → SharePoint.'];
    } elseif ($action === 'reindex' || $action === 'reindex_continue') {
        $r = SpIndex::refresh(20, $action === 'reindex');
        $flash = match ($r['state']) {
            'complete' => ['success', 'Indeks gotowy: ' . $r['files'] . ' plików w ' . $r['folders'] . ' folderach.'],
            'running'  => ['info', 'Indeksowanie trwa — przejrzano ' . $r['folders'] . ' folderów, zindeksowano ' . $r['files'] . ' plików, w kolejce zostało ' . $r['queue'] . ' folderów. Kliknij „Kontynuuj indeksowanie”.'],
            'partial'  => ['warning', 'Indeks jest niepełny: ' . $r['message']],
            'busy'     => ['warning', $r['message']],
            default    => ['danger', 'Indeksowanie nie powiodło się: ' . $r['message']],
        };
    } elseif ($action === 'reindex_abort') {
        SpIndex::abort();
        $flash = ['info', 'Przerwano indeksowanie. Dotychczasowy indeks został bez zmian.'];
    } elseif ($action === 'save_folder') {
        try {
            $f = SpIndex::setStartFolder((string)($_POST['folder'] ?? ''));
            $flash = ['success', ($f === '' ? 'Zakres: cała biblioteka.' : 'Zakres: folder „' . $f . '”.') . ' Odśwież indeks, żeby zastosować zmianę.'];
        } catch (\InvalidArgumentException $e) {
            $flash = ['danger', $e->getMessage()];
        }
    } elseif ($action === 'clear_rejections') {
        $flash = ['success', 'Przywrócono odrzucone propozycje: ' . SpMatcher::clearRejections() . '.'];
    } elseif ($action === 'attach' || $action === 'reject') {
        // tylko pary, które są w AKTUALNYCH propozycjach (te same filtry) — z formularza nie przyjmujemy dowolnych par umowa–plik
        $plan  = SpMatcher::proposals($filters + ['per_contract' => 20]);
        $valid = [];
        foreach ($plan['rows'] as $row) {
            foreach ($row['candidates'] as $cd) {
                $valid[$row['contract']['key'] . '|' . $cd['file']['item_id']] = ['number' => $row['contract']['number'] ?: ('#' . $row['contract']['id']), 'score' => $cd['score'], 'file' => $cd['file']['name']];
            }
        }
        $picked = array_filter((array)($_POST['pick'] ?? []), fn($v, $k) => is_string($v) && $v !== '' && is_string($k), ARRAY_FILTER_USE_BOTH);
        $done = 0; $tried = 0;
        if (!$picked) {
            $flash = ['warning', 'Nie wybrano żadnego pliku.'];
        } else {
            foreach ($picked as $key => $item) {
                if (!preg_match('~^([a-z]+):(\d+)$~', (string)$key, $m)) continue;
                if ($action === 'attach' && $tried >= SPM_ATTACH_BATCH) {
                    $lines[] = ['ok' => false, 'text' => 'Pominięto pozostałe pozycje — jednorazowo przypisujemy do ' . SPM_ATTACH_BATCH . ' plików. Zaznacz je ponownie.'];
                    break;
                }
                $tried++;
                $v     = $valid[$key . '|' . $item] ?? null;
                $label = $v['number'] ?? (string)$key;
                if (!$v) { $lines[] = ['ok' => false, 'text' => $label . ': propozycja jest nieaktualna (umowa ma już plik albo zmienił się indeks) — odśwież stronę.']; continue; }
                if ($action === 'reject') {
                    SpMatcher::reject($m[1], (int)$m[2], (string)$item, $uid);
                    $done++;
                    continue;
                }
                $r = SpMatcher::attach($m[1], (int)$m[2], (string)$item, $uid, (int)$v['score']);
                if ($r['status'] === 'attached') { $done++; $lines[] = ['ok' => true, 'text' => $label . ': przypisano plik „' . $v['file'] . '” (' . $fmtSize((int)$r['bytes']) . ').']; }
                else $lines[] = ['ok' => false, 'text' => $label . ': ' . $r['message']];
            }
            $flash = $action === 'reject'
                ? ['success', 'Odrzucono propozycje: ' . $done . '. Nie pojawią się ponownie (można je przywrócić na dole strony).']
                : [$done === $tried ? 'success' : 'warning', 'Przypisano ' . $done . ' z ' . $tried . ' wybranych plików.'];
        }
    }
    auth_start();
    $_SESSION['spm_result'] = ['flash' => $flash, 'lines' => $lines];
    header('Location: ' . $url(['page' => $page]));
    exit;
}

// wynik poprzedniego żądania (wzorzec Post/Redirect/Get — odświeżenie strony nie powtarza akcji)
auth_start();
$result = $_SESSION['spm_result'] ?? null;
unset($_SESSION['spm_result']);

$st        = SpIndex::status();
$folder    = SpIndex::startFolder();
$hasIndex  = $st['files'] > 0 || $st['app_files'] > 0;
$plan      = $hasIndex ? SpMatcher::proposals($filters) : ['rows' => [], 'unmatched' => [], 'stats' => ['targets' => 0, 'with_proposals' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'files' => 0]];
$rows      = $plan['rows'];
$pages     = max(1, (int)ceil(count($rows) / SPM_PAGE));
$page      = min($page, $pages);
$pageRows  = array_slice($rows, ($page - 1) * SPM_PAGE, SPM_PAGE);
$rejCount  = SpMatcher::rejectionCount();

include dirname(__DIR__) . '/includes/header.php';
include dirname(__DIR__) . '/modules/sp_files/views/matchView.php';
include dirname(__DIR__) . '/includes/footer.php';
