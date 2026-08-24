<?php
/**
 * includes/crm_quick.php — tworzenie wpisów „na skróty" (kontakt / notatka / sprawa / zadanie).
 *
 * Wydzielone z endpointu, bo z tej samej logiki korzystają dwa miejsca:
 *   • crm/api/quick_create.php  — pojedyncza akcja z widżetu szybkich akcji,
 *   • includes/crm_workflows.php — przepływ, czyli kilka takich akcji po kolei.
 *
 * Każda funkcja zwraca ['ok','error','id','url','label'] i NIE przerywa żądania —
 * decyzję, co zrobić z błędem, podejmuje wołający.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm.php';

/** Rodzaje wpisów obsługiwane przez szybkie akcje i przepływy. */
function crm_quick_types(): array {
    return [
        'contact' => ['label' => 'Kontakt', 'field' => 'Imię i nazwisko / nazwa', 'needs_contact' => false],
        'note'    => ['label' => 'Notatka', 'field' => 'Treść notatki',           'needs_contact' => true],
        'case'    => ['label' => 'Sprawa',  'field' => 'Tytuł sprawy',            'needs_contact' => true],
        'task'    => ['label' => 'Zadanie', 'field' => 'Co jest do zrobienia',    'needs_contact' => false],
    ];
}

function _crm_quick_fail(string $msg): array {
    return ['ok' => false, 'error' => $msg, 'id' => 0, 'url' => '', 'label' => ''];
}

/**
 * Tworzy pojedynczy wpis.
 *
 * @param array $in ['type','title','email','contact_id','due_date','owner_id','description']
 * @param int   $uid Autor
 */
function crm_quick_create(array $in, int $uid): array {
    $type       = (string)($in['type'] ?? '');
    $title      = trim((string)($in['title'] ?? ''));
    $contact_id = (int)($in['contact_id'] ?? 0);
    $crm_write  = can_write('crm') || is_admin();

    if ($title === '') return _crm_quick_fail('Wpisz treść — bez tego nie ma czego zapisać.');

    try {
        switch ($type) {
            case 'contact':
                if (!$crm_write) return _crm_quick_fail('Brak uprawnień do CRM.');
                crm_migrate();
                $email = trim((string)($in['email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return _crm_quick_fail('Adres e-mail jest niepoprawny.');
                }
                $id = db_insert('crm_contacts', [
                    'type'            => 'osoba',
                    'status'          => crm_status_default(),
                    'imie_nazwisko'   => mb_substr($title, 0, 200),
                    'email'           => $email ?: null,
                    'avatar_initials' => CrmManager::makeInitials($title),
                    'created_by'      => $uid ?: null,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
                return ['ok' => true, 'error' => '', 'id' => (int)$id, 'label' => 'Kontakt',
                        'url' => APP_URL . '/crm/contact/view.php?id=' . (int)$id];

            case 'note':
                if (!$crm_write) return _crm_quick_fail('Brak uprawnień do CRM.');
                if ($contact_id <= 0) return _crm_quick_fail('Wskaż kontakt, przy którym zapisać notatkę.');
                if (!crm_can_access_contact($contact_id)) return _crm_quick_fail('Brak dostępu do kontaktu.');
                $id = db_insert('crm_notes', [
                    'contact_id' => $contact_id,
                    'body'       => $title,
                    'created_by' => $uid ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return ['ok' => true, 'error' => '', 'id' => (int)$id, 'label' => 'Notatka',
                        'url' => APP_URL . '/crm/contact/view.php?id=' . $contact_id];

            case 'case':
                if (!$crm_write) return _crm_quick_fail('Brak uprawnień do CRM.');
                if ($contact_id <= 0) return _crm_quick_fail('Sprawa musi być przy kontakcie — wskaż go.');
                if (!crm_can_access_contact($contact_id)) return _crm_quick_fail('Brak dostępu do kontaktu.');
                $id = db_insert('crm_cases', [
                    'contact_id'  => $contact_id,
                    'title'       => mb_substr($title, 0, 200),
                    'description' => trim((string)($in['description'] ?? '')) ?: null,
                    'status'      => 'open',
                    'priority'    => 'medium',
                    'created_by'  => $uid ?: null,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
                return ['ok' => true, 'error' => '', 'id' => (int)$id, 'label' => 'Sprawa',
                        'url' => APP_URL . '/crm/cases/view.php?id=' . (int)$id];

            case 'task':
                // Zadanie CRM, nie wiersz w module Zadań — przypomnienie przy kartotece
                // (oddzwonić, wysłać ofertę) nie potrzebuje obszaru, listy ani tablicy
                // zespołu. Zob. includes/crm_tasks.php.
                require_once __DIR__ . '/crm_tasks.php';
                $r = crm_task_add([
                    'title'       => $title,
                    'contact_id'  => $contact_id,
                    'case_id'     => (int)($in['case_id'] ?? 0),
                    'due_date'    => (string)($in['due_date'] ?? ''),
                    'priority'    => (string)($in['priority'] ?? 'medium'),
                    'owner_id'    => (int)($in['owner_id'] ?? 0),
                    'description' => (string)($in['description'] ?? ''),
                ]);
                if (!$r['ok']) return _crm_quick_fail($r['error']);
                return ['ok' => true, 'error' => '', 'id' => (int)$r['id'], 'label' => 'Zadanie',
                        'url' => APP_URL . '/crm/tasks.php?hl=' . (int)$r['id']];

            default:
                return _crm_quick_fail('Nieznany rodzaj wpisu.');
        }
    } catch (\Throwable $e) {
        error_log('[crm_quick_create] ' . $e->getMessage());
        return _crm_quick_fail('Nie udało się zapisać: ' . mb_substr($e->getMessage(), 0, 160));
    }
}

