<?php
/**
 * modules/helpdeskMacros/lib/helpdeskMacros.php — makra Helpdesku: gotowa odpowiedź + akcje na zgłoszeniu.
 *
 * Makro (helpdesk_macros) ma treść (może być pusta) oraz opcjonalne akcje: status, priorytet, przypisanie do siebie, tag.
 * Przyciski makr w karcie zgłoszenia: kliknięcie wstawia treść do pola odpowiedzi i zapamiętuje makro — akcje wykonują się
 * razem z wysłaniem wiadomości; makro bez treści (same akcje) działa od razu. Przypięte makra (show_button=1) są przyciskami
 * stale widocznymi nad polem odpowiedzi; gdy żadne nie jest przypięte, pokazujemy pierwsze pięć aktywnych.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 3) . '/modules/helpdeskCollab/lib/helpdeskCollab.php';

function hdMacrosMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    foreach ([
        "ALTER TABLE helpdesk_macros ADD COLUMN act_status TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_priority TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_assign_me INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_tag TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_macros ADD COLUMN show_button INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_steps TEXT NOT NULL DEFAULT ''",
    ] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
}

/** Aktywne makra z akcjami, w kolejności wyświetlania. */
function hdMacrosActive(): array
{
    hdMacrosMigrate();
    return db_all("SELECT * FROM helpdesk_macros WHERE is_active=1 ORDER BY sort_order, title");
}

/** Makra jako przyciski: przypięte, a gdy brak — pierwsze 5. */
function hdMacroButtons(array $macros): array
{
    $pinned = array_values(array_filter($macros, fn($m) => (int)($m['show_button'] ?? 0) === 1));
    return $pinned ?: array_slice($macros, 0, 5);
}

/** Typy kroków przebiegu (makra i reguły automatyczne): kod => etykieta. */
const HD_MACRO_STEP_TYPES = [
    'status' => 'Ustaw status', 'priority' => 'Ustaw priorytet', 'category' => 'Zmień kategorię',
    'assign_me' => 'Przypisz do wykonującego', 'assign_user' => 'Przypisz do operatora',
    'tag' => 'Dodaj tag', 'untag' => 'Usuń tag', 'note' => 'Dodaj notatkę wewnętrzną', 'watch' => 'Dodaj obserwatora (operator)',
    'notify' => 'Powiadom operatora e-mailem', 'reply' => 'Odpowiedz zgłaszającemu (wiadomość publiczna)', 'macro' => 'Wykonaj inne makro',
];

/** Kroki przebiegu: z act_steps (JSON) albo — dla starszych makr — z pojedynczych pól act_*. @return list<array{type:string,value:string}> */
function hdMacroSteps(array $m): array
{
    $json = trim((string)($m['act_steps'] ?? ''));
    if ($json !== '') {
        $d = json_decode($json, true);
        if (is_array($d)) return array_values(array_filter(array_map(fn($x) => isset($x['type']) && isset(HD_MACRO_STEP_TYPES[$x['type']]) ? ['type' => (string)$x['type'], 'value' => (string)($x['value'] ?? '')] : null, $d)));
    }
    $out = [];
    if (($m['act_status'] ?? '') !== '') $out[] = ['type' => 'status', 'value' => (string)$m['act_status']];
    if (($m['act_priority'] ?? '') !== '') $out[] = ['type' => 'priority', 'value' => (string)$m['act_priority']];
    if (!empty($m['act_assign_me'])) $out[] = ['type' => 'assign_me', 'value' => ''];
    if (($m['act_tag'] ?? '') !== '') $out[] = ['type' => 'tag', 'value' => (string)$m['act_tag']];
    return $out;
}

