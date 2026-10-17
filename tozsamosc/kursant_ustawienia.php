<?php
/**
 * tozsamosc/kursant_ustawienia.php — wygląd nawigacji panelu kursanta: zakładki albo menu rozwijane.
 * Wybór działa w widoku alternatywnym panelu kursanta.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/karty30/ti/kursant/auth.php';

karty30_migrate();
$student = student_current();
if (!$student) { header('Location: ' . APP_URL . '/tozsamosc/kursant.php'); exit; }
$acc_id = (int)$student['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    student_nav_set($acc_id, (string)($_POST['nav'] ?? 'zakladki'));
    // Pierwszy wybór przy logowaniu wraca od razu do panelu; zmiana w Tożsamości zostaje tutaj.
    if (($_POST['wroc'] ?? '') === 'ustawienia') {   // sekcja „Menu panelu” w Ustawieniach kursanta
        header('Location: ' . tiPanelUrl('kursant', 'index.php?tab=ustawienia&menu_ok=1#ust-menu')); exit;
    }
    if (($_POST['wroc'] ?? '') === 'panel') {
        header('Location: ' . tiPanelUrl('kursant', 'index.php')); exit;
    }
    if (($_POST['pierwszy'] ?? '') === '1') {
        header('Location: ' . tiPanelUrl('kursant', 'index.php?ui=alt')); exit;
    }
    header('Location: ' . APP_URL . '/tozsamosc/kursant_ustawienia.php?ok=1'); exit;
}

$obecny  = student_nav($acc_id);
$ok      = isset($_GET['ok']);
$pierwszy = isset($_GET['pierwszy']) || !student_nav_chosen($acc_id);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ustawienia menu — System Tożsamości</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; }
  .metro-bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .metro-card { background: #fff; padding: 2rem; max-width: 520px; margin: 2rem auto; }
  .metro-btn { border-radius: 0; background: #0f6fa8; border-color: #0f6fa8; color: #fff; font-weight: 600; }
  .metro-btn:hover { background: #0c5b8a; border-color: #0c5b8a; color: #fff; }
  .opcja { border: 1px solid #d1d5db; padding: .9rem 1rem; margin-bottom: .6rem; display: block; cursor: pointer; }
  .opcja:has(input:checked) { border: 2px solid #1ba1e2; background: #f0f9ff; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<header class="metro-bar">System Tożsamości · Ustawienia menu</header>
<main class="metro-card">
  <h1 class="h5 fw-bold mb-2">Menu panelu kursanta</h1>
  <p class="small text-body-secondary mb-3">Wybierz, jak ma wyglądać nawigacja w nowym widoku panelu. Zmianę możesz cofnąć w każdej chwili.</p>

  <?php if ($ok): ?>
  <div class="alert alert-success small py-2 rounded-0" role="status">Zapisano wybór menu.</div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <?php if ($pierwszy): ?><input type="hidden" name="pierwszy" value="1"><?php endif; ?>
    <fieldset>
      <legend class="h6 fw-semibold mb-3">Sposób nawigacji</legend>
      <label class="opcja">
        <input type="radio" name="nav" value="zakladki" class="form-check-input me-2" <?= $obecny === 'zakladki' ? 'checked' : '' ?>>
        <strong>Zakładki</strong>
        <span class="d-block small text-body-secondary">Wszystkie sekcje i pozycje widać od razu, jako kafle.</span>
      </label>
      <label class="opcja">
        <input type="radio" name="nav" value="rozwijane" class="form-check-input me-2" <?= $obecny === 'rozwijane' ? 'checked' : '' ?>>
        <strong>Rozwijana bieżąca sekcja</strong>
        <span class="d-block small text-body-secondary">Na pasku jest tylko bieżąca sekcja. Po kliknięciu rozwijają się pozostałe sekcje.</span>
      </label>
      <label class="opcja">
        <input type="radio" name="nav" value="dropdown" class="form-check-input me-2" <?= $obecny === 'dropdown' ? 'checked' : '' ?>>
        <strong>Menu rozwijane (dropdown)</strong>
        <span class="d-block small text-body-secondary">Każda sekcja na pasku rozwija swoje pozycje, wszystkie sekcje są widoczne.</span>
      </label>
    </fieldset>
    <button type="submit" class="btn btn-lg metro-btn w-100 mt-3">Zapisz wybór</button>
  </form>
  <p class="small mt-3 mb-1"><a href="<?= h(APP_URL) ?>/tozsamosc/kursant_haslo.php">Zmiana hasła</a></p>
  <p class="small mt-2 mb-0"><a href="<?= h(tiPanelUrl('kursant', 'index.php')) ?>">Wróć do panelu kursanta</a></p>
</main>
</body>
</html>
