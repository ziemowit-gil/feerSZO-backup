<?php
/**
 * crm/settings/_nav.php — boczna nawigacja ustawień CRM.
 * Otwiera .crm-settings-layout i .crm-settings-body — zamknij przez _nav_end.php.
 */

$_sn_uri = $_SERVER['REQUEST_URI'] ?? '';
$_sn_a   = static fn(string $s): string => str_contains($_sn_uri, $s) ? ' active' : '';
$_sn_root = preg_match('|/crm/settings/(index\.php)?$|', strtok($_sn_uri, '?'));
?>
<?php
// Rejestr: grupa => [tytuł, ikona, pozycje]; pozycja = [etykieta, ścieżka, ikona, fragment URL aktywności|null=dokładny root]
$_sn_groups = [
    ['Moje',                 'bi-person-gear', [
        ['Ogólne',               '/crm/settings/',                  'bi-gear-fill',          '#root'],
        ['Mój podpis',           '/crm/settings/signature.php',     'bi-pen',                'settings/signature'],
    ]],
    ['Kartoteki i pola',     'bi-collection', [
        ['Kategorie kontaktów',  '/crm/settings/categories.php',    'bi-diagram-2',          'settings/categories'],
        ['Statusy',              '/crm/settings/statuses.php',      'bi-bookmark-fill',      'settings/statuses'],
        ['Statusy automatyczne', '/crm/settings/status_rules.php',  'bi-magic',              'settings/status_rules'],
        ['Statusy i listy z AI', '/crm/settings/ai_builder.php',    'bi-stars',              'settings/ai_builder'],
        ['Rodzaje usług',        '/crm/settings/services.php',      'bi-tools',              'settings/services'],
        ['Grupy pól',            '/crm/settings/field_groups.php',  'bi-layers',             'settings/field_groups'],
        ['Pola niestandardowe',  '/crm/settings/fields.php',        'bi-layout-text-sidebar-reverse', 'settings/fields.php'],
        ['Pola systemowe',       '/crm/settings/fields_system.php', 'bi-database-lock',      'settings/fields_system'],
    ]],
    ['Dostęp',               'bi-shield-lock', [
        ['Role CRM',             '/crm/settings/roles.php',         'bi-people-fill',        'settings/roles'],
        ['Uprawnienia (role, pola)', '/crm/settings/permissions.php', 'bi-shield-lock',      'settings/permissions'],
        ['Opiekunowie (automat)', '/crm/settings/owner_rules.php',  'bi-person-gear',        'settings/owner_rules'],
    ]],
    ['Zgody i wysyłki',      'bi-shield-check', [
        ['Cele zgód',            '/crm/settings/consents.php',      'bi-shield-check',       'settings/consents'],
        ['Retencja danych',      '/crm/settings/retention.php',     'bi-eraser',             'settings/retention'],
        ['Lista wykluczeń',      '/crm/settings/suppressions.php',  'bi-slash-circle',       'settings/suppressions'],
    ]],
    ['Oferty i automatyzacje', 'bi-lightning-charge-fill', [
        ['Reguły ofert',         '/crm/settings/offers.php',        'bi-file-earmark-ruled-fill', 'settings/offers'],
        ['Katalog usług',        '/crm/offers/catalog.php',         'bi-list-columns',       'offers/catalog'],
        ['Reguły automatyzacji', '/crm/settings/automations.php',   'bi-lightning-charge-fill', 'settings/automations'],
    ]],
];
if (is_admin()) $_sn_groups[] = ['Zaawansowane', 'bi-sliders2', [
    ['Wymaganie IKA',        '/crm/settings/ika.php',           'bi-shield-lock',        'settings/ika'],
    ['Śledzenie skrzynki',   '/crm/settings/inbox.php',         'bi-inbox',              'settings/inbox'],
    ['Microsoft 365',        '/crm/settings/office.php',        'bi-microsoft',          'settings/office'],
    ['Nozbe',                '/crm/settings/nozbe.php',         'bi-check2-circle',      'settings/nozbe'],
    ['Import TERYT',         '/admin/teryt_import.php',         'bi-geo-alt',            ''],
    ['Baza danych CRM',      '/admin/crm_database.php',         'bi-database-gear',      ''],
]];
$_sn_is = static fn(array $it): bool => $it[3] === '#root' ? (bool)$_sn_root : ($it[3] !== '' && str_contains($_sn_uri, $it[3]));
?>
<style>
.crm-settings-layout { display: flex; gap: 1.25rem; align-items: flex-start; }

.crm-settings-nav {
  width: 216px;
  flex-shrink: 0;
  background: #fff;
  border-radius: 10px;
  border: 1px solid #E5E7EB;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
  padding: .5rem;
  position: sticky;
  top: calc(var(--crm-topbar-h, 52px) + 1rem);
  max-height: calc(100vh - var(--crm-topbar-h, 52px) - 2rem);
  overflow-y: auto;
}
.crm-settings-body { flex: 1; min-width: 0; }

