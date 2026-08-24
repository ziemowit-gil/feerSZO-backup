<?php
/**
 * includes/crm_office.php — Most CRM ↔ Microsoft 365 (Outlook) w stronę WYCHODZĄCĄ
 * i punktowe pobieranie korespondencji kontaktu.
 *
 * Co już było w systemie (nie duplikujemy):
 *   includes/outlook_sync.php  — Outlook → CRM: kontakty (delta), kalendarz, maile
 *                                (inbox + sentitems) dopasowywane po adresie
 *   includes/crm_inbox.php     — śledzenie skrzynki współdzielonej → nowe kontakty
 *
 * Co dodaje ten plik:
 *   • CRM → książka adresowa Outlooka (tworzenie i aktualizacja kontaktu przez Graph),
 *     z powiązaniem przez crm_contacts.outlook_id — ten sam klucz, którego używa
 *     synchronizacja przychodząca, więc kontakt nie zdubluje się w żadną stronę,
 *   • pobranie korespondencji JEDNEGO kontaktu na żądanie (przycisk w kartotece),
 *     bez czekania na globalną delta-synchronizację.
 *
 * Uprawnienia aplikacji w Entra ID: Contacts.ReadWrite (zapis kontaktów),
 * Mail.Read (pobieranie korespondencji).
 *
 * Ustawienia (settings):
 *   crm_office_push_enabled  '1' = wolno zapisywać kontakty do Outlooka
 *   crm_office_mailbox       skrzynka/książka docelowa (domyślnie m365_sync_user_id)
 *   crm_office_folder_id     opcjonalny folder kontaktów (puste = domyślny)
 *   crm_office_folder_name   nazwa folderu (tylko do wyświetlenia)
 *   crm_office_auto_push     '1' = zapisuj automatycznie przy tworzeniu kontaktu
 *   crm_office_mail_days     ile dni wstecz pobierać korespondencję (domyślnie 365)
 *   crm_office_mail_max      limit wiadomości na jedno pobranie (domyślnie 50)
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/m365.php';

// ─────────────────────────────────────────────────────────────────────────────
// SCHEMAT
// ─────────────────────────────────────────────────────────────────────────────

function crm_office_migrate(): bool {
    static $done = null;
    if ($done !== null) return $done;
    $done = false;
    try {
        crm_migrate();
        $pdo = crm_db();
        foreach ([
            "ALTER TABLE crm_contacts ADD COLUMN office_pushed_at DATETIME",
            "ALTER TABLE crm_contacts ADD COLUMN office_push_error TEXT",
            "ALTER TABLE crm_contacts ADD COLUMN office_mail_pulled_at DATETIME",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (\Throwable $e) {}
        }
        $done = true;
    } catch (\Throwable $e) {
        error_log('[crm_office_migrate] ' . $e->getMessage());
    }
    return $done;
}

// ─────────────────────────────────────────────────────────────────────────────
// KONFIGURACJA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Dopisuje do komunikatu o błędzie identyfikację rejestracji aplikacji.
 * Bez tego „Access is denied" nie mówi, KTÓRĄ aplikację w Entra ID poprawić —
 * a instalacje mają osobne rejestracje dla produkcji i preprodukcji.
 */
function crm_office_error_context(string $msg): string {
    try {
        $g = new M365Graph();
        $cid = $g->client_id();
        $tid = $g->tenant_id();
    } catch (\Throwable $e) { return $msg; }
    $bits = [];
    if ($cid !== '') $bits[] = 'Client ID: ' . $cid;
    if ($tid !== '') $bits[] = 'Tenant: ' . $tid;
    return $bits ? ($msg . ' [' . implode(' · ', $bits) . ']') : $msg;
}

/** Skrzynka, której książkę adresową zasilamy (UPN lub Azure AD User ID). */
function crm_office_mailbox(): string {
    return crm_setting('crm_office_mailbox')
        ?: crm_setting('m365_sync_user_id')
        ?: crm_setting('m365_sender_user_id');
}

/** Czy zapis kontaktów do Outlooka jest w ogóle możliwy i włączony. */
function crm_office_push_enabled(): bool {
    if (crm_setting('crm_office_push_enabled') !== '1') return false;
    return crm_office_mailbox() !== '';
}

/** Czy nowe kontakty mają lecieć do Outlooka automatycznie. */
function crm_office_auto_push(): bool {
    return crm_office_push_enabled() && crm_setting('crm_office_auto_push') === '1';
}