function hdMacroStepLabel(array $st): string
{
    $v = $st['value'];
    return match ($st['type']) {
        'status' => 'status → ' . (HD_STATUSES[$v]['label'] ?? $v),
        'priority' => 'priorytet → ' . (HD_PRIORITIES[$v]['label'] ?? $v),
        'assign_me' => 'przypisz do mnie',
        'assign_user' => 'przypisz: ' . (db_one("SELECT name FROM users WHERE id=?", [(int)$v])['name'] ?? '#' . $v),
        'tag' => 'tag: ' . $v, 'untag' => 'usuń tag: ' . $v,
        'note' => 'notatka',
        'category' => 'kategoria → ' . (hd_categories()[$v] ?? $v),
        'notify' => 'powiadom: ' . (db_one("SELECT name FROM users WHERE id=?", [(int)$v])['name'] ?? '#' . $v),
        'reply' => 'odpowiedź do zgłaszającego',
        'macro' => 'makro: ' . (db_one("SELECT title FROM helpdesk_macros WHERE id=?", [(int)$v])['title'] ?? '#' . $v),
        'watch' => 'obserwator: ' . (db_one("SELECT name FROM users WHERE id=?", [(int)$v])['name'] ?? '#' . $v),
        default => $st['type'],
    };
}

/** Czytelny opis przebiegu makra (do podpowiedzi na przycisku). */
function hdMacroActionLabels(array $m): array
{
    return array_map('hdMacroStepLabel', hdMacroSteps($m));
}

function hdMacroHasActions(array $m): bool
{
    return (bool)hdMacroSteps($m);
}

/**
 * Pola zapisu makra z formularza administracyjnego. Kroki przychodzą jako równoległe tablice
 * (step_type[], sv_status[], sv_priority[], sv_user[], sv_text[]) — wiersz i = jeden krok. Starsze pola act_* zerujemy.
 */
function hdMacroActionFields(array $in): array
{
    $steps = [];
    foreach ((array)($in['step_type'] ?? []) as $i => $type) {
        if (!isset(HD_MACRO_STEP_TYPES[$type])) continue;
        $val = match ($type) {
            'status' => (string)($in['sv_status'][$i] ?? ''),
            'priority' => (string)($in['sv_priority'][$i] ?? ''),
            'assign_user', 'watch', 'notify' => (string)(int)($in['sv_user'][$i] ?? 0),
            'category' => (string)($in['sv_category'][$i] ?? ''),
            'macro' => (string)(int)($in['sv_macro'][$i] ?? 0),
            'reply' => mb_substr(trim((string)($in['sv_text'][$i] ?? '')), 0, 3000),
            'tag', 'untag' => mb_substr(hdTagNormalize((string)($in['sv_text'][$i] ?? '')), 0, 30),
            'note' => mb_substr(trim((string)($in['sv_text'][$i] ?? '')), 0, 1000),
            default => '',
        };
        if ($type === 'status' && !isset(HD_STATUSES[$val])) continue;
        if ($type === 'priority' && !isset(HD_PRIORITIES[$val])) continue;
        if (in_array($type, ['assign_user', 'watch', 'notify'], true) && !db_one("SELECT id FROM users WHERE id=? AND is_active=1", [(int)$val])) continue;
        if ($type === 'category' && !isset(hd_categories()[$val])) continue;
        if ($type === 'macro' && !db_one("SELECT id FROM helpdesk_macros WHERE id=?", [(int)$val])) continue;
        if (in_array($type, ['tag', 'untag', 'note', 'reply'], true) && $val === '') continue;
        $steps[] = ['type' => $type, 'value' => $val];
        if (count($steps) >= 12) break;
    }
    return [
        'act_steps' => $steps ? json_encode($steps, JSON_UNESCAPED_UNICODE) : '',
        'act_status' => '', 'act_priority' => '', 'act_assign_me' => 0, 'act_tag' => '',
        'show_button' => !empty($in['show_button']) ? 1 : 0,
    ];
}

/** Podstawia {{numer}}, {{temat}}, {{zglaszajacy}} w tekście kroku. */
function hdStepText(string $text, array $t): string
{
    return strtr($text, ['{{numer}}' => (string)$t['number'], '{{temat}}' => (string)$t['title'], '{{zglaszajacy}}' => (string)($t['requester_name'] ?? '')]);
}

