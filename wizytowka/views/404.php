<?php /** 404.php — strona nie znaleziona. */ ?>
<section class="page notfound">
  <h1 class="page__title"><strong>404</strong></h1>
  <p class="page__excerpt">Nie znaleźliśmy tej strony. Mogła zostać przeniesiona albo usunięta.</p>
  <p><a class="btn btn--accent" href="<?= e(url('/')) ?>"><?= icon_html('bi-house-door-fill') ?> <span>Wróć na stronę główną</span></a></p>
</section>
