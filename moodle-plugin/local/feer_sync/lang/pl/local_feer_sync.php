<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']              = 'FEER NGO — Synchronizacja wolontariuszy';
$string['plugindesc']              = 'Synchronizuje konta wolontariuszy z systemu zarządzania umowami FEER NGO z Moodle.';

// Ustawienia
$string['settings_api']            = 'Połączenie z API FEER';
$string['feer_url']                = 'Adres URL systemu FEER';
$string['feer_url_desc']           = 'Bazowy URL systemu FEER NGO, np. https://system.mojafundacja.pl';
$string['feer_api_key']            = 'Klucz API';
$string['feer_api_key_desc']       = 'Klucz API z uprawnieniem <code>volunteers:read</code>. Wygeneruj w FEER Admin → Klucze API.';
$string['settings_sync']           = 'Zachowanie synchronizacji';
$string['auto_enroll_courses']     = 'Automatyczny zapis na kursy';
$string['auto_enroll_courses_desc']= 'Lista ID kursów Moodle oddzielona przecinkami. Każdy aktywny wolontariusz zostanie na nie zapisany.';
$string['action_course_map']       = 'Mapowanie Działanie → Kurs';
$string['action_course_map_desc']  = 'Obiekt JSON mapujący action_id z FEER na course_id w Moodle, np. {"12": 5, "15": 8}';
$string['suspend_on_end']          = 'Zawieś konto gdy umowa się kończy';
$string['suspend_on_end_desc']     = 'Automatycznie zawieś konto Moodle gdy status umowy wolontariusza zmieni się na zakończona/anulowana.';
$string['create_if_missing']       = 'Utwórz konto jeśli brak';
$string['create_if_missing_desc']  = 'Utwórz nowe konto Moodle dla wolontariuszy, którzy go jeszcze nie mają.';
$string['sync_page_heading']       = 'FEER NGO — Synchronizacja wolontariuszy';
$string['run_sync_now']            = 'Uruchom synchronizację teraz';
$string['sync_log']                = 'Dziennik synchronizacji';
$string['last_sync']               = 'Ostatnia synchronizacja';
$string['task_sync']               = 'Synchronizuj wolontariuszy FEER';
$string['sync_result_ok']          = 'Synchronizacja zakończona: {$a->created} utworzono, {$a->updated} zaktualizowano, {$a->suspended} zawieszono, {$a->errors} błędów.';
$string['sync_standalone']         = 'Synchronizuj wolontariuszy bez umowy';
$string['sync_standalone_desc']    = 'Gdy włączone, konta oznaczone jako "wolontariusz bez umowy" w systemie FEER również zostaną zsynchronizowane z Moodle.';
$string['writeback_login']         = 'Zapisz login Moodle z powrotem do FEER';
$string['writeback_login_desc']    = 'Po utworzeniu konta Moodle, wyślij nazwę użytkownika z powrotem do systemu FEER (endpoint /api/v1/moodle_user_update.php). Wolontariusz zobaczy swój login Moodle w panelu.';
$string['callback_url']            = 'Callback URL (writeback)';
$string['callback_url_desc']       = 'Ten adres jest wywoływany automatycznie przez wtyczkę po utworzeniu konta Moodle. Wyliczany z pola "Adres URL systemu FEER" powyżej — nie wymaga ręcznej konfiguracji.';
