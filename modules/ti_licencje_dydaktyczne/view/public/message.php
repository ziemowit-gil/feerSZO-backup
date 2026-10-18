<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/message.php — komunikat portalu AM5 (link nieaktualny, potwierdzenie, błąd).
 * Zmienne: $title, $text, $tone (success|info|danger), opcjonalnie $numbers (numery dokumentów).
 */
$bg = ['success' => 'tw-bg-m-green', 'info' => 'tw-bg-m-cobalt', 'danger' => 'tw-bg-m-red'][$tone ?? 'info'] ?? 'tw-bg-m-cobalt';
include __DIR__ . '/_top.php';
?>
<section class="<?= $bg ?> tw-text-white tw-p-6" role="<?= ($tone ?? '') === 'danger' ? 'alert' : 'status' ?>">
  <p class="tw-text-lg"><?= h($text) ?></p>
  <?php if (!empty($numbers)): ?>
    <ul class="tw-mt-4 tw-flex tw-flex-wrap tw-gap-2" aria-label="Numery dokumentów">
      <?php foreach ($numbers as $n): ?><li class="tw-bg-white/20 tw-px-3 tw-py-1 tw-font-semibold"><?= h($n) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
