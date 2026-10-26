<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
$PAGE_TITLE = 'Oświadczenie o ochronie danych';
$user = current_user();
$uid  = (int)$user['id'];

$row            = db_one("SELECT gdpr_statement_signed_at, gdpr_statement_ip FROM users WHERE id=?", [$uid]);
$already_signed = !empty($row['gdpr_statement_signed_at']);
$error          = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_signed) {
    csrf_check();
    if (empty($_POST['confirm_statement'])) {
        $error = 'Proszę zaznaczyć potwierdzenie przed złożeniem elektronicznego podpisu.';
    } else {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        db()->prepare(
            "UPDATE users SET gdpr_statement_signed_at=?, gdpr_statement_ip=? WHERE id=?"
        )->execute([date('Y-m-d H:i:s'), $ip, $uid]);
        header('Location: ' . APP_URL . '/panel/gdpr_statement.php?signed=1'); exit;
    }
}

include __DIR__ . '/includes/header_panel.php';
?>

<?php
$signedAt = $already_signed ? strtotime($row['gdpr_statement_signed_at']) : null;
$who      = trim((string)($user['name'] ?? '')) ?: (string)($user['email'] ?? '');
?>
<div class="pv-wrap gd">

  <div class="pv-page-header">
    <div class="pv-page-head-main">
      <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
      <h1 class="pv-page-title"><i class="bi bi-shield-check" aria-hidden="true"></i>Ochrona danych osobowych</h1>
      <p class="pv-page-sub">Oświadczenie o zachowaniu poufności danych osobowych</p>
    </div>
  </div>

  <?php require_once dirname(__DIR__) . '/modules/first_run/lib/firstRun.php'; echo firstLoginProgressHtml($user, 'gdpr'); ?>

  <?php if (isset($_GET['signed'])): ?>
  <div class="pv-alert pv-alert-success" role="status">
    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
    <div><strong>Oświadczenie zostało złożone elektronicznie.</strong> Możesz teraz samodzielnie założyć konto Microsoft 365 (Moje konto Microsoft 365).</div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="pv-alert pv-alert-danger" role="alert"><i class="bi bi-x-circle" aria-hidden="true"></i><div><?= h($error) ?></div></div>
  <?php endif; ?>

  <!-- Kafle stanu -->
  <ul class="gd-tiles" aria-label="Stan oświadczenia">
    <li class="gd-tile <?= $already_signed ? 'is-green' : 'is-orange' ?>">
      <i class="bi <?= $already_signed ? 'bi-patch-check' : 'bi-pen' ?>" aria-hidden="true"></i>
      <span class="gd-tile__lbl">Oświadczenie</span>
      <span class="gd-tile__val"><?= $already_signed ? 'Podpisane' : 'Do podpisu' ?></span>
    </li>
    <li class="gd-tile <?= $already_signed ? 'is-blue' : 'is-grey' ?>">
      <i class="bi bi-microsoft" aria-hidden="true"></i>
      <span class="gd-tile__lbl">Konto Microsoft 365</span>
      <span class="gd-tile__val"><?= $already_signed ? 'Możesz je założyć sam' : 'Czeka na podpis' ?></span>
    </li>
    <li class="gd-tile is-dark">
      <i class="bi bi-person" aria-hidden="true"></i>
      <span class="gd-tile__lbl">Składający</span>
      <span class="gd-tile__val"><?= h($who) ?></span>
    </li>
  </ul>

  <div class="gd-grid">
    <!-- Dokument -->
    <article class="gd-doc" aria-labelledby="gd-doc-t">
      <header class="gd-doc__hd">
        <span class="gd-doc__kicker">Dokument</span>
        <h2 id="gd-doc-t" class="gd-doc__t">Oświadczenie</h2>
        <p class="gd-doc__sub">o zapoznaniu się z zasadami ochrony danych osobowych i zachowaniu ich w poufności</p>
      </header>
      <ol class="gd-doc__list">
        <li>Oświadczam, że zapoznałem się z Polityką Ochrony Danych Osobowych oraz Regulaminem
           informatycznym, które obowiązują w placówce i zobowiązuję się do przestrzegania zasad
           w nich zawartych w szczególności zasad poufności dotyczących ochrony danych osobowych
           w poufności.</li>
        <li>Zostałem również zapoznany z ogólnymi zasadami zabezpieczenia i przetwarzania danych
           osobowych wynikającymi z rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679
           z dnia 27 kwietnia 2016 r. w sprawie ochrony osób fizycznych w związku z przetwarzaniem
           danych osobowych i w sprawie swobodnego przepływu takich danych oraz uchylenia dyrektywy
           95/46/WE (ogólne rozporządzenie o ochronie danych osobowych Dz.&nbsp;Urz.&nbsp;UE L&nbsp;119,
           s.&nbsp;1).</li>
        <li>Oświadczam, że zachowam w poufności wszelkie dane osobowe, które przetwarzałem
           lub przetwarzam w ramach wykonywanych obowiązków oraz metody ich zabezpieczeń,
           także po ustaniu wykonywania obowiązku lub świadczeniu pracy.</li>
      </ol>
      <?php if ($already_signed): // kod z wydruku (tylko na papierze) — jedno stałe oznaczenie oświadczenia danego użytkownika
        require_once dirname(__DIR__) . '/modules/doc_registry/lib/DocRegistry.php';
        $gdCodes = DocRegistry::printFooterHtml('gdpr_oswiadczenie', (string)$uid, 'Oświadczenie o ochronie danych osobowych (użytkownik #' . $uid . ')', 'panel/gdpr_statement.php');
        if ($gdCodes !== ''): ?><div class="gd-codes"><?= $gdCodes ?></div><?php endif; endif; ?>
    </article>

    <!-- Podpis -->
    <aside class="gd-sign" aria-labelledby="gd-sign-t">
      <h2 id="gd-sign-t" class="gd-sign__t"><i class="bi bi-pen" aria-hidden="true"></i> Podpis elektroniczny</h2>
      <?php if ($already_signed): ?>
      <div class="gd-seal" role="status">
        <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
        <div><strong>Złożono elektronicznie</strong><span>Oświadczenie jest ważne — nie trzeba go składać ponownie.</span></div>
      </div>
      <dl class="gd-dl">
        <dt>Podpisujący</dt><dd><?= h($who) ?></dd>
        <dt>Data i godzina</dt><dd><?= h(date('d.m.Y, H:i:s', $signedAt)) ?></dd>
        <dt>Adres IP</dt><dd class="gd-mono"><?= h($row['gdpr_statement_ip'] ?? '—') ?></dd>
      </dl>
      <div class="gd-actions">
        <a href="<?= APP_URL ?>/panel/m365.php" class="tz-btn"><i class="bi bi-microsoft" aria-hidden="true"></i> Konto Microsoft 365</a>
        <a href="<?= APP_URL ?>/panel/index.php" class="tz-btn tz-btn--ghost"><i class="bi bi-house-door" aria-hidden="true"></i> Przejdź do Pulpitu</a>
        <button type="button" class="tz-btn tz-btn--ghost" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Drukuj</button>
      </div>
      <?php else: ?>
      <p class="gd-sign__lead">Konto Microsoft 365 założysz samodzielnie dopiero po złożeniu podpisu. Przeczytaj treść oświadczenia, potwierdź i podpisz.</p>
      <dl class="gd-dl">
        <dt>Podpisujący</dt><dd><?= h($who) ?></dd>
        <dt>Data</dt><dd><?= h(date('d.m.Y')) ?></dd>
        <dt>Adres IP</dt><dd>zapisany przy podpisie</dd>
      </dl>
      <form method="post" class="gd-form" id="gd-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <label class="gd-check" for="confirm_statement">
          <input type="checkbox" id="confirm_statement" name="confirm_statement" value="1" required>
          <span>Zapoznałem/-am się z pełną treścią oświadczenia i akceptuję jego warunki.</span>
        </label>
        <button type="submit" class="tz-btn gd-submit is-off" id="gd-submit">
          <i class="bi bi-pen" aria-hidden="true"></i> Zapoznałem się i podpisuję elektronicznie
        </button>
      </form>
      <?php endif; ?>
    </aside>
  </div>
