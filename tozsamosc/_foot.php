<?php
/** tozsamosc/_foot.php — zamknięcie layoutu podsystemu Tożsamość. */
$__org = defined('ORG_NAME') ? ORG_NAME : '';
?>
</main>
<footer style="max-width:960px;margin:0 auto;padding:1rem;color:#9aa4b2;font-size:.78rem;text-align:center">
  <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>System Tożsamości · połączenie szyfrowane · © <?= date('Y') ?> <?= h($__org) ?>
</footer>
</body>
</html>
