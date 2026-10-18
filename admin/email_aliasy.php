<?php
/**
 * admin/email_aliasy.php — Zatwierdzanie wniosków wolontariuszy o alias e-mail.
 * Dostęp: operatorzy helpdesku + admini. Zatwierdzenie ustawia alias przez Graph API
 * (dopisanie do proxyAddresses) i aktualizuje powiązane zgłoszenie CHG.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/email_alias.php';
require_once dirname(__DIR__) . '/modules/mail_alias/logic/mailAlias.php';
email_alias_migrate();

require_login();
if (!hd_is_operator()) {
    flash_set('danger', 'Brak uprawnień — dostęp tylko dla operatorów helpdesku i administratorów.');
    header('Location: ' . APP_URL . '/index.php'); exit;
}

$me = current_user();

$wizard_link = APP_URL . '/modules/stopka/index.php#h-wz';

// ── Bezpośrednie nadawanie / usuwanie aliasu (Exchange Online) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['_action'] ?? '', ['alias_add', 'alias_remove'], true)) {
    csrf_check();
    $mb_id = trim((string)($_POST['mb'] ?? ''));
    try {
        if ($mb_id === '') throw new InvalidArgumentException('Podaj skrzynkę.');
        if ($_POST['_action'] === 'alias_add') {
            $addr = trim((string)($_POST['alias_local'] ?? '')) . '@' . trim((string)($_POST['alias_domain'] ?? ''));
            $mbx  = mailAliasAdd($mb_id, $addr);
            $msg  = 'Dodano alias ' . strtolower($addr) . ' do skrzynki ' . $mbx['identity'] . '.';
        } else {
            $addr = (string)($_POST['alias'] ?? '');
            $mbx  = mailAliasRemove($mb_id, $addr);
            $msg  = 'Usunięto alias ' . strtolower($addr) . ' ze skrzynki ' . $mbx['identity'] . '.';
        }
        $target = db_one("SELECT id FROM users WHERE lower(COALESCE(NULLIF(m365_login,''), email))=?", [strtolower($mbx['identity'])]);
        if (function_exists('log_user_action')) {
            log_user_action((int)($target['id'] ?? 0), (int)$me['id'], 'mail_' . $_POST['_action'], $msg);
        }
        flash_set('success', $msg);
    } catch (\Throwable $e) {
        flash_set('danger', 'Exchange: ' . $e->getMessage()
            . ' — jeśli to problem z uprawnieniami, użyj kreatora konfiguracji Exchange (link w sekcji „Nadaj alias”).');
    }
    header('Location: ' . APP_URL . '/admin/email_aliasy.php?mb=' . urlencode($mb_id) . '#nadaj'); exit;
}

// ── Obsługa decyzji ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $req_id   = (int)($_POST['req_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['decision_note'] ?? '');

    $req = $req_id ? db_one("SELECT * FROM email_alias_requests WHERE id=?", [$req_id]) : null;

    if (!$req || !in_array($req['status'], ['oczekuje', 'błąd'], true)) {
        flash_set('danger', 'Wniosek nie istnieje lub został już rozpatrzony.');
    } elseif ($decision === 'odrzuc') {
        db()->prepare(
            "UPDATE email_alias_requests
             SET status='odrzucony', decided_by=?, decided_at=CURRENT_TIMESTAMP, decision_note=?
             WHERE id=?"
        )->execute([(int)$me['id'], $note ?: null, $req_id]);
        _ealias_close_ticket((int)$req['ticket_id'], 'zamknięte',
            "Wniosek o alias {$req['requested_alias']} został odrzucony."
            . ($note ? "\n\nPowód: {$note}" : ''));
        flash_set('success', 'Wniosek odrzucony. Zgłaszający otrzymał powiadomienie.');
    } elseif ($decision === 'zatwierdz') {
        $enabled       = m365_setting('m365_enabled') === '1';
        $tenant_id     = m365_setting('m365_tenant_id');
        $client_id     = m365_setting('m365_graph_client_id');
        $client_secret = m365_setting('m365_graph_client_secret');

        if (!$enabled || !$tenant_id || !$client_id || !$client_secret) {
            flash_set('danger', 'Integracja Microsoft 365 nie jest skonfigurowana.');
        } elseif (empty($req['m365_user_id']) && empty($req['m365_login'])) {
            flash_set('danger', 'Wniosek nie ma powiązanego konta M365 (brak m365_user_id i loginu).');
        } else {
            // Exchange Online (Set-Mailbox) — jedyna droga dla skrzynek; Graph proxyAddresses tylko gdy Exchange niedostępny
            $exo_ok = true;
            try { stopkaExToken(); } catch (\Throwable $e) { $exo_ok = false; }
            if ($exo_ok) {
                try {
                    mailAliasAdd($req['m365_login'] ?: $req['m365_user_id'], $req['requested_alias']);
                    db()->prepare(
                        "UPDATE email_alias_requests
                         SET status='zatwierdzony', decided_by=?, decided_at=CURRENT_TIMESTAMP,
                             decision_note=?, applied_at=CURRENT_TIMESTAMP, graph_error=NULL
                         WHERE id=?"
                    )->execute([(int)$me['id'], $note ?: null, $req_id]);
                    _ealias_close_ticket((int)$req['ticket_id'], 'rozwiązane',
                        "Alias {$req['requested_alias']} został ustawiony w Exchange Online. "
                        . "Wiadomości na ten adres będą trafiać do skrzynki.");
                    flash_set('success', 'Alias ustawiony w Exchange Online. Zgłoszenie oznaczone jako rozwiązane.');
                } catch (\Throwable $e) {
                    db()->prepare("UPDATE email_alias_requests SET status='błąd', graph_error=? WHERE id=?")
                        ->execute([mb_substr('Exchange: ' . $e->getMessage(), 0, 500), $req_id]);
                    flash_set('danger', 'Błąd Exchange: ' . $e->getMessage());
                }
            } else try {
                $m365 = new M365Graph([
                    'tenant_id'     => $tenant_id,
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                ]);
                $ok = $m365->add_proxy_alias($req['m365_user_id'], $req['requested_alias']);
                if ($ok) {
                    db()->prepare(
                        "UPDATE email_alias_requests
                         SET status='zatwierdzony', decided_by=?, decided_at=CURRENT_TIMESTAMP,
                             decision_note=?, applied_at=CURRENT_TIMESTAMP, graph_error=NULL
                         WHERE id=?"
                    )->execute([(int)$me['id'], $note ?: null, $req_id]);
                    _ealias_close_ticket((int)$req['ticket_id'], 'rozwiązane',
                        "Alias {$req['requested_alias']} został ustawiony w Microsoft 365 "
                        . "(dodany do proxyAddresses). Wiadomości na ten adres będą trafiać do skrzynki.");
                    flash_set('success', 'Alias ustawiony przez Graph API. Zgłoszenie oznaczone jako rozwiązane.');
                } else {
                    db()->prepare(
                        "UPDATE email_alias_requests
                         SET status='błąd', graph_error=? WHERE id=?"
                    )->execute(['PATCH proxyAddresses nie potwierdził aliasu po zapisie.', $req_id]);
                    flash_set('danger', 'Microsoft 365 nie potwierdził ustawienia aliasu. Wniosek oznaczony jako „Błąd" — zgłoszenie pozostaje otwarte.');
                }
            } catch (\Exception $e) {
                db()->prepare(
                    "UPDATE email_alias_requests SET status='błąd', graph_error=? WHERE id=?"
                )->execute([mb_substr($e->getMessage(), 0, 500), $req_id]);
                flash_set('danger', 'Błąd Graph API: ' . $e->getMessage());
            }
        }
    }
    header('Location: ' . APP_URL . '/admin/email_aliasy.php'
        . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '')); exit;
}

/** Dodaje wiadomość do zgłoszenia i zmienia jego status, z powiadomieniem zgłaszającego. */
function _ealias_close_ticket(int $ticket_id, string $new_status, string $body): void {
    if (!$ticket_id) return;
    $ticket = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticket_id]);
    if (!$ticket) return;
    $old = $ticket['status'];

    db_insert('helpdesk_messages', [
        'ticket_id'   => $ticket_id,
        'user_id'     => (int)(current_user()['id'] ?? 0) ?: null,
        'user_name'   => current_user()['name'] ?? 'Operator',
        'body'        => $body,
        'is_internal' => 0,
    ]);

    $col = $new_status === 'rozwiązane' ? 'resolved_at' : ($new_status === 'zamknięte' ? 'closed_at' : null);
    $sql = "UPDATE helpdesk_tickets SET status=?, updated_at=CURRENT_TIMESTAMP"
         . ($col ? ", {$col}=CURRENT_TIMESTAMP" : '') . " WHERE id=?";
    db()->prepare($sql)->execute([$new_status, $ticket_id]);

    try {
        $ticket['status'] = $new_status;
        hd_notify_status_change($ticket, $old, $new_status, $body);
    } catch (\Throwable $e) {}
}

