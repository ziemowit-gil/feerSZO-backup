<?php
/**
 * includes/outlook_sync.php — Synchronizacja Outlook / MS365 → CRM
 *
 * Klasa OutlookSync:
 *  - sync_contacts()  — delta-sync kontaktów Outlooka → crm_contacts
 *  - sync_calendar()  — sync zdarzeń kalendarza Outlooka → crm_events
 *  - run_all()        — oba naraz, zwraca połączony raport
 *
 * Wymaga: includes/crm.php, includes/m365.php
 * Uruchamiana przez: admin/outlook_sync.php, crm/api/outlook_sync.php
 */

class OutlookSync
{
    private \PDO      $pdo;
    private M365Graph $graph;

    /** Azure AD User ID lub UPN użytkownika, z którego synchujemy. */
    private string $user_id;

    /** Domyślna liczba dni wstecz przy pierwszej synchronizacji kalendarza. */
    private int $calendar_past_days   = 90;

    /** Domyślna liczba dni naprzód przy synchronizacji kalendarza. */
    private int $calendar_future_days = 365;

    public function __construct(?string $user_id = null)
    {
        $this->pdo    = crm_db();
        $this->graph  = new M365Graph();
        $this->user_id = $user_id
            ?? crm_setting('m365_sync_user_id')
            ?: crm_setting('m365_sender_user_id')
            ?: '';

        $this->migrate();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PUBLICZNE API
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Synchronizuje kontakty Outlooka → crm_contacts.
     * Używa delta-link — za pierwszym razem pobiera wszystko, potem tylko zmiany.
     *
     * @return array{created:int, updated:int, removed:int, errors:string[], delta_saved:bool}
     */
    public function sync_contacts(): array
    {
        $this->assert_user_id();

        $delta_link = $this->get_delta('contacts');
        $result     = $this->graph->get_outlook_contacts($this->user_id, $delta_link);

        $created = $updated = $removed = 0;
        $errors  = [];

        foreach ($result['contacts'] as $oc) {
            try {
                $outlook_id = $oc['id'] ?? null;

                // Kontakt usunięty po stronie Outlooka
                if (!empty($oc['@removed'])) {
                    if ($outlook_id) {
                        $this->pdo->prepare(
                            "UPDATE crm_contacts SET crm_active = 0, updated_at = CURRENT_TIMESTAMP
                             WHERE outlook_id = ?"
                        )->execute([$outlook_id]);
                        $removed++;
                    }
                    continue;
                }

                $email = strtolower(trim($oc['emailAddresses'][0]['address'] ?? ''));
                $name  = trim($oc['displayName'] ?? '');

                // Pomiń kontakty bez nazwy i bez emaila
                if (!$name && !$email) {
                    continue;
                }

                // Szukaj istniejącego kontaktu (najpierw po outlook_id, potem po email)
                $existing_id = null;
                if ($outlook_id) {
                    $s = $this->pdo->prepare(
                        "SELECT id FROM crm_contacts WHERE outlook_id = ? LIMIT 1"
                    );
                    $s->execute([$outlook_id]);
                    $existing_id = $s->fetchColumn() ?: null;
                }
                if (!$existing_id && $email) {
                    $s = $this->pdo->prepare(
                        "SELECT id FROM crm_contacts
                         WHERE LOWER(email) = ? AND crm_active = 1 LIMIT 1"
                    );
                    $s->execute([$email]);
                    $existing_id = $s->fetchColumn() ?: null;
                }

                $phone = trim($oc['mobilePhone'] ?? ($oc['businessPhones'][0] ?? ''));
                $addr  = $this->format_address($oc);

                if ($existing_id) {
                    // Aktualizuj pola jeśli się zmieniły, zawsze ustaw outlook_id
                    $this->pdo->prepare(
                        "UPDATE crm_contacts
                         SET outlook_id          = ?,
                             outlook_synced_at   = CURRENT_TIMESTAMP,
                             updated_at          = CURRENT_TIMESTAMP
                         WHERE id = ?"
                    )->execute([$outlook_id, $existing_id]);
                    $updated++;
                } else {
                    // Nowy kontakt z Outlooka
                    $this->pdo->prepare(
                        "INSERT INTO crm_contacts
                            (imie_nazwisko, email, telefon, stanowisko, organizacja, adres,
                             source, status, crm_active, outlook_id, outlook_synced_at,
                             created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?,
                                 'outlook', 'prospect', 1, ?, CURRENT_TIMESTAMP,
                                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                    )->execute([
                        $name,
                        $email,
                        $phone,
                        trim($oc['jobTitle']    ?? ''),
                        trim($oc['companyName'] ?? ''),
                        $addr,
                        $outlook_id,
                    ]);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Kontakt [' . ($oc['id'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        $delta_saved = false;
        if (!empty($result['delta_link'])) {
            $this->set_delta('contacts', $result['delta_link']);
            $delta_saved = true;
        }

        $this->log_sync('contacts', $created, $updated, $removed, $errors);

        return compact('created', 'updated', 'removed', 'errors', 'delta_saved');
    }

    /**
     * Synchronizuje kalendarz Outlooka → crm_events.
     * Zakres: $calendar_past_days dni wstecz + $calendar_future_days naprzód.
     *
     * @param string $calendar_id Puste = domyślny kalendarz
     * @return array{created:int, updated:int, removed:int, errors:string[], delta_saved:bool}
     */
    public function sync_calendar(string $calendar_id = ''): array
    {
        $this->assert_user_id();

        if (!$calendar_id) {
            $calendar_id = crm_setting('m365_sync_calendar_id') ?: '';
        }

        $delta_key  = 'calendar' . ($calendar_id ? "_{$calendar_id}" : '');
        $delta_link = $this->get_delta($delta_key);

        $result = $this->graph->get_calendar_events_delta(
            $this->user_id,
            $calendar_id,
            $delta_link
        );

        $created = $updated = $removed = 0;
        $errors  = [];

        foreach ($result['events'] as $ev) {
            try {
                $outlook_id = $ev['id'] ?? null;
                if (!$outlook_id) continue;

                // Zdarzenie usunięte po stronie Outlooka
                if (!empty($ev['@removed'])) {
                    $this->pdo->prepare(
                        "DELETE FROM crm_events WHERE outlook_id = ?"
                    )->execute([$outlook_id]);
                    $removed++;
                    continue;
                }

                // Pomijaj wystąpienia serii (obsługujemy tylko seriesMaster i singleInstance)
                $ev_type = $ev['type'] ?? 'singleInstance';
                if (in_array($ev_type, ['occurrence', 'exception'], true)) {
                    continue;
                }

                // Pomijaj anulowane zdarzenia (usuń jeśli istnieje)
                if (!empty($ev['isCancelled'])) {
                    $this->pdo->prepare(
                        "DELETE FROM crm_events WHERE outlook_id = ?"
                    )->execute([$outlook_id]);
                    $removed++;
                    continue;
                }

                [$event_date, $event_time] = $this->parse_dt($ev['start'] ?? []);
                [$end_date,   $end_time]   = $this->parse_dt($ev['end']   ?? []);
                $all_day = !empty($ev['isAllDay']) ? 1 : 0;

                $title       = trim($ev['subject'] ?? '(brak tytułu)');
                $description = $this->extract_body($ev['body'] ?? []);
                $location    = trim($ev['location']['displayName'] ?? '');
                if ($location) {
                    $description = $description ? "{$description}\n\n📍 {$location}" : "📍 {$location}";
                }
                $color = $this->category_to_color($ev['categories'] ?? []);

                // Sprawdź czy zdarzenie już istnieje
                $s = $this->pdo->prepare(
                    "SELECT id FROM crm_events WHERE outlook_id = ? LIMIT 1"
                );
                $s->execute([$outlook_id]);
                $existing_id = $s->fetchColumn() ?: null;

                if ($existing_id) {
                    $this->pdo->prepare(
                        "UPDATE crm_events
                         SET title              = ?,
                             description        = ?,
                             event_date         = ?,
                             event_time         = ?,
                             event_end_date     = ?,
                             event_end_time     = ?,
                             all_day            = ?,
                             color              = ?,
                             outlook_synced_at  = CURRENT_TIMESTAMP,
                             updated_at         = CURRENT_TIMESTAMP
                         WHERE id = ?"
                    )->execute([
                        $title, $description,
                        $event_date, $event_time,
                        $end_date,   $end_time,
                        $all_day, $color,
                        $existing_id,
                    ]);
                    $updated++;
                } else {
                    $this->pdo->prepare(
                        "INSERT INTO crm_events
                            (title, description, event_date, event_time,
                             event_end_date, event_end_time, all_day,
                             event_type, color, status,
                             outlook_id, outlook_synced_at,
                             created_at, updated_at)
                         VALUES (?, ?, ?, ?,
                                 ?, ?, ?,
                                 'spotkanie', ?, 'pending',
                                 ?, CURRENT_TIMESTAMP,
                                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                    )->execute([
                        $title, $description,
                        $event_date, $event_time,
                        $end_date,   $end_time,
                        $all_day, $color,
                        $outlook_id,
                    ]);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Event [' . ($ev['id'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        $delta_saved = false;
        if (!empty($result['delta_link'])) {
            $this->set_delta($delta_key, $result['delta_link']);
            $delta_saved = true;
        }

        $this->log_sync('calendar', $created, $updated, $removed, $errors);

        return compact('created', 'updated', 'removed', 'errors', 'delta_saved');
    }

    /**
     * Uruchamia synchronizację kontaktów i kalendarza.
     * @return array{contacts: array, calendar: array, ok: bool, message: string}
     */
    public function run_all(): array
    {
        $contacts_result = ['created'=>0,'updated'=>0,'removed'=>0,'errors'=>[],'delta_saved'=>false];
        $calendar_result = ['created'=>0,'updated'=>0,'removed'=>0,'errors'=>[],'delta_saved'=>false];
        $ok = true;
        $msgs = [];

        $sync_contacts = crm_setting('m365_sync_contacts') !== '0';
        $sync_calendar = crm_setting('m365_sync_calendar') !== '0';

        if ($sync_contacts) {
            try {
                $contacts_result = $this->sync_contacts();
                $msgs[] = sprintf(
                    'Kontakty: +%d utworzono, ~%d zaktualizowano, -%d usunięto',
                    $contacts_result['created'],
                    $contacts_result['updated'],
                    $contacts_result['removed']
                );
                if ($contacts_result['errors']) $ok = false;
            } catch (\Throwable $e) {
                $ok = false;
                $contacts_result['errors'][] = $e->getMessage();
                $msgs[] = 'Kontakty: błąd — ' . $e->getMessage();
            }
        }

        if ($sync_calendar) {
            try {
                $calendar_result = $this->sync_calendar();
                $msgs[] = sprintf(
                    'Kalendarz: +%d utworzono, ~%d zaktualizowano, -%d usunięto',
                    $calendar_result['created'],
                    $calendar_result['updated'],
                    $calendar_result['removed']
                );
                if ($calendar_result['errors']) $ok = false;
            } catch (\Throwable $e) {
                $ok = false;
                $calendar_result['errors'][] = $e->getMessage();
                $msgs[] = 'Kalendarz: błąd — ' . $e->getMessage();
            }
        }

        return [
            'contacts' => $contacts_result,
            'calendar' => $calendar_result,
            'ok'       => $ok,
            'message'  => implode('; ', $msgs) ?: 'Synchronizacja wyłączona w ustawieniach.',
        ];
    }

    /**
     * Pobiera dostępne kalendarze użytkownika M365.
     * @return array Lista kalendarzy [{id, name, isDefault, color}]
     */
    public function get_available_calendars(): array
    {
        $this->assert_user_id();
        return $this->graph->get_calendars($this->user_id);
    }

    /**
     * Resetuje delta-link (wymusi pełny ponowny sync).
     */
    public function reset_delta(string $key = ''): void
    {
        if ($key) {
            $this->pdo->prepare(
                "DELETE FROM crm_outlook_delta WHERE key_ = ?"
            )->execute([$key]);
        } else {
            $this->pdo->exec("DELETE FROM crm_outlook_delta");
        }
    }

    /**
     * Ostatnie wpisy logu synchronizacji (z crm_sync_log).
     */
    public function recent_logs(int $limit = 10): array
    {
        return $this->pdo->query(
            "SELECT * FROM crm_sync_log
             WHERE source IN ('outlook_contacts','outlook_calendar')
             ORDER BY synced_at DESC
             LIMIT {$limit}"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // MIGRACJE DB
    // ══════════════════════════════════════════════════════════════════════════

    private function migrate(): void
    {
        $migrations = [
            "ALTER TABLE crm_contacts ADD COLUMN outlook_id TEXT",
            "ALTER TABLE crm_contacts ADD COLUMN outlook_synced_at DATETIME",
            "ALTER TABLE crm_events   ADD COLUMN outlook_id TEXT",
            "ALTER TABLE crm_events   ADD COLUMN outlook_synced_at DATETIME",
            "CREATE TABLE IF NOT EXISTS crm_outlook_delta (
                key_       TEXT     NOT NULL PRIMARY KEY,
                value      TEXT     NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE INDEX IF NOT EXISTS idx_crm_contacts_outlook_id
                ON crm_contacts(outlook_id)",
            "CREATE INDEX IF NOT EXISTS idx_crm_events_outlook_id
                ON crm_events(outlook_id)",
        ];
        foreach ($migrations as $sql) {
            try { $this->pdo->exec($sql); } catch (\Throwable $e) { /* idempotentne */ }
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POMOCNICZE
    // ══════════════════════════════════════════════════════════════════════════

    private function assert_user_id(): void
    {
        if (!$this->user_id) {
            throw new \RuntimeException(
                'Nie ustawiono użytkownika M365 do synchronizacji. ' .
                'Skonfiguruj "m365_sync_user_id" lub "m365_sender_user_id" w ustawieniach.'
            );
        }
    }

    private function get_delta(string $key): ?string
    {
        $s = $this->pdo->prepare(
            "SELECT value FROM crm_outlook_delta WHERE key_ = ? LIMIT 1"
        );
        $s->execute([$key]);
        return $s->fetchColumn() ?: null;
    }

    private function set_delta(string $key, string $value): void
    {
        $this->pdo->prepare(
            "INSERT INTO crm_outlook_delta (key_, value, updated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(key_) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP"
        )->execute([$key, $value]);
    }

    /**
     * Parsuje obiekt {dateTime, timeZone} z Graph API.
     * @return array [date_string, time_string|null]
     */
    private function parse_dt(array $dt): array
    {
        $raw = $dt['dateTime'] ?? '';
        if (!$raw) return [date('Y-m-d'), null];

        // Może być "2026-06-10T09:00:00.0000000" lub "2026-06-10T09:00:00Z"
        try {
            $tz_name = $dt['timeZone'] ?? 'UTC';
            // Graph zwraca czasem "UTC" i "tZ" bez "Z" na końcu
            if (!str_ends_with($raw, 'Z') && strtolower($tz_name) === 'utc') {
                $raw .= 'Z';
            }
            $ts = new \DateTime($raw, new \DateTimeZone($tz_name));
            // Konwertuj do lokalnej strefy (Europe/Warsaw)
            $local_tz = crm_setting('timezone') ?: 'Europe/Warsaw';
            $ts->setTimezone(new \DateTimeZone($local_tz));
            return [$ts->format('Y-m-d'), $ts->format('H:i:s')];
        } catch (\Throwable $e) {
            return [date('Y-m-d'), null];
        }
    }

    /**
     * Formatuje adres z obiektu Outlooka.
     */
    private function format_address(array $oc): string
    {
        // Priorytety: business > home > other
        foreach (['businessAddress', 'homeAddress', 'otherAddress'] as $key) {
            $addr = $oc[$key] ?? [];
            if (empty($addr)) continue;
            $parts = array_filter([
                $addr['street']      ?? '',
                $addr['postalCode']  ?? '' ? ($addr['postalCode'] . ' ' . ($addr['city'] ?? '')) : ($addr['city'] ?? ''),
                $addr['countryOrRegion'] ?? '',
            ]);
            $line = implode(', ', array_filter($parts));
            if ($line) return $line;
        }
        return '';
    }

    /**
     * Wyciąga treść z obiektu body Outlooka (text/html → plain text).
     */
    private function extract_body(array $body): string
    {
        $content = $body['content'] ?? '';
        if (!$content) return '';

        if (($body['contentType'] ?? '') === 'html') {
            // Zamień BR/P na nowe linie, potem strip_tags
            $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
            $content = preg_replace('/<\/p>/i', "\n", $content);
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim(mb_substr($content, 0, 5000));
    }

    /**
     * Mapuje kategorię Outlooka na kolor hex.
     */
    private function category_to_color(array $categories): string
    {
        $map = [
            'red'     => '#D92D20',
            'orange'  => '#EF6820',
            'yellow'  => '#F7B529',
            'green'   => '#2E844A',
            'teal'    => '#0E7090',
            'blue'    => '#0176D3',
            'purple'  => '#7F2B8B',
            'maroon'  => '#8E1600',
            'steel'   => '#6B7280',
            'darkred' => '#A11C00',
        ];
        // Graph zwraca 'preset0'–'preset24', bez nazw w Application auth
        // Jeśli dostępna kategoria jako słowo — mapuj
        foreach ($categories as $cat) {
            $cat_lc = strtolower($cat);
            foreach ($map as $color_name => $hex) {
                if (str_contains($cat_lc, $color_name)) {
                    return $hex;
                }
            }
        }
        return '#2E844A'; // domyślny zielony
    }

    /**
     * Zapisuje wpis do crm_sync_log.
     */
    private function log_sync(string $type, int $created, int $updated, int $removed, array $errors): void
    {
        $source   = 'outlook_' . $type;
        $total    = $created + $updated + $removed;
        $details  = json_encode([
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'errors'  => $errors,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $this->pdo->prepare(
                "INSERT INTO crm_sync_log (synced_at, records_updated, source, ip, details)
                 VALUES (CURRENT_TIMESTAMP, ?, ?, ?, ?)"
            )->execute([$total, $source, $_SERVER['SERVER_ADDR'] ?? 'cli', $details]);
        } catch (\Throwable $e) { /* nie blokuj */ }
    }
}
