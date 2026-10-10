<?php
/**
 * karty30/ti/kursant/sec_choose.php — wybór obrazka bezpieczeństwa przy pierwszym logowaniu.
 * Kursant klika jeden z obrazków albo wybiera „Wylosuj”. Każdy obrazek ma opis tekstowy (czytniki ekranu).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_sec_image.php';
require_once __DIR__ . '/auth.php';

$s = student_require();
$acc_id = (int)($s['id'] ?? 0);
$acc = db_one("SELECT sec_chosen FROM k30_ti_student_accounts WHERE id = ?", [$acc_id]);
if (!$acc) { header('Location: ../login.php?tab=kursant'); exit; }
if (!empty($acc['sec_chosen'])) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wybor = (string)($_POST['wybor'] ?? '');
    $idx = $wybor === 'losuj' ? null : (int)$wybor;
    ti_sec_image_set($acc_id, $idx);
    header('Location: index.php'); exit;
}

$KP_TITLE      = 'Wybierz obrazek bezpieczeństwa';
$KP_TOPBAR     = ['brand' => 'Panel kursanta', 'icon' => 'pc-display', 'user' => '', 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:720px">
  <h1 class="h4 fw-bold mb-2">Wybierz obrazek bezpieczeństwa</h1>
  <p class="small mb-3">
    Ten obrazek pojawi się przy każdym logowaniu. Służy tylko do sprawdzenia, że strona logowania jest prawdziwa:
    jeśli go nie widzisz albo widzisz inny, nie wpisuj hasła. Obrazek nie zastępuje hasła.
    Każdy obrazek ma też opis tekstowy, który czyta czytnik ekranu.
  </p>

  <form method="post" class="mb-4">
    <button type="submit" name="wybor" value="losuj" class="btn btn-primary">
      <i class="bi bi-shuffle me-1" aria-hidden="true"></i>Wylosuj obrazek
    </button>
  </form>

  <h2 class="h6 fw-semibold mb-2">Albo kliknij jeden z obrazków</h2>
  <div class="row g-3" role="list">
    <?php foreach (TI_SEC_IMAGES as $i => [$opis]): ?>
    <div class="col-6 col-md-3" role="listitem">
      <form method="post">
        <button type="submit" name="wybor" value="<?= (int)$i ?>" class="btn btn-outline-secondary w-100 p-2 text-start">
          <span class="d-block mb-1" style="width:64px;height:64px" aria-hidden="true"><?= ti_sec_image_svg($i) ?></span>
          <span class="small"><?= htmlspecialchars($opis, ENT_QUOTES, 'UTF-8') ?></span>
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
