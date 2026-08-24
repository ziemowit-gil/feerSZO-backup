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
$tab   = in_array($_GET['tab'] ?? '', ['reguly', 'historia'], true) ? (string)$_GET['tab'] : 'znaleziska';

/** Podgląd dry-run trzymamy w sesji: po zapisie robimy przekierowanie (PRG),
    a wynik ma przetrwać do wyświetlenia i zniknąć po jednym pokazaniu. */
$preview = $_SESSION['crm_janitor_preview'] ?? null;
unset($_SESSION['crm_janitor_preview']);

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $uid = (int)(current_user()['id'] ?? 0) ?: null;

    if ($op === 'preview') {
        // Nic nie zapisuje — pokazuje, co BY się stało. To jedyny sposób,
        // żeby przed pierwszym uruchomieniem na żywej bazie zobaczyć zakres.
        $r = crm_janitor_run(true);
        $_SESSION['crm_janitor_preview'] = $r;
        header('Location: ?' . http_build_query(array_filter(['tab' => $tab, 'rule' => $rule]))); exit;
    }

    if ($op === 'save_rules') {
        crm_janitor_set_enabled(array_map('strval', (array)($_POST['rules'] ?? [])));
        flash_set('success', 'Zapisano, które reguły są włączone.');
        header('Location: ?tab=reguly'); exit;
    }

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
    } elseif ($op === 'bulk') {
        $res = crm_janitor_bulk((string)($_POST['action'] ?? ''), (array)($_POST['pick'] ?? []), $uid);
        // Komunikat mówi wprost, ile się NIE udało — raportowanie samego sukcesu
        // na podstawie liczby kliknięć ukrywałoby, że część kartotek już nie
        // istnieje albo nie dała się scalić.
        $msg = sprintf('Wykonano: %d.', $res['done']);
        if ($res['failed']) $msg .= sprintf(' Nie udało się: %d.', $res['failed']);
        if ($res['errors'])  $msg .= ' ' . implode(' ', array_slice($res['errors'], 0, 3));
        flash_set($res['failed'] && !$res['done'] ? 'danger' : ($res['failed'] ? 'warning' : 'success'), $msg);

    } elseif ($op === 'dismiss_rule') {
        // Hurtowe odrzucenie całej kategorii: przydatne, gdy ktoś świadomie
        // godzi się na stan rzeczy (np. kontakty bez opiekuna w małym zespole).
        $r = (string)($_POST['rule'] ?? '');
        $n = 0;
        foreach (crm_janitor_open($r, 500) as $f) { crm_janitor_close((int)$f['id'], 'dismissed', $uid); $n++; }
        flash_set('success', "Odrzucono zgłoszeń: {$n}.");
    }
    header('Location: ?' . http_build_query(array_filter(['tab' => $tab, 'rule' => $rule]))); exit;
}

$counts   = crm_janitor_counts();
$findings = crm_janitor_open($rule, 300);
$runs     = crm_janitor_runs(20);
$last_run = $runs[0] ?? null;

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
    <form method="post" class="m-0 d-flex gap-1">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <button name="_op" value="preview" class="btn btn-sm btn-crm-outline"
              title="Przejdź bazę i pokaż, co BY się zmieniło — bez zapisu">
        <i class="bi bi-eye me-1" aria-hidden="true"></i>Podgląd
      </button>
      <button name="_op" value="run" class="btn btn-sm btn-crm-primary"
              onclick="return confirm('Bot poprawi kosmetykę w kartotece i zgłosi resztę do decyzji. Kontynuować?')">
        <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Przejdź bazę teraz
      </button>
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

  <?php if ($preview): ?>
  <?php /* Wynik dry-run. Świadomie nad zakładkami — to odpowiedź na kliknięcie,
           które użytkownik przed chwilą wykonał, więc ma być pierwszą rzeczą,
           jaką widzi po powrocie. */ ?>
  <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0176D3 !important">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-eye-fill" style="color:#0176D3" aria-hidden="true"></i>
        <strong style="font-size:.9rem">Podgląd — nic nie zostało zapisane</strong>
      </div>
      <p class="mb-2" style="font-size:.85rem">
        Bot poprawiłby <strong><?= (int)$preview['fixed'] ?></strong>
        <?= $preview['fixed'] === 1 ? 'drobiazg' : 'drobiazgów' ?>
        i zgłosił <strong><?= (int)$preview['found'] ?></strong>
        <?= $preview['found'] === 1 ? 'sprawę' : 'spraw' ?> do decyzji.
      </p>
      <?php if (!empty($preview['per_rule'])): ?>
      <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach ($preview['per_rule'] as $rk => $n): ?>
        <span class="badge bg-light text-dark border"><?= h($RULES[$rk]['label'] ?? $rk) ?>: <?= (int)$n ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($preview['samples'])): ?>
      <details>
        <summary class="text-muted" style="font-size:.8rem;cursor:pointer">Przykłady poprawek, które by poszły</summary>
        <table class="table table-sm mt-2 mb-0" style="font-size:.78rem">
          <caption class="visually-hidden">Przykładowe poprawki kosmetyczne</caption>
          <thead class="table-light"><tr>
            <th scope="col">Kartoteka</th><th scope="col">Pole</th><th scope="col">Przed</th><th scope="col">Po</th>
          </tr></thead>
          <tbody>
          <?php foreach ($preview['samples'] as $sm): foreach ($sm['fix'] as $field => $ba): ?>
            <tr>
              <td><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$sm['id'] ?>" target="_blank" rel="noopener"><?= h($sm['name']) ?></a></td>
              <td class="text-muted"><?= h(crm_audit_field_label($field)) ?></td>
              <td><code style="font-size:.72rem">„<?= h($ba[0]) ?>”</code></td>
              <td><code style="font-size:.72rem">„<?= h($ba[1]) ?>”</code></td>
            </tr>
          <?php endforeach; endforeach; ?>
          </tbody>
        </table>
      </details>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link<?= $tab === 'znaleziska' ? ' active' : '' ?>" href="?">
      <i class="bi bi-inboxes me-1" aria-hidden="true"></i>Do decyzji
      <span class="badge bg-secondary ms-1"><?= (int)($counts['ALL'] ?? 0) ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'reguly' ? ' active' : '' ?>" href="?tab=reguly">
      <i class="bi bi-sliders me-1" aria-hidden="true"></i>Reguły</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'historia' ? ' active' : '' ?>" href="?tab=historia">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia przebiegów</a></li>
  </ul>

