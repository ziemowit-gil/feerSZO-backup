<?php
/**
 * karty30/ti/dydaktyk/sec_choose.php — wybór obrazka bezpieczeństwa przez prowadzącego:
 * kliknięcie jednego z ośmiu albo „Wylosuj”. Każdy obrazek ma opis tekstowy (czytniki ekranu).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_sec_image_dyd.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $wybor = (string)($_POST['wybor'] ?? '');
    ti_sec_dyd_set($uid, $wybor === 'losuj' ? null : (int)$wybor);
    flash_set('success', 'Zapisano obrazek bezpieczeństwa. Zobaczysz go na stronie logowania po wpisaniu e-maila.');
    header('Location: sec_choose.php'); exit;
}

$obecny = ti_sec_dyd_assign($uid);

$KP_TITLE      = 'Obrazek bezpieczeństwa — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:720px">
<?= flash_html() ?>
  <h1 class="h4 fw-bold mb-2"><i class="bi bi-image text-primary me-2" aria-hidden="true"></i>Obrazek bezpieczeństwa</h1>
  <p class="small mb-2">
    Ten obrazek pojawi się na stronie logowania po wpisaniu Twojego e-maila. Służy tylko do sprawdzenia,
    że strona jest prawdziwa: jeśli go nie widzisz albo widzisz inny, nie wpisuj hasła.
    Obrazek nie zastępuje hasła.
  </p>
  <p class="small mb-3">
    Obecny: <strong><?= h(ti_sec_image_label($obecny)) ?></strong>
  </p>

  <form method="post" class="mb-4">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <button type="submit" name="wybor" value="losuj" class="btn btn-primary">
      <i class="bi bi-shuffle me-1" aria-hidden="true"></i>Wylosuj obrazek
    </button>
  </form>

  <h2 class="h6 fw-semibold mb-2">Albo kliknij jeden z obrazków</h2>
  <div class="row g-3" role="list">
    <?php foreach (TI_SEC_IMAGES as $i => [$opis]): ?>
    <div class="col-6 col-md-3" role="listitem">
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <button type="submit" name="wybor" value="<?= (int)$i ?>" class="btn btn-outline-secondary w-100 p-2 text-start<?= $i === $obecny ? ' active' : '' ?>">
          <span class="d-block mb-1" style="width:64px;height:64px" aria-hidden="true"><?= ti_sec_image_svg($i) ?></span>
          <span class="small"><?= h($opis) ?></span>
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
