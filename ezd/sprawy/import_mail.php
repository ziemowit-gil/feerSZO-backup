<?php
/**
 * ezd/sprawy/import_mail.php — Import wiadomości e-mail do koszulki EZD.
 *
 * Odwrotny kierunek niż Skrzynka CRM: tam wiadomość „idzie" do sprawy pojedynczo,
 * tu z poziomu koszulki wybiera się z listy korespondencji to, co należy do akt.
 * Każda zaimportowana wiadomość dostaje pismo w rejestrze (EzdMailService), więc
 * numeracja i metryka pozostają spójne z pismami wprowadzanymi ręcznie.
 *
 * Widoczne są tylko wiadomości ze skrzynek, do których użytkownik ma dostęp
 * (poczta_mailbox_acl) i takie, które nie są jeszcze przypisane do żadnej sprawy.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';
require_once dirname(dirname(__DIR__)) . '/includes/poczta_acl.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
if (!in_array($access, ['write', 'pisma'], true)) {
    flash_set('error', 'Do importu pism potrzebny jest dostęp do pism tej koszulki.');
    header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
}
if ($sprawa['status'] === 'closed') {
    flash_set('warning', 'Koszulka jest zamknięta — najpierw ją otwórz, żeby dołożyć pisma.');
    header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
}

poczta_acl_migrate();
$PAGE_TITLE = 'Import maili — ' . $sprawa['znak_sprawy'];

// Strony koszulki powiązane z CRM — najpewniejsza podpowiedź, czyja to korespondencja
$strony = [];
try {
    $strony = db_all(
        "SELECT s.crm_id, c.imie_nazwisko, c.email
         FROM ezd_strony s JOIN crm_contacts c ON c.id = s.crm_id
         WHERE s.sprawa_id=? AND s.crm_id IS NOT NULL", [$id]
    );
} catch (\Throwable $e) {}
$strony_ids = array_values(array_filter(array_map(static fn($s) => (int)$s['crm_id'], $strony)));

// ── Import ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'import') {
    csrf_check();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['comm_ids'] ?? []))));
    if (!$ids) {
        flash_set('warning', 'Nie wybrano żadnej wiadomości.');
    } else {
        $svc = new EzdMailService();
        $ok = 0; $skipped = 0; $errors = [];
        foreach ($ids as $cid) {
            $c = db_one("SELECT id, mailbox_id, ezd_sprawa_id FROM crm_communications WHERE id=?", [$cid]);
            if (!$c) { $skipped++; continue; }
            // Nie importujemy wiadomości ze skrzynki bez uprawnień ani już przypisanych
            $mb = (int)($c['mailbox_id'] ?? 0);
            if ($mb && !poczta_can_access($mb, 'read')) { $skipped++; continue; }
            if (!$mb && !is_admin()) { $skipped++; continue; }
            if (!empty($c['ezd_sprawa_id'])) { $skipped++; continue; }
            try {
                $svc->linkCommToSprawa($cid, $id);
                db()->prepare("UPDATE crm_communications SET inbox_status='archived', is_read=1 WHERE id=?")->execute([$cid]);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = '#' . $cid . ': ' . $e->getMessage();
            }
        }
        $msg = 'Zaimportowano pism: ' . $ok . ($skipped ? ', pominięto: ' . $skipped : '');
        flash_set($errors ? 'warning' : 'success', $msg . ($errors ? '. Błędy: ' . implode(' | ', array_slice($errors, 0, 3)) : '.'));
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#tab-pisma'); exit;
    }
}

// ── Kandydaci ───────────────────────────────────────────────────────────────
$scope   = trim((string)($_GET['scope'] ?? ($strony_ids ? 'strony' : 'all')));
$q       = trim((string)($_GET['q'] ?? ''));
$days    = max(7, min(3650, (int)($_GET['days'] ?? 365)));
$per     = 50;

$where  = ["c.channel='email'", "c.ezd_sprawa_id IS NULL", poczta_scope_sql('c.mailbox_id')];
$params = [];
$where[] = "c.sent_at >= ?";
$params[] = date('Y-m-d H:i:s', time() - $days * 86400);

if ($scope === 'strony' && $strony_ids) {
    $where[] = 'c.contact_id IN (' . implode(',', $strony_ids) . ')';
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(c.subject LIKE ? OR c.from_email LIKE ? OR c.from_name LIKE ? OR ct.imie_nazwisko LIKE ? OR c.body LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$rows = [];
$total = 0;
try {
    $total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM crm_communications c
         LEFT JOIN crm_contacts ct ON ct.id=c.contact_id $where_sql", $params
    )['n'] ?? 0);
    $rows = db_all(
        "SELECT c.id, c.subject, c.body, c.direction, c.sent_at, c.from_name, c.from_email,
                c.has_attachments, c.mailbox_id, ct.imie_nazwisko AS contact_name,
                m.mailbox AS mailbox_name
         FROM crm_communications c
         LEFT JOIN crm_contacts ct ON ct.id = c.contact_id
         LEFT JOIN poczta_mailboxes m ON m.id = c.mailbox_id
         $where_sql
         ORDER BY c.sent_at DESC LIMIT $per", $params
    );
} catch (\Throwable $e) {
    flash_set('danger', 'Nie udało się pobrać listy wiadomości: ' . $e->getMessage());
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
    <li class="breadcrumb-item active">Import maili</li>
  </ol>
</nav>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-1 fw-bold"><i class="bi bi-envelope-arrow-down me-2 text-primary"></i>Import maili do koszulki</h4>
    <div class="text-muted" style="font-size:.86rem">
      <?= h($sprawa['znak_sprawy']) ?> — <?= h($sprawa['title']) ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Wróć do koszulki
  </a>
</div>

<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="row g-2 align-items-center">
    <div class="col-md-4">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input name="q" class="form-control" placeholder="Temat, nadawca, treść…" value="<?= h($q) ?>">
      </div>
    </div>
    <div class="col-md-4">
      <select name="scope" class="form-select form-select-sm">
        <option value="strony" <?= $scope === 'strony' ? 'selected' : '' ?> <?= $strony_ids ? '' : 'disabled' ?>>
          Tylko korespondencja stron koszulki<?= $strony_ids ? '' : ' (brak stron z CRM)' ?>
        </option>
        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Cała dostępna korespondencja</option>
      </select>
    </div>
    <div class="col-md-2">
      <select name="days" class="form-select form-select-sm" aria-label="Zakres czasu">
        <?php foreach ([30 => '30 dni', 90 => '90 dni', 365 => 'rok', 1095 => '3 lata'] as $dv => $dl): ?>
        <option value="<?= $dv ?>" <?= $days === $dv ? 'selected' : '' ?>><?= $dl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtruj</button>
    </div>
  </div>
  <?php if ($strony): ?>
  <div class="text-muted mt-2" style="font-size:.78rem">
    Strony koszulki:
    <?php foreach ($strony as $st): ?>
    <span class="badge bg-light text-dark border me-1"><?= h($st['imie_nazwisko']) ?><?= $st['email'] ? ' · ' . h($st['email']) : '' ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div></form>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_op" value="import">
<input type="hidden" name="id" value="<?= $id ?>">

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="fw-semibold" style="font-size:.92rem">
      Do wyboru: <?= (int)$total ?><?= $total > $per ? ' (pokazano ' . $per . ')' : '' ?>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="imAll">Zaznacz widoczne</button>
      <button type="submit" class="btn btn-sm btn-primary" id="imGo" disabled>
        <i class="bi bi-download me-1"></i>Importuj wybrane (<span id="imCount">0</span>)
      </button>
    </div>
  </div>

  <?php if (!$rows): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-inbox" style="font-size:2.2rem;opacity:.3"></i>
    <p class="mt-2 mb-0" style="font-size:.88rem">
      Brak wiadomości spełniających kryteria.
      <?php if ($scope === 'strony' && $strony_ids): ?>
      <br>Spróbuj przełączyć na „Cała dostępna korespondencja".
      <?php endif; ?>
    </p>
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light">
        <tr>
          <th style="width:36px"></th>
          <th>Temat</th>
          <th>Od / do</th>
          <th>Kontakt</th>
          <th>Skrzynka</th>
          <th class="text-nowrap">Data</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><input type="checkbox" class="form-check-input im-pick" name="comm_ids[]" value="<?= (int)$r['id'] ?>"
                   aria-label="Wybierz wiadomość <?= h($r['subject'] ?: '(bez tematu)') ?>"></td>
        <td>
          <div class="fw-semibold">
            <i class="bi bi-<?= $r['direction'] === 'in' ? 'arrow-down-left text-info' : 'arrow-up-right text-primary' ?> me-1"></i>
            <?= h($r['subject'] ?: '(bez tematu)') ?>
            <?php if ((int)$r['has_attachments']): ?><i class="bi bi-paperclip text-muted ms-1" title="Załączniki"></i><?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.78rem">
            <?= h(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['body'])), 0, 110)) ?>
          </div>
        </td>
        <td class="text-nowrap"><?= h($r['from_name'] ?: ($r['from_email'] ?: '—')) ?></td>
        <td><?= h($r['contact_name'] ?: '—') ?></td>
        <td class="text-muted" style="font-size:.78rem"><?= h($r['mailbox_name'] ?: '—') ?></td>
        <td class="text-nowrap text-muted"><?= h(date('d.m.Y H:i', strtotime((string)$r['sent_at']))) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white text-muted" style="font-size:.78rem">
    Każda zaimportowana wiadomość dostanie pismo w rejestrze koszulki (sygnatura PK/WY)
    i zniknie ze Skrzynki CRM jako załatwiona. Wiadomości już przypisane do innej sprawy nie są pokazywane.
  </div>
  <?php endif; ?>
</div>
</form>

<script>
(function () {
  const go = document.getElementById('imGo');
  const cnt = document.getElementById('imCount');
  const all = document.getElementById('imAll');
  function refresh() {
    const n = document.querySelectorAll('.im-pick:checked').length;
    cnt.textContent = n;
    go.disabled = n === 0;
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList.contains('im-pick')) refresh();
  });
  if (all) all.addEventListener('click', function () {
    const boxes = document.querySelectorAll('.im-pick');
    const target = Array.from(boxes).some(b => !b.checked);
    boxes.forEach(b => { b.checked = target; });
    refresh();
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
