<?php
/**
 * crm/janitor.php — przegląd znalezisk bota sprzątającego.
 *
 * Bot naprawia kosmetykę sam i o niej nie pyta. Tutaj leży to, czego NIE wolno
 * mu ruszyć bez decyzji człowieka: duplikaty, osoby do przypięcia pod podmiot,
 * puste kartoteki. Każde zgłoszenie ma dwa wyjścia — „zajmę się” (prowadzi do
 * właściwego narzędzia) albo „odrzuć” (znika i nie wraca przy kolejnym
 * przebiegu, bo powtarzanie odrzuconej propozycji uczy ignorowania listy).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_janitor.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_janitor_migrate();

$can_write = is_admin() || can_write('crm');
if (!$can_write) {
    flash_set('error', 'Brak uprawnień do porządkowania kartoteki.');
    header('Location: ' . APP_URL . '/crm/index.php'); exit;
}

$RULES = crm_janitor_rules();
$rule  = isset($_GET['rule'], $RULES[$_GET['rule']]) ? (string)$_GET['rule'] : '';

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $uid = (int)(current_user()['id'] ?? 0) ?: null;

    if ($op === 'run') {
        // Uruchomienie ręczne — bot i tak chodzi z cronu, ale po większym
        // imporcie chce się zobaczyć wynik od razu, a nie nazajutrz.
        $r = crm_janitor_run(false);
        flash_set('success', sprintf(
            'Bot przeszedł bazę: naprawił %d %s, zgłosił %d %s do decyzji.',
            $r['fixed'], $r['fixed'] === 1 ? 'drobiazg' : 'drobiazgów',
            $r['found'], $r['found'] === 1 ? 'sprawę' : 'spraw'
        ));
    } elseif ($op === 'done' || $op === 'dismiss') {
        crm_janitor_close((int)($_POST['id'] ?? 0), $op === 'done' ? 'done' : 'dismissed', $uid);
        flash_set('success', $op === 'done' ? 'Oznaczone jako załatwione.' : 'Odrzucone — nie wróci.');
    } elseif ($op === 'dismiss_rule') {
        // Hurtowe odrzucenie całej kategorii: przydatne, gdy ktoś świadomie
        // godzi się na stan rzeczy (np. kontakty bez opiekuna w małym zespole).
        $r = (string)($_POST['rule'] ?? '');
        $n = 0;
        foreach (crm_janitor_open($r, 500) as $f) { crm_janitor_close((int)$f['id'], 'dismissed', $uid); $n++; }
        flash_set('success', "Odrzucono zgłoszeń: {$n}.");
    }
    header('Location: ?' . http_build_query(array_filter(['rule' => $rule]))); exit;
}

$counts   = crm_janitor_counts();
$findings = crm_janitor_open($rule, 300);
$last_run = db_one("SELECT * FROM crm_janitor_runs ORDER BY id DESC LIMIT 1");

$PAGE_TITLE = 'Bot sprzątający';
include __DIR__ . '/includes/header_crm.php';
?>
<style>
.jn-row   { display:grid; grid-template-columns:auto 1fr auto; gap:.75rem; align-items:start;
            padding:.75rem 1rem; border-bottom:1px solid #F1F2F4 }
.jn-row:last-child { border-bottom:none }
.jn-sev   { width:8px; height:8px; border-radius:50%; margin-top:.45rem; flex-shrink:0 }
.jn-title { font-weight:600; font-size:.88rem; color:#111827 }
.jn-det   { font-size:.78rem; color:#6B7280; margin-top:.15rem }
.jn-tag   { display:inline-block; font-size:.68rem; font-weight:700; letter-spacing:.04em;
            text-transform:uppercase; color:#6B7280; background:#F3F4F6; border-radius:4px;
            padding:.05rem .35rem; margin-right:.35rem }
.jn-pill  { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .7rem; border-radius:2rem;
            font-size:.78rem; font-weight:600; text-decoration:none; border:2px solid transparent }
</style>

<div class="container-fluid px-0" style="max-width:1000px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a href="<?= APP_URL ?>/crm/dashboard.php" class="btn btn-sm btn-crm-ghost" aria-label="Wróć do dashboardu"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-stars me-2"></i>Bot sprzątający</h1>
    <form method="post" class="m-0">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="run">
      <button class="btn btn-sm btn-crm-primary"><i class="bi bi-play-fill me-1"></i>Przejdź bazę teraz</button>
    </form>
  </div>

  <p class="text-muted small mb-3" style="max-width:80ch">
    Bot poprawia <strong>sam</strong> tylko kosmetykę — spacje, wielkość liter w adresach,
    myślniki w NIP-ie, brakujący protokół w adresie strony. Każda taka poprawka jest widoczna
    w historii zmian kartoteki. Wszystko, co zmienia sens rekordu albo czego nie cofa jedno
    kliknięcie, ląduje niżej jako <strong>propozycja</strong>.
    <?php if ($last_run): ?>
    Ostatni przebieg: <?= h(date_pl($last_run['started_at'])) ?> —
    naprawił <?= (int)$last_run['fixed'] ?>, zgłosił <?= (int)$last_run['found'] ?>.
    <?php endif; ?>
  </p>

  <!-- Filtry -->
  <div class="d-flex flex-wrap gap-1 mb-3">
    <a href="?" class="jn-pill" <?= $rule === '' ? 'aria-current="page"' : '' ?>
       style="background:<?= $rule === '' ? 'var(--crm-primary-bg)' : '#F3F4F6' ?>;
              color:<?= $rule === '' ? 'var(--crm-primary)' : '#374151' ?>;
              border-color:<?= $rule === '' ? 'var(--crm-primary)' : 'transparent' ?>">
      Wszystkie <span class="badge bg-secondary"><?= (int)($counts['ALL'] ?? 0) ?></span>
    </a>
    <?php foreach ($RULES as $rk => $rv):
      if (($rv['mode'] ?? '') !== 'propose') continue;      // kosmetyki nie trafiają na listę
      $n  = (int)($counts[$rk] ?? 0);
      $on = $rule === $rk;
    ?>
    <a href="?rule=<?= h($rk) ?>" class="jn-pill" <?= $on ? 'aria-current="page"' : '' ?>
       style="background:<?= $on ? 'var(--crm-primary-bg)' : '#F3F4F6' ?>;
              color:<?= $on ? 'var(--crm-primary)' : '#374151' ?>;
              border-color:<?= $on ? 'var(--crm-primary)' : 'transparent' ?>">
      <?= h($rv['label']) ?>
      <span class="badge <?= $n ? 'bg-secondary' : 'bg-light text-muted' ?>"><?= $n ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Lista znalezisk -->
  <div class="card border-0 shadow-sm">
    <?php if (!$findings): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-check2-circle d-block mb-2" style="font-size:1.6rem;color:#2E844A" aria-hidden="true"></i>
      Nic nie czeka na decyzję. Bot poprawia kosmetykę sam, w tle.
    </div>
    <?php else: foreach ($findings as $f):
      $cfg  = $RULES[$f['rule']] ?? [];
      $high = $f['severity'] === 'high';
      $pl   = json_decode((string)$f['payload'], true) ?: [];
      // Odnośnik do narzędzia, które tę sprawę faktycznie rozwiązuje.
      $link = match ($f['rule']) {
          'duplicates'    => !empty($pl['ids'][1])
              ? APP_URL . '/crm/contact/merge.php?a=' . (int)$pl['ids'][0] . '&b=' . (int)$pl['ids'][1]
              : APP_URL . '/crm/contact/merge.php',
          'domain_attach' => APP_URL . '/crm/contact/domains.php?domain=' . urlencode((string)($pl['domain'] ?? '')),
          'empty_contact' => APP_URL . '/crm/contact/view.php?id=' . (int)($pl['id'] ?? 0),
          default         => APP_URL . ($cfg['link'] ?? '/crm/index.php'),
      };
    ?>
    <div class="jn-row">
      <span class="jn-sev" style="background:<?= $high ? '#DC2626' : '#9CA3AF' ?>" aria-hidden="true"></span>
      <div style="min-width:0">
        <div class="jn-title">
          <span class="jn-tag"><?= h($cfg['label'] ?? $f['rule']) ?></span>
          <?= h($f['title']) ?>
        </div>
        <div class="jn-det"><?= h($f['detail']) ?></div>
      </div>
      <div class="d-flex gap-1 flex-shrink-0">
        <a href="<?= h($link) ?>" class="btn btn-sm btn-crm-outline py-0 px-2" title="Otwórz narzędzie, które to rozwiązuje">
          <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Zajmę się
        </a>
        <form method="post" class="m-0">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <input type="hidden" name="_op" value="done">
          <button class="btn btn-sm btn-crm-outline py-0 px-2" title="Załatwione — zdejmij z listy">
            <i class="bi bi-check-lg" aria-hidden="true"></i>
          </button>
        </form>
        <form method="post" class="m-0">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <input type="hidden" name="_op" value="dismiss">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Odrzuć — nie wróci przy kolejnym przebiegu">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($rule !== '' && $findings): ?>
  <form method="post" class="mt-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_op" value="dismiss_rule">
    <input type="hidden" name="rule" value="<?= h($rule) ?>">
    <button class="btn btn-sm btn-outline-secondary"
            onclick="return confirm('Odrzucić wszystkie zgłoszenia w tej kategorii?')">
      Odrzuć całą kategorię
    </button>
    <span class="text-muted small ms-1">Gdy świadomie godzisz się na ten stan rzeczy.</span>
  </form>
  <?php endif; ?>

  <!-- Co bot poprawia sam -->
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
      <div class="crm-section-title">Poprawiane automatycznie</div>
      <div class="row g-2">
        <?php foreach ($RULES as $rk => $rv): if (($rv['mode'] ?? '') !== 'auto') continue;
              $on = crm_janitor_rule_enabled($rk); ?>
        <div class="col-sm-6 d-flex gap-2 align-items-start">
          <i class="bi <?= $on ? 'bi-check-circle-fill text-success' : 'bi-slash-circle text-muted' ?> mt-1" aria-hidden="true"></i>
          <div>
            <div class="fw-semibold" style="font-size:.83rem"><?= h($rv['label']) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= h($rv['desc']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="text-muted mb-0 mt-2" style="font-size:.75rem">
        Regułę wyłącza się wpisem w ustawieniach <code>crm_janitor_off</code>
        (klucze po przecinku). Wyłączona reguła przestaje ruszać dane, ale bot chodzi dalej.
      </p>
    </div>
  </div>

</div>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
