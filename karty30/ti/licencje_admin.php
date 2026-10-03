<?php
/**
 * karty30/ti/licencje_admin.php — PRZENIESIONE.
 *
 * Licencje kursantów TI (katalog oprogramowania, przypisania, forma rozliczenia FAK/FAZ/DSC/GST,
 * powiadomienia SMS / e-mail) obsługuje teraz moduł modules/ti_licencje; ekran to
 * karty30/ti/dydaktyk/licencjeKursantow.php (panel kierownika). Konfiguracja integracji JetBrains
 * jest w tym samym ekranie. Z konta SZO wchodzi się tam przez mostek office_enter.php (admin
 * trafia od razu na ekran, bez ponownego logowania). Ten plik zostaje tylko dla starych linków.
 */
header('Location: dydaktyk/office_enter.php?next=licencjeKursantow.php');
exit;