/**
 * Czy korespondencja ma się dociągać sama.
 *
 * Przycisk „Pobierz maile z Outlooka" wymagał wejścia w kartotekę i kliknięcia —
 * czyli historia komunikacji była pełna tylko tam, gdzie ktoś akurat zajrzał.
 * Automat obchodzi kartoteki po kolei w tle.
 */
function crm_office_auto_mail(): bool {
    return crm_setting('crm_office_auto_mail') === '1';
}

/** Co ile godzin wracać do tej samej kartoteki. Domyślnie doba. */
function crm_office_auto_mail_hours(): int {
    $h = (int)crm_setting('crm_office_auto_mail_hours');
    return $h > 0 ? min(720, max(1, $h)) : 24;
}

function crm_office_mail_days(): int {
    return max(1, (int)(crm_setting('crm_office_mail_days') ?: 365));
}

function crm_office_mail_max(): int {
    return max(1, min(200, (int)(crm_setting('crm_office_mail_max') ?: 50)));
}

/**
 * Uprawnienia aplikacji istotne dla tej integracji — pokazywane w ustawieniach,
 * żeby nie diagnozować „403" z logów. Zwraca [nazwa => bool].
 */
function crm_office_permissions(): array {
    $need = ['Contacts.ReadWrite' => false, 'Mail.Read' => false];
    try {
        $p = (new M365Graph())->get_granted_permissions();
        $granted = array_map('strval', $p['application'] ?? []);
        foreach (array_keys($need) as $k) $need[$k] = in_array($k, $granted, true);
    } catch (\Throwable $e) {
        error_log('[crm_office_permissions] ' . $e->getMessage());
    }
    return $need;
}

/** Nazwa aplikacji z Entra ID (service principal) — do wskazania właściwej rejestracji. */
function crm_office_app_name(): string {
    try {
        $p = (new M365Graph())->get_granted_permissions();
        return (string)($p['sp_display_name'] ?? '');
    } catch (\Throwable $e) { return ''; }
}