/**
 * Wykonuje kroki na zgłoszeniu. $uid = operator wykonujący (0 = automat/reguła). Zwraca listę wykonanych zmian.
 * Zabezpieczenie przed pętlami: makro w makrze do głębokości 3; zmiany z kroków nie uruchamiają reguł automatycznych.
 */
function hdStepsRun(int $ticketId, array $steps, int $uid, string $uname, int $depth = 0): array
{
    hdMacrosMigrate();
    $done = []; $now = date('Y-m-d H:i:s');
    foreach ($steps as $st) {
        $v = (string)$st['value'];
        $cur = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        if (!$cur) break;
        switch ($st['type']) {
            case 'status':
                if (isset(HD_STATUSES[$v]) && $v !== $cur['status']) {
                    $upd = ['status' => $v, 'updated_at' => $now];
                    if ($v === 'rozwiązane') $upd['resolved_at'] = $now;
                    if ($v === 'zamknięte') $upd['closed_at'] = $now;
                    db_update('helpdesk_tickets', $upd, $ticketId); $done[] = hdMacroStepLabel($st);
                    $GLOBALS['_hdStepsStatus'][$ticketId] = [$GLOBALS['_hdStepsStatus'][$ticketId][0] ?? $cur['status'], $v];
                }
                break;
            case 'priority':
                if (isset(HD_PRIORITIES[$v]) && $v !== $cur['priority']) { db_update('helpdesk_tickets', ['priority' => $v, 'updated_at' => $now], $ticketId); $done[] = hdMacroStepLabel($st); }
                break;
            case 'category':
                if (isset(hd_categories()[$v]) && $v !== $cur['category']) { db_update('helpdesk_tickets', ['category' => $v, 'updated_at' => $now], $ticketId); $done[] = hdMacroStepLabel($st); }
                break;
            case 'assign_me':
            case 'assign_user':
                $to = $st['type'] === 'assign_me' ? $uid : (int)$v;
                if ($to > 0 && (int)$cur['assigned_to'] !== $to && !(empty($v) && $st['type'] === 'assign_user')) {
                    $upd = ['assigned_to' => $to, 'updated_at' => $now];
                    if ($cur['status'] === 'nowe') $upd['status'] = 'otwarte';
                    db_update('helpdesk_tickets', $upd, $ticketId);
                    $done[] = $st['type'] === 'assign_me' ? 'przypisano do ' . $uname : hdMacroStepLabel($st);
                    $op = db_one("SELECT email, name FROM users WHERE id=?", [$to]);
                    if ($op && $uid !== $to) { try { hd_notify_assigned($cur, $op); } catch (\Throwable $e) {} }
                }
                break;
            case 'tag':
                if ($v !== '' && !in_array($v, hdTagsOf($ticketId), true)) { hdTagAdd($ticketId, $v); $done[] = hdMacroStepLabel($st); }
                break;
            case 'untag':
                if (in_array($v, hdTagsOf($ticketId), true)) { hdTagRemove($ticketId, $v); $done[] = hdMacroStepLabel($st); }
                break;
            case 'note':
                db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid ?: null, 'user_name' => $uname, 'body' => hdStepText($v, $cur), 'is_internal' => 1]);
                $done[] = 'notatka';
                break;
            case 'watch':
                if (hdWatchAdd($ticketId, (int)$v, '', '', $uid) === null) $done[] = hdMacroStepLabel($st);
                break;
            case 'notify':
                $op = db_one("SELECT email, name FROM users WHERE id=? AND is_active=1 AND email IS NOT NULL AND email<>''", [(int)$v]);
                if ($op) {
                    try {
                        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                        mail_queue_add($op['email'], $op['name'], '[' . $cur['number'] . '] Automatyzacja: zgłoszenie wymaga uwagi',
                            hd_mail_layout(['title' => 'Zgłoszenie wymaga Twojej uwagi', 'preheader' => $cur['number'] . ' — ' . $cur['title'],
                                'intro' => '<p style="margin:0">Powiadomienie z automatyzacji Helpdesku (' . h($uname !== '' ? $uname : 'reguła') . ').</p>',
                                'facts' => [['Numer', $cur['number']], ['Temat', $cur['title']], ['Status', HD_STATUSES[$cur['status']]['label'] ?? $cur['status']],
                                            ['Priorytet', HD_PRIORITIES[$cur['priority']]['label'] ?? $cur['priority']]],
                                'cta' => ['label' => 'Otwórz zgłoszenie', 'url' => APP_URL . '/helpdesk/view.php?id=' . $ticketId]]));
                        $done[] = hdMacroStepLabel($st);
                    } catch (\Throwable $e) { error_log('[helpdeskMacros] notify: ' . $e->getMessage()); }
                }
                break;
            case 'reply':
                if ($v !== '' && $cur['status'] !== 'zamknięte') {
                    $body = hdStepText($v, $cur);
                    $mid = db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid ?: null, 'user_name' => $uid ? $uname : 'Helpdesk IT', 'body' => $body, 'is_internal' => 0]);
                    db_update('helpdesk_tickets', ['updated_at' => $now] + (empty($cur['first_response_at']) ? ['first_response_at' => $now] : []), $ticketId);
                    try { hd_notify_new_message(db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]) ?: $cur,
                        ['id' => $mid, 'user_id' => $uid ?: -1, 'user_name' => $uid ? $uname : 'Helpdesk IT', 'body' => $body, 'is_internal' => 0]); } catch (\Throwable $e) {}
                    $done[] = hdMacroStepLabel($st);
                }
                break;
            case 'macro':
                if ($depth < 3) {
                    $m = db_one("SELECT * FROM helpdesk_macros WHERE id=? AND is_active=1", [(int)$v]);
                    if ($m) { $sub = hdStepsRun($ticketId, hdMacroSteps($m), $uid, $uname, $depth + 1); if ($sub) $done[] = 'makro „' . $m['title'] . '”: ' . implode(', ', $sub); }
                }
                break;
        }
    }
    return $done;
}

