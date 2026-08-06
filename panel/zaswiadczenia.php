<?php
/**
 * Panel wolontariusza — zaświadczenia własne EZD.
 * Przeglądanie własnych wniosków i składanie nowych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/zaswiadczenia_ezd.php';
require_login();

if (!module_enabled('ezd_enabled')) {
    flash_set('error', 'Moduł zaświadczeń jest niedostępny.');
    header('Location:' . APP_URL . '/panel/index.php'); exit;
}

$user    = current_user();
$user_id = (int)$user['id'];

$typy = ezd_zas_typy_all(true);
$moje = ezd_zas_my_all($user_id);

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $typ_id = (int)($_POST['typ_id'] ?? 0);
    $typ    = $typ_id ? ezd_zas_typ_get($typ_id) : null;

    if (!$typ || !$typ['is_active']) {
        $error = 'Wybrany typ zaświadczenia jest niedostępny.';
    } else {
        $dane = [];
        foreach ($typ['pola'] as $pole) {
            $k = $pole['name'] ?? '';
            if (!$k) continue;
            $v = trim($_POST['pole_' . $k] ?? '');
            if (($pole['required'] ?? false) && $v === '') {
                $error = 'Pole „' . h($pole['label'] ?? $k) . '" jest wymagane.';
                break;
            }
            $dane[$k] = $v;
        }
        if (!$error) {
            $name  = trim($user['name'] ?? '');
            $email = trim($user['email'] ?? '');
            $new_id = ezd_zas_create($typ_id, $name, $email, $dane, $user_id);
            flash_set('success', 'Wniosek o zaświadczenie złożony.');
            header('Location:' . APP_URL . '/panel/zaswiadczenia.php'); exit;
        }
    }
}

$PAGE_TITLE = 'Zaświadczenia';
include __DIR__ . '/includes/header_panel.php';
?>

<a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>

<h1 class="pv-page-title"><i class="bi bi-award-fill me-2" aria-hidden="true"></i>Zaświadczenia</h1>

<?php if ($error): ?>
<div class="alert alert-danger py-2 mb-3" style="font-size:.88rem"><i class="bi bi-exclamation-triangle me-1"></i><?= $error ?></div>
<?php endif; ?>
<?= flash_html() ?>

<!-- Moje wnioski i zaświadczenia -->
<?php if ($moje): ?>
<section class="pv-section mb-4">
  <h2 class="pv-section-title"><i class="bi bi-list-ul me-1"></i>Moje zaświadczenia</h2>
  <div class="list-group list-group-flush">
  <?php foreach ($moje as $m):
    $st    = EZD_ZAS_STATUSY[$m['status']] ?? ['label' => $m['status'], 'class' => 'secondary', 'icon' => 'bi-question'];
    $wdt   = $m['wazne_do'] ? strtotime($m['wazne_do']) : 0;
    $exp   = $wdt && $wdt < time();
  ?>
  <div class="list-group-item list-group-item-action px-0" style="border-left:0;border-right:0">
    <div class="d-flex align-items-start gap-3">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <strong style="font-size:.88rem"><?= h($m['typ_nazwa']) ?></strong>
          <span class="badge bg-<?= $st['class'] ?>" style="font-size:.65rem"><i class="bi <?= $st['icon'] ?> me-1"></i><?= $st['label'] ?></span>
          <?php if ($m['nr_zaswiadczenia']): ?>
          <span class="font-monospace text-primary" style="font-size:.8rem"><?= h($m['nr_zaswiadczenia']) ?></span>
          <?php endif; ?>
          <?php if ($exp): ?>
          <span class="badge bg-warning text-dark" style="font-size:.65rem">WYGASŁE</span>
          <?php elseif ($m['wazne_do']): ?>
          <span class="text-muted" style="font-size:.72rem">ważne do <?= date('d.m.Y', $wdt) ?></span>
          <?php endif; ?>
        </div>
        <div class="text-muted" style="font-size:.75rem">Złożono <?= date_pl(substr($m['created_at'],0,10)) ?></div>
      </div>
      <?php if ($m['status'] === 'wydane'): ?>
      <div class="d-flex gap-1 flex-shrink-0">
        <a href="<?= APP_URL ?>/ezd/zaswiadczenia/pdf.php?id=<?= $m['id'] ?>" target="_blank"
           class="btn btn-sm btn-outline-primary py-0 px-2" title="Pobierz PDF">
          <i class="bi bi-file-earmark-pdf"></i>
        </a>
        <?php if ($m['verify_code']): ?>
        <a href="<?= APP_URL ?>/ezd/zaswiadczenia/verify.php?code=<?= rawurlencode($m['verify_code']) ?>" target="_blank"
           class="btn btn-sm btn-outline-secondary py-0 px-2" title="Weryfikacja QR">
          <i class="bi bi-qr-code"></i>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Złóż wniosek -->
<?php if ($typy): ?>
<section class="pv-section mb-4">
  <h2 class="pv-section-title"><i class="bi bi-plus-circle me-1"></i>Złóż wniosek o zaświadczenie</h2>

  <?php foreach ($typy as $t): ?>
  <details class="mb-3" style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden">
    <summary style="padding:14px 16px;cursor:pointer;font-weight:600;font-size:.9rem;background:#f8f9fa;list-style:none;display:flex;align-items:center;justify-content:space-between">
      <span><i class="bi bi-award me-2 text-primary"></i><?= h($t['nazwa']) ?></span>
      <i class="bi bi-chevron-down" style="font-size:.8rem;color:#888"></i>
    </summary>
    <div style="padding:16px">
      <?php if ($t['opis']): ?>
      <p class="text-muted mb-3" style="font-size:.85rem"><?= nl2br(h($t['opis'])) ?></p>
      <?php endif; ?>
      <?php if ($t['waznosc_dni']): ?>
      <p class="text-muted mb-3" style="font-size:.8rem"><i class="bi bi-clock me-1"></i>Zaświadczenie ważne przez <?= $t['waznosc_dni'] ?> dni od wydania.</p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="typ_id" value="<?= $t['id'] ?>">
        <?php foreach ($t['pola'] as $pole):
          $k = $pole['name'] ?? '';
          if (!$k) continue;
          $req = !empty($pole['required']);
        ?>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.82rem"><?= h($pole['label'] ?? $k) ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?></label>
          <?php if (($pole['type'] ?? 'text') === 'textarea'): ?>
          <textarea name="pole_<?= h($k) ?>" class="form-control form-control-sm" rows="3"
                    placeholder="<?= h($pole['placeholder'] ?? '') ?>"
                    <?= $req ? 'required' : '' ?>></textarea>
          <?php else: ?>
          <input type="<?= h($pole['type'] ?? 'text') ?>" name="pole_<?= h($k) ?>" class="form-control form-control-sm"
                 placeholder="<?= h($pole['placeholder'] ?? '') ?>"
                 <?= $req ? 'required' : '' ?>>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Złóż wniosek</button>
      </form>
    </div>
  </details>
  <?php endforeach; ?>

<?php else: ?>
<div class="text-muted text-center py-4" style="font-size:.9rem">
  <i class="bi bi-award" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
  Brak dostępnych typów zaświadczeń.
</div>
<?php endif; ?>

</section>

<?php include __DIR__ . '/includes/footer_panel.php'; ?>
