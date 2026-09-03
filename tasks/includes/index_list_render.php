<?php
/**
 * tasks/includes/index_list_render.php
 * Renderowanie regionu listy/kanbana modułu Zadania — wydzielone z index.php.
 * Używane zarówno przy pełnym renderze strony, jak i przez odświeżenie AJAX
 * (?_ajax=1) po zmianie filtrów.
 */

function _tasks_list_html(array $tasks, array $cnt, array $lists_map, int $ws_id, string $view_mode, ?array $workspace, bool $can_add, bool $is_admin, array $all_org_units = []): string
{
    ob_start(); ?>
<?php if ($view_mode === 'kanban'): ?>
<!-- ── Widok Kanban ──────────────────────────────────────────────────────── -->
<div class="tk-kanban" id="tkListRegion">
  <?php foreach ($lists_map as $lid => $list): ?>
  <?php $col_tasks = array_values(array_filter($tasks, fn($t) => (int)$t['list_id'] === (int)$lid)); ?>
  <div class="tk-kanban-col" data-list-id="<?= (int)$lid ?>">
    <div class="tk-kanban-hdr" style="border-top:3px solid <?= h($list['color'] ?: '#94a3b8') ?>">
      <div class="d-flex align-items-center gap-2">
        <?php if ($list['is_done_state']): ?>
        <i class="bi bi-check-circle-fill text-success" style="font-size:.8rem" aria-hidden="true"></i>
        <?php endif; ?>
        <span class="fw-semibold"><?= h($list['name']) ?></span>
        <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.68rem"><?= count($col_tasks) ?></span>
      </div>
      <?php if ($can_add): ?>
      <button class="tk-col-add" title="Dodaj zadanie w tej kolumnie"
              aria-label="Dodaj zadanie w kolumnie <?= h($list['name'] ?? '') ?>"
              onclick="openAddModal(<?= (int)$lid ?>)">
        <i class="bi bi-plus-lg" aria-hidden="true"></i>
      </button>
      <?php endif; ?>
    </div>
    <div class="tk-kanban-body" data-list-id="<?= (int)$lid ?>">
      <?php foreach ($col_tasks as $t): ?>
      <?php
        $k_pri_color = match((int)$t['priority']) {
          4 => '#dc2626', 3 => '#f59e0b', 2 => '#3b82f6', default => '#94a3b8'
        };
        $k_pri_label = ['','Niski','Normalny','Wysoki','Krytyczny'][(int)$t['priority']] ?? '';
        $k_unit_name = $t['unit_short'] ?: ($t['unit_name'] ?? null);
      ?>
      <div class="tk-card <?= $t['_status']==='done'?'tk-card-done':'' ?>"
           data-task-id="<?= (int)$t['id'] ?>"
           role="button" tabindex="0"
           aria-label="Zadanie: <?= h($t['title']) ?><?= $k_unit_name ? ', '.h($k_unit_name) : '' ?>, priorytet <?= h($k_pri_label) ?><?= $t['_overdue'] ? ', po terminie' : '' ?>"
           onclick="openTask(<?= (int)$t['id'] ?>)"
           onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openTask(<?= (int)$t['id'] ?>)}">
        <div class="tk-card-pri-bar" style="background:<?= $k_pri_color ?>" aria-hidden="true"></div>
        <div class="tk-card-inner">
          <div class="tk-card-title"><?= h($t['title']) ?></div>
          <?php if ($k_unit_name): ?>
          <div class="tk-card-unit">
            <i class="bi bi-diagram-3" aria-hidden="true"></i> <?= h($k_unit_name) ?>
          </div>
          <?php endif; ?>
          <div class="tk-card-footer">
            <div class="d-flex align-items-center gap-1">
              <?php if ($t['due_date']): ?>
              <span class="tk-card-due <?= $t['_overdue'] ? 'overdue' : '' ?>">
                <i class="bi bi-calendar3" aria-hidden="true"></i> <?= h(date('d.m', strtotime($t['due_date']))) ?>
              </span>
              <?php endif; ?>
              <?php if ($t['st_total'] > 0): ?>
              <span class="tk-card-st" title="Podzadania">
                <i class="bi bi-check2-square" aria-hidden="true"></i> <?= (int)$t['st_done'] ?>/<?= (int)$t['st_total'] ?>
              </span>
              <?php endif; ?>
            </div>
            <div class="tk-card-avstack">
              <?php foreach (array_slice($t['assignees'], 0, 3) as $a): ?>
              <span class="tk-card-asgn" title="<?= h($a['name']) ?>"><?= h(mb_substr($a['name'],0,1)) ?></span>
              <?php endforeach; ?>
              <?php if (count($t['assignees']) > 3): ?>
              <span class="tk-card-asgn" style="background:#94a3b8">+<?= count($t['assignees'])-3 ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($col_tasks)): ?>
      <div class="tk-card-drop-hint">Przeciągnij tu lub kliknij +</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<!-- ── Tabela zadań ──────────────────────────────────────────────────────── -->