// ── Lista ────────────────────────────────────────────────────────────────────────
$filter = $_GET['status'] ?? '';
$where  = '1=1';
$params = [];
if ($filter && isset(EALIAS_STATUSES[$filter])) { $where .= ' AND r.status=?'; $params[] = $filter; }

$requests = db_all(
    "SELECT r.*, t.number AS ticket_number, u.name AS decider_name
     FROM email_alias_requests r
     LEFT JOIN helpdesk_tickets t ON t.id = r.ticket_id
     LEFT JOIN users u ON u.id = r.decided_by
     WHERE {$where}
     ORDER BY (r.status='oczekuje') DESC, r.id DESC",
    $params
);

$counts = ['' => 0];
foreach (array_keys(EALIAS_STATUSES) as $k) $counts[$k] = 0;
foreach (db_all("SELECT status, COUNT(*) AS c FROM email_alias_requests GROUP BY status") as $row) {
    $counts[$row['status']] = (int)$row['c'];
    $counts[''] += (int)$row['c'];
}

// ── Nadaj alias: odczyt skrzynki (tylko gdy podano ?mb=) ────────────────────────
$mb_q = trim((string)($_GET['mb'] ?? ''));
$mbx = null; $mbx_err = null; $mb_domains = [];
if ($mb_q !== '') {
    try {
        $mbx = mailAliasMailbox($mb_q);
        $mb_domains = mailAliasDomains();
    } catch (\Throwable $e) {
        $mbx_err = $e->getMessage();
    }
}
$known_mailboxes = mailAliasKnownMailboxes();

