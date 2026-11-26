<?php
/**
 * org/includes/org_nav.php — zakładki Struktury Organizacyjnej (Schemat · Zespoły · Stanowiska).
 * Ustaw $ORG_NAV_ACTIVE = 'schemat' | 'zespoly' | 'stanowiska' przed dołączeniem.
 */
$_org_nav = [
    'schemat'    => ['Schemat i jednostki', '/org/index.php', 'bi-diagram-3', true],
    'zespoly'    => ['Zespoły', '/org/teams.php', 'bi-people-fill', true],
    'stanowiska' => ['Stanowiska', '/org/positions/index.php', 'bi-briefcase', is_admin()],
];
$_org_active = $ORG_NAV_ACTIVE ?? '';
?>
<nav aria-label="Struktura organizacyjna" class="mb-3">
  <ul class="nav nav-tabs">
    <?php foreach ($_org_nav as $_k => [$_lbl, $_href, $_ic, $_show]): if (!$_show) continue; ?>
    <li class="nav-item">
      <a class="nav-link<?= $_k === $_org_active ? ' active' : '' ?>" href="<?= APP_URL . $_href ?>"<?= $_k === $_org_active ? ' aria-current="page"' : '' ?>>
        <i class="bi <?= $_ic ?> me-1" aria-hidden="true"></i><?= h($_lbl) ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</nav>