/** Stan integracji dla UI: co jest skonfigurowane, a czego brakuje. */
function crm_office_status(): array {
    $graph = new M365Graph();
    return [
        'graph_configured' => $graph->is_configured(),
        'client_id'        => $graph->client_id(),
        'tenant_id'        => $graph->tenant_id(),
        'mailbox'          => crm_office_mailbox(),
        'push_enabled'     => crm_office_push_enabled(),
        'auto_push'        => crm_office_auto_push(),
        'auto_mail'        => crm_office_auto_mail(),
        'auto_mail_hours'  => crm_office_auto_mail_hours(),
        'folder_name'      => crm_setting('crm_office_folder_name'),
        'mail_days'        => crm_office_mail_days(),
        'inbound_sync'     => class_exists('OutlookSync') && OutlookSync::integration_enabled(),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// CRM → KSIĄŻKA ADRESOWA OUTLOOKA
// ─────────────────────────────────────────────────────────────────────────────

/** Mapuje kontakt CRM na zasób `contact` Microsoft Graph. */
function crm_office_contact_payload(array $c): array {
    $type    = (string)($c['type'] ?? 'osoba');
    $is_org  = !empty(CRM_CONTACT_TYPES[$type]['org_like']);
    $name    = trim((string)($c['imie_nazwisko'] ?? ''));
    $email   = trim((string)($c['email'] ?? ''));
    $phone   = trim((string)($c['telefon'] ?? ''));

    $payload = [
        'displayName'  => $name !== '' ? $name : ($email ?: 'Kontakt CRM'),
        'categories'   => array_values(array_filter(['CRM', CRM_CONTACT_TYPES[$type]['label'] ?? null])),
    ];

    if ($is_org) {
        $payload['companyName'] = $name;
        if (!empty($c['osoba_kontaktowa'])) $payload['givenName'] = (string)$c['osoba_kontaktowa'];
    } else {
        if (!empty($c['imie']))     $payload['givenName'] = (string)$c['imie'];
        if (!empty($c['nazwisko'])) $payload['surname']   = (string)$c['nazwisko'];
        if (!empty($c['organizacja'])) $payload['companyName'] = (string)$c['organizacja'];
    }

    if ($email !== '') {
        $payload['emailAddresses'] = [['address' => $email, 'name' => $name ?: $email]];
    }
    if ($phone !== '') {
        // Telefon komórkowy dla osób, służbowy dla podmiotów — tak wygląda to naturalnie w Outlooku
        if ($is_org) $payload['businessPhones'] = [$phone];
        else         $payload['mobilePhone']    = $phone;
    }
    if (!empty($c['stanowisko'])) $payload['jobTitle'] = (string)$c['stanowisko'];

    // Adres strukturalny, gdy jest — inaczej pole tekstowe trafia do notatki
    $addr = array_filter([
        'street'          => trim((string)($c['addr_street'] ?? '') . ' ' . (string)($c['addr_house'] ?? '')
                                  . ((string)($c['addr_flat'] ?? '') !== '' ? '/' . $c['addr_flat'] : '')),
        'city'            => (string)($c['addr_city'] ?? ''),
        'postalCode'      => (string)($c['addr_postal'] ?? ''),
        'countryOrRegion' => (string)($c['addr_country'] ?? ''),
    ], static fn($v) => trim((string)$v) !== '');
    if ($addr) {
        $payload[$is_org ? 'businessAddress' : 'homeAddress'] = $addr;
    }

    $notes = [];
    if (!empty($c['nip']))     $notes[] = 'NIP: ' . $c['nip'];
    if (!empty($c['krs']))     $notes[] = 'KRS: ' . $c['krs'];
    if (!$addr && !empty($c['adres'])) $notes[] = 'Adres: ' . $c['adres'];
    if (!empty($c['notatka'])) $notes[] = (string)$c['notatka'];
    // Znacznik pozwala rozpoznać w Outlooku, że wpis pochodzi z CRM
    $notes[] = 'Kontakt z CRM SZO (#' . (int)$c['id'] . ') — ' . APP_URL . '/crm/contact/view.php?id=' . (int)$c['id'];
    $payload['personalNotes'] = implode("\n", $notes);

    return $payload;
}

/**
 * Zapisuje kontakt CRM do książki adresowej Outlooka.
 * Gdy kontakt ma już outlook_id — aktualizuje wpis, w przeciwnym razie tworzy nowy.
 *
 * @return array{ok:bool, action:string, error:string, outlook_id:string}
 */
function crm_office_push_contact(int $contact_id, bool $force = false): array {
    crm_office_migrate();
    $out = ['ok' => false, 'action' => 'none', 'error' => '', 'outlook_id' => ''];

    if (!$force && !crm_office_push_enabled()) {
        $out['error'] = 'Zapis kontaktów do Outlooka jest wyłączony (Ustawienia CRM → Microsoft 365).';
        return $out;
    }
    $mailbox = crm_office_mailbox();
    if ($mailbox === '') {
        $out['error'] = 'Nie wskazano skrzynki docelowej (crm_office_mailbox).';
        return $out;
    }

    $c = crm_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$c) { $out['error'] = 'Kontakt nie istnieje.'; return $out; }
    if (trim((string)($c['imie_nazwisko'] ?? '')) === '' && trim((string)($c['email'] ?? '')) === '') {
        $out['error'] = 'Kontakt bez nazwy i bez adresu e-mail — nie ma czego zapisać.';
        return $out;
    }

    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        $out['error'] = 'Microsoft 365 / Graph nie jest skonfigurowany.';
        return $out;
    }

    $payload   = crm_office_contact_payload($c);
    $folder_id = crm_setting('crm_office_folder_id');
    $existing  = trim((string)($c['outlook_id'] ?? ''));

    try {
        if ($existing !== '') {
            $graph->update_outlook_contact($mailbox, $existing, $payload);
            $st = $graph->last_status();
            if ($st === 404) {
                // Wpis usunięto w Outlooku — twórz od nowa, zamiast zostawić martwe powiązanie
                $existing = '';
            } elseif ($st >= 400) {
                $err = $graph->last_error();
                $out['error'] = crm_office_error_context(
                    'Graph HTTP ' . $st . ($err['message'] ?? '' ? ': ' . $err['message'] : '')
                );
                crm_update('crm_contacts', ['office_push_error' => $out['error']], $contact_id);
                return $out;
            } else {
                $out = ['ok' => true, 'action' => 'updated', 'error' => '', 'outlook_id' => $existing];
            }
        }

        if ($existing === '') {
            $res = $graph->create_outlook_contact($mailbox, $payload, $folder_id);
            $new_id = (string)($res['id'] ?? '');
            if ($new_id === '') {
                $st  = $graph->last_status();
                $err = $graph->last_error();
                $out['error'] = crm_office_error_context(
                    'Graph HTTP ' . $st . ($err['message'] ?? '' ? ': ' . $err['message'] : '')
                    . ($st === 403 ? ' — brak uprawnienia Contacts.ReadWrite dla aplikacji.' : '')
                );
                crm_update('crm_contacts', ['office_push_error' => $out['error']], $contact_id);
                return $out;
            }
            crm_update('crm_contacts', ['outlook_id' => $new_id], $contact_id);
            $out = ['ok' => true, 'action' => 'created', 'error' => '', 'outlook_id' => $new_id];
        }

        crm_update('crm_contacts', [
            'office_pushed_at'  => date('Y-m-d H:i:s'),
            'office_push_error' => null,
            'outlook_synced_at' => date('Y-m-d H:i:s'),
        ], $contact_id);
        return $out;

    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        try { crm_update('crm_contacts', ['office_push_error' => $out['error']], $contact_id); } catch (\Throwable $e2) {}
        return $out;
    }
}

