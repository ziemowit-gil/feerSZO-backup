<?php
/**
 * karty30/ti/dydaktyk/kontaFeerM365.php — konta i licencje Office 365 kursantów na głównym tenancie FEER (kierownik), hurtowo:
 * powiązanie z istniejącym kontem (auto po e-mailu w domenie FEER albo ręcznie UPN), założenie kont, nadanie / odebranie 1–2 licencji.
 * Konta pracowników SZO są chronione. Logika: modules/ti_m365_feer/logic/tiM365Feer.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ms_edu_bulk/logic/msEduBulk.php';
require_once dirname(__DIR__, 3) . '/modules/ti_m365_feer/logic/tiM365Feer.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
karty30_migrate();
TiM365Feer::migrate();
$ready = TiM365Feer::ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if (!$ready) flash_set('danger', 'Połączenie z tenantem FEER (Microsoft 365) nie jest skonfigurowane — administracja SZO → Microsoft 365.');
    elseif ($op === 'link_one') {
        $acc = TiM365Feer::byIds([(int)($_POST['one'] ?? 0)])[0] ?? null;
        try { if (!$acc) throw new RuntimeException('nie znaleziono kursanta.'); TiM365Feer::link(TiM365Feer::graph(), $acc, (string)($_POST['upn'] ?? '')); flash_set('success', 'Powiązano konto FEER.'); }
        catch (\Throwable $e) { flash_set('danger', 'Nie powiązano: ' . $e->getMessage()); }
    } elseif (!in_array($op, ['link_auto', 'create', 'license', 'unlicense', 'unlink'], true)) flash_set('danger', 'Nieznana akcja.');
    else {
        $accs = TiM365Feer::byIds((array)($_POST['ids'] ?? []));
        if (!$accs) flash_set('danger', 'Zaznacz kursantów.');
        else {
            $r = TiM365Feer::act($accs, $op, (array)($_POST['skus'] ?? []));
            flash_set($r['errors'] ? 'warning' : 'success', 'Wykonano: ' . $r['done'] . '/' . count($accs) . ($r['errors'] ? '. Uwagi: ' . implode(' | ', $r['errors']) : '.'));
            if ($r['created']) $_SESSION['feer_m365_created'] = $r['created'];   // hasła pokazujemy raz
        }
    }
    header('Location: kontaFeerM365.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^a-z0-9=&_%.-]/i', '', (string)$_POST['back']) : '')); exit;
}

$q = trim((string)($_GET['q'] ?? '')); $f = (string)($_GET['f'] ?? '');
$rows = TiM365Feer::list($q, $f);
$skus = $ready ? TiM365Feer::graph()->get_subscribed_skus() : [];
$showLic = !empty($_GET['lic']);
$lic = [];
if ($showLic && $ready) { $g = TiM365Feer::graph(); $n = 0; foreach ($rows as $r) if ($r['feer_ms_user_id'] !== '' && $n++ < 80) $lic[(int)$r['id']] = TiM365Feer::licenseNames($g, (string)$r['feer_ms_user_id']); }
$created = $_SESSION['feer_m365_created'] ?? []; unset($_SESSION['feer_m365_created']);
$back = http_build_query(array_filter(['q' => $q, 'f' => $f, 'lic' => $showLic ? 1 : 0]));
$nLinked = count(array_filter($rows, fn($r) => $r['feer_ms_user_id'] !== ''));

$KP_TITLE  = 'Licencje Office 365 — tenant FEER — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'microsoft365.php'; $KIER_LABEL = 'Microsoft 365'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1250px">
  <h1 class="h4 fw-bold mb-3"><i class="bi bi-microsoft text-primary me-2" aria-hidden="true"></i>Office 365 kursantów — tenant FEER</h1>
  <?= msEduBulkTabs(['konta' => ['Konta kursantów', 'microsoft365.php', 'bi-people-fill'], 'lista' => ['Konta z listy (bez kursantów)', 'kontaListaM365.php', 'bi-magic'], 'admin' => ['Administracja kont', 'kontaEduZarzadzaj.php', 'bi-person-gear'], 'feer' => ['Tenant FEER — licencje', 'kontaFeerM365.php', 'bi-microsoft']], 'feer') ?>
  <?= flash_html() ?>
  <p class="small text-body-secondary">Konta kursantów na <strong>głównym tenancie organizacji</strong> (<?= h(TiM365Feer::domain()) ?>) — osobno od kont EDU. Zaznacz kursantów i wybierz akcję: powiązanie z istniejącym kontem, założenie konta albo nadanie / odebranie licencji. Konta pracowników i wolontariuszy SZO są chronione — tu ich nie zmienisz.</p>
  <?php if (!$ready): ?><div class="alert alert-warning">Połączenie z Microsoft 365 (tenant FEER) nie jest skonfigurowane — administrator ustawia je w SZO → Microsoft 365.</div><?php endif; ?>

  <?php if ($created): ?>
  <section class="alert alert-success" aria-labelledby="fc-h"><h2 class="h6 fw-bold" id="fc-h">Założone konta — hasła widoczne tylko teraz</h2>
    <p class="small mb-2">Dane dostępowe wysłano kursantom e-mailem i SMS-em (jeśli mają adres / numer). Przy pierwszym logowaniu Microsoft poprosi o zmianę hasła.</p>
    <table class="table table-sm mb-0 bg-white"><thead><tr><th scope="col">Kursant</th><th scope="col">Login</th><th scope="col">Hasło</th></tr></thead>
      <tbody><?php foreach ($created as $c): ?><tr><th scope="row" class="fw-normal"><?= h($c['name']) ?></th><td class="font-monospace"><?= h($c['upn']) ?></td><td class="font-monospace"><?= h($c['password']) ?></td></tr><?php endforeach; ?></tbody></table>
  </section>
  <?php endif; ?>

  <form method="get" class="row g-2 align-items-end mb-3" role="search" aria-label="Filtr kursantów">
    <div class="col-sm-4"><label class="form-label small mb-0" for="ff-q">Szukaj</label><input class="form-control form-control-sm" id="ff-q" name="q" value="<?= h($q) ?>" placeholder="nazwisko, e-mail, login, konto FEER"></div>
    <div class="col-sm-3"><label class="form-label small mb-0" for="ff-f">Konto FEER</label>
      <select class="form-select form-select-sm" id="ff-f" name="f"><option value="">wszyscy</option><option value="linked"<?= $f === 'linked' ? ' selected' : '' ?>>z kontem FEER</option><option value="unlinked"<?= $f === 'unlinked' ? ' selected' : '' ?>>bez konta FEER</option></select></div>
    <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="ff-l" name="lic" value="1"<?= $showLic ? ' checked' : '' ?>><label class="form-check-label small" for="ff-l">Pokaż licencje (wolniej)</label></div></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Pokaż</button></div>
  </form>

  <form method="post" id="ff-form">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
    <fieldset class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
      <legend class="h6 fw-bold mb-1">Akcja dla zaznaczonych <span class="badge text-bg-secondary" id="ff-n">0</span></legend>
      <?php for ($n = 0; $n < 2; $n++): ?>
      <div class="col-md-3"><label class="form-label small mb-0" for="ff-sku<?= $n ?>">Licencja <?= $n + 1 ?></label>
        <select class="form-select form-select-sm" id="ff-sku<?= $n ?>" name="skus[]"><option value="">— brak —</option>
          <?php foreach ($skus as $s): ?><option value="<?= h($s['skuId']) ?>"><?= h($s['skuPartNumber']) ?> (wolne: <?= (int)($s['prepaidUnits']['enabled'] ?? 0) - (int)($s['consumedUnits'] ?? 0) ?>)</option><?php endforeach; ?>
        </select></div>
      <?php endfor; ?>
      <div class="col-md-6 d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-primary" name="_op" value="license"<?= $ready ? '' : ' disabled' ?>><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nadaj licencje</button>
        <button class="btn btn-sm btn-outline-danger" name="_op" value="unlicense"<?= $ready ? '' : ' disabled' ?> onclick="return confirm('Odebrać wybrane licencje zaznaczonym kursantom?')"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>Odbierz licencje</button>
        <button class="btn btn-sm btn-outline-primary" name="_op" value="create"<?= $ready ? '' : ' disabled' ?> onclick="return confirm('Założyć konta na tenancie FEER zaznaczonym kursantom bez konta (z wybranymi licencjami) i wysłać im dane dostępowe?')"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Załóż konta FEER</button>
        <button class="btn btn-sm btn-outline-secondary" name="_op" value="link_auto"<?= $ready ? '' : ' disabled' ?> title="Powiąż kursantów, których e-mail jest w domenie <?= h(TiM365Feer::domain()) ?>"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Powiąż po e-mailu</button>
        <button class="btn btn-sm btn-link text-danger" name="_op" value="unlink" onclick="return confirm('Rozłączyć w systemie zaznaczonych kursantów z kontami FEER? (konta w Microsoft zostają)')">Rozłącz</button>
      </div>
    </div></fieldset>

    <p class="small text-body-secondary">Kursantów: <?= count($rows) ?>, z kontem FEER: <?= $nLinked ?><?= $showLic && $nLinked > 80 ? ' (licencje pokazane dla pierwszych 80)' : '' ?>.</p>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle">
      <caption class="visually-hidden">Kursanci i ich konta Office 365 na tenancie FEER</caption>
      <thead class="table-light"><tr>
        <th scope="col"><input class="form-check-input" type="checkbox" id="ff-all" aria-label="Zaznacz wszystkich"></th>
        <th scope="col">Kursant</th><th scope="col">Grupy</th><th scope="col">Konto FEER</th><?php if ($showLic): ?><th scope="col">Licencje</th><?php endif; ?><th scope="col">Konto EDU</th>
      </tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td><input class="form-check-input ff-cb" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Zaznacz <?= h($r['name']) ?>"></td>
          <th scope="row" class="fw-normal"><?= h($r['name']) ?><div class="small text-body-secondary"><?= h($r['email'] ?: '') ?></div></th>
          <td class="small" style="max-width:14rem"><?= h(mb_strimwidth((string)$r['groups'], 0, 60, '…') ?: '—') ?></td>
          <td class="small"><?php if ($r['feer_ms_upn'] !== ''): ?><span class="font-monospace"><?= h($r['feer_ms_upn']) ?></span>
            <?php else: ?><span class="text-body-secondary">brak</span>
              <details class="d-inline-block ms-1"><summary class="small">powiąż…</summary>
                <span class="d-flex gap-1 mt-1"><label class="visually-hidden" for="up<?= (int)$r['id'] ?>">UPN konta FEER — <?= h($r['name']) ?></label>
                  <input class="form-control form-control-sm" id="up<?= (int)$r['id'] ?>" form="ff-link-<?= (int)$r['id'] ?>" name="upn" placeholder="imie.nazwisko@<?= h(TiM365Feer::domain()) ?>" style="width:15rem">
                  <button class="btn btn-sm btn-outline-primary" form="ff-link-<?= (int)$r['id'] ?>">Powiąż</button></span>
              </details><?php endif; ?></td>
          <?php if ($showLic): ?><td class="small"><?= $r['feer_ms_user_id'] === '' ? '—' : (isset($lic[(int)$r['id']]) ? h(implode(', ', $lic[(int)$r['id']]) ?: 'brak licencji') : '…') ?></td><?php endif; ?>
          <td class="small font-monospace text-body-secondary"><?= h($r['ms_upn'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </form>
  <?php foreach ($rows as $r): if ($r['feer_ms_upn'] !== '') continue; ?>
  <form method="post" id="ff-link-<?= (int)$r['id'] ?>" hidden><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="link_one"><input type="hidden" name="one" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"></form>
  <?php endforeach; ?>
</div>
</main>
<script>
(function () {
  var all = document.getElementById('ff-all'), n = document.getElementById('ff-n');
  function cnt() { n.textContent = document.querySelectorAll('.ff-cb:checked').length; }
  all.addEventListener('change', function () { document.querySelectorAll('.ff-cb').forEach(function (c) { c.checked = all.checked; }); cnt(); });
  document.querySelectorAll('.ff-cb').forEach(function (c) { c.addEventListener('change', cnt); });
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
