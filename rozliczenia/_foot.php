<?php
/** rozliczenia/_foot.php — zamknięcie layoutu modułu Rozliczenia. */
$__org = defined('ORG_NAME') ? ORG_NAME : '';
?>
</main>
<footer style="max-width:1140px;margin:0 auto;padding:1rem;color:#9aa4b2;font-size:.78rem;text-align:center">
  <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Rozliczenia zajęć · model kombinowany (osobne rozliczenie na grupę) · © <?= date('Y') ?> <?= h($__org) ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