$PAGE_TITLE = 'Aliasy e-mail';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-at text-primary"></i> Wnioski o aliasy e-mail</h4>
</div>

<?= flash_html() ?>

<!-- Nadaj alias bezpośrednio (Exchange Online) -->
<div class="card shadow-sm mb-4" id="nadaj">
  <div class="card-body">
    <h5 class="card-title mb-1"><i class="bi bi-plus-circle text-primary"></i> Nadaj alias</h5>
    <p class="small text-muted mb-3">Dodaje lub usuwa dodatkowy adres skrzynki w Exchange Online — bez wniosku.
      Adres główny i login się nie zmieniają. Problem z uprawnieniami?
      <a href="<?= h($wizard_link) ?>">Kreator konfiguracji Exchange</a>.</p>

    <form method="get" class="row g-2 align-items-end mb-3" role="search">
      <div class="col-sm-8 col-lg-6">
        <label for="mb" class="form-label small mb-1">Skrzynka (login M365 lub adres e-mail)</label>
        <input type="text" id="mb" name="mb" class="form-control" list="mb_list" value="<?= h($mb_q) ?>"
               placeholder="jan.kowalski@feer.org.pl" autocomplete="off" required>
        <datalist id="mb_list">
          <?php foreach ($known_mailboxes as $km): ?>
          <option value="<?= h($km['login']) ?>"><?= h($km['name']) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-primary"><i class="bi bi-search me-1" aria-hidden="true"></i>Pokaż adresy</button>
      </div>
    </form>

    <?php if ($mbx_err): ?>
    <div class="alert alert-danger small mb-0" role="alert">
      <i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= h($mbx_err) ?>
      — <a href="<?= h($wizard_link) ?>" class="alert-link">Kreator konfiguracji Exchange</a>
    </div>
    <?php elseif ($mbx): ?>
    <div class="border rounded p-3">
      <div class="mb-2"><strong><?= h($mbx['name'] ?: $mbx['identity']) ?></strong>
        <span class="text-muted small">(<?= h($mbx['identity']) ?>)</span></div>
      <ul class="list-unstyled mb-3">
        <li class="d-flex align-items-center gap-2 py-1">
          <span class="font-monospace"><?= h($mbx['primary']) ?></span>
          <span class="badge bg-primary">adres główny</span>
        </li>
        <?php foreach ($mbx['aliases'] as $al): ?>
        <li class="d-flex align-items-center gap-2 py-1 border-top">
          <span class="font-monospace flex-grow-1"><?= h($al) ?></span>
          <form method="post" class="m-0">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="alias_remove">
            <input type="hidden" name="mb" value="<?= h($mbx['identity']) ?>">
            <input type="hidden" name="alias" value="<?= h($al) ?>">
            <button class="btn btn-sm btn-outline-danger" aria-label="Usuń alias <?= h($al) ?>"
                    onclick="return confirm('Usunąć alias <?= h($al) ?>? Poczta na ten adres przestanie dochodzić.')">
              <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń
            </button>
          </form>
        </li>
        <?php endforeach; ?>
        <?php if (!$mbx['aliases']): ?>
        <li class="small text-muted py-1 border-top">Brak aliasów.</li>
        <?php endif; ?>
      </ul>

      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="alias_add">
        <input type="hidden" name="mb" value="<?= h($mbx['identity']) ?>">
        <div class="col-sm-7 col-lg-5">
          <label for="alias_local" class="form-label small mb-1">Nowy alias</label>
          <div class="input-group">
            <input type="text" id="alias_local" name="alias_local" class="form-control" required
                   pattern="[a-z0-9]([a-z0-9._\-]{0,62}[a-z0-9])?" placeholder="kasia" autocomplete="off"
                   aria-describedby="alias_local_help">
            <span class="input-group-text">@</span>
            <select name="alias_domain" class="form-select" aria-label="Domena">
              <?php foreach ($mb_domains as $dom): ?>
              <option value="<?= h($dom) ?>"><?= h($dom) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="alias_local_help" class="form-text">Małe litery, cyfry, kropka, myślnik, podkreślenie.</div>
        </div>
        <div class="col-auto mb-4">
          <button class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj alias</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<h5 class="mb-2">Wnioski</h5>

