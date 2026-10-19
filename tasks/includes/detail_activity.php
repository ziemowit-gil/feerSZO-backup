<!--
 tasks/includes/detail_activity.php — wydzielone z tasks/detail.php.
 Historia zdarzeń (komentarze: detail_comments.php). Wymaga: $task, $my_role, $history, $all_users, $cur_user.
-->
<!-- ══ HISTORIA ════════════════════════════════════════════════════════════ -->
<?php if ($history):

/**
 * Definicje typów zdarzeń:
 *   icon   — Bootstrap Icons klasa
 *   bg     — tło ikony
 *   color  — kolor ikony
 *   badge_bg / badge_color — kolorystyka etykiety badge
 *   label  — tekst etykiety
 *   desc   — funkcja generująca opis (opcjonalna)
 */
$ev_defs = [
  'created' => [
    'icon'        => 'bi-plus-lg',
    'bg'          => '#dcfce7', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Utworzono',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'moved' => [
    'icon'        => 'bi-arrow-right',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Przeniesiono',
    'desc'        => fn($e) => $e['from_value'] && $e['to_value']
      ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>'
        . ' <i class="bi bi-arrow-right" aria-hidden="true"></i> '
        . '<span class="td-hi-to">' . h($e['to_value']) . '</span>'
      : '',
  ],
  'assigned' => [
    'icon'        => 'bi-person-plus',
    'bg'          => '#ede9fe', 'color' => '#7c3aed',
    'badge_bg'    => '#f5f3ff', 'badge_color' => '#7c3aed',
    'label'       => 'Przypisano',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">👤 ' . h($e['to_value']) . '</span>' : '',
  ],
  'unassigned' => [
    'icon'        => 'bi-person-dash',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Odpięto',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">👤 ' . h($e['from_value']) . '</span>' : '',
  ],
  'completed' => [
    'icon'        => 'bi-check-circle-fill',
    'bg'          => '#dcfce7', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Ukończono',
    'desc'        => fn($e) => '',
  ],
  'reopened' => [
    'icon'        => 'bi-arrow-counterclockwise',
    'bg'          => '#fef9c3', 'color' => '#d97706',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#d97706',
    'label'       => 'Wznowiono',
    'desc'        => fn($e) => '',
  ],
  'confirmed' => [
    'icon'        => 'bi-patch-check-fill',
    'bg'          => '#ede9fe', 'color' => '#7c3aed',
    'badge_bg'    => '#f5f3ff', 'badge_color' => '#7c3aed',
    'label'       => 'Potwierdzono wykonanie',
    'desc'        => fn($e) => '',
  ],
  'rejected' => [
    'icon'        => 'bi-x-octagon-fill',
    'bg'          => '#fee2e2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Odrzucono wykonanie',
    'desc'        => fn($e) => $e['to_value']
      ? '<span class="td-hi-val" title="' . h($e['to_value']) . '">' . h(mb_substr($e['to_value'],0,50)) . (mb_strlen($e['to_value'])>50?'…':'') . '</span>'
      : '',
  ],
  'archived' => [
    'icon'        => 'bi-archive-fill',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Zarchiwizowano',
    'desc'        => fn($e) => '',
  ],
  'unarchived' => [
    'icon'        => 'bi-arrow-counterclockwise',
    'bg'          => '#f0fdf4', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Przywrócono z archiwum',
    'desc'        => fn($e) => '',
  ],
  'tag_added' => [
    'icon'        => 'bi-tag-fill',
    'bg'          => '#f0fdf4', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Tag dodany',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-to">#' . h($e['to_value']) . '</span>' : '',
  ],
  'tag_removed' => [
    'icon'        => 'bi-tag',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Tag usunięty',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">#' . h($e['from_value']) . '</span>' : '',
  ],
  'dependency_added' => [
    'icon'        => 'bi-diagram-2',
    'bg'          => '#fef3c7', 'color' => '#b45309',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#b45309',
    'label'       => 'Czeka na',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-to">' . h($e['to_value']) . '</span>' : '',
  ],
  'dependency_removed' => [
    'icon'        => 'bi-diagram-2',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Usunięto zależność',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>' : '',
  ],
  'comment_edited' => [
    'icon'        => 'bi-pencil',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Komentarz edytowany',
    'desc'        => fn($e) => '',
  ],
  'comment_added' => [
    'icon'        => 'bi-chat-fill',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Komentarz',
    'desc'        => fn($e) => '',
  ],
  'priority_changed' => [
    'icon'        => 'bi-flag-fill',
    'bg'          => '#fef9c3', 'color' => '#d97706',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#d97706',
    'label'       => 'Priorytet',
    'desc'        => fn($e) => $e['from_value'] && $e['to_value']
      ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>'
        . ' → <span class="td-hi-to">' . h($e['to_value']) . '</span>'
      : ($e['to_value'] ? '<span class="td-hi-to">' . h($e['to_value']) . '</span>' : ''),
  ],
  'due_changed' => [
    'icon'        => 'bi-calendar3',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Termin',
    'desc'        => fn($e) => $e['from_value'] && $e['to_value']
      ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>'
        . ' → <span class="td-hi-to">' . h($e['to_value']) . '</span>'
      : ($e['to_value'] ? '<span class="td-hi-to">' . h($e['to_value']) . '</span>' : ''),
  ],
  'title_changed' => [
    'icon'        => 'bi-pencil',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Tytuł zmieniony',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'description_changed' => [
    'icon'        => 'bi-text-left',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Opis zmieniony',
    'desc'        => fn($e) => '',
  ],
  'uploaded_file' => [
    'icon'        => 'bi-paperclip',
    'bg'          => '#ede9fe', 'color' => '#7c3aed',
    'badge_bg'    => '#f5f3ff', 'badge_color' => '#7c3aed',
    'label'       => 'Plik dodany',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">📎 ' . h($e['to_value']) . '</span>' : '',
  ],
  'deleted_file' => [
    'icon'        => 'bi-paperclip',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Plik usunięty',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">📎 ' . h($e['from_value']) . '</span>' : '',
  ],
  'leader_notified' => [
    'icon'        => 'bi-megaphone-fill',
    'bg'          => '#fef9c3', 'color' => '#d97706',
    'badge_bg'    => '#fffbeb', 'badge_color' => '#d97706',
    'label'       => 'Zgłoszono problem',
    'desc'        => fn($e) => $e['to_value']
      ? '<span class="td-hi-val" title="' . h($e['to_value']) . '">💬 ' . h(mb_substr($e['to_value'],0,50)) . (mb_strlen($e['to_value'])>50?'…':'') . '</span>'
      : '',
  ],
  'linked' => [
    'icon'        => 'bi-link-45deg',
    'bg'          => '#e0f2fe', 'color' => '#0f6fa8',
    'badge_bg'    => '#f0f9ff', 'badge_color' => '#0f6fa8',
    'label'       => 'Powiązano',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'unlinked' => [
    'icon'        => 'bi-link-45deg',
    'bg'          => '#f1f5f9', 'color' => '#64748b',
    'badge_bg'    => '#f8fafc', 'badge_color' => '#64748b',
    'label'       => 'Usunięto powiązanie',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>' : '',
  ],
  'mail_source' => [
    'icon'        => 'bi-envelope-paper',
    'bg'          => '#e0f2fe', 'color' => '#0f6fa8',
    'badge_bg'    => '#f0f9ff', 'badge_color' => '#0f6fa8',
    'label'       => 'Z wiadomości e-mail',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">✉ ' . h(mb_strimwidth($e['to_value'], 0, 80, '…')) . '</span>' : '',
  ],
  'sla_escalated' => [
    'icon'        => 'bi-alarm-fill',
    'bg'          => '#fee2e2', 'color' => '#a20025',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#a20025',
    'label'       => 'Eskalacja SLA',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'rule_applied' => [
    'icon'        => 'bi-magic',
    'bg'          => '#e0f2fe', 'color' => '#0f6fa8',
    'badge_bg'    => '#f0f9ff', 'badge_color' => '#0f6fa8',
    'label'       => 'Reguła automatyzacji',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'problem_resolved' => [
    'icon'        => 'bi-shield-check',
    'bg'          => '#dcfce7', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Problem rozwiązany',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">✓ ' . h($e['to_value']) . '</span>' : '',
  ],
  'takeover_requested' => [
    'icon'        => 'bi-person-up',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Prośba o przekazanie',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">→ 👤 ' . h($e['to_value']) . '</span>' : '',
  ],
  'transfer_rejected' => [
    'icon'        => 'bi-person-x',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Odrzucono przekazanie',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">👤 ' . h($e['from_value']) . '</span>' : '',
  ],
  'deleted' => [
    'icon'        => 'bi-trash3-fill',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Usunięto',
    'desc'        => fn($e) => '',
  ],
  'subtask_added' => [
    'icon'        => 'bi-plus-square',
    'bg'          => '#f0fdf4', 'color' => '#16a34a',
    'badge_bg'    => '#f0fdf4', 'badge_color' => '#16a34a',
    'label'       => 'Podzadanie dodane',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
  'subtask_deleted' => [
    'icon'        => 'bi-dash-square',
    'bg'          => '#fef2f2', 'color' => '#dc2626',
    'badge_bg'    => '#fef2f2', 'badge_color' => '#dc2626',
    'label'       => 'Podzadanie usunięte',
    'desc'        => fn($e) => $e['from_value'] ? '<span class="td-hi-from">' . h($e['from_value']) . '</span>' : '',
  ],
  'time_started' => [
    'icon'        => 'bi-stopwatch',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Rozpoczęto śledzenie czasu',
    'desc'        => fn($e) => '',
  ],
  'time_logged' => [
    'icon'        => 'bi-clock-fill',
    'bg'          => '#dbeafe', 'color' => '#2563eb',
    'badge_bg'    => '#eff6ff', 'badge_color' => '#2563eb',
    'label'       => 'Zalogowano czas',
    'desc'        => fn($e) => $e['to_value'] ? '<span class="td-hi-val">' . h($e['to_value']) . '</span>' : '',
  ],
];
?>
<div class="td-section">
  <details>
    <summary class="td-label" style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:.35rem">
      <i class="bi bi-clock-history" aria-hidden="true"></i>
      Historia
      <span class="td-hi-badge ms-1"
            style="background:#f1f5f9;color:#64748b">
        <?= count($history) ?>
      </span>
    </summary>

    <div class="mt-2" role="list" aria-label="Historia zmian zadania">
      <?php foreach ($history as $he):
        $def   = $ev_defs[$he['event_type']] ?? null;
        $icon  = $def['icon']        ?? 'bi-circle';
        $bg    = $def['bg']          ?? '#f1f5f9';
        $color = $def['color']       ?? '#64748b';
        $bb    = $def['badge_bg']    ?? '#f1f5f9';
        $bc    = $def['badge_color'] ?? '#64748b';
        $label = $def['label']       ?? str_replace('_',' ', $he['event_type']);
        $desc  = $def ? ($def['desc'])($he) : '';
        $time  = substr($he['occurred_at'] ?? '', 0, 16);
        $actor = h($he['actor_name'] ?? '');
      ?>
      <div class="td-history-item" role="listitem">

        <!-- Ikona -->
        <span class="td-hi-icon" style="background:<?= $bg ?>;color:<?= $color ?>" aria-hidden="true">
          <i class="bi <?= $icon ?>"></i>
        </span>

        <!-- Treść -->
        <div class="flex-grow-1 min-width-0">
          <div class="d-flex align-items-center flex-wrap gap-1">
            <!-- Badge etykieta -->
            <span class="td-hi-badge" style="background:<?= $bb ?>;color:<?= $bc ?>">
              <?= h($label) ?>
            </span>
            <!-- Opis / wartości -->
            <?php if ($desc): ?>
            <span><?= $desc ?></span>
            <?php endif; ?>
          </div>
          <!-- Meta: czas · autor -->
          <div class="td-hi-meta">
            <time datetime="<?= h($he['occurred_at'] ?? '') ?>">
              <?= h($time) ?>
            </time>
            <?php if ($actor): ?>
            · <span><?= $actor ?></span>
            <?php endif; ?>
          </div>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
  </details>
</div>
<?php endif; ?>

