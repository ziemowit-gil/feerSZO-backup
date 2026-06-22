<?php
/**
 * includes/crm_inbox.php — Śledzenie skrzynki współdzielonej (np. fundacja@feer.org.pl)
 * i automatyczne dopisywanie nadawców do kartoteki CRM.
 *
 * Czyta nowe wiadomości przez Microsoft Graph (uprawnienie aplikacji Mail.Read),
 * dla każdej:
 *   - dopasowuje kontakt po adresie e-mail nadawcy,
 *   - jeśli brak — tworzy nowy kontakt (gdy włączone),
 *   - loguje wiadomość przychodzącą w historii komunikacji (z deduplikacją po ID Graph).
 *
 * Ustawienia (tabela settings, klucze):
 *   crm_inbox_watch_enabled        '1' = włączone
 *   crm_inbox_watch_mailbox        adres skrzynki (domyślnie fundacja@feer.org.pl)
 *   crm_inbox_watch_create         '0' = tylko loguj do istniejących, nie twórz nowych
 *   crm_inbox_watch_skip_internal  '0' = nie pomijaj nadawców z własnej domeny
 *   crm_inbox_watch_last           kursor — receivedDateTime ostatnio przetworzonej wiadomości
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/m365.php';

/** Domyślny adres śledzonej skrzynki. */
function crm_inbox_mailbox(): string {
    return crm_setting('crm_inbox_watch_mailbox') ?: 'fundacja@feer.org.pl';
}

/**
 * Wykonuje jeden przebieg śledzenia skrzynki.
 * @return array{enabled:bool,fetched:int,created:int,logged:int,skipped:int,errors:string[],mailbox:string}
 */
function crm_inbox_watch_run(): array {
    $res = [
        'enabled' => false, 'fetched' => 0, 'created' => 0, 'logged' => 0,
        'skipped' => 0, 'errors' => [], 'mailbox' => crm_inbox_mailbox(),
    ];

    if (crm_setting('crm_inbox_watch_enabled') !== '1') return $res;
    $res['enabled'] = true;

    $mailbox       = crm_inbox_mailbox();
    $do_create     = crm_setting('crm_inbox_watch_create') !== '0';        // domyślnie: twórz
    $skip_internal = crm_setting('crm_inbox_watch_skip_internal') !== '0';  // domyślnie: pomijaj własną domenę

    $since = crm_setting('crm_inbox_watch_last');
    if ($since === '') {
        // Pierwszy przebieg — weź ostatnie 7 dni, by nie zaciągać całej historii.
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
    }

    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        $res['errors'][] = 'Microsoft 365 / Graph nie jest skonfigurowany.';
        return $res;
    }

    try {
        $msgs = $graph->inbox_messages($mailbox, $since, 50);
    } catch (\Throwable $e) {
        $res['errors'][] = 'Błąd odczytu skrzynki: ' . $e->getMessage();
        return $res;
    }
    $res['fetched'] = count($msgs);

    $own_domain = strtolower(substr(strrchr($mailbox, '@') ?: '', 1));
    $max_ts     = $since;

    foreach ($msgs as $m) {
        $ext_id = (string)($m['id'] ?? '');
        $ts     = (string)($m['receivedDateTime'] ?? '');
        if ($ts !== '' && $ts > $max_ts) $max_ts = $ts;

        $from  = $m['from']['emailAddress'] ?? ($m['sender']['emailAddress'] ?? []);
        $email = strtolower(trim($from['address'] ?? ''));
        $name  = trim($from['name'] ?? '');

        // Pomiń: brak/niepoprawny adres, samą skrzynkę, opcjonalnie własną domenę
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $res['skipped']++; continue; }
        if ($email === strtolower($mailbox)) { $res['skipped']++; continue; }
        if ($skip_internal && $own_domain && str_ends_with($email, '@' . $own_domain)) { $res['skipped']++; continue; }

        // Deduplikacja po ID wiadomości Graph
        if ($ext_id !== '' && db_one("SELECT id FROM crm_communications WHERE external_id=?", [$ext_id])) {
            $res['skipped']++; continue;
        }

        // Dopasuj kontakt po adresie e-mail
        $contact = db_one("SELECT * FROM crm_contacts WHERE LOWER(email)=? AND crm_active=1", [$email]);

        if (!$contact) {
            if (!$do_create) { $res['skipped']++; continue; }
            $disp  = $name !== '' ? $name : ucfirst(strtok($email, '@'));
            $parts = preg_split('/\s+/', $disp, 2);
            try {
                $cid = CrmManager::createContact([
                    'type'          => 'osoba',
                    'status'        => 'prospect',
                    'imie_nazwisko' => $disp,
                    'imie'          => $parts[0] ?? '',
                    'nazwisko'      => $parts[1] ?? '',
                    'email'         => $email,
                    'source'        => 'inbox',
                ]);
                CrmManager::addNote(
                    $cid,
                    'Kontakt utworzony automatycznie na podstawie wiadomości na skrzynkę ' . $mailbox . '.',
                    0
                );
                $contact = db_one("SELECT * FROM crm_contacts WHERE id=?", [$cid]);
                $res['created']++;
            } catch (\Throwable $e) {
                $res['errors'][] = 'Nie udało się utworzyć kontaktu ' . $email . ': ' . $e->getMessage();
                continue;
            }
        }
        if (!$contact) { $res['skipped']++; continue; }

        // Zaloguj wiadomość przychodzącą
        try {
            db_insert('crm_communications', [
                'contact_id'  => (int)$contact['id'],
                'channel'     => 'email',
                'direction'   => 'in',
                'subject'     => mb_substr((string)($m['subject'] ?? '(bez tematu)'), 0, 300),
                'body'        => mb_substr((string)($m['bodyPreview'] ?? ''), 0, 2000),
                'status'      => 'odebrana',
                'sent_at'     => $ts !== '' ? date('Y-m-d H:i:s', strtotime($ts)) : date('Y-m-d H:i:s'),
                'external_id' => $ext_id !== '' ? $ext_id : null,
            ]);
            db()->prepare("UPDATE crm_contacts SET updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), (int)$contact['id']]);
            $res['logged']++;
        } catch (\Throwable $e) {
            $res['errors'][] = 'Błąd zapisu komunikacji (' . $email . '): ' . $e->getMessage();
        }
    }

    if ($max_ts !== '' && $max_ts !== $since) {
        crm_setting_save('crm_inbox_watch_last', $max_ts);
    }

    return $res;
}
