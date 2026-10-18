<?php
/**
 * modules/panel_metro/view/important.php — blok „Ważne informacje” na pulpicie Panelu współpracownika.
 * Komunikaty ustawiane przez admina (Komunikaty → Nowy, tryb „Ważna informacja”). Zostają do
 * daty wygaśnięcia — nie znikają po przeczytaniu. Wejście: $items (wiersze announcements).
 */
$_impMax = 420;
?>
<section class="mt-imp" aria-labelledby="pvm-imp-h">
  <h2 class="mt-imp__hd" id="pvm-imp-h"><span class="sq" aria-hidden="true"><i class="bi bi-exclamation-lg"></i></span>Ważne <b>informacje</b></h2>
  <div class="mt-imp__list<?= count($items) > 1 ? ' mt-imp__list--2' : '' ?>">
    <?php foreach ($items as $_a):
      $_kat  = (string)($_a['kategoria'] ?? 'ogolne');
      $_body = (string)($_a['body'] ?? '');
      $_long = mb_strlen($_body) > $_impMax;
      $_url  = APP_URL . '/komunikaty/announcement.php?id=' . (int)$_a['id'];
    ?>
    <article class="mt-imp__item" style="--c:<?= h(ann_kategoria_color($_kat)) ?>" aria-labelledby="pvm-imp-<?= (int)$_a['id'] ?>">
      <div class="mt-imp__meta">
        <?php if ((int)($_a['is_pinned'] ?? 0)): ?><span><i class="bi bi-pin-angle-fill" aria-hidden="true"></i> Przypięte</span><?php endif; ?>
        <span><?= h(ann_kategoria_label($_kat)) ?></span>
        <span><?= h(date('d.m.Y', strtotime((string)$_a['created_at']))) ?></span>
        <?php if (!empty($_a['expires_at'])): ?><span>ważne do <?= h(date('d.m.Y', strtotime((string)$_a['expires_at']))) ?></span><?php endif; ?>
      </div>
      <h3 class="mt-imp__ttl" id="pvm-imp-<?= (int)$_a['id'] ?>"><?= h($_a['title']) ?></h3>
      <?php if ($_body !== ''): ?>
      <p class="mt-imp__body"><?= h($_long ? rtrim(mb_substr($_body, 0, $_impMax)) . '…' : $_body) ?></p>
      <?php endif; ?>
      <?php if ($_long): ?><a class="mt-imp__more" href="<?= h($_url) ?>">Czytaj całość<span class="visually-hidden">: <?= h($_a['title']) ?></span></a><?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
</section>
