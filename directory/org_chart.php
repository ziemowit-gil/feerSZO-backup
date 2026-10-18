<?php
/**
 * directory/org_chart.php — Struktura organizacyjna (tylko odczyt).
 * Wbudowana w moduł Katalogu współpracowników, bez przycisków edycji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';
require_once dirname(__DIR__) . '/includes/org.php';

require_login();
directory_migrate();

$PAGE_TITLE = 'Struktura organizacyjna';

// ── Dane ──────────────────────────────────────────────────────────────────────
$all_units      = [];
$internal_tree  = [];
$external_units = [];
$enabled        = true;

try {
    $all_units      = org_units_all();
    $internal_units = array_filter($all_units, fn($u) => ($u['kind'] ?? 'internal') !== 'external');
    $external_units = array_filter($all_units, fn($u) => ($u['kind'] ?? 'internal') === 'external');
    $internal_tree  = org_build_tree($internal_units);
    // Katalog jednostek zewnętrznych — płaska lista wg nazwy
    usort($external_units, fn($a, $b) => strcmp($a['name'], $b['name']));
} catch (\Throwable $e) {
    $enabled = false;
}

$has_units     = $enabled && !empty($all_units);
$total_units   = count(array_filter($all_units, fn($u) => $u['status'] === 'active' && ($u['kind'] ?? 'internal') !== 'external'));
$total_external = count($external_units);
$total_members = 0;
try {
    $r = db_one("SELECT COUNT(*) AS c FROM org_members WHERE status='active' AND (valid_to IS NULL OR valid_to >= date('now'))");
    $total_members = (int)($r['c'] ?? 0);
} catch (\Throwable $e) {}

// Jednostka szczegółowa (GET unit_id)
$unit_focus = (int)($_GET['unit'] ?? 0);

include __DIR__ . '/includes/header_dir.php';
?>

<style>
/* ── Drzewo org — styl kart ────────────────────────────────────── */
.oc-tree { }
.oc-node { position: relative; }

/* Dashed connector dla dzieci */
.oc-children {
  margin-left: 1.4rem;
  padding-left: .85rem;
  border-left: 2px dashed #C7D2FE;
  padding-top: .2rem;
}

/* Węzeł = karta */
.oc-row {
  display: flex; align-items: center; gap: .6rem;
  padding: .5rem .75rem;
  border-radius: 10px;
  background: #fff;
  border: 1px solid var(--dir-border);
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  cursor: pointer;
  margin-bottom: 5px;
  transition: border-color .14s, box-shadow .14s, background .12s;
}
.oc-row:hover {
  border-color: var(--dir-primary-light);
  background: var(--dir-primary-bg);
  box-shadow: 0 3px 10px rgba(79,70,229,.1);
}
[role="treeitem"].focused > .oc-row {
  border-color: var(--dir-primary);
  background: var(--dir-primary-bg);
  box-shadow: 0 3px 12px rgba(79,70,229,.14);
  border-left-width: 3px;
}
[role="treeitem"]:focus { outline: 2px solid var(--dir-primary); outline-offset: 1px; border-radius: 10px; }

/* Toggle chevron */
.oc-toggle {
  background: none; border: none; padding: 0;
  color: var(--dir-primary-light); font-size: .78rem;
  width: 18px; flex-shrink: 0; cursor: pointer;
  transition: transform .15s, color .1s;
  display: flex; align-items: center; justify-content: center;
}
.oc-toggle:hover { color: var(--dir-primary); }
.oc-toggle.open { transform: rotate(90deg); }

