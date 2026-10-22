<?php
/** Pasek zakładek modułu Noclegi wg roli. Zmienne: $active (klucz zakładki), $role. */
$_tabs = [['index', 'Moje noclegi', 'nocl.index', 1], ['nowe', 'Nowe zgłoszenie', 'nocl.create', 1]];
if (in_array($role, ['koordynator', 'logistyk'], true)) { $_tabs[] = ['kolejka', 'Kolejka zgłoszeń', 'nocl.queue', 1]; $_tabs[] = ['budzety', 'Budżety i granty', 'nocl.budgets', 1]; $_tabs[] = ['faktury', 'Faktury', 'nocl.invoices', 1]; }
if ($role === 'logistyk') { $_tabs[] = ['rooming', 'Rooming', 'nocl.rooming', 1]; $_tabs[] = ['obiekty', 'Obiekty i pokoje', 'nocl.facilities', 1]; $_tabs[] = ['role', 'Role', 'nocl.roles', 1]; }
?>
<p class="ncl-eyebrow">Noclegi · <?= h(noclRoleModulu()[$role] ?? '') ?></p>
<nav class="ncl-nav" aria-label="Noclegi — sekcje">
<?php foreach ($_tabs as [$k, $l, $rt]): ?>
  <a href="<?= h(route($rt)) ?>"<?= ($active ?? '') === $k ? ' aria-current="page"' : '' ?>><?= h($l) ?></a>
<?php endforeach; ?>
</nav>
<?= flash_html() ?>
