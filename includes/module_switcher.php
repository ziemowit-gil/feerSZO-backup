<?php
/**
 * includes/module_switcher.php — Waffle dropdown przełącznika modułów.
 *
 * Użycie przed include:
 *   $msw_active = 'crm';   // klucz aktywnego modułu — patrz $_msw_mods poniżej
 *   $msw_dark   = false;   // true → topbar ciemny (button white-on-dark)
 */

if (!isset($msw_active)) $msw_active = 'szo';
if (!isset($msw_dark))   $msw_dark   = false;

$_msw_mods = [
    ['key'=>'szo',       'label'=>'SZO',           'icon'=>'bi-building',          'mc'=>'#2563eb','mb'=>'#eff6ff','url'=>APP_URL.'/index.php',                          'check'=>null],
    ['key'=>'crm',       'label'=>'CRM',           'icon'=>'bi-diagram-2-fill',    'mc'=>'#16a34a','mb'=>'#f0fdf4','url'=>APP_URL.'/crm/dashboard.php',                  'check'=>fn()=>module_enabled('crm_enabled')&&can_read('crm')],
    ['key'=>'tasks',     'label'=>'Zadania',        'icon'=>'bi-kanban',            'mc'=>'#ea580c','mb'=>'#fff7ed','url'=>APP_URL.'/tasks/dashboard.php',                'check'=>fn()=>module_enabled('tasks_enabled')],
    ['key'=>'wol',       'label'=>'Wolontariusze',  'icon'=>'bi-heart-fill',        'mc'=>'#e11d48','mb'=>'#fff1f2','url'=>APP_URL.'/contracts/wolontariat/list.php',     'check'=>null],
    ['key'=>'actions',   'label'=>'Działania',      'icon'=>'bi-calendar-event',    'mc'=>'#0891b2','mb'=>'#ecfeff','url'=>APP_URL.'/strategy/actions/index.php',         'check'=>null],
    ['key'=>'grants',    'label'=>'Granty',         'icon'=>'bi-cash-coin',         'mc'=>'#15803d','mb'=>'#f0fdf4','url'=>APP_URL.'/grants/index.php',                   'check'=>null],
    ['key'=>'events',    'label'=>'Wydarzenia',     'icon'=>'bi-calendar-star-fill','mc'=>'#7c3aed','mb'=>'#f5f3ff','url'=>APP_URL.'/events/index.php',                   'check'=>fn()=>module_enabled('events_enabled')],
    ['key'=>'directory', 'label'=>'Katalog',        'icon'=>'bi-person-lines-fill', 'mc'=>'#4338ca','mb'=>'#eef2ff','url'=>APP_URL.'/directory/',                         'check'=>null],
    ['key'=>'strategy',  'label'=>'Strategia',      'icon'=>'bi-bullseye',          'mc'=>'#6d28d9','mb'=>'#f5f3ff','url'=>APP_URL.'/strategy/index.php',                 'check'=>null],
    ['key'=>'reports',   'label'=>'Raporty',        'icon'=>'bi-bar-chart-line',    'mc'=>'#0284c7','mb'=>'#f0f9ff','url'=>APP_URL.'/reports/index.php',                  'check'=>null],
    ['key'=>'k30',       'label'=>'Karty 30',       'icon'=>'bi-card-checklist',    'mc'=>'#4f46e5','mb'=>'#eef2ff','url'=>APP_URL.'/karty30/index.php',                  'check'=>fn()=>can_read('karty30')],
    ['key'=>'helpdesk',  'label'=>'Helpdesk',       'icon'=>'bi-ticket-perforated', 'mc'=>'#b45309','mb'=>'#fffbeb','url'=>APP_URL.'/helpdesk/index.php',                 'check'=>fn()=>module_enabled('helpdesk_enabled')],
    ['key'=>'szkolenia', 'label'=>'Szkolenia',      'icon'=>'bi-calendar2-check',   'mc'=>'#7c3aed','mb'=>'#f5f3ff','url'=>APP_URL.'/szkolenia/index.php',                'check'=>fn()=>function_exists('tidycal_enabled') && tidycal_enabled() && (can_read('szkolenia')||is_admin())],
    ['key'=>'rodo',      'label'=>'RODO',           'icon'=>'bi-shield-lock',       'mc'=>'#475569','mb'=>'#f8fafc','url'=>APP_URL.'/rodo/index.php',                     'check'=>null],
    ['key'=>'admin',     'label'=>'Admin',          'icon'=>'bi-gear-fill',         'mc'=>'#1e293b','mb'=>'#f1f5f9','url'=>APP_URL.'/admin/index.php',                    'check'=>fn()=>is_admin()],
];

