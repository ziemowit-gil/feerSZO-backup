<?php
/**
 * karty30/ti/dydaktyk/noclegi.php — noclegi kadry przy szkoleniach stacjonarnych (noc przed / noc po).
 *
 * Kierownik (dyd_is_staff) zaznacza noclegi dla wszystkich; prowadzący widzi tylko swoje (bez edycji).
 * Daty wylicza modules/ti_lodging z lekcji stacjonarnych — nic się nie wpisuje ręcznie.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_lodging/logic/lodging.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();
TiLodging::migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    if (!$staff) { header('Location: noclegi.php'); exit; }
    $want = [];
    foreach ((array)($_POST['n'] ?? []) as $key => $_) $want[(string)$key] = true;
    $changed = 0;
    foreach (TiLodging::spans() as $r) {
        foreach (array_keys(TiLodging::KINDS) as $k) {
            $key = $r['course_id'] . ':' . $r['uid'] . ':' . $k;
            $flags = TiLodging::flags();
            if (isset($want[$key]) !== isset($flags[$key])) {
                if (TiLodging::set((int)$r['course_id'], (int)$r['uid'], $k, isset($want[$key]), $uid)) $changed++;
            }
        }
    }
    flash_set('success', $changed ? 'Noclegi zapisane.' : 'Brak zmian.');
    header('Location: noclegi.php'); exit;
}

$rows = TiLodging::spans(null, 0, $staff ? 0 : $uid);
$flags = TiLodging::flags();
$names = [];
foreach (db_all("SELECT id, name FROM users") as $u) $names[(int)$u['id']] = $u['name'];
$fmt = fn(string $d) => date('d.m.Y', strtotime($d));

$KP_TITLE  = 'Noclegi kadry — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php if ($staff): $KIER_CUR = 'noclegi.php'; $KIER_LABEL = 'Noclegi kadry'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<?= flash_html() ?>
<h1 class="h4 fw-bold mb-2"><i class="bi bi-moon-stars text-primary me-2" aria-hidden="true"></i><?= $staff ? 'Noclegi kadry' : 'Moje noclegi' ?></h1>
<p class="small text-body-secondary">Szkolenia stacjonarne od dziś. Noc przed: noc poprzedzająca pierwszą lekcję stacjonarną. Noc po: noc po ostatniej lekcji stacjonarnej. Daty wynikają z terminów lekcji.</p>

<?php if (!$rows): ?>
<div class="alert alert-secondary">Brak nadchodzących szkoleń stacjonarnych.</div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="_token" value="<?= dyd_token() ?>">
  <div class="table-responsive">
  <table class="table table-sm table-striped align-middle">
    <caption class="visually-hidden">Noclegi kadry przy szkoleniach stacjonarnych</caption>
    <thead><tr>
      <th scope="col">Grupa</th><th scope="col">Osoba</th><th scope="col">Lekcje stacjonarne</th>
      <?php foreach (TiLodging::KINDS as $label): ?><th scope="col"><?= h($label) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $nt = TiLodging::nights($r['first_d'], $r['last_d']); ?>
      <tr>
        <td><?= h($r['course_name']) ?></td>
        <td><?= h($names[(int)$r['uid']] ?? '—') ?></td>
        <td><?= h($fmt($r['first_d'])) ?><?= $r['first_d'] !== $r['last_d'] ? ' – ' . h($fmt($r['last_d'])) : '' ?> <span class="text-body-secondary small">(<?= (int)$r['n'] ?>)</span></td>
        <?php foreach (array_keys(TiLodging::KINDS) as $k):
          $key = $r['course_id'] . ':' . $r['uid'] . ':' . $k; $on = isset($flags[$key]);
          $lbl = TiLodging::KINDS[$k] . ' — ' . ($names[(int)$r['uid']] ?? '') . ', ' . $r['course_name']; ?>
        <td>
          <?php if ($staff): ?>
            <input class="form-check-input" type="checkbox" name="n[<?= h($key) ?>]" value="1" id="n-<?= h(str_replace(':', '-', $key)) ?>" <?= $on ? 'checked' : '' ?> aria-label="<?= h($lbl) ?>">
            <label class="small ms-1" for="n-<?= h(str_replace(':', '-', $key)) ?>"><?= h($fmt($nt[$k][0])) ?> → <?= h($fmt($nt[$k][1])) ?></label>
          <?php elseif ($on): ?>
            <span class="badge text-bg-success">Tak</span> <span class="small"><?= h($fmt($nt[$k][0])) ?> → <?= h($fmt($nt[$k][1])) ?></span>
          <?php else: ?><span class="text-body-secondary">Nie</span><?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($staff): ?><button class="btn btn-primary">Zapisz noclegi</button><?php endif; ?>
</form>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
