<?php
/**
 * modules/ti/view/nabor/public/konto.php — konto kandydata: lista zgłoszeń (kilkoro dzieci / kilka grup).
 * Zmienne: $rows, $org, $acc, $form, ($notFound). Kontroler: NaborCandidateController::dashboard.
 */
$title = 'Moje zgłoszenia';
include __DIR__ . '/_top.php';
$badge = ['new' => 'tw-bg-blue-100 tw-text-blue-900', 'pending_review' => 'tw-bg-sky-100 tw-text-sky-900', 'custom_schedule' => 'tw-bg-amber-100 tw-text-amber-900',
          'accepted' => 'tw-bg-green-100 tw-text-green-900', 'waitlist' => 'tw-bg-amber-100 tw-text-amber-900', 'rejected' => 'tw-bg-red-100 tw-text-red-900',
          'cancelled' => 'tw-bg-gray-200 tw-text-gray-800', 'archived' => 'tw-bg-gray-100 tw-text-gray-700'];
?>
<div class="tw-flex tw-items-center tw-gap-3 tw-mb-4">
  <h1 class="tw-text-2xl tw-font-extrabold tw-mr-auto">Moje zgłoszenia</h1>
  <a class="k-btn" href="<?= h(naborUrl('/zapisy')) ?>">+ Nowe zgłoszenie</a>
</div>
<?php if (!empty($notFound)): ?><div class="tw-bg-red-50 tw-border tw-border-red-300 tw-rounded-xl tw-p-3 tw-mb-4" role="alert">Nie znaleziono zgłoszenia.</div><?php endif; ?>
<?php if (!$rows): ?>
  <div class="k-card tw-text-center tw-text-gray-600">Nie masz jeszcze zgłoszeń w obiegu. Zgłoszenie pojawi się tu po potwierdzeniu adresu e-mail.</div>
<?php endif; ?>
<ul class="tw-grid tw-gap-3 tw-list-none tw-p-0">
<?php foreach ($rows as $r): ?>
  <li class="k-card tw-mb-0">
    <a href="<?= h(naborUrl('/konto/zgloszenia/' . (int)$r['id'])) ?>" class="tw-no-underline tw-text-gray-900 tw-block">
      <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
        <span class="tw-font-bold tw-text-lg tw-mr-auto"><?= h($r['participant_name']) ?></span>
        <span class="k-badge <?= $badge[$r['status']] ?? 'tw-bg-gray-100' ?>"><?= h(naborStatusLabel($r['status'])) ?></span>
      </div>
      <div class="tw-text-sm tw-text-gray-600"><?= h($r['registration_number']) ?> · <?= h($r['is_custom_schedule'] && !$r['course_id'] ? 'termin do ustalenia' : (string)$r['course_name']) ?> · złożono <?= h(naborTime($r['created_at'])) ?></div>
      <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-2 tw-text-sm">
        <?php if ((int)$r['unread']): ?><span class="k-badge tw-bg-feer tw-text-white">Nowe wiadomości: <?= (int)$r['unread'] ?></span><?php endif; ?>
        <?php if ((int)$r['docs_todo']): ?><span class="k-badge tw-bg-amber-200 tw-text-amber-900">Dokumenty do wgrania: <?= (int)$r['docs_todo'] ?></span><?php endif; ?>
        <?php if ($r['status'] === 'accepted' && !$r['place_confirmed_at']): ?><span class="k-badge tw-bg-green-600 tw-text-white">Potwierdź miejsce</span><?php endif; ?>
      </div>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php include __DIR__ . '/_bottom.php'; ?>
