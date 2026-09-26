<?php
/**
 * includes/crm_form_intake.php — przyjęcie zgłoszenia z formularza.
 *
 * Ta warstwa powstała, bo obsługa zgłoszenia siedziała w całości w
 * crm/form/index.php, czyli w pliku renderującym stronę. Dopóki formularze
 * były tylko wewnętrzne, nikomu to nie przeszkadzało. Gdy zgłoszenie ma
 * przyjść z zewnątrz — z CMS-a przez API — musi przejść DOKŁADNIE tą samą
 * ścieżką: grupa, rejestr zgód, pola niestandardowe, powiadomienie,
 * automatyzacje. Druga implementacja obok pierwszej rozjechałaby się przy
 * pierwszej zmianie w którejkolwiek z nich.
 *
 * Przy okazji doszło znajdowanie istniejącego kontaktu po adresie e-mail.
 * Stara ścieżka ZAWSZE robiła INSERT, więc ten sam człowiek wypełniający
 * formularz dwa razy zostawiał w bazie dwie kartoteki. Przy formularzu na
 * stronie publicznej, do którego wraca się po tygodniu, to nie jest przypadek
 * brzegowy, tylko norma.
 */

declare(strict_types=1);

require_once __DIR__ . '/crm.php';

/** Samonaprawa schematu: kolumna z konfiguracją skutków przyjęcia zgłoszenia. */
function crm_form_intake_schema_heal(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE crm_web_forms ADD COLUMN intake_json TEXT DEFAULT '{}'"); }
    catch (\Throwable $e) { /* kolumna już jest */ }

    // Dziennik przyjęć — bez niego integracja z zewnętrznym CMS-em jest
    // nieprzejrzysta: gdy zgłoszenie nie dotrze, nie ma gdzie zajrzeć.
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_form_intake_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            source      TEXT    NOT NULL DEFAULT '',
            form_slug   TEXT    NOT NULL DEFAULT '',
            contact_id  INTEGER,
            inbox_id    INTEGER,
            status      TEXT    NOT NULL DEFAULT 'ok',
            error       TEXT    NOT NULL DEFAULT '',
            payload     TEXT    NOT NULL DEFAULT '',
            remote_ip   TEXT    NOT NULL DEFAULT '',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_intake_log ON crm_form_intake_log(created_at)");
    } catch (\Throwable $e) {}
}

/** Wpis do dziennika przyjęć. */
function crm_form_intake_log(array $row): void
{
    crm_form_intake_schema_heal();
    try {
        db_insert('crm_form_intake_log', [
            'source'     => (string)($row['source']    ?? ''),
            'form_slug'  => (string)($row['form_slug'] ?? ''),
            'contact_id' => $row['contact_id'] ?? null,
            'inbox_id'   => $row['inbox_id']   ?? null,
            'status'     => (string)($row['status'] ?? 'ok'),
            'error'      => mb_substr((string)($row['error'] ?? ''), 0, 500),
            'payload'    => mb_substr(json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE), 0, 4000),
            'remote_ip'  => (string)($row['remote_ip'] ?? ''),
        ]);
    } catch (\Throwable $e) {}
}

/**
 * Domyślne skutki uboczne przyjęcia zgłoszenia.
 *
 * Samo założenie kartoteki nikogo nie informuje, że ktoś czeka na odpowiedź.
 * Zgłoszenie z formularza jest wiadomością od człowieka i ma trafić tam, gdzie
 * zespół faktycznie pracuje — do Skrzynki CRM. Stąd `inbox`: wiersz w
 * crm_communications z direction='in', nierozpoznany od maila poza numerem
 * i etykietą źródła. Nie „udaje" maila w sensie fałszowania nadawcy — adres
 * jest prawdziwy, ten z formularza; udaje tylko KANAŁ, żeby zgłoszenie miało
 * ten sam obieg co korespondencja.
 */