$_msw_cur = ['label'=>'Moduły','icon'=>'bi-grid-3x3-gap'];
foreach ($_msw_mods as $_m) {
    if ($_m['key'] === $msw_active) { $_msw_cur = $_m; break; }
}
$_msw_btn_cls = $msw_dark ? 'mod-sw-btn mod-sw-btn--dark' : 'mod-sw-btn';
?>
<style>
/* ── Waffle module switcher — shared ─────────────────────────────── */
.mod-sw-btn {
  display:inline-flex;align-items:center;gap:.3rem;
  background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;
  padding:.28rem .6rem;font-size:.8rem;font-weight:500;color:#334155;
  cursor:pointer;line-height:1.4;transition:all .12s;white-space:nowrap;flex-shrink:0;
}
.mod-sw-btn:hover,.mod-sw-btn[aria-expanded="true"] {
  background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;
}
.mod-sw-btn--dark {
  background:rgba(255,255,255,.1) !important;
  border:1px solid rgba(255,255,255,.35) !important;
  color:rgba(255,255,255,.9) !important;
}
.mod-sw-btn--dark:hover,.mod-sw-btn--dark[aria-expanded="true"] {
  background:rgba(255,255,255,.2) !important;
  border-color:rgba(255,255,255,.6) !important;
  color:#fff !important;
}
.mod-sw-cur { max-width:80px;overflow:hidden;text-overflow:ellipsis; }
.mod-sw-panel { border-radius:14px !important;min-width:0 !important; }
.mod-sw-head {
  padding:.6rem .7rem .25rem;
  font-size:.63rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.09em;color:#94a3b8;
}
.mod-sw-grid {
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:.15rem;padding:.15rem .45rem .35rem;
}
.msw-tile {
  display:flex;flex-direction:column;align-items:center;gap:.28rem;
  padding:.5rem .25rem;border-radius:10px;text-decoration:none;
  color:#374151;font-size:.7rem;font-weight:500;text-align:center;
  transition:background .1s,color .1s;line-height:1.25;
}
.msw-tile:hover { background:#f8fafc;color:#1e293b; }
.msw-tile.msw-on { background:#eff6ff;color:#1d4ed8; }
.msw-ic {
  width:38px;height:38px;border-radius:10px;
  background:var(--mb,#f8fafc);color:var(--mc,#64748b);
  display:flex;align-items:center;justify-content:center;
  font-size:1.05rem;transition:transform .12s;flex-shrink:0;
}
.msw-tile:hover .msw-ic { transform:scale(1.08); }
.msw-tile.msw-on .msw-ic { background:#dbeafe;color:#1d4ed8; }
.mod-sw-footer {
  padding:.4rem .7rem .55rem;border-top:1px solid #f1f5f9;margin-top:.15rem;
}
.mod-sw-footer a {
  font-size:.74rem;color:#64748b;text-decoration:none;
  display:inline-flex;align-items:center;gap:.25rem;
}
.mod-sw-footer a:hover { color:#1e293b; }
</style>
<div class="dropdown" id="mod-sw">
  <button type="button" class="<?= $_msw_btn_cls ?>"
          data-bs-toggle="dropdown" data-bs-auto-close="outside"
          aria-expanded="false"
          aria-label="Przełącz moduł — aktualnie: <?= h($_msw_cur['label']) ?>">
    <i class="bi <?= $_msw_cur['icon'] ?>"></i>
    <span class="mod-sw-cur"><?= h($_msw_cur['label']) ?></span>
    <i class="bi bi-chevron-down" style="font-size:.55rem;opacity:.45;margin-left:.05rem"></i>
  </button>

  <div class="dropdown-menu p-0 shadow mod-sw-panel">
    <div class="mod-sw-head">Przejdź do modułu</div>
    <div class="mod-sw-grid">
      <?php foreach ($_msw_mods as $_msw_m):
        if ($_msw_m['check'] !== null && !($_msw_m['check'])()) continue;
        $_msw_on = ($_msw_m['key'] === $msw_active) ? ' msw-on' : '';
      ?>
      <a href="<?= h($_msw_m['url']) ?>" class="msw-tile<?= $_msw_on ?>">
        <div class="msw-ic" style="--mc:<?= h($_msw_m['mc']) ?>;--mb:<?= h($_msw_m['mb']) ?>">
          <i class="bi <?= h($_msw_m['icon']) ?>"></i>
        </div>
        <span><?= h($_msw_m['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="mod-sw-footer">
      <a href="<?= APP_URL ?>/portal.php">
        <i class="bi bi-grid-3x3-gap me-1"></i>Portal — wszystkie moduły
      </a>
    </div>
  </div>
</div>
