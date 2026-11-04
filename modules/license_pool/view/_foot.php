<?php /** modules/license_pool/view/_foot.php — stopka wspólna (domyka _head.php). Wejście: $ctx. */
echo '</div></main>';
if ($ctx === 'ti') include dirname(__DIR__, 3) . '/karty30/ti/kursant/_layout_foot.php';
else include dirname(__DIR__, 3) . '/includes/footer.php';