</div>

<style>
.gd-tiles{list-style:none;margin:0 0 1rem;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem}
.gd-tile{display:flex;flex-direction:column;justify-content:flex-end;min-height:104px;padding:.85rem 1rem;color:#fff;position:relative}
.gd-tile > i{position:absolute;top:.75rem;left:1rem;font-size:1.35rem;opacity:.95}
.gd-tile__lbl{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;opacity:.9}
.gd-tile__val{font-size:1.15rem;font-weight:300;line-height:1.25;overflow-wrap:anywhere}
.gd-tile.is-green{background:var(--m-green,#007a00)} .gd-tile.is-orange{background:var(--m-orange,#c25100)}
.gd-tile.is-blue{background:var(--m-blue,#0f6fa8)} .gd-tile.is-grey{background:var(--m-grey,#5f5f5f)} .gd-tile.is-dark{background:var(--m-dark,#1d1d1d)}
.gd-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:1rem;align-items:start;margin-bottom:1.5rem}
@media (max-width:860px){.gd-grid{grid-template-columns:1fr}}
.gd-doc{background:var(--m-surface,#fff);border:1px solid var(--m-line,#e1e1e1);border-top:6px solid var(--m-acc,#0f6fa8);padding:1.5rem 1.75rem}
.gd-doc__kicker{display:block;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--m-muted,#5f5f5f)}
.gd-doc__t{font-size:1.9rem;font-weight:300;margin:.15rem 0 .2rem;text-transform:uppercase;letter-spacing:.08em}
.gd-doc__sub{margin:0 0 1.25rem;color:var(--m-muted,#5f5f5f);font-size:.9rem}
.gd-doc__list{margin:0;padding:0;list-style:none;counter-reset:gd}
.gd-doc__list li{counter-increment:gd;position:relative;padding:0 0 0 2.6rem;margin:0 0 1rem;line-height:1.65;font-size:.95rem}
.gd-doc__list li:last-child{margin-bottom:0}
.gd-doc__list li::before{content:"§ " counter(gd);position:absolute;left:0;top:.05rem;font-weight:600;color:var(--m-acc,#0f6fa8)}
.gd-sign{background:var(--m-light,#f2f2f2);padding:1.25rem;position:sticky;top:1rem}
.gd-sign__t{font-size:1.1rem;font-weight:400;margin:0 0 .75rem;display:flex;gap:.5rem;align-items:center}
.gd-sign__lead{font-size:.88rem;margin:0 0 1rem;color:var(--m-ink,#111827)}
.gd-dl{display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;margin:0 0 1rem;font-size:.88rem}
.gd-dl dt{color:var(--m-muted,#5f5f5f);font-weight:400}
.gd-dl dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.gd-mono{font-family:ui-monospace,Menlo,Consolas,monospace}
.gd-check{display:flex;gap:.6rem;align-items:flex-start;padding:.75rem;background:var(--m-surface,#fff);border:2px solid var(--m-line,#e1e1e1);cursor:pointer;font-size:.9rem;font-weight:600;margin-bottom:.75rem}
.gd-check:has(input:checked){border-color:var(--m-acc,#0f6fa8)}
.gd-check input{width:1.15rem;height:1.15rem;margin-top:.1rem;flex-shrink:0;accent-color:var(--m-acc,#0f6fa8)}
.gd-submit{width:100%;justify-content:center}
.gd-submit.is-off{opacity:.55}
.gd-seal{display:flex;gap:.75rem;align-items:flex-start;background:var(--m-green,#007a00);color:#fff;padding:.9rem 1rem;margin-bottom:1rem}
.gd-seal > i{font-size:1.6rem;line-height:1}
.gd-seal strong{display:block} .gd-seal span{font-size:.82rem;opacity:.92}
.gd-codes{display:none}
.gd-actions{display:flex;flex-wrap:wrap;gap:.5rem}
@media print{
  .gd-codes{display:block!important;margin-top:1.25rem;padding-top:.5rem;border-top:1px solid #999}
  .pv-navbar,.pv-page-back,.gd-tiles,.gd-actions,.pv-alert,footer,.pv-footer{display:none!important}
  .gd-grid{grid-template-columns:1fr} .gd-sign{position:static;background:#fff;border:1px solid #000}
  .gd-seal{background:#fff;color:#000;border:1px solid #000}
}
</style>
<script>
(function () {
  var c = document.getElementById('confirm_statement'), b = document.getElementById('gd-submit');
  if (!c || !b) return;
  var sync = function () { b.classList.toggle('is-off', !c.checked); };
  c.addEventListener('change', sync); sync();
})();
</script>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