/**
 * Wykonuje przebieg makra na zgłoszeniu (operator $uid). Zwraca listę wykonanych zmian (pusta = nic nie zmieniono).
 * Zmiana statusu wysyła powiadomienia jak zwykła zmiana; całość zapisuje się notatką wewnętrzną.
 */
function hdMacroApply(int $ticketId, int $macroId, int $uid, string $uname): array
{
    hdMacrosMigrate();
    $m = db_one("SELECT * FROM helpdesk_macros WHERE id=? AND is_active=1", [$macroId]);
    $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
    if (!$m || !$t) return [];
    unset($GLOBALS['_hdStepsStatus'][$ticketId]);
    $done = hdStepsRun($ticketId, hdMacroSteps($m), $uid, $uname);
    if ($done) {
        db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid ?: null, 'user_name' => $uname,
            'body' => 'Makro „' . $m['title'] . '”: ' . implode(', ', $done), 'is_internal' => 1]);
        if (isset($GLOBALS['_hdStepsStatus'][$ticketId])) {
            [$old, $new] = $GLOBALS['_hdStepsStatus'][$ticketId];
            $fresh = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
            if ($fresh) hd_notify_status_change($fresh, $old, $new, '');
        }
    }
    return $done;
}


/**
 * Kreator kroków (HTML + skrypt) wspólny dla makr i reguł. Pola: step_type[], sv_status[], sv_priority[], sv_category[],
 * sv_user[], sv_macro[], sv_text[] — wiersz i = jeden krok (zob. hdMacroActionFields). $excludeMacroId = makro, którego nie
 * można wybrać jako krok „wykonaj makro” (to samo makro).
 */