/* Ikonka jednostki — kolorowe pudełko */
.oc-unit-icon {
  width: 30px; height: 30px; border-radius: 8px;
  background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
  color: var(--dir-primary);
  display: flex; align-items: center; justify-content: center;
  font-size: .88rem; flex-shrink: 0;
}
[role="treeitem"].focused > .oc-row .oc-unit-icon,
.oc-row:hover .oc-unit-icon {
  background: linear-gradient(135deg, #E0E7FF 0%, #C7D2FE 100%);
}

/* Treść węzła */
.oc-unit-body { flex: 1; min-width: 0; }
.oc-name { font-size: .86rem; font-weight: 600; color: var(--dir-text); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.oc-unit-meta { font-size: .7rem; color: var(--dir-text-light); display: block; margin-top: .05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Pozostałe elementy wiersza */
.oc-code { font-size: .63rem; font-family: monospace; background: #F1F5F9; color: #475569; padding: .1rem .38rem; border-radius: 4px; flex-shrink: 0; }
.oc-count {
  font-size: .7rem; background: var(--dir-primary-bg); color: var(--dir-primary);
  border: 1px solid var(--dir-primary-light); border-radius: 10px;
  padding: .05rem .42rem; flex-shrink: 0; font-weight: 700; min-width: 20px; text-align: center;
}
.oc-inactive { opacity: .45; }

/* ── Panel szczegółów ─────────────────────────────────────────── */
.oc-detail {
  background: #fff; border: 1px solid var(--dir-border);
  border-radius: 14px; padding: 0; overflow: hidden;
}
.oc-detail-header {
  background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%);
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #C7D2FE;
  display: flex; align-items: center; gap: .75rem;
}
.oc-detail-header-icon {
  width: 36px; height: 36px; border-radius: 10px;
  background: linear-gradient(135deg, var(--dir-primary-dark), var(--dir-primary));
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.oc-detail-title { font-size: 1rem; font-weight: 700; color: var(--dir-text); margin: 0; line-height: 1.2; }
.oc-detail-meta  { font-size: .77rem; color: var(--dir-text-muted); margin-top: .15rem; }
.oc-detail-body  { padding: 1rem 1.25rem; }

/* Karta członka jednostki */
.oc-member-card {
  display: flex; align-items: center; gap: .65rem;
  padding: .5rem .65rem; border-radius: 9px;
  text-decoration: none; color: inherit;
  border: 1px solid transparent;
  transition: background .1s, border-color .1s;
}
.oc-member-card:hover { background: var(--dir-primary-bg); border-color: var(--dir-primary-light); color: inherit; }
.oc-member-name { font-size: .85rem; font-weight: 600; color: var(--dir-text); }
.oc-member-pos  { font-size: .72rem; color: var(--dir-text-muted); }
.oc-member-head-badge {
  font-size: .62rem; font-weight: 700; padding: .1rem .38rem;
  background: #FEF9C3; color: #A16207; border-radius: 4px;
  border: 1px solid #FDE68A; white-space: nowrap; flex-shrink: 0;
}

/* Karta jednostki zewnętrznej */
.oc-ext-card {
  display: flex; flex-direction: column; gap: .35rem; height: 100%;
  background: #fff; border: 1px solid var(--dir-border);
  border-radius: 12px; padding: .9rem 1rem;
  cursor: pointer; transition: box-shadow .12s, border-color .12s;
}
.oc-ext-card:hover, .oc-ext-card:focus-visible {
  box-shadow: 0 3px 14px rgba(0,0,0,.08); border-color: #7DD3FC; outline: none;
}
.oc-ext-card.focused { border-color: #0EA5E9; box-shadow: 0 0 0 2px rgba(14,165,233,.25); }
.oc-ext-head { display: flex; align-items: center; gap: .5rem; }
.oc-ext-icon { width: 30px; height: 30px; border-radius: 8px; background: #E0F2FE; color: #0284C7;
               display: flex; align-items: center; justify-content: center; font-size: .95rem; flex-shrink: 0; }
.oc-ext-name { font-size: .9rem; font-weight: 700; color: var(--dir-text); flex: 1; min-width: 0; }
.oc-ext-type { font-size: .68rem; font-weight: 600; color: #0369A1; background: #E0F2FE;
               border: 1px solid #BAE6FD; border-radius: 6px; padding: .05rem .4rem; align-self: flex-start; }
.oc-ext-meta { font-size: .76rem; color: var(--dir-text-muted); display: flex; flex-wrap: wrap; gap: .15rem .7rem; }
.oc-ext-meta a { color: inherit; text-decoration: none; }
.oc-ext-meta a:hover { color: var(--dir-primary); text-decoration: underline; }

@media (max-width: 640px) {
  .oc-code { display: none; }
  .oc-children { margin-left: .85rem; padding-left: .5rem; }
}
</style>

<?php
require_once dirname(__DIR__) . '/modules/katalog_metro/lib/katalogMetro.php';
katalogMetroAssets();
// Kafle: każda jednostka najwyższego poziomu = grupa (ona sama szeroko + wszystkie podległe)
$ktmFlatten = function (array $nodes) use (&$ktmFlatten): array {
    $out = [];
    foreach ($nodes as $n) { $out[] = $n; if (!empty($n['children'])) $out = array_merge($out, $ktmFlatten($n['children'])); }
    return $out;
};
$ktmHref = fn(int $id) => APP_URL . '/directory/org_chart.php?unit=' . $id . '#jednostka';
?>
<div class="pvm ktm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Katalog współpracowników · struktura organizacyjna</div>
      <h1 class="mt-title">Jednostki</h1>
      <p class="mt-lead">Kliknij kafel jednostki, aby zobaczyć jej skład, kierownika i jednostki podległe.</p>
      <?php if ($has_units): ?>
      <div class="ktm-search" role="search">
        <label for="ktm-q" class="visually-hidden">Filtruj jednostki i osoby</label>
        <input type="search" id="ktm-q" placeholder="Filtruj jednostki…" autocomplete="off">
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php if (!$has_units): ?>
  <div class="ktm-empty" role="status">
    <i class="bi bi-diagram-3 d-block mb-2" aria-hidden="true" style="font-size:2.5rem;opacity:.4"></i>
    Struktura organizacyjna nie jest jeszcze skonfigurowana.
    <?php if (is_admin()): ?><p class="mt-3 mb-0"><a class="ktm-chip" href="<?= APP_URL ?>/org/units/add.php"><i class="bi bi-plus-lg" aria-hidden="true"></i>Utwórz pierwszą jednostkę</a></p><?php endif; ?>
  </div>
<?php else: ?>

  <section class="mt-grp" aria-label="Podsumowanie">
    <div class="mt-grid mt-grid--sm">
      <?php
      panelMetroTile(['href' => '#ktm-kafle', 'icon' => 'bi-diagram-3', 'label' => 'Jednostki', 'val' => (string)$total_units, 'sub' => 'aktywnych wewnętrznych', 'color' => 'indigo']);
      panelMetroTile(['href' => APP_URL . '/directory/', 'icon' => 'bi-people-fill', 'label' => 'Osoby', 'val' => (string)$total_members, 'sub' => 'przypisań w jednostkach', 'color' => 'teal']);
      if ($total_external) panelMetroTile(['href' => '#ktm-zewn', 'icon' => 'bi-buildings', 'label' => 'Zewnętrzne', 'val' => (string)$total_external, 'sub' => 'partnerzy', 'color' => 'grey']);
      ?>
    </div>
  </section>

  <?php if ($unit_focus > 0): ?>
  <div id="jednostka" tabindex="-1">
    <p style="margin:0 0 .6rem"><a class="ktm-chip" href="<?= APP_URL ?>/directory/org_chart.php"><i class="bi bi-x-lg" aria-hidden="true"></i>Zamknij kartę jednostki</a>
       <a class="ktm-chip" href="<?= APP_URL ?>/directory/?unit_id=<?= (int)$unit_focus ?>"><i class="bi bi-people" aria-hidden="true"></i>Pokaż w katalogu osób</a></p>
    <?php katalogUnitDetail($unit_focus); ?>
  </div>
  <?php endif; ?>

  <nav class="mt-pivot" role="tablist" aria-label="Widok struktury">
    <button type="button" role="tab" id="ktm-tab-kafle" aria-controls="ktm-kafle" aria-selected="true" data-ktm-tab="kafle">Kafle</button>
    <button type="button" role="tab" id="ktm-tab-drzewo" aria-controls="ktm-drzewo" aria-selected="false" data-ktm-tab="drzewo" tabindex="-1">Drzewo</button>
  </nav>

  <div id="ktm-kafle" role="tabpanel" aria-labelledby="ktm-tab-kafle" tabindex="-1">
    <?php foreach ($internal_tree as $root):
        $desc = !empty($root['children']) ? $ktmFlatten($root['children']) : [];
        $gid  = 'ktm-g' . (int)$root['id'];
    ?>
    <section class="mt-grp" aria-labelledby="<?= $gid ?>" data-ktm-filter>
      <h2 class="mt-grp-h" id="<?= $gid ?>"><?= h($root['name']) ?></h2>
      <div class="mt-grid mt-grid--sm">
        <?php
        $head = null; try { $head = org_unit_head((int)$root['id']); } catch (\Throwable $e) {}
        katalogUnitTile($root, $ktmHref((int)$root['id']), ['size' => 'w', 'on' => (int)$root['id'] === $unit_focus,
            'sub' => trim(((int)$root['member_count']) . ' os.' . ($desc ? ' · ' . count($desc) . ' podległych' : '') . ($head ? ' · ' . $head['user_name'] : ''))]);
        foreach ($desc as $d) katalogUnitTile($d, $ktmHref((int)$d['id']), ['on' => (int)$d['id'] === $unit_focus,
            'icon' => !empty($d['children']) ? 'bi-diagram-3' : 'bi-folder2']);
        ?>
      </div>
    </section>
    <?php endforeach; ?>
    <?php if (!$internal_tree): ?><div class="ktm-empty" role="status">Brak jednostek wewnętrznych.</div><?php endif; ?>

    <?php if ($external_units): ?>
    <section class="mt-grp" aria-labelledby="ktm-zewn" data-ktm-filter>
      <h2 class="mt-grp-h" id="ktm-zewn">Jednostki zewnętrzne · partnerzy</h2>
      <div class="mt-grid mt-grid--sm">
        <?php foreach ($external_units as $u) {
            $type = (defined('ORG_EXT_TYPES') && !empty($u['cooperation_type']) && isset(ORG_EXT_TYPES[$u['cooperation_type']])) ? ORG_EXT_TYPES[$u['cooperation_type']] : 'partner';
            katalogUnitTile($u, $ktmHref((int)$u['id']), ['external' => true, 'sub' => $type, 'on' => (int)$u['id'] === $unit_focus]);
        } ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <div id="ktm-drzewo" role="tabpanel" aria-labelledby="ktm-tab-drzewo" tabindex="-1" hidden>
    <div class="d-flex gap-2 mb-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="ocExpandAll()" aria-controls="ocTree"><i class="bi bi-arrows-expand me-1" aria-hidden="true"></i>Rozwiń wszystko</button>
      <button class="btn btn-sm btn-outline-secondary" onclick="ocCollapseAll()" aria-controls="ocTree"><i class="bi bi-arrows-collapse me-1" aria-hidden="true"></i>Zwiń wszystko</button>
    </div>
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="oc-tree" id="ocTree">
          <?php if ($internal_tree): ?>
          <ul role="tree" aria-label="Drzewo struktury organizacyjnej" class="list-unstyled mb-0">
            <?php dir_render_tree($internal_tree, 1, $unit_focus); ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-6">
        <div id="ocDetailPanel" role="region" aria-label="Szczegóły jednostki" aria-live="polite">
          <div class="ktm-empty">Wybierz jednostkę w drzewie, aby zobaczyć jej skład.</div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('[data-ktm-tab]');
  function show(key, focus) {
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-ktm-tab') === key;
      t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
      document.getElementById('ktm-' + t.getAttribute('data-ktm-tab')).hidden = !on;
      if (on && focus) t.focus();
    });
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.getAttribute('data-ktm-tab')); history.replaceState(null, '', t.getAttribute('data-ktm-tab') === 'drzewo' ? '#drzewo' : location.pathname + location.search); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; show(n.getAttribute('data-ktm-tab'), true);
    });
  });
  if (location.hash === '#drzewo') show('drzewo');
  var j = document.getElementById('jednostka'); if (j && location.hash === '#jednostka') j.focus();
})();
</script>
<?php katalogFilterScript(); ?>