<!-- Filtry -->
<div class="mb-3 d-flex gap-2 flex-wrap">
  <?php
  $tabs = ['' => ['label' => 'Wszystkie', 'class' => 'secondary']];
  foreach (EALIAS_STATUSES as $k => $st) $tabs[$k] = ['label' => $st['label'], 'class' => $st['class']];
  foreach ($tabs as $key => $tab):
      $active = $filter === $key ? '' : 'outline-';
      $url = APP_URL . '/admin/email_aliasy.php' . ($key ? '?status=' . $key : '');
  ?>
  <a href="<?= h($url) ?>" class="btn btn-sm btn-<?= $active ?><?= $tab['class'] ?>">
    <?= h($tab['label']) ?>
    <span class="badge bg-<?= $tab['class'] ?> ms-1"><?= (int)($counts[$key] ?? 0) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$requests): ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-at fs-1 d-block mb-2 opacity-25"></i>
    Brak wniosków<?= $filter ? ' o statusie „' . h(EALIAS_STATUSES[$filter]['label'] ?? $filter) . '"' : '' ?>.
  </div>
</div>
<?php else: ?>

<div class="d-flex flex-column gap-3">
<?php foreach ($requests as $req):
    $is_open = in_array($req['status'], ['oczekuje', 'błąd'], true);
?>
<div class="card shadow-sm <?= $req['status'] === 'oczekuje' ? 'border-warning' : ($req['status'] === 'błąd' ? 'border-danger' : '') ?>">
  <div class="card-body">
    <div class="row g-3 align-items-start">

      <div class="col-md-6">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
          <span class="fw-semibold font-monospace"><?= h($req['requested_alias']) ?></span>
          <?= ealias_status_badge($req['status']) ?>
        </div>
        <div class="small text-muted mb-1">
          <i class="bi bi-person"></i> <?= h($req['requester_name'] ?: $req['requester_email']) ?>
        </div>
        <div class="small text-muted mb-1">
          <i class="bi bi-box-arrow-in-right"></i> Login: <?= h($req['m365_login'] ?: '—') ?>
        </div>
        <div class="small text-muted mb-1">
          <i class="bi bi-calendar"></i> Złożono: <?= h(substr($req['created_at'] ?? '', 0, 16)) ?>
        </div>
        <?php if (!empty($req['ticket_number'])): ?>
        <div class="small mb-1">
          <i class="bi bi-ticket-perforated text-muted"></i>
          <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$req['ticket_id'] ?>" class="text-decoration-none">
            <?= h($req['ticket_number']) ?>
          </a>
        </div>
        <?php endif; ?>
        <?php if (!empty($req['decided_at'])): ?>
        <div class="small text-muted mb-1">
          <i class="bi bi-gavel"></i> Decyzja: <?= h($req['decider_name'] ?: '—') ?>, <?= h(substr($req['decided_at'], 0, 16)) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($req['decision_note'])): ?>
        <div class="small text-muted fst-italic">„<?= h($req['decision_note']) ?>"</div>
        <?php endif; ?>
        <?php if (!empty($req['graph_error'])): ?>
        <div class="alert alert-danger py-1 px-2 small mt-2 mb-0"><i class="bi bi-bug me-1"></i><?= h($req['graph_error']) ?></div>
        <?php endif; ?>
      </div>

      <?php if ($is_open): ?>
      <div class="col-md-6">
        <form method="post" class="d-flex flex-column gap-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="req_id" value="<?= (int)$req['id'] ?>">
          <textarea name="decision_note" rows="2" class="form-control form-control-sm"
                    placeholder="Notatka / powód (opcjonalnie)"></textarea>
          <div class="d-flex gap-2">
            <button type="submit" name="decision" value="zatwierdz"
                    class="btn btn-success btn-sm flex-grow-1"
                    onclick="return confirm('Zatwierdzić i ustawić alias <?= h($req['requested_alias']) ?> przez Microsoft 365?')">
              <i class="bi bi-check-lg me-1"></i>Zatwierdź i ustaw
            </button>
            <button type="submit" name="decision" value="odrzuc"
                    class="btn btn-outline-danger btn-sm flex-grow-1"
                    onclick="return confirm('Odrzucić ten wniosek?')">
              <i class="bi bi-x-lg me-1"></i>Odrzuć
            </button>
          </div>
        </form>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