function crm_form_intake_defaults(): array
{
    return [
        'inbox'         => true,   // wiersz w Skrzynce CRM
        'activity'      => true,   // działanie „odpowiedzieć" w crm_activities
        'activity_days' => 1,      // termin działania: jutro
        'task'          => false,  // zadanie w module Zadania (wymaga listy)
        'task_list_id'  => 0,
        'assign_to'     => 0,      // kto dostaje działanie/zadanie i zgłoszenie w skrzynce
    ];
}

/** Konfiguracja skutków dla formularza: z intake_json, uzupełniona domyślnymi. */
function crm_form_intake_config(array $form, array $override = []): array
{
    $cfg = json_decode((string)($form['intake_json'] ?? '{}'), true) ?: [];
    return array_merge(crm_form_intake_defaults(), $cfg, $override);
}


/**
 * Przyjmuje zgłoszenie i zakłada albo aktualizuje kontakt.
 *
 * @param array $form  wiersz z crm_web_forms
 * @param array $data  wartości pól standardowych (email, imie_nazwisko, telefon…)
 * @param array $opts  custom  => [field_def_id => wartość]
 *                     consents=> [id zgody, …] zaznaczone w formularzu
 *                     source  => nadpisanie crm_contacts.source (np. „cms:kontakt")
 *                     ip      => adres, z którego przyszło zgłoszenie
 *                     dedupe  => szukaj istniejącego kontaktu po e-mailu (domyślnie tak)
 *
 * @return array{ok:bool,contact_id:int,created:bool,error:?string}
 */
