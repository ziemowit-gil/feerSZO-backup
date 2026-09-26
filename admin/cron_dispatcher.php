<?php
/**
 * admin/cron_dispatcher.php — wizualny edytor dyspozytora CRON (tylko admin).
 *
 * Rejestr agentów: modules/cron_dispatcher/logic/registry.php (wartości domyślne).
 * Zmiany z tego ekranu to nadpisania w bazie (cron_agent_overrides), czytane przez
 * cron/dispatcher.php przy każdym przebiegu — bez edycji kodu i bez restartu.
 * Szczegóły i zasady: modules/cron_dispatcher/logic/cron_dispatcher.php.
 *
 * AJAX (POST, CSRF): _ajax=save (rows JSON), run, cancel, reset; GET _ajax=log&name=.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/cron_dispatcher/logic/cron_dispatcher.php';

require_role('admin');
cron_dispatcher_migrate();
$user = current_user();
$uid  = (int)$user['id'];
$uname = (string)($user['name'] ?? '');

// ── AJAX ──────────────────────────────────────────────────────────────────
$ajax = $_GET['_ajax'] ?? $_POST['_ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $out = static function (array $d, int $code = 200): never {
        http_response_code($code);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };
    try {
        if ($ajax === 'log') {
            $name = (string)($_GET['name'] ?? '');
            if (!isset(cron_dispatcher_registry()[$name])) $out(['error' => 'Nieznany agent'], 404);
            $tail = cron_dispatcher_log_tail($name, 120);
            $out(['name' => $name, 'log' => $tail,
                  'path' => defined('LOG_PATH') ? rtrim(LOG_PATH, '/') . '/cron_' . $name . '.log' : null]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['error' => 'Method not allowed'], 405);
        csrf_check();
        $name = (string)($_POST['name'] ?? '');
        switch ($ajax) {
            case 'save':
                $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
                if (!is_array($rows)) $out(['error' => 'Nieprawidłowe dane'], 400);
                $done = []; $errors = [];
                foreach ($rows as $n => $d) {
                    try { $done[$n] = cron_dispatcher_save((string)$n, (array)$d, $uid, $uname); }
                    catch (InvalidArgumentException $e) { $errors[$n] = $e->getMessage(); }
                }
                $out(['ok' => !$errors, 'saved' => $done, 'errors' => $errors], $errors ? 422 : 200);
            case 'run':
                cron_dispatcher_request_run($name, $uid, $uname);
                $out(['ok' => true]);
            case 'cancel':
                cron_dispatcher_cancel_run($name);
                $out(['ok' => true]);
            case 'reset':
                $out(['ok' => true, 'summary' => cron_dispatcher_save($name, ['enabled' => true], $uid, $uname)]);
        }
        $out(['error' => 'Nieznana akcja'], 400);
    } catch (InvalidArgumentException $e) {
        $out(['error' => $e->getMessage()], 422);
    }
}

// ── Dane widoku ───────────────────────────────────────────────────────────
$registry = cron_dispatcher_registry();
$agents   = cron_dispatcher_effective($registry);
$state    = cron_dispatcher_state();
$ovr      = cron_dispatcher_overrides();
$hb       = (int)($state[CRON_DISPATCHER_HEARTBEAT]['last_run_at'] ?? 0);
$hbAge    = $hb ? time() - $hb : null;

$load = array_fill(0, 24, 0.0);
$rows = [];
foreach ($agents as $name => $a) {
    foreach (cron_dispatcher_hour_load($a) as $h => $n) $load[$h] += $n;
    $last = cron_dispatcher_last_run($name, $state);
    $def  = $a['default'];
    $o    = $ovr[$name] ?? null;
    $rows[cron_dispatcher_category($name)][] = [
        'name'        => $name,
        'file'        => str_replace(dirname(__DIR__) . '/', '', $a['file']),
        'exists'      => is_file($a['file']),
        'args'        => (string)($a['args'] ?? ''),
        'enabled'     => (bool)$a['enabled'],
        'dynamic'     => is_string($def['interval']) && is_callable($def['interval']),
        'def_interval'=> cron_dispatcher_interval_seconds($def),
        'interval'    => cron_dispatcher_interval_seconds($a),
        'iv_override' => $o && $o['interval_sec'] !== null,
        'def_window'  => isset($def['schedule']) ? [(int)$def['schedule'][0], (int)$def['schedule'][1]] : null,
        'window'      => isset($a['schedule']) ? [(int)$a['schedule'][0], (int)$a['schedule'][1]] : null,
        'overridden'  => (bool)$a['overridden'],
        'note'        => (string)($o['note'] ?? ''),
        'last'        => $last,
        'last_trigger'=> $state[$name]['last_trigger'] ?? null,
        'next'        => cron_dispatcher_next_run($a, $last),
        'requested'   => (bool)$a['run_requested'],
    ];
}
ksort($rows);
$counts = [
    'all'      => count($agents),
    'disabled' => count(array_filter($agents, fn($a) => !$a['enabled'])),
    'modified' => count(array_filter($agents, fn($a) => $a['overridden'])),
    'queued'   => count(array_filter($agents, fn($a) => $a['run_requested'])),
];
$audit = cron_dispatcher_audit_list(30);
$maxLoad = max(1, max($load));
$nowHour = (int)date('G');

$rel = static function (?int $ts): string {
    if (!$ts) return '—';
    $d = time() - $ts;
    $abs = abs($d);
    $txt = $abs < 60 ? $abs . ' s' : ($abs < 3600 ? round($abs / 60) . ' min' : ($abs < 86400 ? round($abs / 3600, 1) . ' h' : round($abs / 86400, 1) . ' dni'));
    return $d >= 0 ? $txt . ' temu' : 'za ' . $txt;
};

$PAGE_TITLE = 'Harmonogram CRON — edytor dyspozytora';
include dirname(__DIR__) . '/includes/header.php';
?>
<style>
  .cd-strip { display: grid; grid-template-columns: repeat(24, 1fr); gap: 2px; user-select: none; touch-action: none; min-width: 240px; }
  .cd-strip .h { height: 22px; border-radius: 3px; background: #e9ecef; cursor: pointer; position: relative; }
  .cd-strip .h.on { background: #0d6efd; }
  .cd-strip.off .h.on { background: #adb5bd; }
  .cd-strip .h.now { outline: 2px solid #fd7e14; outline-offset: -2px; }
  .cd-strip .h:focus-visible { outline: 2px solid #212529; outline-offset: 1px; }
  .cd-hours { display: grid; grid-template-columns: repeat(24, 1fr); gap: 2px; font-size: .62rem; color: #6c757d; text-align: center; min-width: 240px; }
  .cd-row.dirty { background: #fff8e1 !important; }
  .cd-row.disabled .cd-name { opacity: .55; text-decoration: line-through; }
  .cd-row td { vertical-align: middle; }
  .cd-savebar { position: sticky; bottom: 0; z-index: 20; }
  .cd-load rect.cur { fill: #fd7e14; }
  .cd-log { background: #0f172a; color: #e2e8f0; font-size: .78rem; max-height: 60vh; overflow: auto; white-space: pre-wrap; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-calendar2-range text-primary"></i> Harmonogram CRON <span class="text-muted fs-6">— edytor dyspozytora</span></h4>
  <a href="<?= APP_URL ?>/admin/cron_setup.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-terminal me-1"></i>Konfiguracja crontab</a>
</div>

<?= flash_html() ?>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <?php if ($hbAge === null): ?>
          <div class="d-flex gap-2 align-items-start"><i class="bi bi-question-circle-fill text-secondary fs-4"></i>
            <div><div class="fw-semibold">Brak sygnału z dyspozytora</div>
              <div class="small text-muted">Dyspozytor jeszcze nie przebiegł z nową wersją albo crontab go nie uruchamia — zob. <a href="<?= APP_URL ?>/admin/cron_setup.php">konfiguracja</a>.</div></div></div>
        <?php else: $ok = $hbAge < 180; ?>
          <div class="d-flex gap-2 align-items-start"><i class="bi bi-<?= $ok ? 'heart-pulse-fill text-success' : 'exclamation-octagon-fill text-danger' ?> fs-4"></i>
            <div><div class="fw-semibold"><?= $ok ? 'Dyspozytor działa' : 'Dyspozytor nie odpowiada' ?></div>
              <div class="small text-muted">Ostatni przebieg: <?= h($rel($hb)) ?> (<?= h(date('d.m H:i:s', $hb)) ?>)<?= $ok ? '' : ' — sprawdź crontab / serwer.' ?></div></div></div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-3 mt-3 small">
          <span><strong><?= $counts['all'] ?></strong> agentów</span>
          <span class="<?= $counts['disabled'] ? 'text-danger' : 'text-muted' ?>"><strong><?= $counts['disabled'] ?></strong> wyłączonych</span>
          <span class="<?= $counts['modified'] ? 'text-primary' : 'text-muted' ?>"><strong><?= $counts['modified'] ?></strong> zmienionych</span>
          <span class="text-muted"><strong><?= $counts['queued'] ?></strong> w kolejce „teraz”</span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between small mb-1">
          <span class="fw-semibold">Obciążenie doby — szacowana liczba uruchomień w każdej godzinie</span>
          <span class="text-muted">teraz: <span style="color:#fd7e14">■</span> <?= sprintf('%02d:00', $nowHour) ?></span>
        </div>
        <svg class="cd-load" viewBox="0 0 480 90" width="100%" height="90" role="img"
             aria-label="Szacowana liczba uruchomień agentów w poszczególnych godzinach doby; najwięcej <?= (int)round($maxLoad) ?> na godzinę">
          <?php foreach ($load as $hr => $n): $bh = $n > 0 ? max(2, round($n / $maxLoad * 70)) : 0; ?>
            <rect x="<?= $hr * 20 + 2 ?>" y="<?= 72 - $bh ?>" width="16" height="<?= $bh ?>" rx="2" fill="#0d6efd" opacity=".8" class="<?= $hr === $nowHour ? 'cur' : '' ?>"><title><?= sprintf('%02d:00–%02d:00', $hr, $hr + 1) ?>: ~<?= round($n) ?> uruchomień</title></rect>
            <?php if ($hr % 3 === 0): ?><text x="<?= $hr * 20 + 10 ?>" y="86" font-size="9" text-anchor="middle" fill="#6c757d"><?= $hr ?></text><?php endif; ?>
          <?php endforeach; ?>
        </svg>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body py-2 row g-2 align-items-center">
    <div class="col-md-4"><input type="search" id="cd-q" class="form-control form-control-sm" placeholder="Szukaj agenta lub pliku…" aria-label="Szukaj agenta"></div>
    <div class="col-md-3">
      <select id="cd-cat" class="form-select form-select-sm" aria-label="Kategoria">
        <option value="">Wszystkie kategorie</option>
        <?php foreach (array_keys($rows) as $cat): ?><option><?= h($cat) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <div class="btn-group btn-group-sm" role="group" aria-label="Filtr stanu">
        <input type="radio" class="btn-check" name="cd-f" id="cdf-all" value="" checked><label class="btn btn-outline-secondary" for="cdf-all">Wszystkie</label>
        <input type="radio" class="btn-check" name="cd-f" id="cdf-off" value="off"><label class="btn btn-outline-secondary" for="cdf-off">Wyłączone</label>
        <input type="radio" class="btn-check" name="cd-f" id="cdf-mod" value="mod"><label class="btn btn-outline-secondary" for="cdf-mod">Zmienione</label>
        <input type="radio" class="btn-check" name="cd-f" id="cdf-now" value="now"><label class="btn btn-outline-secondary" for="cdf-now">Teraz w oknie</label>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table table-sm mb-0" id="cd-table">
      <thead class="table-light">
        <tr>
          <th style="width:1%">Wł.</th>
          <th>Agent</th>
          <th style="width:13rem">Interwał</th>
          <th style="min-width:280px">Okno godzinowe <span class="fw-normal text-muted small">(kliknij lub przeciągnij; przez północ: „⇄ odwróć”)</span></th>
          <th class="text-nowrap">Ostatnio / następnie</th>
          <th class="text-end" style="width:1%">Akcje</th>
        </tr>
      </thead>
      <?php foreach ($rows as $cat => $list): ?>
      <tbody data-cat="<?= h($cat) ?>">
        <tr class="table-group-divider cd-cat-head"><th colspan="6" class="small text-uppercase text-muted bg-light"><?= h($cat) ?> <span class="fw-normal">(<?= count($list) ?>)</span></th></tr>
        <?php foreach ($list as $r): $w = $r['window'] ?? [0, 24]; ?>
        <tr class="cd-row <?= $r['enabled'] ? '' : 'disabled' ?>" data-name="<?= h($r['name']) ?>"
            data-agent='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>'>
          <td>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input cd-en" type="checkbox" role="switch" <?= $r['enabled'] ? 'checked' : '' ?> aria-label="Włącz agenta <?= h($r['name']) ?>">
            </div>
          </td>
          <td class="cd-name">
            <div class="fw-semibold small font-monospace"><?= h($r['name']) ?>
              <?php if ($r['overridden']): ?><span class="badge bg-primary-subtle text-primary-emphasis ms-1" title="Zmieniony w panelu — różni się od rejestru">zmieniony</span><?php endif; ?>
              <?php if (!$r['exists']): ?><span class="badge bg-danger ms-1" title="Dyspozytor pomija agenta">brak pliku</span><?php endif; ?>
              <?php if ($r['requested']): ?><span class="badge bg-warning text-dark ms-1 cd-q-badge">w kolejce</span><?php endif; ?>
            </div>
            <div class="small text-muted text-truncate" style="max-width:22rem" title="<?= h($r['file']) ?>"><?= h($r['file']) ?><?= $r['args'] !== '' ? ' <code>' . h($r['args']) . '</code>' : '' ?></div>
            <input type="text" class="form-control form-control-sm mt-1 cd-note <?= $r['note'] === '' ? 'd-none' : '' ?>" value="<?= h($r['note']) ?>" placeholder="Notatka (np. dlaczego wyłączony)" aria-label="Notatka do agenta">
          </td>
          <td>
            <select class="form-select form-select-sm cd-iv" aria-label="Interwał <?= h($r['name']) ?>">
              <option value="">domyślny — <?= $r['dynamic'] ? 'dynamiczny (' . h(cron_dispatcher_interval_label($r['def_interval'])) . ' teraz)' : h(cron_dispatcher_interval_label($r['def_interval'])) ?></option>
              <?php $ivs = CRON_DISPATCHER_INTERVALS; if ($r['iv_override'] && !isset($ivs[$r['interval']])) $ivs[$r['interval']] = cron_dispatcher_interval_label($r['interval']); ksort($ivs);
              foreach ($ivs as $sec => $lbl): ?>
                <option value="<?= $sec ?>" <?= $r['iv_override'] && $r['interval'] === $sec ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
              <option value="custom">własny (minuty)…</option>
            </select>
          </td>
          <td>
            <div class="cd-hours" aria-hidden="true"><?php for ($i = 0; $i < 24; $i++): ?><span><?= $i % 6 === 0 ? $i : '' ?></span><?php endfor; ?></div>
            <div class="cd-strip <?= $r['enabled'] ? '' : 'off' ?>" role="group" aria-label="Okno godzinowe <?= h($r['name']) ?>">
              <?php for ($i = 0; $i < 24; $i++): ?>
                <span class="h <?= cron_dispatcher_in_window(['schedule' => $w], $i) ? 'on' : '' ?> <?= $i === $nowHour ? 'now' : '' ?>" data-h="<?= $i ?>" tabindex="<?= $i === 0 ? 0 : -1 ?>"
                      title="<?= sprintf('%02d:00–%02d:00', $i, $i + 1) ?>"></span>
              <?php endfor; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-1 small">
              <span class="cd-wlabel text-muted"></span>
              <span>
                <button type="button" class="btn btn-link btn-sm p-0 cd-w-inv" title="Zamień zaznaczenie na jego dopełnienie — np. 06–22 → 22–06 (przez północ)">⇄ odwróć</button>
                · <button type="button" class="btn btn-link btn-sm p-0 cd-w-all">cała doba</button>
                <?php if ($r['def_window']): ?> · <button type="button" class="btn btn-link btn-sm p-0 cd-w-def">domyślne <?= h(cron_dispatcher_window_label($r['def_window'])) ?></button><?php endif; ?>
              </span>
            </div>
          </td>
          <td class="small text-nowrap">
            <div title="<?= $r['last'] ? h(date('d.m.Y H:i:s', $r['last'])) : '' ?>"><i class="bi bi-clock-history text-muted"></i> <?= h($rel($r['last'])) ?><?= $r['last_trigger'] === 'manual' ? ' <span class="badge bg-light text-dark border">ręcznie</span>' : '' ?></div>
            <div class="text-muted" title="<?= $r['next'] ? h(date('d.m.Y H:i', $r['next'])) : '' ?>"><i class="bi bi-arrow-right-circle"></i> <?= $r['next'] ? h($rel($r['next'])) : 'wyłączony' ?></div>
          </td>
          <td class="text-end text-nowrap">
            <div class="btn-group btn-group-sm">
              <button type="button" class="btn btn-outline-success cd-run <?= $r['requested'] ? 'd-none' : '' ?>" title="Uruchom przy najbliższym przebiegu dyspozytora (≤ 1 min), z pominięciem okna i interwału"><i class="bi bi-play-fill"></i></button>
              <button type="button" class="btn btn-warning cd-cancel <?= $r['requested'] ? '' : 'd-none' ?>" title="Anuluj uruchomienie"><i class="bi bi-x-lg"></i></button>
              <button type="button" class="btn btn-outline-secondary cd-log" title="Log agenta"><i class="bi bi-journal-text"></i></button>
              <button type="button" class="btn btn-outline-secondary cd-note-btn" title="Notatka"><i class="bi bi-sticky"></i></button>
              <button type="button" class="btn btn-outline-danger cd-reset <?= $r['overridden'] ? '' : 'd-none' ?>" title="Przywróć ustawienia z rejestru"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<div class="cd-savebar d-none" id="cd-savebar">
  <div class="alert alert-warning shadow d-flex justify-content-between align-items-center mb-3">
    <span><i class="bi bi-pencil-square me-1"></i>Niezapisane zmiany: <strong id="cd-dirty-n">0</strong> agent(ów). Dyspozytor użyje ich od najbliższego przebiegu.</span>
    <span class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="cd-discard">Odrzuć</button>
      <button type="button" class="btn btn-sm btn-primary" id="cd-save"><i class="bi bi-check-lg me-1"></i>Zapisz zmiany</button>
    </span>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-white fw-semibold small"><i class="bi bi-clock-history me-1"></i>Historia zmian harmonogramu</div>
  <?php if (!$audit): ?>
    <div class="card-body small text-muted">Brak zmian — wszyscy agenci działają według rejestru.</div>
  <?php else: ?>
    <ul class="list-group list-group-flush small">
      <?php foreach ($audit as $a): ?>
        <li class="list-group-item d-flex gap-3">
          <span class="text-muted text-nowrap"><?= h(date('d.m.Y H:i', strtotime($a['created_at']))) ?></span>
          <code><?= h($a['name']) ?></code>
          <span class="flex-grow-1"><?= h($a['details']) ?></span>
          <span class="text-muted"><?= h($a['user_name']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="modal fade" id="cd-log-modal" tabindex="-1" aria-labelledby="cd-log-title" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cd-log-title">Log</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body p-0"><pre class="cd-log m-0 p-3" id="cd-log-body">…</pre></div>
      <div class="modal-footer small text-muted justify-content-between"><span id="cd-log-path"></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="cd-log-refresh"><i class="bi bi-arrow-clockwise me-1"></i>Odśwież</button></div>
    </div>
  </div>
</div>

<script>
(function () {
  const base = <?= json_encode(APP_URL . '/admin/cron_dispatcher.php') ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const dirty = new Map();   // name → stan edytowany
  const post = (ajax, data) => fetch(base, { method: 'POST', credentials: 'same-origin',
      body: new URLSearchParams(Object.assign({ _csrf: csrf, _ajax: ajax }, data)) })
    .then(r => r.json().then(j => ({ ok: r.ok, j })));
  const ivLabel = s => { const m = { 60:'co minutę',3600:'co godzinę',86400:'raz dziennie',604800:'raz w tygodniu' };
    return m[s] || (s % 86400 === 0 ? 'co ' + s/86400 + ' dni' : s % 3600 === 0 ? 'co ' + s/3600 + ' h' : 'co ' + Math.round(s/60) + ' min'); };

  document.querySelectorAll('.cd-row').forEach(row => {
    const a = JSON.parse(row.dataset.agent);
    const cells = [...row.querySelectorAll('.cd-strip .h')];
    const strip = row.querySelector('.cd-strip');
    const lbl = row.querySelector('.cd-wlabel');
    const ivSel = row.querySelector('.cd-iv');
    const en = row.querySelector('.cd-en');
    const note = row.querySelector('.cd-note');
    const orig = { enabled: a.enabled, iv: ivSel.value, win: a.window ? a.window.slice() : [0, 24], note: a.note };
    let win = orig.win.slice();

    // Okno [od, do): od < do zwykłe, od > do przez północ (22–6 = 22..23 i 0..5), do ∈ 1..24.
    const inW = h => win[0] < win[1] ? (h >= win[0] && h < win[1]) : (h >= win[0] || h < win[1]);
    const wLen = () => win[0] < win[1] ? win[1] - win[0] : 24 - win[0] + win[1];
    const isAll = () => win[0] === 0 && win[1] === 24;
    const pad = n => String(n).padStart(2, '0');
    const paint = () => {
      cells.forEach((c, i) => c.classList.toggle('on', inW(i)));
      const all = isAll();
      lbl.textContent = all ? 'cała doba' : pad(win[0]) + ':00–' + pad(win[1]) + ':00' + (win[0] > win[1] ? ' (przez północ)' : '');
      strip.setAttribute('aria-label', 'Okno godzinowe ' + a.name + ': ' + lbl.textContent);
    };
    const current = () => {
      const all = win[0] === 0 && win[1] === 24;
      const isDef = a.def_window ? (win[0] === a.def_window[0] && win[1] === a.def_window[1]) : all;
      return { enabled: en.checked, interval_sec: ivSel.value || null,
               schedule_mode: isDef ? 'default' : (all ? 'none' : 'custom'), schedule_from: win[0], schedule_to: win[1],
               note: note.value.trim() };
    };
    const check = () => {
      const changed = en.checked !== orig.enabled || ivSel.value !== orig.iv || win[0] !== orig.win[0] || win[1] !== orig.win[1] || note.value.trim() !== orig.note;
      row.classList.toggle('dirty', changed);
      row.classList.toggle('disabled', !en.checked);
      strip.classList.toggle('off', !en.checked);
      changed ? dirty.set(a.name, current) : dirty.delete(a.name);
      document.getElementById('cd-dirty-n').textContent = dirty.size;
      document.getElementById('cd-savebar').classList.toggle('d-none', dirty.size === 0);
    };
    row._reset = () => { en.checked = orig.enabled; ivSel.value = orig.iv; win = orig.win.slice(); note.value = orig.note; paint(); check(); };

    // Przeciąganie po pasku: zakres [start, koniec] włącznie → okno [min, max+1)
    let dragFrom = null;
    const hourAt = e => { const t = document.elementFromPoint(e.clientX, e.clientY); return t && t.closest('.cd-strip') === strip && t.dataset.h !== undefined ? +t.dataset.h : null; };
    strip.addEventListener('pointerdown', e => {
      const h = hourAt(e); if (h === null) return;
      dragFrom = h; strip.setPointerCapture(e.pointerId); win = [h, h + 1]; paint();
    });
    strip.addEventListener('pointermove', e => {
      if (dragFrom === null) return;
      const h = hourAt(e); if (h === null) return;
      win = [Math.min(dragFrom, h), Math.max(dragFrom, h) + 1]; paint();
    });
    strip.addEventListener('pointerup', () => { if (dragFrom !== null) { dragFrom = null; check(); } });
    // Klawiatura (z zawijaniem przez północ): ←/→ przesuwa okno, Shift+→ wydłuża,
    // Shift+← skraca, I = odwróć, Home/End = cała doba.
    const invert = () => { if (!isAll()) win = [win[1] % 24, win[0] === 0 ? 24 : win[0]]; };
    strip.addEventListener('keydown', e => {
      const all = isAll();
      if (e.key === 'ArrowRight' && e.shiftKey) { if (!all) win = wLen() >= 23 ? [0, 24] : [win[0], win[1] % 24 + 1]; }
      else if (e.key === 'ArrowLeft' && e.shiftKey) { if (all) win = [0, 23]; else if (wLen() > 1) win = [win[0], win[1] === 1 ? 24 : win[1] - 1]; }
      else if (e.key === 'ArrowRight') { if (!all) win = [(win[0] + 1) % 24, win[1] === 24 ? 1 : win[1] + 1]; }
      else if (e.key === 'ArrowLeft') { if (!all) win = [(win[0] + 23) % 24, win[1] === 1 ? 24 : win[1] - 1]; }
      else if (e.key === 'i' || e.key === 'I') invert();
      else if (e.key === 'Home' || e.key === 'End') win = [0, 24];
      else return;
      e.preventDefault(); paint(); check();
    });
    row.querySelector('.cd-w-inv').addEventListener('click', () => { invert(); paint(); check(); });
    row.querySelector('.cd-w-all').addEventListener('click', () => { win = [0, 24]; paint(); check(); });
    const wd = row.querySelector('.cd-w-def');
    if (wd) wd.addEventListener('click', () => { win = a.def_window.slice(); paint(); check(); });

    ivSel.addEventListener('change', () => {
      if (ivSel.value === 'custom') {
        const m = parseInt(prompt('Interwał w minutach (1–43200):', Math.round((a.interval || 60) / 60)), 10);
        if (m >= 1 && m <= 43200) {
          const sec = m * 60;
          let opt = [...ivSel.options].find(o => o.value === String(sec));
          if (!opt) { opt = new Option(ivLabel(sec), sec); ivSel.insertBefore(opt, ivSel.lastElementChild); }
          ivSel.value = String(sec);
        } else ivSel.value = orig.iv;
      }
      check();
    });
    en.addEventListener('change', check);
    note.addEventListener('input', check);
    row.querySelector('.cd-note-btn').addEventListener('click', () => { note.classList.toggle('d-none'); if (!note.classList.contains('d-none')) note.focus(); });

    row.querySelector('.cd-run').addEventListener('click', () => post('run', { name: a.name }).then(({ ok, j }) => {
      if (!ok) return alert(j.error || 'Błąd');
      row.querySelector('.cd-run').classList.add('d-none'); row.querySelector('.cd-cancel').classList.remove('d-none');
    }));
    row.querySelector('.cd-cancel').addEventListener('click', () => post('cancel', { name: a.name }).then(() => {
      row.querySelector('.cd-cancel').classList.add('d-none'); row.querySelector('.cd-run').classList.remove('d-none');
      const b = row.querySelector('.cd-q-badge'); if (b) b.remove();
    }));
    row.querySelector('.cd-reset').addEventListener('click', () => {
      if (!confirm('Przywrócić ustawienia z rejestru dla ' + a.name + '?')) return;
      post('reset', { name: a.name }).then(({ ok, j }) => ok ? location.reload() : alert(j.error || 'Błąd'));
    });
    row.querySelector('.cd-log').addEventListener('click', () => openLog(a.name));
    paint();
  });

  document.getElementById('cd-save').addEventListener('click', () => {
    const rows = {}; dirty.forEach((fn, n) => { rows[n] = fn(); });
    post('save', { rows: JSON.stringify(rows) }).then(({ ok, j }) => {
      if (ok) return location.reload();
      alert('Nie zapisano:\n' + Object.entries(j.errors || {}).map(([n, m]) => n + ': ' + m).join('\n') + (j.error || ''));
    });
  });
  document.getElementById('cd-discard').addEventListener('click', () => document.querySelectorAll('.cd-row.dirty').forEach(r => r._reset()));
  window.addEventListener('beforeunload', e => { if (dirty.size) { e.preventDefault(); e.returnValue = ''; } });

  // Filtry
  const q = document.getElementById('cd-q'), cat = document.getElementById('cd-cat');
  const nowH = <?= $nowHour ?>;
  const filter = () => {
    const t = q.value.trim().toLowerCase(), c = cat.value, f = document.querySelector('input[name=cd-f]:checked').value;
    document.querySelectorAll('#cd-table tbody').forEach(tb => {
      let vis = 0;
      tb.querySelectorAll('.cd-row').forEach(r => {
        const a = JSON.parse(r.dataset.agent);
        const inWin = r.querySelectorAll('.cd-strip .h')[nowH].classList.contains('on');
        const show = (!c || tb.dataset.cat === c) && (!t || (a.name + ' ' + a.file).toLowerCase().includes(t))
          && (f === '' || (f === 'off' && !r.querySelector('.cd-en').checked) || (f === 'mod' && (a.overridden || r.classList.contains('dirty')))
              || (f === 'now' && inWin && r.querySelector('.cd-en').checked));
        r.classList.toggle('d-none', !show); if (show) vis++;
      });
      tb.querySelector('.cd-cat-head').classList.toggle('d-none', vis === 0);
    });
  };
  [q, cat].forEach(el => el.addEventListener('input', filter));
  document.querySelectorAll('input[name=cd-f]').forEach(el => el.addEventListener('change', filter));

  // Log
  let logName = null;
  const modal = new bootstrap.Modal(document.getElementById('cd-log-modal'));
  function openLog(name) {
    logName = name;
    document.getElementById('cd-log-title').textContent = 'Log: ' + name;
    document.getElementById('cd-log-body').textContent = 'Wczytywanie…';
    modal.show(); loadLog();
  }
  function loadLog() {
    fetch(base + '?_ajax=log&name=' + encodeURIComponent(logName), { credentials: 'same-origin' }).then(r => r.json()).then(j => {
      const body = document.getElementById('cd-log-body');
      body.textContent = j.log === null ? 'Log niedostępny (brak pliku lub brak uprawnień do odczytu).' : (j.log || '(pusty)');
      body.scrollTop = body.scrollHeight;
      document.getElementById('cd-log-path').textContent = j.path || '';
    });
  }
  document.getElementById('cd-log-refresh').addEventListener('click', loadLog);
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
