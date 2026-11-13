<?php
/**
 * modules/sso_logto/view/migratorFiles.php — sekcja #pliki w migratorze: wynik zakładania kont w chmurze (hasła raz)
 * i zadania kopiowania plików z OneDrive do chmury.
 */
require_once dirname(__DIR__) . '/logic/SsoController.php';
$ssoRows = SsoController::takeResult('migrator');
?>
<section id="pliki" class="card mt-4" aria-labelledby="ssoMigFilesH">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 id="ssoMigFilesH" class="h6 mb-0"><i class="bi bi-files" aria-hidden="true"></i> Pliki z OneDrive → chmura (<?= h(SsoConfig::cloudLabel()) ?>)</h2>
    <span class="small text-body-secondary">w tle co 5 min · folder „<?= h(CloudFilesCopy::TARGET) ?>” · niedostępny serwer = ponowienie co <?= CloudFilesCopy::RETRY_HOURS ?> h</span>
  </div>
  <div class="card-body small">
    <?php if ($ssoRows) { $ssoResId = 'ssoMigResult'; include __DIR__ . '/_result.php'; } ?>
    <p class="mb-2">Zaznacz osoby na liście wyżej i użyj „<?= SsoConfig::logtoConfigured() ? 'Konto Logto + chmura' : 'Konto w chmurze + pliki' ?>” (pasek pod listą). Każda dostaje jedno nowe hasło — to samo w logowaniu jednokrotnym i w chmurze. Hasło do poczty zostaje bez zmian. Przenosimy wszystkie pliki: także notesy OneNote, skróty do udostępnionych plików i pliki większe niż 2 GB (wysyłane w częściach).</p>
    <?php $jobsBack = '/migracjaadmin'; $ssoJobs = null; include __DIR__ . '/_jobs.php'; ?>
  </div>
</section>