<div class="tk-wrap" id="tkListRegion">

  <!-- Pasek zbiorczych akcji -->
  <div class="tk-bulk-bar" id="tk-bulk-bar" role="toolbar" aria-label="Zbiorcze akcje">
    <span class="tk-bulk-count" id="tk-bulk-count" aria-live="polite">0 zaznaczonych</span>
    <div class="tk-bulk-sep"></div>

    <button class="tk-bulk-btn" onclick="bulkAction('assign_me')"
            aria-label="Przypisz zaznaczone zadania do siebie">
      <i class="bi bi-person-check" aria-hidden="true"></i>Przypisz do mnie
    </button>

    <button class="tk-bulk-btn" onclick="bulkAction('complete')"
            aria-label="Oznacz zaznaczone jako ukończone">
      <i class="bi bi-check2-circle" aria-hidden="true"></i>Zakończ
    </button>

    <label class="visually-hidden" for="tk-bulk-pri">Zmień priorytet</label>
    <select id="tk-bulk-pri" class="tk-bulk-pri-sel"
            onchange="if(this.value){bulkAction('priority',{priority:parseInt(this.value)});this.value=''}"
            aria-label="Zmień priorytet zaznaczonych zadań">
      <option value="">⚑ Priorytet…</option>
      <option value="4">🔴 Krytyczny</option>
      <option value="3">🟡 Wysoki</option>
      <option value="2">🔵 Normalny</option>
      <option value="1">⚪ Niski</option>
    </select>

    <?php if (!empty($lists_map)): ?>
    <label class="visually-hidden" for="tk-bulk-list">Przenieś do listy</label>
    <select id="tk-bulk-list" class="tk-bulk-list-sel"
            onchange="if(this.value){bulkAction('move',{list_id:parseInt(this.value)});this.value=''}"
            aria-label="Przenieś zaznaczone zadania do wybranej kolumny">
      <option value="">↦ Przenieś do…</option>
      <?php foreach ($lists_map as $l): ?>
      <option value="<?= $l['id'] ?>"><?= h($l['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <?php if ($can_add): ?>
    <div class="tk-bulk-sep"></div>
    <button class="tk-bulk-btn danger" onclick="bulkAction('delete')"
            aria-label="Usuń zaznaczone zadania">
      <i class="bi bi-trash3" aria-hidden="true"></i>Usuń
    </button>
    <?php endif; ?>

    <button class="tk-bulk-close" onclick="bulkClear()"
            aria-label="Anuluj zaznaczenie">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>

  <div style="overflow-x:auto">
    <table class="tk-table"
           id="task-table"
           role="grid"
           aria-label="Zadania obszaru <?= $workspace ? h($workspace['name']) : '' ?>"
           aria-rowcount="<?= count($tasks) ?>">
      <thead>
        <tr>
          <th scope="col" class="th-check">
            <input type="checkbox" class="tk-row-check" id="tk-check-all"
                   onchange="bulkToggleAll(this)"
                   aria-label="Zaznacz wszystkie zadania">
          </th>
          <th scope="col" style="width:3%" aria-label="Priorytet i tytuł">Zadanie</th>
          <th scope="col" style="width:10%" class="th-center">Status</th>
          <th scope="col" style="width:6%"  class="th-center" aria-label="Weryfikacja wykonania">Weryf.</th>
          <th scope="col" style="width:8%"  class="th-center">Priorytet</th>
          <th scope="col" style="width:8%">Termin</th>
          <th scope="col" style="width:10%">Postęp</th>
          <th scope="col" style="width:12%">Tagi</th>
          <th scope="col" style="width:10%">Przypisani</th>
          <th scope="col" style="width:9%"  class="th-center">Akcja</th>
        </tr>
      </thead>
      <tbody id="tk-tbody">

      <?php if (!$tasks): ?>
      <tr>
        <td colspan="10">
          <?php $is_brand_new = ($workspace && empty($lists_map)); ?>
          <?php if ($is_brand_new && $can_add): ?>
          <div style="padding:2rem 1.5rem;text-align:center;max-width:480px;margin:0 auto">
            <div style="font-size:2.5rem;margin-bottom:.75rem">🎉</div>
            <div style="font-weight:700;font-size:1rem;color:#0f172a;margin-bottom:.35rem">Obszar „<?= $workspace ? h($workspace['name']) : '' ?>" jest gotowy!</div>
            <p style="font-size:.83rem;color:#64748b;line-height:1.6;margin-bottom:1.25rem">
              Teraz dodaj pierwsze zadania. Możesz też najpierw stworzyć listy
              (np. „Do zrobienia" / „W trakcie" / „Gotowe") żeby lepiej porządkować pracę.
            </p>
            <div style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap">
              <button type="button" onclick="openAddModal()"
                      style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:.55rem 1.1rem;font-size:.83rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem">
                <i class="bi bi-plus-lg"></i>Dodaj pierwsze zadanie
              </button>
              <?php if ($is_admin): ?>
              <a href="<?= APP_URL ?>/admin/tasks_workspaces.php"
                 style="background:#f8fafc;color:#374151;border:1.5px solid #e2e8f0;border-radius:8px;padding:.5rem 1rem;font-size:.83rem;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem">
                <i class="bi bi-list-ul"></i>Zarządzaj listami
              </a>
              <?php endif; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="tk-empty">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <p class="fw-semibold mb-1">Brak zadań spełniających kryteria</p>
            <p class="small mb-0">Zmień filtry lub <button type="button" class="btn btn-link btn-sm p-0" onclick="openAddModal()">dodaj nowe zadanie</button>.</p>
          </div>
          <?php endif; ?>
        </td>
      </tr>

      <?php else: foreach ($tasks as $task):
        $pri_meta = [
          4 => ['🔴','Krytyczny','#dc2626'],
          3 => ['🟡','Wysoki',   '#f59e0b'],
          2 => ['🔵','Normalny', '#3b82f6'],
          1 => ['⚪','Niski',    '#94a3b8'],
        ][(int)$task['priority']] ?? ['⚪','Normalny','#94a3b8'];

        $st_total = (int)$task['st_total'];
        $st_done  = (int)$task['st_done'];
        $st_pct   = $st_total ? round($st_done/$st_total*100) : 0;

        $status_info = match($task['_status']) {
          'open'  => ['s-open',  'Do zrobienia', 'bi-circle'],
          'taken' => ['s-taken', 'Przydzielone', 'bi-person-fill'],
          'done'  => ['s-done',  'Ukończone',    'bi-check-circle-fill'],
          default => ['s-open',  'Do zrobienia', 'bi-circle'],
        };

        $row_label = h($task['title'])
          . ', status: ' . $status_info[1]
          . ', priorytet: ' . $pri_meta[1]
          . ($task['_overdue'] ? ', po terminie' : '');
      ?>
      <tr class="<?= $task['_status']==='done'?'row-done':'' ?>"
          data-pri="<?= $task['priority'] ?>"
          data-task-id="<?= $task['id'] ?>"
          tabindex="0"
          role="row"
          aria-label="<?= $row_label ?>"
          onclick="if(!event.target.closest('td.td-check')&&!event.target.closest('td:last-child')&&!event.target.closest('.tk-status-btn')&&!event.target.closest('.dropdown'))openTask(<?= $task['id'] ?>)"
          onkeydown="if((event.key==='Enter'||event.key===' ')&&!event.target.closest('input'))openTask(<?= $task['id'] ?>)">

        <!-- Checkbox -->
        <td class="td-check" onclick="event.stopPropagation()">
          <input type="checkbox"
                 class="tk-row-check tk-row-select"
                 data-id="<?= $task['id'] ?>"
                 onchange="bulkOnCheck(this)"
                 aria-label="Zaznacz zadanie: <?= h($task['title']) ?>">
        </td>

        <!-- Zadanie: tytuł + podtytuł -->
        <td>
          <div class="tk-title"><?= h($task['title']) ?></div>
          <?php if ($task['description']): ?>
          <div class="tk-subtitle"><?= h(mb_substr(strip_tags($task['description']),0,80)) ?></div>
          <?php endif; ?>
        </td>

        <!-- Status — klikalny przycisk inline -->
        <td class="th-center" style="text-align:center" onclick="event.stopPropagation()">
          <?php if ($task['_status'] === 'done'): ?>
          <button type="button" class="tk-status s-done tk-status-btn"
                  data-task-id="<?= $task['id'] ?>"
                  onclick="tkToggleDone(this, <?= $task['id'] ?>)"
                  title="Kliknij aby cofnąć ukończenie">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Ukończone
          </button>
          <?php else: ?>
          <button type="button" class="tk-status <?= $status_info[0] ?> tk-status-btn"
                  data-task-id="<?= $task['id'] ?>"
                  onclick="tkToggleDone(this, <?= $task['id'] ?>)"
                  title="Kliknij aby oznaczyć jako ukończone">
            <i class="bi <?= $status_info[2] ?>" aria-hidden="true"></i> <?= $status_info[1] ?>
          </button>
          <?php endif; ?>
        </td>

        <!-- Weryfikacja wykonania: Z / ZP / O -->
        <td class="th-center" style="text-align:center">
          <?php if ($task['_status'] === 'done'): ?>
            <?php if (!empty($task['confirmed_at'])): ?>
            <span class="tk-confirm-badge zp"
                  title="Potwierdzone przez lidera <?= h(substr($task['confirmed_at'],0,10)) ?>"
                  aria-label="Zakończone i potwierdzone przez lidera">ZP</span>
            <?php elseif (!empty($task['rejected_at'])): ?>
            <span class="tk-confirm-badge o"
                  title="Odrzucone przez lidera <?= h(substr($task['rejected_at'],0,10)) ?>. Powód: <?= h($task['rejection_reason'] ?? '') ?>"
                  aria-label="Zakończone, ale odrzucone przez lidera. Powód: <?= h($task['rejection_reason'] ?? '') ?>">O</span>
            <?php else: ?>
            <span class="tk-confirm-badge z"
                  title="Zakończone, ale niepotwierdzone przez lidera"
                  aria-label="Zakończone, ale niepotwierdzone przez lidera">Z</span>
            <?php endif; ?>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Priorytet — dropdown inline -->
        <td style="text-align:center" onclick="event.stopPropagation()">
          <div class="dropdown d-inline-block">
            <button class="pri-btn btn btn-link btn-sm p-0" data-bs-toggle="dropdown"
                    style="text-decoration:none"
                    title="Priorytet: <?= h($pri_meta[1]) ?>"
                    aria-label="Priorytet: <?= h($pri_meta[1]) ?>. Kliknij, aby zmienić">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $pri_meta[2] ?>;vertical-align:middle" aria-hidden="true"></span>
            </button>
            <ul class="dropdown-menu shadow-sm py-1">
              <?php foreach ([4=>'🔴 Krytyczny',3=>'🟡 Wysoki',2=>'🔵 Normalny',1=>'⚪ Niski'] as $p=>$pl): ?>
              <li><button class="dropdown-item" onclick="tkSetPriority(<?= $task['id'] ?>, <?= $p ?>)"><?= $pl ?></button></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </td>

        <!-- Termin -->
        <td>
          <?php if ($task['due_date']): ?>
          <span class="td-due <?= $task['_overdue']?'overdue':'' ?>"
                aria-label="Termin: <?= h($task['due_date']) ?><?= $task['_overdue']?' (po terminie)':'' ?>">
            <?php if ($task['_overdue']): ?>
            <i class="bi bi-alarm me-1" aria-hidden="true"></i>
            <?php else: ?>
            <i class="bi bi-calendar3 me-1 text-muted" aria-hidden="true"></i>
            <?php endif; ?>
            <?= h(date('d.m.Y', strtotime($task['due_date']))) ?>
          </span>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Postęp podzadań -->
        <td>
          <?php if ($st_total > 0): ?>
          <div class="tk-prog-wrap"
               aria-label="Podzadania: <?= $st_done ?>/<?= $st_total ?>">
            <div class="tk-prog-track"
                 role="progressbar"
                 aria-valuenow="<?= $st_pct ?>"
                 aria-valuemin="0" aria-valuemax="100">
              <div class="tk-prog-fill <?= $st_done===$st_total?'bg-success':'bg-primary' ?>"
                   style="width:<?= $st_pct ?>%"></div>
            </div>
            <span class="tk-prog-label"><?= $st_done ?>/<?= $st_total ?></span>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Tagi -->
        <td>
          <?php if ($task['tags']): ?>
          <div class="tk-tags">
            <?php foreach (array_slice($task['tags'],0,3) as $tag): ?>
            <span class="tk-tag"
                  style="background:<?= h($tag['color']) ?>;color:<?= h($tag['text_color']) ?>">
              <?= h($tag['name']) ?>
            </span>
            <?php endforeach; ?>
            <?php if (count($task['tags'])>3): ?>
            <span class="tk-tag" style="background:#f1f5f9;color:#64748b">
              +<?= count($task['tags'])-3 ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">—</span>
          <?php endif; ?>
        </td>

        <!-- Przypisani + jednostka -->
        <td>
          <?php if ($task['assignees']): ?>
          <div class="tk-av-stack" aria-label="Przypisani: <?= h(implode(', ', array_column($task['assignees'],'name'))) ?>">
            <?php foreach (array_slice($task['assignees'],0,4) as $a): ?>
            <?= task_avatar_initials($a['name'], '#2563eb', '#fff') ?>
            <?php endforeach; ?>
            <?php if (count($task['assignees'])>4): ?>
            <span class="tk-av" style="background:#64748b"
                  aria-label="+<?= count($task['assignees'])-4 ?> więcej">
              +<?= count($task['assignees'])-4 ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="text-muted" style="font-size:.75rem">Brak</span>
          <?php endif; ?>
          <?php if (!empty($task['unit_name'])): ?>
          <div class="mt-1"><?= task_unit_badge((int)$task['unit_id']) ?></div>
          <?php endif; ?>
        </td>

        <!-- Akcja (klik zatrzymuje propagację) -->
        <td style="text-align:center" onclick="event.stopPropagation()">
          <div class="tk-actions justify-content-center">
            <button type="button"
                    class="btn-open"
                    onclick="openTask(<?= $task['id'] ?>)"
                    aria-label="Otwórz zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz
            </button>
            <?php if ($task['_status'] !== 'done'): ?>
            <?php if ($task['_mine']): ?>
            <button type="button"
                    class="btn-unclaim"
                    onclick="claimTask(<?= $task['id'] ?>,'remove',this)"
                    aria-label="Oddaj zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-person-dash me-1" aria-hidden="true"></i>Oddaj
            </button>
            <?php elseif ($task['_status'] === 'open'): ?>
            <button type="button"
                    class="btn-claim"
                    onclick="claimTask(<?= $task['id'] ?>,'add',this)"
                    aria-label="Weź zadanie: <?= h($task['title']) ?>">
              <i class="bi bi-hand-index me-1" aria-hidden="true"></i>Weź
            </button>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </td>

      </tr>
      <?php endforeach; endif; ?>

      </tbody>
    </table>
  </div>

  <!-- Stopka z licznikiem -->
  <?php if ($tasks): ?>
  <div class="px-3 py-2 border-top" style="background:var(--tk-bg-soft);font-size:.75rem;color:var(--tk-muted)">
    Pokazano <strong><?= count($tasks) ?></strong> zadań
    <?php if ($cnt['all'] !== count($tasks)): ?>
    z <strong><?= $cnt['all'] ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Legenda kolumny "Weryf." -->
  <div class="tk-legend" aria-label="Legenda kolumny weryfikacji wykonania">
    <span><span class="tk-confirm-badge z" aria-hidden="true">Z</span> Zakończone, ale niepotwierdzone</span>
    <span><span class="tk-confirm-badge zp" aria-hidden="true">ZP</span> Zakończone i potwierdzone przez lidera</span>
    <span><span class="tk-confirm-badge o" aria-hidden="true">O</span> Odrzucone przez lidera (z podanym powodem)</span>
  </div>

</div>
<?php endif; ?>
<?php
    return ob_get_clean();
}
