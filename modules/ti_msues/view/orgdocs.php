<?php
/**
 * modules/ti_msues/view/orgdocs.php — dokumenty instytucji wymagane przez MSUES (wspólne dla wszystkich usług): adres publikacji albo opis.
 * Zmienne: $docs (MsuesRequirements::orgDocs), $actor. Kontroler: MsuesController::orgDocs.
 */
require __DIR__ . '/_parts.php';
$title = 'Dokumenty instytucji MSUES';
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
?>
<p class="mb-2"><a href="<?= h(route('msues.reqs')) ?>">&larr; Wymagania usług MSUES</a></p>
<h1 class="h4 mb-1"><i class="bi bi-building text-primary" aria-hidden="true"></i> Dokumenty instytucji</h1>
<p class="small text-muted">Wspólne dla wszystkich usług MSUES. Wpisz adres strony, na której dokument jest opublikowany, albo znak i miejsce przechowywania. Puste pole = wezwanie do uzupełnienia w każdej usłudze.</p>
<form method="post" action="<?= h(route('msues.orgdocs.save')) ?>"><?= Router::csrfField() ?>
<?php foreach (MsuesRequirements::AREAS as $ak => $al): $list = array_filter($docs, fn($d) => $d['area'] === $ak); if (!$list) continue; ?>
<section class="card mb-3"><div class="card-header fw-semibold"><?= h($al) ?></div><div class="card-body row g-2">
  <?php foreach ($list as $k => $d): ?>
  <div class="col-12" id="<?= h($k) ?>"><label class="form-label small mb-0 fw-semibold" for="od_<?= h($k) ?>"><?= $d['value'] !== '' ? msuesCheckIcon(true) : msuesCheckIcon(false) ?> <?= h($d['label']) ?></label>
    <input class="form-control form-control-sm" id="od_<?= h($k) ?>" name="<?= h($k) ?>" maxlength="500" value="<?= h($d['value']) ?>" placeholder="<?= h($d['hint']) ?>"></div>
  <?php endforeach; ?>
</div></section>
<?php endforeach; ?>
<button class="btn btn-primary"><i class="bi bi-save"></i> Zapisz</button>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
