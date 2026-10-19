<!--
 tasks/includes/detail_mail_source.php — źródło zadania: wiadomość e-mail (z Skrzynki CRM, Poczty EZD albo skrzynki obszaru).
 Wymaga: $id. Dane: task_mail_sources (tasks/lib/tasksMail.php).
-->
<?php
require_once dirname(__DIR__) . '/lib/tasksMail.php';
$td_mail = tasksMailSourceOf((int)$id);
if ($td_mail):
  $td_mail_url = $td_mail['comm_id'] ? tasksMailBackUrl((int)$td_mail['comm_id'], $td_mail['source'] === 'ezd' ? 'ezd' : 'crm') : '';
?>
<div class="td-section">
  <div class="td-label"><i class="bi bi-envelope-paper" aria-hidden="true"></i>Z wiadomości e-mail</div>
  <div class="small">
    <strong><?= h($td_mail['subject'] ?: '(bez tematu)') ?></strong><br>
    <span class="text-muted">od <?= h(trim($td_mail['from_name'] . ' <' . $td_mail['from_email'] . '>')) ?>
      <?= $td_mail['received_at'] ? ' · ' . h(date('d.m.Y H:i', strtotime($td_mail['received_at']))) : '' ?>
      · <?= h(['manual' => 'utworzone ręcznie', 'crm' => 'ze Skrzynki CRM', 'ezd' => 'z Poczty EZD', 'mailbox' => 'automatycznie ze skrzynki obszaru'][$td_mail['source']] ?? $td_mail['source']) ?>
      <?= $td_mail['ack_sent_at'] ? ' · nadawca dostał potwierdzenie ZAD-' . (int)$id : '' ?></span>
    <?php if ($td_mail_url): ?><br><a href="<?= h($td_mail_url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Otwórz wiadomość</a><?php endif; ?>
  </div>
</div>
<?php endif; unset($td_mail, $td_mail_url); ?>
