<?php
/**
 * crm/settings/_nav.php — boczna nawigacja ustawień CRM.
 * Otwiera .crm-settings-layout i .crm-settings-body — zamknij przez _nav_end.php.
 */

$_sn_uri = $_SERVER['REQUEST_URI'] ?? '';
$_sn_a   = static fn(string $s): string => str_contains($_sn_uri, $s) ? ' active' : '';
$_sn_root = preg_match('|/crm/settings/(index\.php)?$|', strtok($_sn_uri, '?'));
?>
<style>
.crm-settings-layout { display: flex; gap: 1.25rem; align-items: flex-start; }

.crm-settings-nav {
  width: 196px;
  flex-shrink: 0;
  background: #fff;
  border-radius: 10px;
  border: 1px solid #E5E7EB;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
  padding: .4rem .4rem .6rem;
  position: sticky;
  top: calc(var(--crm-topbar-h, 52px) + 1rem);
  max-height: calc(100vh - var(--crm-topbar-h, 52px) - 2rem);
  overflow-y: auto;
}

.crm-settings-body { flex: 1; min-width: 0; }

.csn-title {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: #9CA3AF;
  padding: .55rem .65rem .2rem;
}

.csn-link {
  display: flex; align-items: center; gap: .55rem;
  padding: .38rem .65rem;
  border-radius: 6px;
  font-size: .815rem; font-weight: 500;
  color: #374151; text-decoration: none;
  transition: background .1s, color .1s;
  white-space: nowrap;
  overflow: hidden;
}
.csn-link i { font-size: .9rem; width: 17px; text-align: center; flex-shrink: 0; color: #9CA3AF; transition: color .1s; }
.csn-link img { width: 14px; height: 14px; flex-shrink: 0; opacity: .55; transition: opacity .1s; }
.csn-link:hover { background: #F9FAFB; color: var(--crm-primary); }
.csn-link:hover i, .csn-link:hover img { color: var(--crm-primary); opacity: 1; }
.csn-link.active { background: var(--crm-primary-bg, #EFF6FF); color: var(--crm-primary); font-weight: 600; }
.csn-link.active i { color: var(--crm-primary); }
.csn-link.active::before {
  display: none; /* the sidebar already has a clear active state */
}

.csn-sep { height: 1px; background: #F3F4F6; margin: .35rem .3rem; }

@media (max-width: 700px) {
  .crm-settings-layout { flex-direction: column; }
  .crm-settings-nav { width: 100%; position: static; max-height: none; overflow-y: visible; }
}
</style>

<div class="crm-settings-layout">

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════ -->
<nav class="crm-settings-nav" aria-label="Nawigacja ustawień CRM">

  <div class="csn-title">Ustawienia CRM</div>

  <a href="<?= APP_URL ?>/crm/settings/" class="csn-link<?= $_sn_root ? ' active' : '' ?>">
    <i class="bi bi-gear-fill"></i> Ogólne
  </a>
  <a href="<?= APP_URL ?>/crm/settings/signature.php" class="csn-link<?= $_sn_a('settings/signature') ?>">
    <i class="bi bi-pen"></i> Mój podpis
  </a>

  <div class="csn-sep"></div>
  <div class="csn-title">Kontakty</div>

  <a href="<?= APP_URL ?>/crm/settings/roles.php" class="csn-link<?= $_sn_a('settings/roles') ?>">
    <i class="bi bi-people-fill"></i> Role CRM
  </a>
  <a href="<?= APP_URL ?>/crm/settings/permissions.php" class="csn-link<?= $_sn_a('settings/permissions') ?>">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>Uprawnienia (role, pola)
  </a>
  <a href="<?= APP_URL ?>/crm/settings/owner_rules.php" class="csn-link<?= $_sn_a('settings/owner_rules') ?>">
    <i class="bi bi-person-gear" aria-hidden="true"></i>Opiekunowie (automat)
  </a>
  <a href="<?= APP_URL ?>/crm/settings/statuses.php" class="csn-link<?= $_sn_a('settings/statuses') ?>">
    <i class="bi bi-bookmark-fill"></i> Statusy
  </a>
  <a href="<?= APP_URL ?>/crm/settings/services.php" class="csn-link<?= $_sn_a('settings/services') ?>">
    <i class="bi bi-tools"></i> Rodzaje usług
  </a>
  <a href="<?= APP_URL ?>/crm/settings/consents.php" class="csn-link<?= $_sn_a('settings/consents') ?>">
    <i class="bi bi-shield-check"></i> Cele zgód
  </a>
  <a href="<?= APP_URL ?>/crm/settings/retention.php" class="csn-link<?= $_sn_a('settings/retention') ?>">
    <i class="bi bi-eraser"></i> Retencja danych
  </a>

  <div class="csn-sep"></div>
  <div class="csn-title">Oferty</div>

  <a href="<?= APP_URL ?>/crm/settings/offers.php" class="csn-link<?= $_sn_a('settings/offers') ?>">
    <i class="bi bi-file-earmark-ruled-fill"></i> Reguły ofert
  </a>
  <a href="<?= APP_URL ?>/crm/offers/catalog.php" class="csn-link<?= $_sn_a('offers/catalog') ?>">
    <i class="bi bi-list-columns"></i> Katalog usług
  </a>

  <div class="csn-sep"></div>
  <div class="csn-title">Wysyłki</div>

  <a href="<?= APP_URL ?>/crm/settings/suppressions.php" class="csn-link<?= $_sn_a('settings/suppressions') ?>">
    <i class="bi bi-slash-circle"></i> Lista wykluczeń
  </a>

  <div class="csn-sep"></div>
  <div class="csn-title">Automatyzacje</div>

  <a href="<?= APP_URL ?>/crm/settings/automations.php" class="csn-link<?= $_sn_a('settings/automations') ?>">
    <i class="bi bi-lightning-charge-fill"></i> Reguły automatyzacji
  </a>

  <div class="csn-sep"></div>
  <div class="csn-title">Pola formularza</div>

  <a href="<?= APP_URL ?>/crm/settings/field_groups.php" class="csn-link<?= $_sn_a('settings/field_groups') ?>">
    <i class="bi bi-layers"></i> Grupy pól
  </a>
  <a href="<?= APP_URL ?>/crm/settings/fields.php" class="csn-link<?= $_sn_a('settings/fields.php') ?>">
    <i class="bi bi-layout-text-sidebar-reverse"></i> Pola niestandardowe
  </a>
  <a href="<?= APP_URL ?>/crm/settings/fields_system.php" class="csn-link<?= $_sn_a('settings/fields_system') ?>">
    <i class="bi bi-database-lock"></i> Pola systemowe
  </a>

  <?php if (is_admin()): ?>
  <div class="csn-sep"></div>
  <div class="csn-title">Zaawansowane</div>

  <a href="<?= APP_URL ?>/crm/settings/ika.php" class="csn-link<?= $_sn_a('settings/ika') ?>">
    <i class="bi bi-shield-lock"></i> Wymaganie IKA
  </a>
  <a href="<?= APP_URL ?>/crm/settings/inbox.php" class="csn-link<?= $_sn_a('settings/inbox') ?>">
    <i class="bi bi-inbox"></i> Śledzenie skrzynki
  </a>
  <a href="<?= APP_URL ?>/crm/settings/office.php" class="csn-link<?= $_sn_a('settings/office') ?>">
    <i class="bi bi-microsoft"></i> Microsoft 365
  </a>
  <a href="<?= APP_URL ?>/admin/teryt_import.php" class="csn-link">
    <i class="bi bi-geo-alt"></i> Import TERYT
  </a>
  <a href="<?= APP_URL ?>/admin/crm_database.php" class="csn-link">
    <i class="bi bi-database-gear"></i> Baza danych CRM
  </a>
  <a href="<?= APP_URL ?>/crm/settings/nozbe.php" class="csn-link<?= $_sn_a('settings/nozbe') ?>">
    <img src="https://nozbe.com/favicon.ico" alt=""> Nozbe
  </a>
  <?php endif; ?>

</nav><!-- /.crm-settings-nav -->

<!-- ══ TREŚĆ ══════════════════════════════════════════════════════════== -->
<div class="crm-settings-body">
