<?php
/**
 * edok/mailbox.php — Pulpit skrzynki faktur (np. faktury@feer.org.pl): co przyszło, od kogo i co z tym zrobiono.
 * Źródło: edok_mailbox_log (includes/edok_queue.php — edok_queue_mail_ingest). Wyniki: do kolejki „do opisu”, dokument z XML,
 * duplikat, pominięte (odbicia, nadawcy spoza listy), ignorowane (nieobsługiwany typ), bez załącznika, błąd (ponawiany automatycznie).
 * Dostęp: administrator, rola „dodawanie dokumentów” i główny księgowy. „Sprawdź teraz” — tylko administrator.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';
require_once __DIR__ . '/../includes/edok_queue.php';

edok_require_access();
if (!is_admin() && !edok_has_role('upload') && !edok_has_role('ksiegowy')) {
    http_response_code(403);
    die('Pulpit skrzynki faktur jest dostępny dla administratora, osób dodających dokumenty i głównego księgowego.');
}
edok_migrate(); edok_mailbox_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run' && is_admin()) {
    csrf_check();
    $r = edok_queue_mail_ingest();
    org_setting_set('edok_queue_mail_last_run', date('Y-m-d H:i:s') . ' — wiadomości: ' . $r['messages'] . ', dodano plików: ' . $r['added']
        . ($r['docs'] ? ', dokumentów z XML: ' . $r['docs'] : '') . ($r['dups'] ? ', duplikatów: ' . $r['dups'] : '') . ($r['errors'] ? ', błędy: ' . count($r['errors']) : ''));
    flash_set($r['errors'] ? 'warning' : 'success', "Sprawdzono skrzynkę: wiadomości {$r['messages']}, do kolejki {$r['added']}, dokumentów z XML {$r['docs']}, duplikatów {$r['dups']}"
        . ($r['errors'] ? '. Błędy: ' . implode(' | ', array_slice($r['errors'], 0, 3)) : '.'));
    header('Location: ' . APP_URL . '/edok/mailbox.php'); exit;
}

const MB_ACTIONS = [
    'queued'  => ['Do kolejki',      '#0d6efd', 'plik czeka na opisanie w kolejce'],
    'doc'     => ['Dokument z XML',  '#198754', 'dokument EODoK założony automatycznie'],
    'dup'     => ['Duplikat',        '#6c757d', 'faktura już jest w EODoK'],
    'skipped' => ['Pominięte',       '#b45309', 'odbicie, autoodpowiedź albo nadawca spoza listy'],
    'ignored' => ['Ignorowane',      '#6c757d', 'nieobsługiwany typ lub rozmiar pliku'],
    'noatt'   => ['Bez załącznika',  '#6c757d', 'wiadomość bez plików'],
    'error'   => ['Błąd',            '#a20025', 'zostanie ponowione w następnym przebiegu'],
];
$f = [
    'action' => isset(MB_ACTIONS[$_GET['action'] ?? '']) ? (string)$_GET['action'] : '',
    'q'      => trim((string)($_GET['q'] ?? '')),
    'days'   => in_array((string)($_GET['days'] ?? '7'), ['1', '7', '30', '0'], true) ? (string)($_GET['days'] ?? '7') : '7',
];
$w = ['1=1']; $p = [];
if ($f['action'] !== '') { $w[] = 'action = ?'; $p[] = $f['action']; }
if ($f['days'] !== '0') { $w[] = 'created_at >= ?'; $p[] = date('Y-m-d H:i:s', strtotime('-' . (int)$f['days'] . ' days')); }
if ($f['q'] !== '') { $like = '%' . str_replace(['%', '_'], '', mb_strtolower($f['q'])) . '%'; $w[] = '(LOWER(from_addr) LIKE ? OR LOWER(subject) LIKE ? OR LOWER(att_name) LIKE ? OR LOWER(reason) LIKE ?)'; array_push($p, $like, $like, $like, $like); }
$where = implode(' AND ', $w);
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 50;
$total = (int)(db_one("SELECT COUNT(*) AS n FROM edok_mailbox_log WHERE $where", $p)['n'] ?? 0);
$rows = db_all("SELECT * FROM edok_mailbox_log WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$pages = max(1, (int)ceil($total / $per));
$stat = [];
foreach (db_all("SELECT action, COUNT(*) AS n FROM edok_mailbox_log WHERE created_at >= ? GROUP BY action", [date('Y-m-d H:i:s', strtotime('-7 days'))]) as $r) $stat[$r['action']] = (int)$r['n'];

$mailbox = trim(org_setting('edok_queue_mailbox'));
$on = org_setting('edok_queue_mail_enabled') === '1';
$any = org_setting('edok_queue_mail_any_sender') === '1';
$auto = org_setting('edok_queue_mail_autodoc') === '1';
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge($f, ['p' => 1], $over), fn($v) => $v !== '' && $v !== null); if (($q['p'] ?? 1) == 1) unset($q['p']); return '?' . http_build_query($q); };
$flag = fn(bool $v, string $yes, string $no) => '<span class="badge ' . ($v ? 'bg-success' : 'bg-secondary') . '">' . h($v ? $yes : $no) . '</span>';

$PAGE_TITLE = 'Skrzynka faktur — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <a href="<?= APP_URL ?>/edok/queue.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do kolejki do opisu"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-envelope-paper" aria-hidden="true"></i> Skrzynka faktur</h1>
  <?php if ($mailbox !== ''): ?><span class="text-muted"><?= h($mailbox) ?></span><?php endif; ?>
  <?php if (is_admin() && $mailbox !== ''): ?>
  <form method="post" class="ms-auto"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="run">
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Sprawdź skrzynkę teraz</button></form>
  <?php endif; ?>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <?php if ($mailbox === ''): ?>
      <p class="mb-0 text-muted">Skrzynka nie jest skonfigurowana<?= is_admin() ? ' — ustaw ją w „Ustawienia (admin)” na stronie <a href="' . h(APP_URL . '/edok/queue.php') . '">Kolejka do opisu</a>' : '' ?>.</p>
    <?php else: ?>
    <div class="d-flex flex-wrap gap-3 align-items-center small">
      <span>Odbiór: <?= $flag($on, 'włączony', 'wyłączony') ?></span>
      <span>Każdy nadawca: <?= $flag($any, 'tak', 'tylko lista dozwolonych') ?></span>
      <span>Dokumenty z XML: <?= $flag($auto, 'automatycznie', 'do kolejki') ?></span>
      <?php if (org_setting('edok_queue_mail_last_run') !== ''): ?><span class="text-muted">Ostatnie sprawdzenie: <?= h(org_setting('edok_queue_mail_last_run')) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-3" aria-label="Podsumowanie z 7 dni">
      <?php foreach (MB_ACTIONS as $k => [$lbl, $col, $hint]): ?>
        <a href="<?= h($qs(['action' => $k, 'days' => '7'])) ?>" class="text-decoration-none border rounded px-2 py-1 small" title="<?= h($hint) ?>" style="border-left:4px solid <?= h($col) ?>!important;color:inherit">
          <span class="fw-semibold"><?= (int)($stat[$k] ?? 0) ?></span> <?= h($lbl) ?></a>
      <?php endforeach; ?>
      <span class="small text-muted align-self-center">z ostatnich 7 dni</span>
    </div>
  </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Szukaj w dzienniku skrzynki">
  <div class="col-12 col-md-4"><label for="mb-q" class="form-label small mb-1">Nadawca, temat, plik lub powód</label>
    <input id="mb-q" type="search" name="q" value="<?= h($f['q']) ?>" class="form-control form-control-sm" autocomplete="off"></div>
  <div class="col-6 col-md-3"><label for="mb-a" class="form-label small mb-1">Wynik</label>
    <select id="mb-a" name="action" class="form-select form-select-sm"><option value="">Wszystkie</option>
      <?php foreach (MB_ACTIONS as $k => [$lbl]): ?><option value="<?= h($k) ?>"<?= $f['action'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label for="mb-d" class="form-label small mb-1">Okres</label>
    <select id="mb-d" name="days" class="form-select form-select-sm">
      <?php foreach (['1' => 'Ostatnia doba', '7' => '7 dni', '30' => '30 dni', '0' => 'Cały dziennik'] as $v => $l): ?><option value="<?= h($v) ?>"<?= $f['days'] === (string)$v ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-primary btn-sm">Szukaj</button><a href="<?= APP_URL ?>/edok/mailbox.php" class="btn btn-outline-secondary btn-sm">Wyczyść</a></div>
</form>

<p class="small text-muted mb-2" role="status">Pozycji: <b><?= $total ?></b></p>
<div class="table-responsive">
  <table class="table table-sm align-middle" style="font-size:.88rem">
    <caption class="visually-hidden">Dziennik odbioru skrzynki faktur, od najnowszych</caption>
    <thead class="table-light"><tr><th scope="col">Czas</th><th scope="col">Nadawca</th><th scope="col">Temat</th><th scope="col">Plik</th><th scope="col">Wynik</th><th scope="col" class="text-end">Przejdź</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= $total === 0 && $f['q'] === '' && $f['action'] === '' ? 'Dziennik jest pusty — skrzynka nie przyjęła jeszcze żadnej wiadomości.' : 'Brak pozycji dla wybranych kryteriów.' ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): [$lbl, $col] = MB_ACTIONS[$r['action']] ?? [$r['action'], '#6c757d']; ?>
      <tr>
        <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
        <td class="text-truncate" style="max-width:200px" title="<?= h($r['from_addr']) ?>"><?= h($r['from_addr']) ?></td>
        <td class="text-truncate" style="max-width:260px" title="<?= h($r['subject']) ?>"><?= h($r['subject']) ?: '<span class="text-muted">(bez tematu)</span>' ?></td>
        <td class="text-truncate" style="max-width:200px" title="<?= h($r['att_name']) ?>"><?= $r['att_name'] !== '' ? h($r['att_name']) : '<span class="text-muted">—</span>' ?></td>
        <td><span class="badge" style="background:<?= h($col) ?>"><?= h($lbl) ?></span><?php if ($r['reason'] !== ''): ?><div class="small text-muted"><?= h($r['reason']) ?></div><?php endif; ?></td>
        <td class="text-end text-nowrap">
          <?php if (!empty($r['doc_id'])): ?><a class="btn btn-sm btn-outline-success py-0 px-2" href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$r['doc_id'] ?>" aria-label="Otwórz dokument EODoK z wiadomości od <?= h($r['from_addr']) ?>">Dokument</a><?php endif; ?>
          <?php if (!empty($r['queue_id']) && db_one("SELECT 1 FROM edok_queue WHERE id = ?", [(int)$r['queue_id']])): ?><a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= APP_URL ?>/edok/add.php?queue=<?= (int)$r['queue_id'] ?>" aria-label="Opisz plik <?= h($r['att_name']) ?>">Opisz</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<nav aria-label="Strony wyników"><ul class="pagination pagination-sm flex-wrap">
  <?php for ($i = 1; $i <= $pages; $i++): if ($i !== 1 && $i !== $pages && abs($i - $page) > 3) { if (abs($i - $page) === 4) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; continue; } ?>
    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= h($qs(['p' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
