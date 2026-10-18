<?php
/** modules/ti_room_srs/view/_foot.php — stopka (domyka _head.php: panel dydaktyka albo SZO). */
if (defined('TI_ROOM_SRS_PANEL')) {
    echo '</div></main>';
    include dirname(__DIR__, 3) . '/karty30/ti/kursant/_layout_foot.php';
} else {
    include dirname(__DIR__, 3) . '/includes/footer.php';
}
