<?php
/**
 * includes/crm_automation.php — silnik automatyzacji CRM: „zdarzenie → akcja".
 *
 * Zdarzenia i dokładne miejsca wywołania crm_automation_fire() są udokumentowane
 * w każdym pliku, który je wywołuje (CrmManager::createContact/addTag, aktualizacje
 * statusu kontaktu/sprawy, tworzenie sprawy) — nie ma globalnego before/after-save
 * hooka w db_insert()/db_update(), więc pokrycie jest celowo punktowe, nie „wszędzie".
 *
 * Zabezpieczenie przed pętlą: TRWAŁY (nie tylko pamięciowy) guard — pomijamy
 * regułę, jeśli już odpaliła dla tego samego kontaktu w ostatnich 60 sekundach.
 * Chroni to również przed pingpongiem dwóch reguł uruchamianych w osobnych
 * requestach/tickach crona, czego licznik per-request by nie złapał.
 */

require_once __DIR__ . '/crm.php';

const CRM_AUTOMATION_LOOP_WINDOW_SEC = 60;

/** Dopasowuje trigger_config reguły do kontekstu zdarzenia. Pusty config = zawsze pasuje. */
function crm_automation_matches(array $trigger_config, array $context): bool {
    foreach ($trigger_config as $key => $expected) {
        if ($expected === '' || $expected === null) continue; // pole nieustawione w regule = brak filtra
        if (($context[$key] ?? null) != $expected) return false;
    }
    return true;
}

function crm_automation_log(int $automation_id, ?int $contact_id, string $event, string $status, string $detail = ''): void {
    db_insert('crm_automation_log', [
        'automation_id' => $automation_id,
        'contact_id'    => $contact_id,
        'event'         => $event,
        'status'        => $status,
        'detail'        => $detail,
    ]);
}

/**
 * Punkt wejścia — wywoływany z miejsc mutujących dane (patrz komentarze przy wywołaniach).
 *
 * Dwa niezależne zabezpieczenia przed pętlą:
 *  1) $GLOBALS in-memory „w trakcie wykonywania" — reguła A może synchronicznie
 *     wywołać samą siebie w tym samym stosie wywołań (np. add_tag -> addTag() ->
 *     fire('tag_added') -> ta sama reguła) SZYBCIEJ niż zdąży się zapisać log
 *     do bazy, więc sam log w tabeli nie wystarczy — bez tego rekurencja jest
 *     nieskończona (zaobserwowane podczas testów: memory exhausted).
 *  2) Log w crm_automation_log z oknem czasowym — łapie pingpong reguł
 *     odpalanych w OSOBNYCH requestach/tickach crona, gdzie pamięć procesu
 *     PHP już nie istnieje.
 */
function crm_automation_fire(string $event, int $contact_id, array $context = []): void {
    static $in_flight = [];
    crm_migrate();

    $rules = db_all("SELECT * FROM crm_automations WHERE trigger_event=? AND is_active=1", [$event]);
    if ($rules && !isset($context['contact_type'])) {
        // Typ kontaktu dochodzi do kontekstu automatycznie, niezależnie od zdarzenia —
        // tak reguła może np. pominąć „kontakt techniczny" (adres noreply/system),
        // bez powtarzania tego samego pola w każdym wywołującym miejscu.
        $c = db_one("SELECT type FROM crm_contacts WHERE id=?", [$contact_id]);
        $context['contact_type'] = $c['type'] ?? null;
    }
    foreach ($rules as $rule) {
        $trigger_config = json_decode($rule['trigger_config'] ?: '{}', true) ?: [];
        if (!crm_automation_matches($trigger_config, $context)) continue;

        $key = $rule['id'] . '_' . $contact_id;
        if (isset($in_flight[$key])) {
            crm_automation_log((int)$rule['id'], $contact_id, $event, 'skipped_loop_guard',
                'Reguła już wykonuje się dla tego kontaktu w tym samym łańcuchu wywołań.');
            continue;
        }

        $recent = db_one(
            "SELECT COUNT(*) AS n FROM crm_automation_log
             WHERE automation_id=? AND contact_id=? AND created_at > datetime('now', ?)",
            [$rule['id'], $contact_id, '-' . CRM_AUTOMATION_LOOP_WINDOW_SEC . ' seconds']
        );
        if ((int)($recent['n'] ?? 0) > 0) {
            crm_automation_log((int)$rule['id'], $contact_id, $event, 'skipped_loop_guard',
                'Reguła już odpaliła dla tego kontaktu w ostatnich ' . CRM_AUTOMATION_LOOP_WINDOW_SEC . 's.');
            continue;
        }

        $in_flight[$key] = true;
        try {
            crm_automation_run_action($rule, $contact_id, $context);
            db()->prepare("UPDATE crm_automations SET run_count=run_count+1, last_run_at=datetime('now') WHERE id=?")
                ->execute([$rule['id']]);
            crm_automation_log((int)$rule['id'], $contact_id, $event, 'ok');
        } catch (\Throwable $e) {
            crm_automation_log((int)$rule['id'], $contact_id, $event, 'error', $e->getMessage());
        }
    }
}

