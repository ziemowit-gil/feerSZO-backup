<?php /** modules/ti_licencje_dydaktyczne/view/public/_bottom.php — stopka portalu AM5 (domyka _top.php). */ ?>
</main>
<footer class="tw-max-w-4xl tw-mx-auto tw-px-4 tw-pb-8 tw-text-xs tw-text-m-steel">
  <?= h($org) ?> · <?= ($sym ?? LD_SYMBOL) === 'AM7' ? 'Licencje do oprogramowania — wzory AM7/1–5' : (($sym ?? '') === 'AM20' ? 'Zmiana płatnika licencji — AM20 i AM20-1' : 'Licencje dydaktyczne — wzory AM5/1–3') ?> · realizacja do <?= LD_REALIZATION_DAYS ?> dni
</footer>
</body>
</html>
