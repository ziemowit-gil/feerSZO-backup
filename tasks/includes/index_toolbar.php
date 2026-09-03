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
<!-- ── Nagłówek obszaru: nazwa + główna akcja ──────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h2 class="h5 fw-bold mb-0 d-flex align-items-center gap-2">
    <span class="ws-dot" style="width:10px;height:10px;border-radius:50%;background:<?= h($workspace['color']) ?>" aria-hidden="true"></span>
    <?= h($workspace['name']) ?>
  </h2>
  <?php if ($can_add): ?>
  <div class="d-flex gap-2">
    <?php if ($available_templates && $lists_map): ?>
    <button type="button"
            class="btn btn-outline-primary"
            onclick="openTemplateModal()"
            aria-label="Zastosuj szablon zadań">
      <i class="bi bi-list-check me-1" aria-hidden="true"></i>Zastosuj szablon
    </button>
    <?php endif; ?>
    <button type="button"
            class="btn btn-primary"
            onclick="openAddModal()"
            aria-label="Dodaj nowe zadanie">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowe zadanie
    </button>
  </div>
  <?php endif; ?>
</div>

<?php
$_tk_active_filters = (int)((bool)$filter_priority) + (int)((bool)$filter_list)
                    + (int)((bool)$filter_tag) + (int)((bool)$filter_area) + (int)((bool)$filter_unit);
?>
<!-- ── Pasek narzędzi ────────────────────────────────────────────────────── -->
<form id="tkFilterForm" class="tk-toolbar" role="search" aria-label="Filtry i wyszukiwanie" onsubmit="tkAjaxLoad(event)">
  <input type="hidden" name="ws"     value="<?= (int)$ws_id ?>">
  <input type="hidden" name="status" id="tk-status-hidden" value="<?= h($filter_status) ?>">
  <input type="hidden" name="view"   value="<?= h($view_mode) ?>">

  <!-- Szukaj -->
  <div>
    <label class="visually-hidden" for="tk-q">Szukaj zadania</label>
    <input type="search" id="tk-q" name="q" class="tk-search"
           placeholder="Szukaj…"
           value="<?= h($filter_q) ?>"
           aria-label="Szukaj zadania po tytule lub opisie">
  </div>

  <div class="tk-sep" role="separator" aria-hidden="true"></div>

  <!-- Status -->
  <div class="d-flex gap-1 flex-wrap" role="group" aria-label="Filtr statusu">
    <?php
    $sp = [
      'all'   => ['Wszystkie', 'bi-list-ul'],
      'open'  => ['Do zrobienia', 'bi-circle'],
      'taken' => ['Przydzielone', 'bi-person-fill'],
      'done'  => ['Ukończone', 'bi-check-circle-fill'],
      'mine'  => ['Moje',      'bi-person-check-fill'],
    ];
    foreach ($sp as $k => [$lbl, $ico]):
    ?>
    <button type="button"
       class="tk-pill <?= $filter_status===$k?'active':'' ?>"
       data-s="<?= $k ?>"
       aria-pressed="<?= $filter_status===$k?'true':'false' ?>"
       onclick="tkSetStatus('<?= $k ?>', this)">
      <i class="bi <?= $ico ?>" aria-hidden="true"></i><?= $lbl ?>
      <span class="pill-n" id="tk-cnt-<?= $k ?>"><?= $cnt[$k] ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="tk-sep" role="separator" aria-hidden="true"></div>

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
    <div class="dropdown-menu shadow tk-filters-panel" id="tk-filters-panel" role="menu" aria-label="Panel filtrów">

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

  <!-- Powiadomienia + Widok + Dodaj zadanie + Usuń obszar -->
  <div class="ms-auto d-flex gap-2 align-items-center">
    <?php if ($ws_id && $my_role): ?>
    <?php $np_active = $my_notify_prefs['notify_email'] || $my_notify_prefs['notify_sms'] || $my_notify_prefs['notify_push']; ?>
    <button type="button" class="btn btn-outline-secondary btn-sm"
            onclick="openNotifyModal()"
            title="Powiadomienia dla tego obszaru"
            aria-haspopup="dialog">
      <i class="bi bi-bell<?= $np_active ? '-fill text-warning' : '' ?>"></i>
    </button>
    <?php endif; ?>
    <?php
    $kanban_url = '?' . http_build_query(array_merge($_GET, ['ws'=>$ws_id,'view'=>'kanban']));
    $list_url   = '?' . http_build_query(array_merge($_GET, ['ws'=>$ws_id,'view'=>'list']));
    $export_url = APP_URL . '/tasks/export.php?' . http_build_query(array_merge($_GET, ['ws'=>$ws_id]));
    ?>
    <a href="<?= h($export_url) ?>" class="btn btn-outline-secondary btn-sm" title="Eksportuj widoczne zadania do CSV">
      <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Eksportuj
    </a>
    <?php if ($view_mode === 'kanban'): ?>
    <a href="<?= $list_url ?>" class="btn btn-outline-secondary btn-sm" title="Widok listy">
      <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista
    </a>
    <?php else: ?>
    <a href="<?= $kanban_url ?>" class="btn btn-outline-secondary btn-sm" title="Widok Kanban">
      <i class="bi bi-kanban me-1" aria-hidden="true"></i>Kanban
    </a>
    <?php endif; ?>

    <?php if ($can_add): ?>
    <button type="button"
            class="btn btn-outline-danger btn-sm"
            onclick="openDeleteWsModal()"
            aria-haspopup="dialog"
            aria-label="Usuń obszar <?= h($workspace['name']) ?>">
      <i class="bi bi-trash3" aria-hidden="true"></i>
    </button>
    <?php endif; ?>
  </div>

</form>
