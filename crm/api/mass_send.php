<?php
/**
 * crm/api/mass_send.php — Mass mailing/SMS do grupy lub tagów.
 * POST: { action:'start', group_id, tag_filter, channel, subject, body, template_name }
 * POST: { action:'status', send_id }
 * POST: { action:'execute', send_id }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';

header('Content-Type: application/json; charset=utf-8');

function api_ok(mixed $d = null): never { echo json_encode(['ok'=>true,'data'=>$d],JSON_UNESCAPED_UNICODE); exit; }
function api_err(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m],JSON_UNESCAPED_UNICODE); exit; }

if (!current_user()) api_err('Wymagane logowanie.',401);
if (!can_write('crm') && !is_admin()) api_err('Brak uprawnień.',403);
crm_migrate();

$body   = json_decode(file_get_contents('php://input'),true) ?? $_POST;
$action = $body['action'] ?? '';
$uid    = (int)(current_user()['id'] ?? 0);

// ── Zbierz odbiorców ──────────────────────────────────────────────────────────
function collect_recipients(array $body): array {
    $ids = [];

    // Z grupy (i podgrup)
    $group_id = (int)($body['group_id'] ?? 0);
    if ($group_id) {
        // Bezpośredni członkowie
        $rows = db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [$group_id]);
        foreach ($rows as $r) $ids[] = (int)$r['contact_id'];

        // Połączone grupy (child groups)
        $linked = db_all(
            "SELECT gm.contact_id FROM crm_group_members gm
             JOIN crm_group_links gl ON gl.child_group_id=gm.group_id
             WHERE gl.parent_group_id=?", [$group_id]
        );
        foreach ($linked as $r) $ids[] = (int)$r['contact_id'];

        // Podgrupy (parent_id)
        $sub_groups = db_all("SELECT id FROM crm_groups WHERE parent_id=?", [$group_id]);
        foreach ($sub_groups as $sg) {
            $sub_members = db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [(int)$sg['id']]);
            foreach ($sub_members as $r) $ids[] = (int)$r['contact_id'];
        }
    }

    // Z tagów
    $tags = array_filter(array_map('trim', explode(',', $body['tag_filter'] ?? '')));
    foreach ($tags as $tag) {
        $rows = db_all("SELECT contact_id FROM crm_tags WHERE tag=?", [$tag]);
        foreach ($rows as $r) $ids[] = (int)$r['contact_id'];
    }

    // Z listy ID
    foreach ((array)($body['contact_ids'] ?? []) as $cid) {
        $ids[] = (int)$cid;
    }

    return array_values(array_unique(array_filter($ids)));
}

if ($action === 'preview') {
    $ids = collect_recipients($body);
    $channel = $body['channel'] ?? 'email';
    // Filtruj wg kanału — tylko kontakty z wymaganymi danymi
    $valid = [];
    foreach ($ids as $cid) {
        $c = db_one("SELECT id, imie_nazwisko, email, telefon FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
        if (!$c) continue;
        if ($channel === 'email' && !$c['email']) continue;
        if ($channel === 'sms'   && !$c['telefon']) continue;
        $valid[] = ['id'=>(int)$c['id'],'name'=>$c['imie_nazwisko'],'email'=>$c['email'],'telefon'=>$c['telefon']];
    }
    api_ok(['count'=>count($valid),'contacts'=>array_slice($valid,0,20),'total'=>count($valid)]);
}

if ($action === 'start') {
    $ids     = collect_recipients($body);
    $channel = in_array($body['channel']??'', ['email','sms']) ? $body['channel'] : 'email';
    $subject = trim($body['subject'] ?? '');
    $msg     = trim($body['body']    ?? '');
    $tpl     = trim($body['template_name'] ?? '');

    if (empty($ids))   api_err('Brak odbiorców.');
    if (!$msg)         api_err('Treść wiadomości jest wymagana.');
    if ($channel === 'email' && !$subject) api_err('Temat jest wymagany dla e-maila.');

    $send_id = db_insert('crm_mass_sends', [
        'group_id'      => ($body['group_id'] ?? 0) ?: null,
        'channel'       => $channel,
        'subject'       => $subject ?: null,
        'body'          => $msg,
        'template_name' => $tpl ?: null,
        'recipients'    => count($ids),
        'sent_ok'       => 0,
        'sent_fail'     => 0,
        'status'        => 'ready',
        'created_by'    => $uid,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);

    // Zapisz listę odbiorców (w body jako JSON — lekkie rozwiązanie dla SQLite)
    db()->prepare("UPDATE crm_mass_sends SET body=? WHERE id=?")
        ->execute([json_encode(['body'=>$msg,'ids'=>$ids]), $send_id]);

    api_ok(['send_id'=>$send_id,'recipients'=>count($ids)]);
}

if ($action === 'execute') {
    $send_id = (int)($body['send_id'] ?? 0);
    $ms = db_one("SELECT * FROM crm_mass_sends WHERE id=?", [$send_id]);
    if (!$ms) api_err('Nie znaleziono wysyłki.',404);
    if ($ms['status'] === 'done') api_ok(['status'=>'done','sent_ok'=>$ms['sent_ok'],'sent_fail'=>$ms['sent_fail']]);

    db()->prepare("UPDATE crm_mass_sends SET status='sending' WHERE id=?")->execute([$send_id]);

    $payload  = json_decode($ms['body'], true);
    $ids      = $payload['ids']  ?? [];
    $msg_body = $payload['body'] ?? $ms['body'];
    $channel  = $ms['channel'];
    $subject  = $ms['subject'] ?? '';
    $tpl      = $ms['template_name'] ?? '';

    $ok = $fail = 0;
    foreach ($ids as $cid) {
        $contact = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [(int)$cid]);
        if (!$contact) { $fail++; continue; }
        if ($channel === 'email' && !$contact['email']) { $fail++; continue; }
        if ($channel === 'sms'   && !$contact['telefon']) { $fail++; continue; }

        $rendered_body    = CrmManager::renderTemplate($msg_body, $contact);
        $rendered_subject = CrmManager::renderTemplate($subject, $contact);

        try {
            CrmManager::sendAndLog((int)$cid, $channel, $rendered_body, $rendered_subject, $tpl, true);
            $ok++;
        } catch (\Throwable $e) {
            $fail++;
        }
    }

    db()->prepare("UPDATE crm_mass_sends SET status='done',sent_ok=?,sent_fail=?,finished_at=? WHERE id=?")
        ->execute([$ok, $fail, date('Y-m-d H:i:s'), $send_id]);

    api_ok(['status'=>'done','sent_ok'=>$ok,'sent_fail'=>$fail,'total'=>count($ids)]);
}

if ($action === 'status') {
    $send_id = (int)($body['send_id'] ?? 0);
    $ms = db_one("SELECT status,sent_ok,sent_fail,recipients FROM crm_mass_sends WHERE id=?", [$send_id]);
    if (!$ms) api_err('Nie znaleziono.',404);
    api_ok($ms);
}

api_err('Nieznana akcja.');
