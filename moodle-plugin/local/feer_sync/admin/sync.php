<?php
/**
 * Panel ręcznej synchronizacji i dziennika.
 * Admin → Wtyczki → Lokalne → FEER NGO → Synchronizuj teraz
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/feer_sync/admin/sync.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('sync_page_heading', 'local_feer_sync'));
$PAGE->set_heading(get_string('sync_page_heading', 'local_feer_sync'));
$PAGE->set_pagelayout('admin');

// ── Ręczna synchronizacja ─────────────────────────────────────────────────────
$sync_result = null;
if ($action === 'run' && confirm_sesskey()) {
    set_config('_manual_trigger', '1', 'local_feer_sync');
    $task = new \local_feer_sync\task\sync_task();
    try {
        $task->execute();
        $sync_result = ['ok' => true];
    } catch (\Throwable $e) {
        $sync_result = ['ok' => false, 'error' => $e->getMessage()];
    }
    unset_config('_manual_trigger', 'local_feer_sync');
}

// ── Test połączenia ───────────────────────────────────────────────────────────
$ping_result = null;
if ($action === 'ping' && confirm_sesskey()) {
    try {
        $client      = new \local_feer_sync\api\feer_client();
        $ping_result = $client->ping();
    } catch (\Throwable $e) {
        $ping_result = ['error' => $e->getMessage()];
    }
}

// ── Dane do wyświetlenia ──────────────────────────────────────────────────────
global $DB;
$logs = $DB->get_records('local_feer_sync_log', null, 'timecreated DESC', '*', 0, 20);

$mapped_count = $DB->count_records('local_feer_sync_users');
$last_sync    = get_config('local_feer_sync', 'last_sync_at') ?: '—';
$feer_url     = get_config('local_feer_sync', 'feer_url')    ?: '';
$api_key_set  = (bool)(get_config('local_feer_sync', 'feer_api_key'));

// ── HTML ──────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sync_page_heading', 'local_feer_sync'));

// Alerty wyniku
if ($sync_result !== null) {
    if ($sync_result['ok']) {
        echo $OUTPUT->notification(get_string('changes_saved', 'core'), 'success');
    } else {
        echo $OUTPUT->notification($sync_result['error'], 'error');
    }
}
if ($ping_result !== null) {
    if (isset($ping_result['error'])) {
        echo $OUTPUT->notification('Błąd połączenia: ' . s($ping_result['error']), 'error');
    } else {
        $total = $ping_result['total'] ?? '?';
        echo $OUTPUT->notification("Połączenie OK — system FEER widzi {$total} wolontariuszy.", 'success');
    }
}

// ── Status ────────────────────────────────────────────────────────────────────
echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h5', 'Status', ['class' => 'card-title']);

$status_rows = [
    ['Adres FEER', $feer_url ?: html_writer::tag('span', '— nie ustawiono —', ['class' => 'text-danger'])],
    ['Klucz API', $api_key_set ? html_writer::tag('span', '✓ ustawiony', ['class' => 'text-success']) : html_writer::tag('span', '— brak —', ['class' => 'text-danger'])],
    ['Ostatnia synchronizacja', s($last_sync)],
    ['Konta w mapie (feer→moodle)', $mapped_count],
];

$tbl = new html_table();
$tbl->attributes['class'] = 'table table-sm table-bordered';
foreach ($status_rows as [$k, $v]) {
    $row = new html_table_row();
    $row->cells[] = new html_table_cell(html_writer::tag('strong', $k));
    $row->cells[] = new html_table_cell($v);
    $tbl->data[] = $row;
}
echo html_writer::table($tbl);

// Przyciski akcji
$run_url  = new moodle_url('/local/feer_sync/admin/sync.php', ['action' => 'run',  'sesskey' => sesskey()]);
$ping_url = new moodle_url('/local/feer_sync/admin/sync.php', ['action' => 'ping', 'sesskey' => sesskey()]);
$cfg_url  = new moodle_url('/admin/settings.php', ['section' => 'local_feer_sync']);

echo html_writer::tag('a', '▶ Synchronizuj teraz', ['href' => $run_url,  'class' => 'btn btn-primary me-2',
    'onclick' => "return confirm('Uruchomić synchronizację teraz?')"]);
echo html_writer::tag('a', '⚡ Test połączenia', ['href' => $ping_url, 'class' => 'btn btn-outline-secondary me-2']);
echo html_writer::tag('a', '⚙ Ustawienia',       ['href' => $cfg_url,  'class' => 'btn btn-outline-secondary']);

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// ── Dziennik synchronizacji ───────────────────────────────────────────────────
echo html_writer::tag('h5', get_string('sync_log', 'local_feer_sync'));

if (empty($logs)) {
    echo $OUTPUT->notification('Brak historii synchronizacji.', 'info');
} else {
    $tbl2 = new html_table();
    $tbl2->attributes['class'] = 'table table-sm table-striped';
    $tbl2->head = ['Czas', 'Wyzwolono', 'Status', 'Pobrano', 'Utworzono', 'Zaktualizowano', 'Zawieszono', 'Błędów', 'Komunikat'];

    foreach ($logs as $log) {
        $row   = new html_table_row();
        $ok    = $log->status === 'ok';
        $row->attributes['class'] = $ok ? '' : 'table-danger';

        $row->cells[] = new html_table_cell(userdate($log->timecreated, '%d.%m.%Y %H:%M'));
        $row->cells[] = new html_table_cell($log->trigger === 'manual' ? 'Ręcznie' : 'Harmonogram');
        $row->cells[] = new html_table_cell($ok
            ? html_writer::tag('span', 'OK',    ['class' => 'badge bg-success'])
            : html_writer::tag('span', 'BŁĄD', ['class' => 'badge bg-danger']));
        $row->cells[] = new html_table_cell($log->fetched);
        $row->cells[] = new html_table_cell($log->created);
        $row->cells[] = new html_table_cell($log->updated);
        $row->cells[] = new html_table_cell($log->suspended);
        $row->cells[] = new html_table_cell($log->errors);
        $row->cells[] = new html_table_cell(s($log->message ?? ''));
        $tbl2->data[] = $row;
    }
    echo html_writer::table($tbl2);
}

echo $OUTPUT->footer();
