<?php
/**
 * karty30/ti/terms_pdf.php — PRZENIESIONE: oświadczenie administratora (pominięcie / zdalna akceptacja regulaminu)
 * pobiera się z panelu dydaktyka — karty30/ti/dydaktyk/regulaminy.php?pdf=<id>. Przekierowanie przez mostek office_enter.php.
 */
$id = (int)($_GET['id'] ?? 0);
header('Location: dydaktyk/office_enter.php?next=' . rawurlencode('regulaminy.php?pdf=' . $id));
exit;
