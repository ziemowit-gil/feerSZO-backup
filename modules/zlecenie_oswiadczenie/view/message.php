<?php /** modules/zlecenie_oswiadczenie/view/message.php — komunikat (błąd dostępu, wygasły link). Wymaga: $title, $msg. */ ?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card" role="alert">
  <h1><?= h($title) ?></h1>
  <p><?= h($msg) ?></p>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