<?php if ($tab === 'znaleziska'): ?>

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
  <form method="post" id="jnBulkForm">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="bulk">

  <?php if ($findings): ?>
  <?php /* Pasek akcji masowych. Wyszarzony do czasu zaznaczenia czegokolwiek —
           przycisk „scal duplikaty" gotowy do kliknięcia przy pustym
           zaznaczeniu to zaproszenie do wypadku. */ ?>
  <div class="d-flex flex-wrap gap-2 align-items-center mb-2 p-2 rounded"
       id="jnBulkBar" style="background:#F9FAFB;border:1px solid #E5E7EB">
    <div class="form-check m-0">
      <input class="form-check-input" type="checkbox" id="jnAll">
      <label class="form-check-label small fw-semibold" for="jnAll">Zaznacz widoczne</label>
    </div>
    <span class="text-muted small" id="jnCount">0 zaznaczonych</span>
    <span class="vr d-none d-sm-block"></span>
    <?php foreach (crm_janitor_bulk_actions() as $ak => $av): ?>
    <button type="submit" name="action" value="<?= h($ak) ?>"
            class="btn btn-sm <?= !empty($av['confirm']) ? 'btn-outline-danger' : 'btn-crm-outline' ?> jn-bulk-act"
            disabled
            title="<?= h($av['desc']) ?><?= $av['rules'] ? ' Dotyczy tylko: ' . implode(', ', array_map(fn($r) => $RULES[$r]['label'] ?? $r, $av['rules'])) . '.' : '' ?>"
            <?php if (!empty($av['confirm'])): ?>
            data-confirm="<?= h($av['label']) ?> — operacji nie cofa jedno kliknięcie. Kontynuować?"
            <?php endif; ?>>
      <i class="bi <?= h($av['icon']) ?> me-1" aria-hidden="true"></i><?= h($av['label']) ?>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  </form>

  <?php /* UWAGA: formularz masowy jest ZAMKNIĘTY powyżej. Każdy wiersz ma
           własne formularze („załatwione", „odrzuć"), a formularz zagnieżdżony
           w formularzu jest przez przeglądarkę wyrzucany — działałby wtedy
           tylko jeden z nich. Checkboxy wiążemy z paskiem atrybutem `form`. */ ?>
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
      <span class="d-flex align-items-start gap-2">
        <input type="checkbox" class="form-check-input jn-pick mt-1" form="jnBulkForm"
               name="pick[]" value="<?= (int)$f['id'] ?>"
               aria-label="Zaznacz: <?= h($f['title']) ?>">
        <span class="jn-sev" style="background:<?= $high ? '#DC2626' : '#9CA3AF' ?>" aria-hidden="true"></span>
      </span>
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

  <script>
  (function () {
      var form = document.getElementById('jnBulkForm');
      if (!form) return;
      var all   = document.getElementById('jnAll');
      var count = document.getElementById('jnCount');
      var acts  = document.querySelectorAll('.jn-bulk-act');

      function picks() { return document.querySelectorAll('.jn-pick:checked'); }
      function refresh() {
          var n = picks().length;
          count.textContent = n + (n === 1 ? ' zaznaczone' : ' zaznaczonych');
          acts.forEach(function (b) { b.disabled = n === 0; });
          var boxes = document.querySelectorAll('.jn-pick');
          all.checked = n > 0 && n === boxes.length;
          all.indeterminate = n > 0 && n < boxes.length;
      }

      all.addEventListener('change', function () {
          document.querySelectorAll('.jn-pick').forEach(function (b) { b.checked = all.checked; });
          refresh();
      });
      document.querySelectorAll('.jn-pick').forEach(function (b) { b.addEventListener('change', refresh); });

      // Potwierdzenie tylko przy akcjach, których nie cofa jedno kliknięcie.
      acts.forEach(function (b) {
          b.addEventListener('click', function (e) {
              if (b.dataset.confirm && !confirm(b.dataset.confirm + '\n\nZaznaczonych: ' + picks().length))
                  e.preventDefault();
          });
      });

      refresh();
  })();
  </script>