function crm_automation_run_action(array $rule, int $contact_id, array $context): void {
    $cfg     = json_decode($rule['action_config'] ?: '{}', true) ?: [];
    $contact = db_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$contact) throw new \RuntimeException('Kontakt nie istnieje.');

    switch ($rule['action_type']) {
        case 'send_email_template':
            // RODO — respektuj globalne wypisanie (ta sama flaga co kampanie mailowe)
            if (!empty($contact['email_opt_out']) || empty($contact['email'])) return;
            // Kontakt techniczny to adres noreply/system — wysyłka tam nic nie da,
            // a często odbija się jako niedostarczona.
            if (($contact['type'] ?? '') === 'kontakt_techniczny') return;
            $tpl = db_one("SELECT * FROM crm_templates WHERE id=? AND is_active=1", [(int)($cfg['template_id'] ?? 0)]);
            if (!$tpl) return;
            require_once __DIR__ . '/mail_queue.php';
            $subject = CrmManager::renderTemplate($tpl['subject'] ?? '', $contact);
            $body    = CrmManager::renderTemplate($tpl['body'] ?? '', $contact);
            mail_queue_add($contact['email'], $contact['imie_nazwisko'] ?? '', $subject ?: 'Wiadomość', $body, '', 'crm_automation', (int)$rule['id'], '', false);
            db_insert('crm_communications', [
                'contact_id'    => $contact_id,
                'channel'       => 'email',
                'direction'     => 'out',
                'template_name' => 'automatyzacja:' . $rule['name'],
                'subject'       => $subject,
                'body'          => $body,
                'status'        => 'w kolejce',
                'sent_by'       => $rule['created_by'],
                'sent_at'       => date('Y-m-d H:i:s'),
            ]);
            break;

        case 'add_tag':
            // CrmManager::addTag() sam odpala zdarzenie tag_added — nie duplikujemy tu.
            if (!empty($cfg['tag'])) CrmManager::addTag($contact_id, $cfg['tag']);
            break;

        case 'remove_tag':
            if (!empty($cfg['tag'])) CrmManager::removeTag($contact_id, $cfg['tag']);
            break;

        case 'create_activity':
            db_insert('crm_activities', [
                'contact_id'   => $contact_id,
                'type'         => 'task',
                'title'        => $cfg['title'] ?? 'Zadanie z automatyzacji',
                'description'  => 'Utworzone automatycznie przez regułę „' . $rule['name'] . '".',
                'status'       => 'planned',
                'created_by'   => $rule['created_by'],
            ]);
            break;

        case 'change_contact_status':
            if (!empty($cfg['status']) && $cfg['status'] !== $contact['status']) {
                $from = $contact['status'];
                db()->prepare("UPDATE crm_contacts SET status=?, updated_at=datetime('now') WHERE id=?")
                    ->execute([$cfg['status'], $contact_id]);
                crm_automation_fire('contact_status_changed', $contact_id, ['from_status' => $from, 'to_status' => $cfg['status']]);
            }
            break;
    }
}
