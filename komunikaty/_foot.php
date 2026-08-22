<?php
/** komunikaty/_foot.php — zamknięcie powłoki modułu (para do _shell.php). */
if (!empty($_is_volunteer_only)) {
    include dirname(__DIR__) . '/panel/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
