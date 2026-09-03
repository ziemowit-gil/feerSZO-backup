<!--
 tasks/includes/detail_fields.php — wydzielone z tasks/detail.php.
 Tagi + Opis. Wymaga: $task, $my_role, $can_edit, $task_tags, $avail_tags.
-->
<!-- ══ TAGI ════════════════════════════════════════════════════════════════ -->
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-tags" aria-hidden="true"></i>Tagi
  </div>
  <div class="td-tags" id="td-tags" role="group" aria-label="Tagi zadania">
    <?php foreach ($avail_tags as $tag):
      $on = in_array($tag['id'], $tag_ids, true);
    ?>
    <button type="button"
            class="td-tag-btn"
            style="background:<?= $on ? h($tag['color']) : '#f1f5f9' ?>;
                   color:<?= $on ? h($tag['text_color']) : '#64748b' ?>;
                   border-color:<?= $on ? h($tag['color']) : '#e2e8f0' ?>"
            data-tag-id="<?= $tag['id'] ?>"
            data-active="<?= $on ? 1 : 0 ?>"
            aria-pressed="<?= $on ? 'true' : 'false' ?>"
            <?= $can_edit ? 'onclick="tdToggleTag(' . (int)$tag['id'] . ', this)"' : 'disabled aria-disabled="true"' ?>>
      <?php if ($on): ?><i class="bi bi-check2" style="font-size:.65rem" aria-hidden="true"></i><?php endif; ?>
      <?= h($tag['name']) ?>
    </button>
    <?php endforeach; ?>
    <?php if (!$avail_tags): ?>
    <p class="text-muted small mb-0">
      Brak tagów<?php if (is_admin()): ?> — <a href="<?= APP_URL ?>/admin/tasks_tags.php" target="_blank">dodaj</a><?php endif; ?>.
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- ══ OPIS ════════════════════════════════════════════════════════════════ -->
<?php if (task_field_visible('description', $my_role)): ?>
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-text-left" aria-hidden="true"></i>Opis
  </div>
  <?php if (task_field_editable('description', $my_role)): ?>
  <label class="visually-hidden" for="td-desc">Opis zadania</label>
  <textarea id="td-desc"
            class="form-control form-control-sm"
            rows="4"
            placeholder="Dodaj opis zadania…"
            onblur="tdPatch({description:this.value})"><?= h($task['description'] ?? '') ?></textarea>
  <?php else: ?>
  <div class="small" style="white-space:pre-wrap;color:#374151;line-height:1.6">
    <?= $task['description'] ? nl2br(h($task['description'])) : '<em class="text-muted">Brak opisu.</em>' ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

