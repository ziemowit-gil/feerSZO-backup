<?php
/**
 * crm/settings/suppressions.php — lista wykluczeń wysyłek (crm_suppressions).
 *
 * PO CO OSOBNA LISTA, JEŚLI JEST JUŻ email_opt_out NA KONTAKCIE:
 * flaga chroni JEDEN rekord kontaktu. Ten sam adres wraca do CRM przy importach
 * i synchronizacji z Outlookiem jako nowy rekord — bez flagi. Lista wykluczeń
 * jest prowadzona per adres, więc wypisanie i twarde odbicie przetrwają
 * ponowne dodanie kontaktu. Kampanie odsiewają OBA źródła.
 *
 * Wpisy powstają automatycznie: wypisanie (crm/track/unsub.php), twarde odbicie
 * rozpoznane po komunikacie serwera (crm_campaign_refresh_stats). Ręcznie można
 * dodać adres i — świadomą decyzją — zdjąć go z listy.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_campaign.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do ustawień CRM.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_migrate();

$PAGE_TITLE = 'CRM — Lista wykluczeń';

$REASONS = [
    'unsubscribe' => ['Wypisanie',            '#6B7280', 'bi-person-x'],
    'hard_bounce' => ['Adres nie istnieje',   '#DC2626', 'bi-x-octagon'],
    'soft_bounce' => ['Chwilowo niedostępny', '#D97706', 'bi-hourglass'],
    'complaint'   => ['Zgłoszenie spamu',     '#B91C1C', 'bi-flag'],
    'manual'      => ['Decyzja operatora',    '#0176D3', 'bi-hand-index'],
    'rodo'        => ['Żądanie RODO',         '#7C3AED', 'bi-shield-lock'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'add') {
        $emails = preg_split('/[\s,;]+/', (string)($_POST['emails'] ?? '')) ?: [];
        $reason = array_key_exists((string)($_POST['reason'] ?? ''), $REASONS) ? $_POST['reason'] : 'manual';
        $detail = trim((string)($_POST['detail'] ?? ''));
        $added = $bad = 0;
        foreach ($emails as $e) {
            $e = trim($e);
            if ($e === '') continue;
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) { $bad++; continue; }
            crm_suppression_add($e, $reason, null, $detail);
            $added++;
        }
        flash_set($added ? 'success' : 'danger',
            $added . ' adres(ów) na liście wykluczeń.' . ($bad ? " Pominięto {$bad} nieprawidłowych." : ''));

    } elseif ($op === 'remove') {
        $email = trim((string)($_POST['email'] ?? ''));
        // Zdjęcie z listy to świadome wznowienie wysyłek na adres, który wcześniej
        // odbił albo się wypisał — dlatego trafia do logu synchronizacji CRM.
        crm_suppression_remove($email);
        try {
            db_insert('crm_sync_log', [
                'source'  => 'suppression_remove',
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
                'details' => 'Zdjęto z listy wykluczeń: ' . $email
                           . ' (użytkownik #' . (int)(current_user()['id'] ?? 0) . ')',
            ]);
        } catch (\Throwable $e) {}
        flash_set('success', 'Adres zdjęty z listy wykluczeń: ' . $email);
    }

    header('Location: ' . APP_URL . '/crm/settings/suppressions.php');
    exit;
}

$q      = trim((string)($_GET['q'] ?? ''));
$reason = (string)($_GET['reason'] ?? '');

$sql    = "SELECT * FROM crm_suppressions WHERE 1=1";
$params = [];
if ($q !== '')                                { $sql .= " AND email LIKE ?"; $params[] = '%' . mb_strtolower($q) . '%'; }
if (array_key_exists($reason, $REASONS))      { $sql .= " AND reason = ?";   $params[] = $reason; }
$sql .= " ORDER BY created_at DESC LIMIT 500";

$rows  = db_all($sql, $params);
$total = (int)(db_one("SELECT COUNT(*) AS c FROM crm_suppressions")['c'] ?? 0);
$by_reason = db_all("SELECT reason, COUNT(*) AS n FROM crm_suppressions GROUP BY reason ORDER BY n DESC");
$opt_out   = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE COALESCE(email_opt_out,0)=1")['c'] ?? 0);

include __DIR__ . '/../includes/header_crm.php';
require_once __DIR__ . '/_nav.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0 small">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/settings/">Ustawienia CRM</a></li>
  <li class="breadcrumb-item active">Lista wykluczeń</li>
</ol></nav>

<div class="crm-object-header shadow-sm mb-3">
  <div class="crm-object-icon"><i class="bi bi-slash-circle"></i></div>
  <div>
    <h1 class="crm-object-title">Lista wykluczeń wysyłek</h1>
    <div class="crm-object-count">
      <?= $total ?> adres(ów) na liście · <?= $opt_out ?> kontakt(ów) z flagą opt-out
    </div>
  </div>
</div>

<div class="alert alert-light border py-2 mb-3" style="font-size:.83rem">
  Do adresów z tej listy <strong>żadna kampania nie wyśle wiadomości</strong> — niezależnie od segmentu
  i niezależnie od tego, ile razy kontakt zostanie ponownie zaimportowany. Lista działa na adres,
  a flaga <code>opt_out</code> na pojedynczy rekord kontaktu; jedno nie zastępuje drugiego.
</div>

<div class="row g-3">
  <div class="col-lg-8">

    <form class="d-flex gap-2 mb-3" method="get">
      <input type="search" name="q" class="form-control form-control-sm" placeholder="Szukaj adresu…" value="<?= h($q) ?>">
      <select name="reason" class="form-select form-select-sm" style="max-width:230px">
        <option value="">Wszystkie powody</option>
        <?php foreach ($REASONS as $k => $r): ?>
        <option value="<?= $k ?>"<?= $reason === $k ? ' selected' : '' ?>><?= h($r[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-crm-primary">Filtruj</button>
    </form>

    <?php if ($by_reason): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php foreach ($by_reason as $b):
        [$lab, $col, $ico] = $REASONS[$b['reason']] ?? [$b['reason'], '#6B7280', 'bi-dot']; ?>
        <span class="badge" style="background:<?= $col ?>1a;color:<?= $col ?>">
          <i class="bi <?= $ico ?> me-1"></i><?= h($lab) ?>: <?= (int)$b['n'] ?>
        </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Adres</th><th>Powód</th><th>Dodano</th><th>Szczegóły</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        [$lab, $col, $ico] = $REASONS[$r['reason']] ?? [$r['reason'], '#6B7280', 'bi-dot']; ?>
        <tr>
          <td class="small"><?= h($r['email']) ?></td>
          <td><span class="badge" style="background:<?= $col ?>1a;color:<?= $col ?>"><i class="bi <?= $ico ?> me-1"></i><?= h($lab) ?></span></td>
          <td class="text-muted small"><?= $r['created_at'] ? h(date('d.m.Y H:i', strtotime($r['created_at']))) : '—' ?></td>
          <td class="text-muted small"><?= h(mb_substr((string)($r['detail'] ?? ''), 0, 70)) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Zdjąć <?= h($r['email']) ?> z listy wykluczeń? Kampanie zaczną znów wysyłać na ten adres.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_op" value="remove">
              <input type="hidden" name="email" value="<?= h($r['email']) ?>">
              <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Zdejmij z listy"><i class="bi bi-arrow-counterclockwise"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
      <tr><td colspan="5" class="text-center text-muted py-4">
        <?= $q !== '' || $reason !== '' ? 'Brak wyników dla tego filtra.' : 'Lista jest pusta — to dobra wiadomość.' ?>
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if (count($rows) === 500): ?>
    <p class="text-muted small">Pokazano 500 najnowszych wpisów — zawęź filtrem, żeby zobaczyć pozostałe.</p>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="cv-panel">
      <div class="cv-panel__body">
        <h2 class="h6 fw-bold mb-2">Dodaj adresy ręcznie</h2>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="add">
          <div class="mb-2">
            <label class="form-label small" for="sEmails">Adresy e-mail</label>
            <textarea class="form-control form-control-sm" id="sEmails" name="emails" rows="5"
                      placeholder="jeden adres w linii albo po przecinku" required></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label small" for="sReason">Powód</label>
            <select class="form-select form-select-sm" id="sReason" name="reason">
              <?php foreach ($REASONS as $k => $r): ?>
              <option value="<?= $k ?>"<?= $k === 'manual' ? ' selected' : '' ?>><?= h($r[0]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small" for="sDetail">Notatka (opcjonalnie)</label>
            <input type="text" class="form-control form-control-sm" id="sDetail" name="detail" maxlength="200">
          </div>
          <button class="btn btn-crm-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Dodaj do listy</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/_nav_end.php'; ?>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
