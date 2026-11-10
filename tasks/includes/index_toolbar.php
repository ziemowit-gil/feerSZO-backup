<?php
/**
 * tasks/includes/index_toolbar.php
 * Nagłówek obszaru + pasek filtrów/wyszukiwania modułu Zadania.
 * Wydzielone z index.php. Oczekuje zmiennych z kontrolera: $workspace, $can_add,
 * $ws_id, $filter_status, $filter_priority, $filter_tag, $filter_list, $filter_q,
 * $lists_map, $available_tags, $all_areas, $all_org_units, $view_mode,
 * $my_notify_prefs, $my_role, $cnt, $available_templates.
 */
?>
<?php
$_tk_ws_color = (string)($workspace['color'] ?: '#5f5f5f');
$kanban_url = '?' . http_build_query(array_merge($_GET, ['ws' => $ws_id, 'view' => 'kanban']));
$list_url   = '?' . http_build_query(array_merge($_GET, ['ws' => $ws_id, 'view' => 'list']));
$export_url = APP_URL . '/tasks/export.php?' . http_build_query(array_merge($_GET, ['ws' => $ws_id]));
$np_active  = $ws_id && $my_role && ($my_notify_prefs['notify_email'] || $my_notify_prefs['notify_sms'] || $my_notify_prefs['notify_push']);
?>
<!-- ── Nagłówek obszaru: nazwa, widok i działania (rzadsze w menu „⋯”) ─────────── -->
<div class="tk-head">
  <h2 class="tk-head-t">
    <span class="tk-head-tile" style="--ws:<?= h($_tk_ws_color) ?>;--ws-ink:<?= task_ws_ink($_tk_ws_color) ?>" aria-hidden="true"><i class="bi <?= h($workspace['icon'] ?? '' ?: 'bi-folder') ?>"></i></span>
    <span><?= h($workspace['name']) ?></span>
  </h2>
  <div class="tk-head-act">
    <div class="tk-viewsw" role="group" aria-label="Widok zadań">
      <a href="<?= h($list_url) ?>" class="tk-viewsw-b<?= $view_mode !== 'kanban' ? ' is-on' : '' ?>"<?= $view_mode !== 'kanban' ? ' aria-current="page"' : '' ?> onclick="return tkGoView('list')">
        <i class="bi bi-list-ul" aria-hidden="true"></i>Lista</a>
      <a href="<?= h($kanban_url) ?>" class="tk-viewsw-b<?= $view_mode === 'kanban' ? ' is-on' : '' ?>"<?= $view_mode === 'kanban' ? ' aria-current="page"' : '' ?> onclick="return tkGoView('kanban')">
        <i class="bi bi-kanban" aria-hidden="true"></i>Kanban</a>
    </div>
    <?php if ($can_add && $available_templates && $lists_map): ?>
    <button type="button" class="tk-hbtn" onclick="openTemplateModal()">
      <i class="bi bi-list-check" aria-hidden="true"></i>Zastosuj szablon
    </button>
    <?php endif; ?>
    <?php if ($can_add): ?>
    <button type="button" class="tk-hbtn tk-hbtn--cta" onclick="openAddModal()">
      <i class="bi bi-plus-lg" aria-hidden="true"></i>Nowe zadanie
    </button>
    <?php endif; ?>
    <div class="dropdown">
      <button type="button" class="tk-hbtn tk-hbtn--icon" data-bs-toggle="dropdown" aria-expanded="false"
              aria-label="Więcej działań obszaru" title="Więcej: powiadomienia, eksport<?= $can_add ? ', usuwanie obszaru' : '' ?>">
        <i class="bi bi-three-dots" aria-hidden="true"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end tk-more">
        <?php if ($ws_id && $my_role): ?>
        <li><button type="button" class="dropdown-item" onclick="openNotifyModal()" aria-haspopup="dialog">
          <i class="bi bi-bell<?= $np_active ? '-fill' : '' ?>" aria-hidden="true"></i>Powiadomienia obszaru
          <span class="tk-more-note"><?= $np_active ? 'włączone' : 'wyłączone' ?></span></button></li>
        <?php endif; ?>
        <li><a class="dropdown-item" href="<?= h($export_url) ?>" onclick="return tkExport()">
          <i class="bi bi-filetype-csv" aria-hidden="true"></i>Eksportuj widoczne do CSV</a></li>
        <?php if ($can_add): ?>
        <li><hr class="dropdown-divider"></li>
        <li><button type="button" class="dropdown-item tk-more-danger" onclick="openDeleteWsModal()" aria-haspopup="dialog">
          <i class="bi bi-trash3" aria-hidden="true"></i>Usuń obszar…</button></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<script>
/* Widok i eksport z BIEŻĄCYMI filtrami (filtry zmieniają się bez przeładowania, więc adres z serwera bywa nieaktualny) */
function tkFormQuery(extra) {
  var f = document.getElementById('tkFilterForm');
  var q = new URLSearchParams(f ? new FormData(f) : undefined);
  Object.keys(extra || {}).forEach(function (k) { q.set(k, extra[k]); });
  return q.toString();
}
function tkGoView(v) { location.href = '?' + tkFormQuery({ view: v }); return false; }
function tkExport() { location.href = <?= json_encode(APP_URL . '/tasks/export.php') ?> + '?' + tkFormQuery({}); return false; }
</script>

<?php
$_tk_active_filters = (int)((bool)$filter_priority) + (int)((bool)$filter_list)
                    + (int)((bool)$filter_tag) + (int)((bool)$filter_area) + (int)((bool)$filter_unit);