function crm_form_intake(array $form, array $data, array $opts = []): array
{
    crm_migrate();
    crm_form_intake_schema_heal();

    $custom   = (array)($opts['custom']   ?? []);
    $consents = (array)($opts['consents'] ?? []);
    $ip       = $opts['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $dedupe   = $opts['dedupe'] ?? true;
    $source   = (string)($opts['source'] ?? ('webform:' . $form['slug']));

    $consents_config = json_decode((string)($form['consents_json']   ?? '[]'), true) ?: [];
    $automations     = json_decode((string)($form['automations_json'] ?? '[]'), true) ?: [];

    // Odsiewamy klucze, których nie ma w tabeli — formularz może przysłać pole,
    // którego CRM nie zna, i to nie powód, żeby odrzucić całe zgłoszenie.
    $allowed = [];
    try {
        foreach (db_all("SELECT name FROM pragma_table_info('crm_contacts')") as $col) $allowed[] = $col['name'];
    } catch (\Throwable $e) { $allowed = ['imie_nazwisko','email','telefon','organizacja','notatka','adres']; }

    $fields = [];
    foreach ($data as $k => $v) {
        $v = is_string($v) ? trim($v) : $v;
        if ($v === '' || $v === null)              continue;
        if (!in_array($k, $allowed, true))         continue;
        if (in_array($k, ['id','crm_active','created_at','updated_at'], true)) continue;
        $fields[$k] = $v;
    }

    if (empty($fields['imie_nazwisko']) && empty($fields['email'])) {
        return ['ok' => false, 'contact_id' => 0, 'created' => false,
                'error' => 'Zgłoszenie bez nazwy i bez adresu e-mail — nie ma czego zapisać.'];
    }
    if (!empty($fields['email']) && !filter_var((string)$fields['email'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'contact_id' => 0, 'created' => false, 'error' => 'Nieprawidłowy adres e-mail.'];
    }
    if (empty($fields['imie_nazwisko'])) $fields['imie_nazwisko'] = (string)$fields['email'];

    // ── Kontakt: znajdź albo utwórz ───────────────────────────────────────
    $existing = null;
    if ($dedupe && !empty($fields['email'])) {
        $existing = db_one(
            "SELECT * FROM crm_contacts WHERE crm_active=1 AND lower(email)=lower(?) ORDER BY id LIMIT 1",
            [(string)$fields['email']]
        );
    }

    if ($existing) {
        $contact_id = (int)$existing['id'];
        // Uzupełniamy WYŁĄCZNIE puste pola. Formularz na stronie publicznej nie
        // jest wiarygodniejszy od kartoteki prowadzonej przez zespół — nie ma
        // prawa nadpisać telefonu, który ktoś wprowadził ręcznie.
        $fill = [];
        foreach ($fields as $k => $v) {
            if ($k === 'imie_nazwisko') continue;
            $cur = $existing[$k] ?? null;
            if ($cur === null || $cur === '') $fill[$k] = $v;
        }
        if ($fill) CrmManager::updateContact($contact_id, $fill);
        CrmManager::addNote($contact_id, sprintf(
            'Ponowne zgłoszenie przez formularz „%s” (%s).%s',
            $form['title'], date('Y-m-d H:i'),
            $fill ? ' Uzupełniono: ' . implode(', ', array_keys($fill)) . '.' : ''
        ), null);
        $created = false;
    } else {
        $fields['type']       = $fields['type']   ?? 'osoba';
        $fields['status']     = $form['default_status'] ?: 'prospect';
        $fields['source']     = $source;
        $fields['crm_active'] = 1;
        $fields['created_at'] = date('Y-m-d H:i:s');
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $contact_id = (int)crm_insert('crm_contacts', $fields);
        if (!$contact_id) {
            return ['ok' => false, 'contact_id' => 0, 'created' => false, 'error' => 'Nie udało się zapisać kontaktu.'];
        }
        require_once __DIR__ . '/crm_automation.php';
        crm_automation_fire('contact_created', $contact_id);
        $created = true;
    }

    // ── Grupa formularza ──────────────────────────────────────────────────
    if (!empty($form['group_id'])) {
        try {
            crm_insert('crm_group_members', [
                'group_id'   => (int)$form['group_id'],
                'contact_id' => $contact_id,
                'added_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* już w grupie */ }
    }

    // ── Zgody ─────────────────────────────────────────────────────────────
    // Zgoda z przypisanym celem idzie do rejestru (cel, moment, źródło, IP,
    // kopia klauzuli). Bez celu zostaje tagiem — formularze niezmapowane na
    // katalog celów mają działać jak dotąd.
    require_once __DIR__ . '/crm_consent.php';
    $by_id = [];
    foreach ($consents_config as $con) $by_id[(string)($con['id'] ?? '')] = $con;

    foreach ($consents as $cid) {
        $con = $by_id[(string)$cid] ?? null;
        $pid = (int)($con['purpose_id'] ?? 0);
        try {
            if ($pid > 0) {
                $consent_row_id = crm_consent_record($contact_id, $pid, true, [
                    'source'        => 'formularz',
                    'source_detail' => 'Formularz: ' . $form['title'],
                    'klauzula'      => (string)($con['text'] ?? ''),
                    'ip'            => $ip,
                ]);
                // Klauzula informacyjna z rejestru — dowód, którą wersję widziała osoba.
                $gslug = crm_consent_gdpr_slug(crm_one("SELECT * FROM crm_consent_purposes WHERE id = ?", [$pid]));
                if ($gslug !== '') {
                    gdpr_clause_accept_from_post($gslug, 'crm_form',
                        ['name' => (string)($fields['imie_nazwisko'] ?? ''), 'email' => (string)($fields['email'] ?? '')],
                        ['crm_consent', (int)$consent_row_id]);
                }
            } else {
                crm_insert('crm_tags', ['contact_id' => $contact_id, 'tag' => 'zgoda:' . $cid,
                                        'created_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $e) {}
    }

    // ── Pola niestandardowe ───────────────────────────────────────────────
    if ($custom) {
        $clean = [];
        foreach ($custom as $did => $val) {
            $did = (int)$did;
            if ($did > 0 && $val !== '' && $val !== null) $clean[$did] = (string)$val;
        }
        if ($clean) CrmManager::saveFieldValues($contact_id, $clean);
    }

    // ── Licznik i powiadomienie ───────────────────────────────────────────
    try { db()->prepare("UPDATE crm_web_forms SET submissions=submissions+1 WHERE id=?")->execute([(int)$form['id']]); }
    catch (\Throwable $e) {}

    if (!empty($form['notify_email']) && filter_var((string)$form['notify_email'], FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/approval.php';
        $url  = APP_URL . '/crm/contact/view.php?id=' . $contact_id;
        $rows = '';
        foreach ($data as $k => $v) {
            if ($v === '' || $v === null) continue;
            $rows .= "<tr><td style='padding:3px 12px 3px 0;color:#555'>" . h((string)$k)
                   . ":</td><td><strong>" . h((string)$v) . "</strong></td></tr>";
        }
        $body = "<p>" . ($created ? 'Nowe zgłoszenie' : 'Ponowne zgłoszenie') . " przez formularz <strong>"
              . h((string)$form['title']) . "</strong>:</p><table style='border-collapse:collapse;font-size:.9rem'>"
              . $rows . "</table><p><a href='{$url}'>Otwórz w CRM →</a></p>";
        try {
            approval_send_email((string)$form['notify_email'],
                ($created ? 'Nowe zgłoszenie: ' : 'Ponowne zgłoszenie: ') . $form['title'],
                $body, 'crm_webform', (int)$form['id']);
        } catch (\Throwable $e) {}
    }

    // ── Skutki uboczne: skrzynka, działanie, zadanie ──────────────────────
    $cfg  = crm_form_intake_config($form, (array)($opts['intake'] ?? []));
    $side = crm_form_intake_side_effects($form, $contact_id, $data, $cfg, $ip);

    // ── Automatyzacje formularza ──────────────────────────────────────────
    run_form_automations($automations, $contact_id, $data, $form);

    return [
        'ok'         => true,
        'contact_id' => $contact_id,
        'created'    => $created,
        'error'      => null,
    ] + $side;
}

/**
 * Zgłoszenie jako wiadomość w Skrzynce, działanie i zadanie.
 *
 * Każdy skutek osobno w try — jeśli moduł Zadań jest wyłączony albo skrzynka
 * jeszcze nie zmigrowana, zgłoszenie i tak ma zostać przyjęte. Utrata
 * kartoteki dlatego, że nie udało się założyć zadania, byłaby absurdem.
 *
 * @return array{inbox_id:int,activity_id:int,task_id:int}
 */
function crm_form_intake_side_effects(array $form, int $contact_id, array $data, array $cfg, ?string $ip): array
{
    $out = ['inbox_id' => 0, 'activity_id' => 0, 'task_id' => 0];

    $contact   = db_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]) ?: [];
    $from_mail = (string)($data['email'] ?? ($contact['email'] ?? ''));
    $from_name = (string)($data['imie_nazwisko'] ?? ($contact['imie_nazwisko'] ?? 'Formularz'));
    $subject   = 'Formularz: ' . (string)$form['title'];
    $assign    = (int)($cfg['assign_to'] ?? 0) ?: null;

    // Treść: wszystkie pola zgłoszenia, w kolejności z formularza.
    $lines = [];
    foreach ($data as $k => $v) {
        if ($v === '' || $v === null) continue;
        $lines[] = '<tr><td style="padding:2px 12px 2px 0;color:#6B7280;vertical-align:top">'
                 . h((string)$k) . '</td><td><strong>' . nl2br(h((string)$v)) . '</strong></td></tr>';
    }
    $body_html = '<p>Zgłoszenie przez formularz <strong>' . h((string)$form['title']) . '</strong>'
               . ($ip ? ' (IP ' . h($ip) . ')' : '') . ':</p>'
               . '<table style="border-collapse:collapse;font-size:.9rem">' . implode('', $lines) . '</table>';

    // ── 1. Skrzynka CRM ───────────────────────────────────────────────────
    if (!empty($cfg['inbox'])) {
        try {
            require_once __DIR__ . '/crm_mailbox.php';
            if (function_exists('crm_mailbox_schema_heal')) crm_mailbox_schema_heal();
            $iid = (int)db_insert('crm_communications', [
                'contact_id'   => $contact_id,
                'channel'      => 'email',
                'direction'    => 'in',
                'subject'      => $subject,
                'body'         => strip_tags(str_replace(['</td>', '</tr>'], [': ', "\n"], $body_html)),
                'body_html'    => $body_html,
                'status'       => 'odebrana',
                'sent_at'      => date('Y-m-d H:i:s'),
                'inbox_status' => 'active',
                'is_read'      => 0,
                'assigned_to'  => $assign,
                'from_name'    => $from_name,
                'from_email'   => $from_mail ?: null,
                // Wątek po formularzu i adresie: kolejne zgłoszenia tej samej
                // osoby z tego samego formularza skleją się w jedną rozmowę.
                'thread_key'   => 'form:' . $form['slug'] . ':' . mb_strtolower($from_mail),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            if ($iid && function_exists('crm_msg_no')) crm_msg_no($iid);
            $out['inbox_id'] = $iid;
        } catch (\Throwable $e) { error_log('[form_intake:inbox] ' . $e->getMessage()); }
    }

    // ── 2. Działanie „odpowiedzieć" ───────────────────────────────────────
    if (!empty($cfg['activity'])) {
        try {
            $days = max(0, (int)($cfg['activity_days'] ?? 1));
            $out['activity_id'] = (int)db_insert('crm_activities', [
                'contact_id'   => $contact_id,
                'type'         => 'task',
                'title'        => 'Odpowiedzieć na zgłoszenie: ' . (string)$form['title'],
                'description'  => strip_tags(str_replace(['</td>', '</tr>'], [': ', "\n"], $body_html)),
                'scheduled_at' => date('Y-m-d H:i:s', strtotime("+{$days} days 09:00")),
                'status'       => 'planned',
                'assigned_to'  => $assign,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('[form_intake:activity] ' . $e->getMessage()); }
    }

    // ── 3. Zadanie w module Zadania ───────────────────────────────────────
    // Tylko gdy wskazano listę: zadanie bez listy nie ma gdzie się pokazać,
    // więc zamiast zgadywać, po cichu pomijamy.
    if (!empty($cfg['task']) && !empty($cfg['task_list_id'])) {
        try {
            $list = db_one("SELECT id, workspace_id FROM task_lists WHERE id=?", [(int)$cfg['task_list_id']]);
            if ($list) {
                $days = max(0, (int)($cfg['activity_days'] ?? 1));
                $out['task_id'] = (int)db_insert('tasks', [
                    'workspace_id' => (int)$list['workspace_id'],
                    'list_id'      => (int)$list['id'],
                    'title'        => 'Zgłoszenie z formularza: ' . (string)$form['title'],
                    'description'  => strip_tags(str_replace(['</td>', '</tr>'], [': ', "\n"], $body_html))
                                      . "\n\nKontakt w CRM: " . APP_URL . '/crm/contact/view.php?id=' . $contact_id,
                    'priority'     => 'medium',
                    'due_date'     => date('Y-m-d', strtotime("+{$days} days")),
                    'created_by'   => $assign,
                    'created_at'   => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) { error_log('[form_intake:task] ' . $e->getMessage()); }
    }

    return $out;
}

/**
 * Wykonaj automatyzacje formularza po pomyślnym zapisie kontaktu.
 */
function run_form_automations(array $autos, int $contact_id, array $data, array $form): void {
    if (!$autos) return;
    require_once __DIR__ . '/approval.php';

    $contact = crm_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);

    foreach ($autos as $auto) {
        if (empty($auto['enabled'])) continue;
        $type = $auto['type'] ?? '';
        $cfg  = $auto['config'] ?? [];

        try {
            switch ($type) {

                case 'send_email':
                    // Email do zgłaszającego
                    $to = $data['email'] ?? ($contact['email'] ?? '');
                    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) break;
                    $subject = CrmManager::renderTemplate($cfg['subject'] ?? 'Twoje zgłoszenie', $contact);
                    $body_raw = $cfg['body'] ?? '';
                    $body_html = nl2br(htmlspecialchars(CrmManager::renderTemplate($body_raw, $contact)));
                    approval_send_email($to, $subject, '<html><body style="font-family:sans-serif">' . $body_html . '</body></html>', 'crm_webform', (int)$form['id']);
                    break;

                case 'send_notification':
                    $to = $cfg['email'] ?? '';
                    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) break;
                    $subject = CrmManager::renderTemplate($cfg['subject'] ?? 'Nowe zgłoszenie', $contact);
                    $rows_html = '';
                    foreach ($data as $k => $v) {
                        if ($v) $rows_html .= "<tr><td style='padding:3px 12px 3px 0;color:#555'>{$k}:</td><td><strong>" . htmlspecialchars((string)$v) . "</strong></td></tr>";
                    }
                    $body = "<p>Nowe zgłoszenie przez formularz <strong>" . htmlspecialchars($form['title']) . "</strong>:</p><table>{$rows_html}</table>";
                    approval_send_email($to, $subject, $body, 'crm_webform', (int)$form['id']);
                    break;

                case 'add_tag':
                    $tags = array_filter(array_map('trim', explode(',', $cfg['tags'] ?? '')));
                    foreach ($tags as $tag) {
                        try { CrmManager::addTag($contact_id, $tag); } catch (\Throwable $e) {}
                    }
                    break;

                case 'set_status':
                    $status = trim($cfg['status'] ?? '');
                    if ($status && array_key_exists($status, crm_statuses()) && $status !== ($contact['status'] ?? null)) {
                        $from_status = $contact['status'] ?? null;
                        crm_db()->prepare("UPDATE crm_contacts SET status=? WHERE id=?")->execute([$status, $contact_id]);
                        require_once __DIR__ . '/crm_automation.php';
                        crm_automation_fire('contact_status_changed', $contact_id, ['from_status' => $from_status, 'to_status' => $status]);
                    }
                    break;

                case 'nozbe_task':
                    require_once __DIR__ . '/nozbe.php';
                    if (nozbe_setting('nozbe_enabled') !== '1') break;
                    $nz = NozbeAPI::from_settings();
                    if (!$nz->is_configured()) break;
                    $task_name  = CrmManager::renderTemplate($cfg['task_name'] ?? 'Zgłoszenie: {imie_nazwisko}', $contact);
                    $project_id = $cfg['project_id'] ?: nozbe_setting('nozbe_default_project_id');
                    $due_days   = max(0, (int)($cfg['due_days'] ?? 1));
                    $due_date   = $due_days ? date('Y-m-d', strtotime("+{$due_days} days")) : null;
                    $desc       = "Formularz: {$form['title']}\nKontakt: {$contact['imie_nazwisko']}\nE-mail: {$contact['email']}";
                    if ($project_id) $nz->create_task($task_name, $project_id, $desc, $due_date);
                    break;

                case 'webhook':
                    $url = trim($cfg['url'] ?? '');
                    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) break;
                    $payload = json_encode([
                        'form_id'    => (int)$form['id'],
                        'form_slug'  => $form['slug'],
                        'contact_id' => $contact_id,
                        'data'       => $data,
                        'timestamp'  => date('c'),
                    ]);
                    $headers = ['Content-Type: application/json'];
                    if (!empty($cfg['secret'])) {
                        $sig = hash_hmac('sha256', $payload, $cfg['secret']);
                        $headers[] = 'X-Form-Secret: ' . $sig;
                    }
                    $ctx = stream_context_create(['http'=>[
                        'method'  => 'POST',
                        'header'  => implode("\r\n", $headers),
                        'content' => $payload,
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ]]);
                    @file_get_contents($url, false, $ctx);
                    break;
            }
        } catch (\Throwable $e) {
            error_log("[form_automation:{$type}] {$e->getMessage()}");
        }
    }
}