<script>
(function () {
  // ── Helpers ───────────────────────────────────────────────────────────────
  function getVisibleTreeItems() {
    return Array.from(document.querySelectorAll('[role="treeitem"]')).filter(function (el) {
      // An item is visible if none of its ancestors have aria-expanded="false"
      var parent = el.parentElement;
      while (parent) {
        var owner = parent.closest('[role="treeitem"]');
        if (owner && owner.getAttribute('aria-expanded') === 'false') return false;
        if (!owner) break;
        parent = owner.parentElement;
      }
      return true;
    });
  }

  function focusItem(item) {
    if (!item) return;
    // Set tabindex on all items, then focus the target
    document.querySelectorAll('[role="treeitem"]').forEach(function (el) {
      el.setAttribute('tabindex', '-1');
    });
    item.setAttribute('tabindex', '0');
    item.focus();
  }

  function expandItem(item) {
    var children = item.querySelector(':scope > [role="group"]');
    if (!children) return false; // leaf node
    if (item.getAttribute('aria-expanded') === 'false') {
      children.style.display = '';
      item.setAttribute('aria-expanded', 'true');
      var btn = item.querySelector(':scope > .oc-row > .oc-toggle');
      if (btn) btn.classList.add('open');
      return true;
    }
    return false;
  }

  function collapseItem(item) {
    var children = item.querySelector(':scope > [role="group"]');
    if (!children) return false;
    if (item.getAttribute('aria-expanded') === 'true') {
      children.style.display = 'none';
      item.setAttribute('aria-expanded', 'false');
      var btn = item.querySelector(':scope > .oc-row > .oc-toggle');
      if (btn) btn.classList.remove('open');
      return true;
    }
    return false;
  }

  function loadDetail(uid) {
    var panel = document.getElementById('ocDetailPanel');
    panel.setAttribute('aria-busy', 'true');
    panel.innerHTML = '<div class="dir-info-card text-center py-4"><i class="bi bi-hourglass-split" aria-hidden="true" style="font-size:1.5rem;opacity:.4"></i><p class="mt-2 mb-0 small text-muted">Ładowanie…</p></div>';
    fetch('<?= APP_URL ?>/directory/org_unit_detail.php?id=' + uid, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function (r) { return r.text(); })
      .then(function (html) {
        panel.innerHTML = html;
        panel.removeAttribute('aria-busy');
      })
      .catch(function () {
        panel.innerHTML = '<div class="alert alert-warning small" role="alert">Błąd ładowania danych.</div>';
        panel.removeAttribute('aria-busy');
      });
  }

  // ── Keyboard navigation ───────────────────────────────────────────────────
  var tree = document.getElementById('ocTree');
  if (tree) {
    tree.addEventListener('keydown', function (e) {
      var focused = document.activeElement;
      if (!focused || focused.getAttribute('role') !== 'treeitem') return;

      var items = getVisibleTreeItems();
      var idx = items.indexOf(focused);

      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          if (idx < items.length - 1) focusItem(items[idx + 1]);
          break;

        case 'ArrowUp':
          e.preventDefault();
          if (idx > 0) focusItem(items[idx - 1]);
          break;

        case 'ArrowRight':
          e.preventDefault();
          // If collapsed, expand; if already expanded, move to first child
          if (focused.getAttribute('aria-expanded') === 'false') {
            expandItem(focused);
          } else if (focused.getAttribute('aria-expanded') === 'true') {
            var firstChild = focused.querySelector('[role="group"] > [role="treeitem"]');
            if (firstChild) focusItem(firstChild);
          }
          break;

        case 'ArrowLeft':
          e.preventDefault();
          // If expanded, collapse; otherwise move to parent
          if (focused.getAttribute('aria-expanded') === 'true') {
            collapseItem(focused);
          } else {
            var parentGroup = focused.parentElement;
            if (parentGroup && parentGroup.getAttribute('role') === 'group') {
              var parentItem = parentGroup.closest('[role="treeitem"]');
              if (parentItem) focusItem(parentItem);
            }
          }
          break;

        case 'Enter':
        case ' ':
          e.preventDefault();
          var uid = focused.dataset.unit;
          if (uid) {
            document.querySelectorAll('[role="treeitem"]').forEach(function (r) { r.classList.remove('focused'); });
            focused.classList.add('focused');
            loadDetail(uid);
          }
          break;

        case 'Home':
          e.preventDefault();
          if (items.length) focusItem(items[0]);
          break;

        case 'End':
          e.preventDefault();
          if (items.length) focusItem(items[items.length - 1]);
          break;
      }
    });
  }

  // ── Toggle buttons ────────────────────────────────────────────────────────
  document.querySelectorAll('.oc-toggle').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var item = this.closest('[role="treeitem"]');
      if (!item) return;
      var childrenEl = item.querySelector(':scope > [role="group"]');
      if (!childrenEl) return;
      var hidden = childrenEl.style.display === 'none';
      childrenEl.style.display = hidden ? '' : 'none';
      this.classList.toggle('open', hidden);
      item.setAttribute('aria-expanded', hidden ? 'true' : 'false');
    });
  });

  // ── Row click — load detail AJAX ──────────────────────────────────────────
  document.querySelectorAll('[role="treeitem"][data-unit]').forEach(function (item) {
    var row = item.querySelector('.oc-row');
    if (row) {
      row.style.cursor = 'pointer';
      row.addEventListener('click', function (e) {
        if (e.target.closest('.oc-toggle')) return;
        document.querySelectorAll('[role="treeitem"]').forEach(function (r) { r.classList.remove('focused'); });
        item.classList.add('focused');
        loadDetail(item.dataset.unit);
      });
    }
  });

  // ── Katalog jednostek zewnętrznych — klik / klawiatura ─────────────────────
  document.querySelectorAll('.oc-ext-card[data-unit]').forEach(function (card) {
    function activate() {
      document.querySelectorAll('.oc-ext-card').forEach(function (c) { c.classList.remove('focused'); });
      document.querySelectorAll('[role="treeitem"]').forEach(function (r) { r.classList.remove('focused'); });
      card.classList.add('focused');
      loadDetail(card.dataset.unit);
      var panel = document.getElementById('ocDetailPanel');
      if (panel && window.innerWidth < 992) panel.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
    card.addEventListener('click', activate);
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
    });
  });

  // Set first treeitem as focusable
  var firstItem = document.querySelector('[role="treeitem"]');
  if (firstItem) firstItem.setAttribute('tabindex', '0');
})();

