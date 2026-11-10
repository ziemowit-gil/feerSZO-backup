<?php
/**
 * modules/ti_licencje_kursant/view/_admin_nav_bs.php — menu Administratora Licencji na ekranach licencji w szacie Bootstrap
 * (zamiast menu kierownika, gdy ekran działa w Panelu Licencji: LICENCE_ADMIN_MOUNT, licencje/_mount.php).
 * Wejście: $KIER_CUR (bieżący plik / adres, jak w menu kierownika).
 */
require_once dirname(__DIR__) . '/logic/LicencjeAdminNav.php';
$_lan_cur = (string)($KIER_CUR ?? '');
?>
<nav class="la-nav" aria-label="Menu Administratora Licencji" style="background:#1d1d1d;color:#fff;padding:.4rem 1rem;display:flex;flex-wrap:wrap;gap:.25rem;align-items:center">
  <span style="font-weight:600;margin-right:.75rem">Administrator Licencji</span>
  <?php foreach (LicencjeAdminNav::items() as [$_lh, $_ll, $_lk]): $_la = $_lk !== '' && ($_lan_cur === $_lk || ($_lk === 'licencjeDydaktyczne.php' && $_lan_cur === 'licencjeDydaktyczne.php' && !str_contains((string)($_GET['_route'] ?? ''), 'korekt'))); ?>
    <a href="<?= h($_lh) ?>"<?= $_la ? ' aria-current="page"' : '' ?> style="color:#fff;padding:.3rem .65rem;text-decoration:none;<?= $_la ? 'background:#1ba1e2;' : '' ?>"><?= h($_ll) ?></a>
  <?php endforeach; ?>
</nav>