/** Usuwa kontakt z książki adresowej Outlooka (nie rusza kartoteki CRM). */
function crm_office_unlink_contact(int $contact_id, bool $delete_remote = true): array {
    crm_office_migrate();
    $c = crm_one("SELECT id, outlook_id FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$c || trim((string)($c['outlook_id'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Kontakt nie jest powiązany z Outlookiem.'];
    }
    if ($delete_remote) {
        $mailbox = crm_office_mailbox();
        if ($mailbox === '') return ['ok' => false, 'error' => 'Brak skrzynki docelowej.'];
        try {
            (new M365Graph())->delete_outlook_contact($mailbox, (string)$c['outlook_id']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    crm_update('crm_contacts', ['outlook_id' => null, 'office_pushed_at' => null], $contact_id);
    return ['ok' => true, 'error' => ''];
}

/**
 * Masowy zapis: kontakty jeszcze nieprzeniesione albo zmienione po ostatnim zapisie.
 * Używane przez cron i przycisk „wyślij wszystkie".
 */
function crm_office_push_pending(int $limit = 100): array {
    crm_office_migrate();
    $res = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
    if (!crm_office_push_enabled()) { $res['errors'][] = 'Zapis do Outlooka wyłączony.'; return $res; }

    $limit = max(1, min(1000, $limit));
    $rows = crm_all(
        "SELECT id FROM crm_contacts
         WHERE crm_active = 1
           AND (imie_nazwisko IS NOT NULL OR email IS NOT NULL)
           AND (office_pushed_at IS NULL OR office_pushed_at < updated_at)
         ORDER BY updated_at DESC LIMIT $limit"
    );
    foreach ($rows as $r) {
        $one = crm_office_push_contact((int)$r['id']);
        if ($one['ok']) {
            $res[$one['action'] === 'created' ? 'created' : 'updated']++;
        } else {
            $res['failed']++;
            if (count($res['errors']) < 10) $res['errors'][] = '#' . $r['id'] . ': ' . $one['error'];
        }
    }
    return $res;
}

// ─────────────────────────────────────────────────────────────────────────────
// KORESPONDENCJA KONTAKTU → KARTOTEKA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Dociąga korespondencję dla kartotek, które jej dawno nie miały.
 *
 * Kolejność: najpierw nigdy niepobierane, potem najdawniej pobierane. Dzięki temu
 * pierwsze przebiegi nadrabiają zaległości całej bazy, a potem automat krąży
 * równomiernie, zamiast odświeżać w kółko te same kartoteki.
 *
 * Limit na przebieg jest twardy, bo każde zapytanie to wywołanie Graph API —
 * przy tysiącu kontaktów nieograniczony przebieg wpadłby w limity Microsoftu.
 *
 * @return array{done:int, logged:int, failed:int, errors:string[]}
 */
function crm_office_pull_pending(int $limit = 25, ?int $hours = null): array {
    crm_office_migrate();
    $res = ['done' => 0, 'logged' => 0, 'failed' => 0, 'errors' => []];

    $hours = $hours ?? crm_office_auto_mail_hours();
    $limit = max(1, min(500, $limit));

    $rows = crm_all(
        "SELECT id FROM crm_contacts
          WHERE crm_active = 1
            AND email IS NOT NULL AND email <> ''
            AND (office_mail_pulled_at IS NULL
                 OR office_mail_pulled_at < datetime('now', '-{$hours} hours'))
       ORDER BY office_mail_pulled_at IS NOT NULL, office_mail_pulled_at, id
          LIMIT {$limit}"
    );

    foreach ($rows as $r) {
        $one = crm_office_pull_contact_mail((int)$r['id']);
        if (!empty($one['ok'])) {
            $res['done']++;
            $res['logged'] += (int)($one['logged'] ?? 0);
        } else {
            $res['failed']++;
            if (count($res['errors']) < 5) $res['errors'][] = '#' . $r['id'] . ': ' . ($one['error'] ?? '');
            // Błąd uprawnień albo konfiguracji dotyczy wszystkich kartotek naraz —
            // dalsze próby tylko mnożą nieudane wywołania Graph API
            if (str_contains((string)($one['error'] ?? ''), 'Mail.Read')
                || str_contains((string)($one['error'] ?? ''), 'nie jest skonfigurowany')) break;
        }
    }
    return $res;
}

/**
 * Pobiera z Outlooka korespondencję z adresem kontaktu (w obie strony) i dopisuje
 * ją do historii komunikacji w kartotece. Deduplikacja po outlook_message_id,
 * więc wielokrotne uruchomienie nie powiela wpisów.
 *
 * @return array{ok:bool, fetched:int, logged:int, skipped:int, error:string}
 */
function crm_office_pull_contact_mail(int $contact_id, ?int $days = null, ?int $max = null): array {
    crm_office_migrate();
    $res = ['ok' => false, 'fetched' => 0, 'logged' => 0, 'skipped' => 0, 'error' => ''];

    $c = crm_one("SELECT id, imie_nazwisko, email FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$c) { $res['error'] = 'Kontakt nie istnieje.'; return $res; }

    $email = strtolower(trim((string)($c['email'] ?? '')));
    if ($email === '') { $res['error'] = 'Kontakt nie ma adresu e-mail.'; return $res; }

    $mailbox = crm_office_mailbox();
    if ($mailbox === '') { $res['error'] = 'Nie wskazano skrzynki, z której pobierać korespondencję.'; return $res; }

    $graph = new M365Graph();
    if (!$graph->is_configured()) { $res['error'] = 'Microsoft 365 / Graph nie jest skonfigurowany.'; return $res; }

    $days  = $days ?? crm_office_mail_days();
    $max   = $max  ?? crm_office_mail_max();
    $since = time() - $days * 86400;

    try {
        $msgs = $graph->search_messages_participant($mailbox, $email, $max);
    } catch (\Throwable $e) {
        $res['error'] = $e->getMessage();
        return $res;
    }
    if (!$msgs && $graph->last_status() >= 400) {
        $err = $graph->last_error();
        $st  = $graph->last_status();
        $res['error'] = 'Graph HTTP ' . $st . ($err['message'] ?? '' ? ': ' . $err['message'] : '');
        if ($st === 403 || $st === 401) {
            $res['error'] .= ' — aplikacja w Entra ID nie ma uprawnienia Mail.Read (Application)'
                           . ' albo brakuje zgody administratora.';
        }
        $res['error'] = crm_office_error_context($res['error']);
        return $res;
    }

    foreach ($msgs as $m) {
        $res['fetched']++;
        $mid = (string)($m['id'] ?? '');
        if ($mid === '') { $res['skipped']++; continue; }

        $from = strtolower(trim((string)($m['from']['emailAddress']['address'] ?? '')));
        $dir  = ($from === $email) ? 'in' : 'out';
        $when = (string)($dir === 'in' ? ($m['receivedDateTime'] ?? '') : ($m['sentDateTime'] ?? ''));
        $ts   = $when ? strtotime($when) : 0;
        if ($ts && $ts < $since) { $res['skipped']++; continue; }

        $dup = crm_one(
            "SELECT id FROM crm_communications WHERE outlook_message_id=? AND contact_id=? LIMIT 1",
            [$mid, $contact_id]
        );
        if ($dup) { $res['skipped']++; continue; }

        crm_insert('crm_communications', [
            'contact_id'         => $contact_id,
            'channel'            => 'email',
            'direction'          => $dir,
            'subject'            => trim((string)($m['subject'] ?? '')) ?: '(bez tematu)',
            'body'               => trim((string)($m['bodyPreview'] ?? '')) ?: '(brak treści)',
            'status'             => 'zsynchronizowana',
            'outlook_message_id' => $mid,
            'from_email'         => $from ?: null,
            'from_name'          => trim((string)($m['from']['emailAddress']['name'] ?? '')) ?: null,
            'has_attachments'    => !empty($m['hasAttachments']) ? 1 : 0,
            'sent_at'            => $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'),
        ]);
        $res['logged']++;
    }

    crm_update('crm_contacts', ['office_mail_pulled_at' => date('Y-m-d H:i:s')], $contact_id);
    $res['ok'] = true;
    return $res;
}