// ── Rozwiń / zwiń wszystkie (global, called from onclick) ─────────────────────
function ocExpandAll() {
  document.querySelectorAll('[role="treeitem"]').forEach(function (item) {
    var childrenEl = item.querySelector(':scope > [role="group"]');
    if (childrenEl) {
      childrenEl.style.display = '';
      item.setAttribute('aria-expanded', 'true');
      var btn = item.querySelector(':scope > .oc-row > .oc-toggle');
      if (btn) btn.classList.add('open');
    }
  });
}
function ocCollapseAll() {
  document.querySelectorAll('[role="treeitem"]').forEach(function (item) {
    var childrenEl = item.querySelector(':scope > [role="group"]');
    if (childrenEl) {
      childrenEl.style.display = 'none';
      item.setAttribute('aria-expanded', 'false');
      var btn = item.querySelector(':scope > .oc-row > .oc-toggle');
      if (btn) btn.classList.remove('open');
    }
  });
}
</script>

<?php
include __DIR__ . '/includes/footer_dir.php';

// ── Helpery renderujące ───────────────────────────────────────────────────────

function dir_render_tree(array $nodes, int $level = 1, int $focus_id = 0): void {
    foreach ($nodes as $node):
        $has_ch       = !empty($node['children']);
        $active       = $node['status'] === 'active';
        $focused      = (int)$node['id'] === $focus_id;
        $head         = null;
        try { $head = org_unit_head((int)$node['id']); } catch (\Throwable $e) {}
        $member_count = (int)($node['member_count'] ?? 0);
        $a11y         = h($node['name'])
            . ($node['code'] ? ', kod: ' . h($node['code']) : '')
            . ($head ? ', kierownik: ' . h($head['user_name']) : '')
            . ', ' . $member_count . ' ' . ($member_count === 1 ? 'wolontariusz' : 'wolontariuszy');
        ?>
        <li role="treeitem"
            aria-level="<?= $level ?>"
            <?= $has_ch ? 'aria-expanded="true"' : '' ?>
            tabindex="-1"
            data-unit="<?= (int)$node['id'] ?>"
            class="oc-node<?= $focused ? ' focused' : '' ?><?= $active ? '' : ' oc-inactive' ?>"
            aria-label="<?= $a11y ?>">

          <div class="oc-row">
            <!-- Toggle -->
            <?php if ($has_ch): ?>
            <button class="oc-toggle open" tabindex="-1" aria-hidden="true"
                    aria-label="Zwiń <?= h($node['name']) ?>">
              <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
            <?php else: ?>
            <span style="width:18px;flex-shrink:0" aria-hidden="true"></span>
            <?php endif; ?>

            <!-- Ikonka kolorowa -->
            <span class="oc-unit-icon" aria-hidden="true">
              <i class="bi bi-<?= $has_ch ? 'diagram-3' : 'folder2' ?>"></i>
            </span>

            <!-- Treść -->
            <div class="oc-unit-body" aria-hidden="true">
              <span class="oc-name"><?= h($node['name']) ?></span>
              <?php if ($head): ?>
              <span class="oc-unit-meta">
                <i class="bi bi-person me-1" style="font-size:.65rem"></i><?= h($head['user_name']) ?>
              </span>
              <?php endif; ?>
            </div>

            <?php if ($node['code']): ?>
            <span class="oc-code" aria-hidden="true"><?= h($node['code']) ?></span>
            <?php endif; ?>

            <span class="oc-count" aria-hidden="true" title="<?= $member_count ?> wolontariuszy">
              <?= $member_count ?>
            </span>
          </div>

          <?php if ($has_ch): ?>
          <ul role="group" class="oc-children list-unstyled mb-0">
            <?php dir_render_tree($node['children'], $level + 1, $focus_id); ?>
          </ul>
          <?php endif; ?>
        </li>
    <?php endforeach;
}
?>
