<?php
// Polskie tłumaczenie wtyczki nextcloud_attachments (FEER, 2026-11-26).
// Na serwerze: ~/roundcube/l10n/nextcloud_attachments/pl_PL.inc → app/plugins/nextcloud_attachments/l10n/pl_PL.inc

$labels['login_failed'] = 'Nie udało się połączyć z chmurą. Spróbuj ponownie później.';
$labels['logging_in'] = 'Łączenie z chmurą…';
$labels['no_login_link'] = 'Chmura nie zwróciła odnośnika do logowania. Spróbuj ponownie później.';
$labels['logged_in'] = 'Połączono z chmurą.';
$labels['login'] = 'Połącz';
$labels['login_and_link_file'] = 'Połącz i wyślij link';

$labels['file_too_big'] = 'Plik jest za duży';
$labels['file_too_big_not_logged_in_explain'] = 'Plik jest większy niż %limit% i nie zmieści się w wiadomości. Po połączeniu z chmurą (poniżej) możesz go wysłać jako link do pliku w chmurze.';
$labels['file_too_big_explain'] = 'Plik jest większy niż %limit% i nie zmieści się w wiadomości. Możesz go wysłać jako link do pliku w chmurze.';

$labels['file_big'] = 'Jak wysłać ten plik?';
$labels['file_big_explain'] = 'Plik jest dość duży: %size%. Wiadomość będzie lżejsza, jeśli wyślesz link do pliku w chmurze.';
$labels['file_big_not_logged_in_explain'] = 'Plik jest dość duży: %size%. Wiadomość będzie lżejsza, jeśli połączysz się z chmurą (poniżej) i wyślesz link do pliku.';

$labels['link_file'] = 'Wyślij do chmury i wstaw link';
$labels['attach'] = 'Załącz kopię';

$labels['upload_success_link_inserted'] = 'Plik jest w chmurze, link wstawiony do wiadomości';

$labels['upload_failed_title'] = 'Nie udało się wysłać do chmury';
$labels['upload_warning_title'] = 'Uwaga (chmura)';
$labels['upload_failed'] = 'Nie udało się wysłać do chmury:';

$labels['delete_ok'] = 'Plik usunięty z chmury';
$labels['delete_error_explain'] = 'Nie udało się usunąć pliku (kod: <b>%reason%</b>). Możesz mimo to usunąć go z listy załączników.';
$labels['remove_attachment'] = 'Usuń załącznik z chmury';
$labels['remove_from_list'] = 'Pomiń i usuń z listy';
$labels['remove_from_nextcloud_question'] = 'Ten załącznik jest w chmurze. Usunąć go także z chmury? Link w treści wiadomości trzeba usunąć samodzielnie.';
$labels['remove_from_nextcloud'] = 'Usuń z chmury';
$labels['keep_in_nextcloud'] = 'Zostaw w chmurze, usuń z listy';

$labels['missing_config'] = 'Brak konfiguracji chmury. Zgłoś to w Helpdesku IT.';
$labels['cannot_mkdir'] = 'nie udało się utworzyć folderu w chmurze:';
$labels['folder_access'] = 'Brak dostępu do folderu w chmurze. Sprawdź uprawnienia albo zgłoś to w Helpdesku IT.';
$labels['cannot_find_unique_name'] = 'nie udało się dobrać wolnej nazwy pliku. Zmień nazwę pliku albo plików w folderze w chmurze.';
$labels['cannot_link'] = 'plik jest w chmurze, ale nie udało się utworzyć linku. Utwórz go ręcznie albo zgłoś to w Helpdesku IT.';

$labels['cloud_attachments'] = 'Załączniki w chmurze';
$labels['cloud_server'] = 'Chmura';
$labels['status'] = 'Stan';
$labels['connected_as'] = 'Połączono jako';
$labels['disconnect'] = 'rozłącz';
$labels['connect'] = 'połącz';
$labels['not_connected'] = 'Nie połączono';

$labels['server_default'] = 'Domyślnie: ';

$labels['folder_layout'] = 'Układ folderów';
$labels['folder_layout_flat'] = 'Wszystko w jednym folderze';
$labels['folder_layout_hash'] = 'Według początku skrótu pliku';
$labels['folder_layout_date'] = 'Według daty';
$labels['folder_layout_date_Y'] = 'Według roku';
$labels['folder_layout_date_Y/LLLL'] = 'Według roku i miesiąca';
$labels['folder_layout_date_Y/LLLL/dd'] = 'Według roku, miesiąca i dnia';
$labels['folder_layout_date_Y/LL'] = 'Według roku i miesiąca';
$labels['folder_layout_date_Y/LL/dd'] = 'Według roku, miesiąca i dnia';
$labels['folder_layout_date_Y/ww'] = 'Według roku i tygodnia';
$labels['folder_layout_date_Y/ww/EEEE'] = 'Według roku, tygodnia i dnia tygodnia';
$labels['folder_layout_date_Y/ww/E'] = 'Według roku, tygodnia i dnia tygodnia';

$labels['password_protected_links'] = 'Linki chronione hasłem';
$labels['expire_links'] = 'Linki z datą wygaśnięcia';
$labels['expire_links_after'] = 'Linki wygasają po (dniach)';

$labels['attached_html_lang'] = 'Język opisu załącznika';
$labels['display_language'] = 'Język interfejsu';
$labels['attached_html_lang_explain'] = 'Język tekstu, który odbiorca zobaczy przy linku do pliku. Języki bez tłumaczenia wyświetlą się po angielsku.';

$labels['password'] = 'Hasło';
$labels['valid_until'] = 'Ważny do';

$labels['filelink_attachment'] = 'Załącznik w chmurze';
$labels['file_is_filelink_download_below'] = 'Plik $filename jest w chmurze. Pobierzesz go z linku poniżej.';
$labels['size'] = 'Rozmiar';
$labels['link'] = 'Link';
$labels['checksum'] = 'Suma kontrolna';
$labels['available_until'] = 'Dostępny do';
$labels['valid_until_expires'] = '$validuntil, potem link wygaśnie';
$labels['attachment_disclaimer'] = 'Plik jest przechowywany na $serverurl i podlega zasadom tego serwera. <b>Nadawca może usunąć plik w dowolnej chwili, więc zapisz go u siebie, jeśli chcesz go zachować.</b> Otwieraj pliki tylko od zaufanych nadawców, zwłaszcza programy i dokumenty biurowe.';
$labels['copyright'] = 'Wygenerowano przez $repolocation dla Roundcube, autor: $author';
$labels['icons'] = 'Ikony';
$labels['deletion'] = 'usunięcie';
