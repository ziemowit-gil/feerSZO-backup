<?php
// kolab/calendar. Sterownik i adres CalDAV ustawia feer_cloud_dav (NIE tutaj — load_config wtyczki
// nadpisałby ustawienie): chmura połączona → caldav na nc.feer.org.pl, inaczej database (main config).
// Zaproszenia (iTIP) z maili da się przyjąć do tego kalendarza; wysyłka przez SMTP użytkownika.
$config['calendar_default_view'] = 'agendaWeek';
$config['calendar_timeslots'] = 2;
$config['calendar_first_day'] = 1;
$config['calendar_work_start'] = 8;
$config['calendar_work_end'] = 17;
$config['calendar_itip_send_option'] = 3;
$config['calendar_itip_after_action'] = 0;
$config['calendar_itip_smtp_server'] = null;
