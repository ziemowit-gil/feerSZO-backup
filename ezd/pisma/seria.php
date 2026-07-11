<?php
/**
 * Korespondencja seryjna — z jednego szablonu generuje wiele pism wychodzących
 * w ramach jednej sprawy (po jednym na adresata). Tokeny {{odbiorca}} / {{znak_obcy}}
 * podstawiane per wiersz listy adresatów.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$sprawa_id = (int)($_GET['sprawa_id'] ?? $_POST['sprawa_id'] ?? 0);
$sprawa    = ezd_sprawa_get($sprawa_id);
if (!$sprawa) { flash_set('error','Koszulka nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }
if (ezd_sprawa_access($sprawa, (int)current_user()['id']) !== 'write') { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
if ($sprawa['status'] === 'closed' && !is_admin()) {
    flash_set('error','Koszulka jest zamknięta.'); header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.$sprawa_id); exit;
}

$user_id  = (int)current_user()['id'];
$szablony = ezd_szablony_all(true, 'pismo');
$errors   = [];
$sel_id   = (int)($_POST['szablon_id'] ?? $_GET['szablon_id'] ?? 0);
$adresaci = $_POST['adresaci'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'generate') {
    csrf_check();
    $sz = ezd_szablon_get($sel_id);
    if (!$sz)                 $errors[] = 'Wybierz szablon.';
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$adresaci)), fn($l) => $l !== ''));
    if (!$lines)              $errors[] = 'Podaj co najmniej jednego adresata (jeden w wierszu).';

    if (!$errors) {
        $created = 0;
        foreach ($lines as $line) {
            // format wiersza: „Odbiorca” lub „Odbiorca | znak obcy”
            $parts    = array_map('trim', explode('|', $line, 2));
            $odbiorca = $parts[0];
            $znakObcy = $parts[1] ?? '';
            $ctx = ezd_szablon_context($sprawa, ['odbiorca' => $odbiorca, 'znak_obcy' => $znakObcy]);
            $tytul = ezd_szablon_render($sz['tytul_wzor'], $ctx);
            if ($tytul === '') $tytul = $sz['nazwa'] . ' — ' . $odbiorca;
            try {
                ezd_pismo_create([
                    'sprawa_id'     => $sprawa_id,
                    'kierunek'      => $sz['kierunek'] ?: 'wychodzace',
                    'title'         => $tytul,
                    'tresc'         => ezd_szablon_render($sz['tresc_wzor'], $ctx),
                    'odbiorca'      => $odbiorca,
                    'nadawca'       => (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')),
                    'data_pisma'    => date('Y-m-d'),
                    'data_wysylki'  => '',
                    'status'        => 'nowe',
                    'owner_id'      => $user_id,
                    'rodzaj_medium' => $sz['rodzaj_medium'] ?: 'papier',
                ], $user_id);
                $created++;
            } catch (\Throwable $e) { /* pomiń wadliwy wiersz */ }
        }
        ezd_log(null, $sprawa_id, null, null, $user_id, 'pisma_seria',
            'Korespondencja seryjna: ' . $created . ' pism z szablonu „' . $sz['nazwa'] . '"');
        flash_set('success', "Wygenerowano $created pism(a) wychodzących.");
        header('Location:' . APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id); exit;
    }
}

$PAGE_TITLE = 'Korespondencja seryjna — ' . $sprawa['znak_sprawy'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Korespondencja seryjna</li>
</ol></nav>

<h4 class="fw-bold mb-1"><i class="bi bi-envelope-paper text-primary me-2"></i>Korespondencja seryjna</h4>
<div class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-folder2 me-1"></i>Sprawa: <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h($sprawa['title']) ?></div>

<?php if(!$szablony): ?>
  <div class="alert alert-warning">Brak aktywnych szablonów pism. Dodaj szablon w
    <a href="<?= APP_URL ?>/admin/ezd_szablony.php">Szablony pism</a>.</div>
<?php else: ?>

<?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $e) echo '<div>• '.h($e).'</div>'; ?></div><?php endif; ?>

<div class="row"><div class="col-lg-9">
<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_op" value="generate">
<input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>">
<div class="card shadow-sm">
  <div class="card-body">
    <div class="mb-3">
      <label class="form-label fw-semibold">Szablon <span class="text-danger">*</span></label>
      <select name="szablon_id" class="form-select" required>
        <option value="">— wybierz szablon —</option>
        <?php foreach($szablony as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $sel_id===(int)$s['id']?'selected':'' ?>><?= h($s['nazwa']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-1">
      <label class="form-label fw-semibold">Adresaci <span class="text-danger">*</span></label>
      <textarea name="adresaci" class="form-control font-monospace" rows="10" style="font-size:.85rem"
        placeholder="Jeden adresat w wierszu. Opcjonalnie znak pisma adresata po pionowej kresce:&#10;Jan Kowalski, ul. Polna 1, 00-001 Warszawa&#10;Firma ABC Sp. z o.o. | ABC/123/2026"><?= h($adresaci) ?></textarea>
    </div>
    <p class="text-muted small mb-0">
      Każdy wiersz = jedno pismo wychodzące. Token <code>{{odbiorca}}</code> otrzyma treść wiersza,
      a <code>{{znak_obcy}}</code> — tekst po znaku <code>|</code>.
    </p>
  </div>
  <div class="card-footer d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-collection me-1"></i>Wygeneruj pisma</button>
    <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $sprawa_id ?>" class="btn btn-outline-secondary">Pojedyncze pismo</a>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</div>
</form>
</div></div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
