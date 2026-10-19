<!--
 tasks/includes/detail_comments.php — Komentarze zadania (prawa kolumna okna zadania, obok Plików).
 Wydzielone z detail_activity.php. Wymaga: $my_role, $comments, $all_users, $uid, $can_edit, td_render_mentions().
 Identyfikatory (#td-comments, #td-new-cmt, #td-mention-dd) bez zmian — używa ich detail_js_core.php.
-->
<!-- ══ KOMENTARZE ══════════════════════════════════════════════════════════ -->
<?php if (task_field_visible('comments', $my_role)): ?>
<div class="td-section">
  <div class="td-label">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>Komentarze
    <?php if ($comments): ?>
    <span class="badge bg-secondary ms-1" style="font-size:.6rem"><?= count($comments) ?></span>
    <?php endif; ?>
  </div>

  <div id="td-comments" aria-live="polite" aria-label="Lista komentarzy">
    <?php foreach ($comments as $c): ?>
    <div class="td-comment" id="cmt-<?= $c['id'] ?>">
      <div class="td-comment-meta">
        <div>
          <span class="td-comment-author"><?= h($c['author_name']) ?></span>
          <span class="td-comment-date ms-2"><?= h(substr($c['created_at'],0,16)) ?></span>
          <?php if (!empty($c['is_edited'])): ?>
          <span class="td-comment-date" title="Edytowano <?= h(substr($c['updated_at'] ?? '', 0, 16)) ?>">(edytowany)</span>
          <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-1">
        <?php if ((int)$c['author_id'] === $uid && task_field_editable('comments', $my_role)): ?>
        <button type="button" class="td-comment-edit"
                onclick="tdEditComment(<?= (int)$c['id'] ?>)"
                aria-label="Edytuj swój komentarz" title="Edytuj">
          <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
        <?php if ($can_edit || (int)$c['author_id'] === $uid): ?>
        <button type="button"
                class="btn-close"
                onclick="tdDeleteComment(<?= $c['id'] ?>)"
                aria-label="Usuń komentarz od <?= h($c['author_name']) ?>"
                style="font-size:.55rem"></button>
        <?php endif; ?>
        </div>
      </div>
      <div class="td-comment-body" data-raw="<?= h($c['body']) ?>"><?= td_render_mentions($c['body'], $all_users) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!$comments): ?>
    <p class="text-muted small mb-2">Brak komentarzy.</p>
    <?php endif; ?>
  </div>

  <?php if (task_field_editable('comments', $my_role)): ?>
  <label class="visually-hidden" for="td-new-cmt">Nowy komentarz</label>
  <textarea id="td-new-cmt"
            class="form-control form-control-sm mt-1"
            rows="2"
            placeholder="Napisz komentarz… (Ctrl+Enter wysyła, @ dodaje wzmiankę)"
            onkeydown="tdCmtKeydown(event)"
            aria-describedby="td-cmt-hint"></textarea>
  <div id="td-mention-dd" class="td-mention-dd" role="listbox" aria-label="Sugestie wzmianek"></div>
  <p id="td-cmt-hint" class="visually-hidden">Użyj @ aby wspomnieć użytkownika. Ctrl+Enter aby wysłać.</p>
  <button type="button"
          class="btn btn-sm btn-primary mt-2"
          onclick="tdAddComment()">
    <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij komentarz
  </button>
  <?php endif; ?>
</div>
<?php endif; ?>