.csn-title { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #6B7280; padding: .3rem .6rem .45rem; }
.csn-find { width: 100%; font-size: .8rem; padding: .3rem .6rem; border: 1px solid #D1D5DB; border-radius: 6px; margin-bottom: .35rem; }
.csn-find:focus { outline: 2px solid var(--crm-primary, #0176D3); outline-offset: 1px; }

.csn-grp { border-top: 1px solid #F3F4F6; }
.csn-grp:first-of-type { border-top: 0; }
.csn-grp > summary {
  list-style: none; cursor: pointer; display: flex; align-items: center; gap: .5rem;
  padding: .45rem .6rem; border-radius: 6px; font-size: .8rem; font-weight: 600; color: #1F2937;
}
.csn-grp > summary::-webkit-details-marker { display: none; }
.csn-grp > summary:hover { background: #F9FAFB; }
.csn-grp > summary:focus-visible { outline: 2px solid var(--crm-primary, #0176D3); outline-offset: -2px; }
.csn-grp > summary i.g { color: #6B7280; width: 17px; text-align: center; }
.csn-grp > summary .chev { margin-left: auto; font-size: .65rem; color: #9CA3AF; transition: transform .15s; }
.csn-grp[open] > summary .chev { transform: rotate(90deg); }
.csn-grp.has-active > summary { color: var(--crm-primary); }

.csn-link {
  display: flex; align-items: center; gap: .55rem;
  padding: .32rem .6rem .32rem 1.75rem; border-radius: 6px;
  font-size: .8rem; font-weight: 500; color: #374151; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.csn-link i { font-size: .85rem; width: 16px; text-align: center; flex-shrink: 0; color: #6B7280; }
.csn-link:hover { background: #F9FAFB; color: var(--crm-primary); }
.csn-link.active { background: var(--crm-primary-bg, #EFF6FF); color: var(--crm-primary); font-weight: 600; }
.csn-link.active i { color: var(--crm-primary); }
.csn-none { display: none; font-size: .78rem; color: #6B7280; padding: .4rem .6rem; }

@media (max-width: 700px) {
  .crm-settings-layout { flex-direction: column; }
  .crm-settings-nav { width: 100%; position: static; max-height: none; overflow-y: visible; }
}
</style>

<div class="crm-settings-layout">

<!-- ══ MENU USTAWIEŃ ═════════════════════════════════════════════════════ -->
<nav class="crm-settings-nav" aria-label="Nawigacja ustawień CRM">
  <div class="csn-title">Ustawienia CRM</div>
  <input type="search" class="csn-find" id="csnFind" placeholder="Szukaj ustawienia…" aria-label="Szukaj ustawienia" autocomplete="off">

  <?php foreach ($_sn_groups as $_g):
      $_ga = false; foreach ($_g[2] as $_it) if ($_sn_is($_it)) { $_ga = true; break; } ?>
  <details class="csn-grp<?= $_ga ? ' has-active' : '' ?>"<?= ($_ga || $_g[0] === 'Moje') ? ' open' : '' ?>>
    <summary><i class="bi <?= h($_g[1]) ?> g" aria-hidden="true"></i><?= h($_g[0]) ?><i class="bi bi-chevron-right chev" aria-hidden="true"></i></summary>
    <?php foreach ($_g[2] as $_it): $_ia = $_sn_is($_it); ?>
    <a href="<?= APP_URL . h($_it[1]) ?>" class="csn-link<?= $_ia ? ' active' : '' ?>"<?= $_ia ? ' aria-current="page"' : '' ?>>
      <i class="bi <?= h($_it[2]) ?>" aria-hidden="true"></i><?= h($_it[0]) ?>
    </a>
    <?php endforeach; ?>
  </details>
  <?php endforeach; ?>
  <div class="csn-none" id="csnNone" role="status">Brak pasujących ustawień.</div>
</nav><!-- /.crm-settings-nav -->

<script>
(function(){
  var q = document.getElementById('csnFind'), none = document.getElementById('csnNone');
  if (!q) return;
  var grps = document.querySelectorAll('.crm-settings-nav .csn-grp'), openState = [];
  q.addEventListener('input', function(){
    var t = q.value.trim().toLowerCase(), any = false;
    grps.forEach(function(g, i){
      if (t && openState[i] === undefined) openState[i] = g.open;
      var hit = 0;
      g.querySelectorAll('.csn-link').forEach(function(a){
        var m = !t || a.textContent.toLowerCase().indexOf(t) !== -1;
        a.style.display = m ? '' : 'none'; if (m) hit++;
      });
      g.style.display = hit ? '' : 'none'; if (hit) any = true;
      if (t) g.open = !!hit; else if (openState[i] !== undefined) { g.open = openState[i]; openState[i] = undefined; }
    });
    none.style.display = (t && !any) ? 'block' : 'none';
  });
})();
</script>

<!-- ══ TREŚĆ ═════════════════════════════════════════════════════════ -->
<div class="crm-settings-body">