function hdStepsBuilder(array $steps, int $excludeMacroId = 0): string
{
    hdMacrosMigrate();
    $ops = db_all("SELECT id, name FROM users WHERE helpdesk_operator=1 AND is_active=1 ORDER BY name");
    $macros = array_values(array_filter(db_all("SELECT id, title FROM helpdesk_macros WHERE is_active=1 ORDER BY title"), fn($m) => (int)$m['id'] !== $excludeMacroId));
    $row = function (array $st) use ($ops, $macros): string {
        $sel = fn(bool $on) => $on ? ' selected' : '';
        $t = $st['type']; $v = (string)$st['value'];
        $h = '<div class="hd-step row g-2 align-items-center mb-1" data-step><div class="col-sm-4"><select name="step_type[]" class="form-select form-select-sm" data-step-type aria-label="Rodzaj kroku">';
        foreach (HD_MACRO_STEP_TYPES as $k => $l) $h .= '<option value="' . h($k) . '"' . $sel($t === $k) . '>' . h($l) . '</option>';
        $h .= '</select></div><div class="col-sm-7">';
        $h .= '<select name="sv_status[]" class="form-select form-select-sm" data-sv="status">';
        foreach (HD_STATUSES as $k => $x) $h .= '<option value="' . h($k) . '"' . $sel($t === 'status' && $v === $k) . '>' . h($x['label']) . '</option>';
        $h .= '</select><select name="sv_priority[]" class="form-select form-select-sm" data-sv="priority">';
        foreach (HD_PRIORITIES as $k => $x) $h .= '<option value="' . h($k) . '"' . $sel($t === 'priority' && $v === $k) . '>' . h($x['label']) . '</option>';
        $h .= '</select><select name="sv_category[]" class="form-select form-select-sm" data-sv="category">';
        foreach (hd_categories() as $k => $l) $h .= '<option value="' . h($k) . '"' . $sel($t === 'category' && $v === $k) . '>' . h($l) . '</option>';
        $h .= '</select><select name="sv_user[]" class="form-select form-select-sm" data-sv="assign_user watch notify">';
        foreach ($ops as $o) $h .= '<option value="' . (int)$o['id'] . '"' . $sel(in_array($t, ['assign_user', 'watch', 'notify'], true) && (int)$v === (int)$o['id']) . '>' . h($o['name']) . '</option>';
        $h .= '</select><select name="sv_macro[]" class="form-select form-select-sm" data-sv="macro">';
        foreach ($macros as $m) $h .= '<option value="' . (int)$m['id'] . '"' . $sel($t === 'macro' && (int)$v === (int)$m['id']) . '>' . h($m['title']) . '</option>';
        $h .= '</select><input name="sv_text[]" class="form-control form-control-sm" data-sv="tag untag note reply" maxlength="3000" placeholder="tag lub treść (można użyć {{numer}}, {{temat}}, {{zglaszajacy}})" value="'
            . h(in_array($t, ['tag', 'untag', 'note', 'reply'], true) ? $v : '') . '">';
        return $h . '</div><div class="col-sm-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger py-0" data-step-del aria-label="Usuń krok"><i class="bi bi-x-lg"></i></button></div></div>';
    };
    $out = '<div id="hdSteps">';
    foreach ($steps as $st) $out .= $row($st);
    $out .= '</div><template id="hdStepTpl">' . $row(['type' => 'status', 'value' => 'otwarte']) . '</template>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="hdStepAdd"><i class="bi bi-plus-lg me-1"></i>Dodaj krok</button>';
    return $out . <<<'JS'
<script>
(function(){
  var box=document.getElementById('hdSteps'), tpl=document.getElementById('hdStepTpl'), add=document.getElementById('hdStepAdd');
  if(!box||!tpl||!add) return;
  function sync(row){
    var t=row.querySelector('[data-step-type]').value;
    row.querySelectorAll('[data-sv]').forEach(function(el){ el.style.display=el.dataset.sv.split(' ').indexOf(t)>-1?'':'none'; });  /* pól nie wyłączamy: tablice sv_*[] muszą mieć po jednym elemencie na krok */
  }
  function bind(row){
    row.querySelector('[data-step-type]').addEventListener('change',function(){sync(row);});
    row.querySelector('[data-step-del]').addEventListener('click',function(){row.remove();});
    sync(row);
  }
  box.querySelectorAll('[data-step]').forEach(bind);
  add.addEventListener('click',function(){ box.appendChild(tpl.content.cloneNode(true)); bind(box.lastElementChild); });
})();
</script>
JS;
}