<?php elseif ($tab === 'reguly'): ?>

  <!-- ══ REGUŁY ══════════════════════════════════════════════════════════ -->
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_op" value="save_rules">

    <?php foreach ([
        'auto'    => ['Poprawiane automatycznie', 'Bot zmienia dane sam. Wyłącznie kosmetyka — nic z tego nie zmienia znaczenia rekordu, a każda poprawka jest widoczna w historii zmian kartoteki.', '#2E844A'],
        'propose' => ['Zgłaszane do decyzji',     'Bot tylko znajduje i opisuje. Zmiana wymaga kliknięcia człowieka, bo albo zmienia sens rekordu, albo nie cofa jej jedno kliknięcie.', '#0176D3'],
    ] as $mode => [$title, $desc, $color]): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>" aria-hidden="true"></span>
          <div class="crm-section-title mb-0"><?= h($title) ?></div>
        </div>
        <p class="text-muted mb-3" style="font-size:.79rem;max-width:76ch"><?= h($desc) ?></p>

        <?php foreach ($RULES as $rk => $rv): if (($rv['mode'] ?? '') !== $mode) continue;
              $on = crm_janitor_rule_enabled($rk); ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="rules[]" value="<?= h($rk) ?>"
                 id="rule_<?= h($rk) ?>" <?= $on ? 'checked' : '' ?>>
          <label class="form-check-label" for="rule_<?= h($rk) ?>">
            <span class="fw-semibold" style="font-size:.86rem"><?= h($rv['label']) ?></span>
            <?php if (($rv['severity'] ?? '') === 'high'): ?>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" style="font-size:.65rem">ważne</span>
            <?php endif; ?>
            <span class="d-block text-muted" style="font-size:.76rem"><?= h($rv['desc']) ?></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex flex-wrap gap-2 align-items-center">
      <button class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz reguły</button>
      <span class="text-muted small">
        Wyłączona reguła przestaje ruszać dane i przestaje zgłaszać — bot chodzi dalej, tylko ją pomija.
      </span>
    </div>
  </form>

<?php else: ?>

  <!-- ══ HISTORIA ════════════════════════════════════════════════════════ -->
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Historia przebiegów bota</caption>
        <thead class="table-light">
          <tr>
            <th scope="col" style="width:12rem">Kiedy</th>
            <th scope="col" class="text-end" style="width:8rem">Poprawek</th>
            <th scope="col" class="text-end" style="width:8rem">Zgłoszeń</th>
            <th scope="col">Co zadziałało</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$runs): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">
            Bot jeszcze nie przechodził bazy. Uruchom go przyciskiem u góry albo poczekaj na nocny przebieg.
          </td></tr>
        <?php else: foreach ($runs as $r): $sum = json_decode((string)$r['summary'], true) ?: []; ?>
          <tr>
            <td class="text-muted small"><?= h(date_pl($r['started_at'])) ?></td>
            <td class="text-end"><?= (int)$r['fixed'] ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><?= (int)$r['found'] ?: '<span class="text-muted">—</span>' ?></td>
            <td>
              <?php if (!$sum): ?><span class="text-muted small">nic do zrobienia</span>
              <?php else: foreach ($sum as $rk => $n): ?>
              <span class="badge bg-light text-dark border me-1 mb-1"><?= h($RULES[$rk]['label'] ?? $rk) ?>: <?= (int)$n ?></span>
              <?php endforeach; endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="text-muted small mt-2">
    Bot chodzi z crona co dobę między 2:00 a 4:00 (agent <code>crm_janitor</code> w dispatcherze).
    Co dokładnie zmienił w konkretnej kartotece, widać w jej historii zmian — podpisuje się jako
    „proces automatyczny”.
  </p>

<?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