?>
<!-- ── Pasek narzędzi ────────────────────────────────────────────────────── -->
<form id="tkFilterForm" class="tk-toolbar" role="search" aria-label="Filtry i wyszukiwanie" onsubmit="tkAjaxLoad(event)">
  <input type="hidden" name="ws"     value="<?= (int)$ws_id ?>">
  <input type="hidden" name="status" id="tk-status-hidden" value="<?= h($filter_status) ?>">
  <input type="hidden" name="view"   value="<?= h($view_mode) ?>">

  <!-- Status jako zakładki (pivot) z licznikami -->
  <div class="tk-pivot" role="group" aria-label="Filtr statusu">
    <?php
    $sp = [
      'all'   => 'Wszystkie',
      'open'  => 'Do zrobienia',
      'taken' => 'Przydzielone',
      'done'  => 'Ukończone',
      'mine'  => 'Moje',
    ];
    foreach ($sp as $k => $lbl):
    ?>
    <button type="button"
       class="tk-pill <?= $filter_status===$k?'active':'' ?>"
       data-s="<?= $k ?>"
       aria-pressed="<?= $filter_status===$k?'true':'false' ?>"
       onclick="tkSetStatus('<?= $k ?>', this)">
      <?= $lbl ?>
      <span class="pill-n<?= (int)$cnt[$k] ? '' : ' is-zero' ?>" id="tk-cnt-<?= $k ?>"><?= $cnt[$k] ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="tk-tools">
  <!-- Szukaj -->
  <div class="tk-search-w">
    <i class="bi bi-search" aria-hidden="true"></i>
    <label class="visually-hidden" for="tk-q">Szukaj zadania</label>
    <input type="search" id="tk-q" name="q" class="tk-search"
           placeholder="Szukaj w obszarze…"
           value="<?= h($filter_q) ?>"
           aria-label="Szukaj zadania po tytule lub opisie">
  </div>

  <!-- Więcej filtrów — priorytet/kategoria/tag/obszar/jednostka pod jednym przyciskiem -->
  <div class="dropdown" id="tk-filters-wrap">
    <button type="button"
            class="tk-filters-btn <?= $_tk_active_filters ? 'has-active' : '' ?>"
            id="tk-filters-btn"
            data-bs-toggle="dropdown"
            data-bs-auto-close="outside"
            aria-haspopup="true" aria-expanded="false"
            aria-label="Więcej filtrów<?= $_tk_active_filters ? " — {$_tk_active_filters} aktywnych" : '' ?>">
      <i class="bi bi-funnel<?= $_tk_active_filters ? '-fill' : '' ?>" aria-hidden="true"></i>
      Filtry
      <span class="tk-filters-badge <?= $_tk_active_filters ? '' : 'd-none' ?>" id="tk-filters-badge"><?= $_tk_active_filters ?></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow tk-filters-panel" id="tk-filters-panel" role="menu" aria-label="Panel filtrów">

      <div class="tk-filters-field">
        <label for="tk-pri">Priorytet</label>
        <select id="tk-pri" name="pri" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_priority?'selected':'' ?>>Każdy priorytet</option>
          <option value="4" <?= $filter_priority==4?'selected':'' ?>>🔴 Krytyczny</option>
          <option value="3" <?= $filter_priority==3?'selected':'' ?>>🟡 Wysoki</option>
          <option value="2" <?= $filter_priority==2?'selected':'' ?>>🔵 Normalny</option>
          <option value="1" <?= $filter_priority==1?'selected':'' ?>>⚪ Niski</option>
        </select>
      </div>

      <?php if (count($lists_map) > 1): ?>
      <div class="tk-filters-field">
        <label for="tk-list">Kategoria</label>
        <select id="tk-list" name="list" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_list?'selected':'' ?>>Każda kategoria</option>
          <?php foreach ($lists_map as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $filter_list==$l['id']?'selected':'' ?>><?= h($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($available_tags): ?>
      <div class="tk-filters-field">
        <label for="tk-tag">Tag</label>
        <select id="tk-tag" name="tag" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_tag?'selected':'' ?>>Każdy tag</option>
          <?php foreach ($available_tags as $tg): ?>
          <option value="<?= $tg['id'] ?>" <?= $filter_tag==$tg['id']?'selected':'' ?>><?= h($tg['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($all_areas): ?>
      <div class="tk-filters-field">
        <label for="tk-area">Obszar</label>
        <select id="tk-area" name="area" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_area?'selected':'' ?>>Każdy obszar</option>
          <?php foreach ($all_areas as $ar): ?>
          <option value="<?= $ar['id'] ?>" <?= $filter_area==$ar['id']?'selected':'' ?>>
            <?= h($ar['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($all_org_units): ?>
      <div class="tk-filters-field">
        <label for="tk-unit">Jednostka</label>
        <select id="tk-unit" name="unit" class="tk-select" onchange="tkAjaxLoad()">
          <option value="0" <?= !$filter_unit?'selected':'' ?>>Każda jednostka</option>
          <?php foreach ($all_org_units as $ou): ?>
          <option value="<?= $ou['id'] ?>" <?= $filter_unit==$ou['id']?'selected':'' ?>>
            <?= h($ou['short_name'] ?: $ou['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="tk-filters-panel-footer">
        <button type="button" id="tk-clear-filters" class="btn btn-link btn-sm text-decoration-none p-0 <?= $_tk_active_filters || $filter_status!=='all' || $filter_q!=='' ? '' : 'd-none' ?>"
                onclick="tkClearFilters()">
          <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Wyczyść wszystkie filtry
        </button>
      </div>

    </div>
  </div>

  </div><!-- /.tk-tools -->

</form>
