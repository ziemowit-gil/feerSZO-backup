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

    // Z wielu grup (group_ids) lub pojedynczej (group_id)
    $group_ids = array_filter(array_map('intval', (array)($body['group_ids'] ?? [])));
    if (!$group_ids && ($body['group_id'] ?? 0)) {
        $group_ids = [(int)$body['group_id']];
    }

    foreach ($group_ids as $group_id) {
        $rows = db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [$group_id]);
        foreach ($rows as $r) $ids[] = (int)$r['contact_id'];

        $linked = db_all(
            "SELECT gm.contact_id FROM crm_group_members gm
             JOIN crm_group_links gl ON gl.child_group_id=gm.group_id
             WHERE gl.parent_group_id=?", [$group_id]
        );
        foreach ($linked as $r) $ids[] = (int)$r['contact_id'];

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

    // Z listy ID (indywidualne kontakty)
    foreach ((array)($body['contact_ids'] ?? []) as $cid) {
        $ids[] = (int)$cid;
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Nadpisania adresata: { contact_id: person_id }. Operator może dla każdego
 * podmiotu wskazać inną osobę kontaktową niż domyślna.
 *
 * @return array<int,int>
 */
function recipient_overrides(array $body): array
{
    $out = [];
    foreach ((array)($body['person_overrides'] ?? []) as $cid => $pid) {
        $cid = (int)$cid; $pid = (int)$pid;
        if ($cid > 0 && $pid > 0) $out[$cid] = $pid;
    }
    return $out;
}

if ($action === 'preview') {
    $ids       = collect_recipients($body);
    $channel   = $body['channel'] ?? 'email';
    $overrides = recipient_overrides($body);

    // Adres bierzemy z resolvera, nie wprost z kontaktu: firma bez adresu ogólnego,
    // ale z e-mailem osoby kontaktowej, jest prawidłowym odbiorcą.
    $valid = [];
    foreach ($ids as $cid) {
        $c = db_one("SELECT id, imie_nazwisko, email, telefon FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
        if (!$c) continue;

        $to = crm_contact_recipient((int)$c['id'], $overrides[(int)$c['id']] ?? null, $c);
        if ($channel === 'email' && $to['email']   === '') continue;
        if ($channel === 'sms'   && $to['telefon'] === '') continue;

        // Lista osób do wyboru w interfejsie — tylko te, do których da się napisać.
        $persons = [];
        foreach (CrmManager::getContactPersons((int)$c['id']) as $p) {
            $addr = $channel === 'sms' ? trim((string)$p['telefon']) : trim((string)$p['email']);
            if ($addr === '') continue;
            $persons[] = [
                'id'      => (int)$p['id'],
                'name'    => $p['imie_nazwisko'],
                'role'    => $p['stanowisko'] ?: '',
                'address' => $addr,
                'default' => !empty($p['is_default_recipient']),
            ];
        }

        $valid[] = [
            'id'         => (int)$c['id'],
            'name'        => $c['imie_nazwisko'],
            'email'       => $to['email'],
            'telefon'     => $to['telefon'],
            'to_name'     => $to['name'],
            'to_source'   => $to['source'],
            'person_id'   => $to['person_id'],
            'persons'     => $persons,
        ];
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

    // DW — lista adresów e-mail (waliduj)
    $dw_raw   = (array)($body['dw'] ?? []);
    $dw_clean = array_values(array_filter($dw_raw, fn($e) => filter_var(trim($e), FILTER_VALIDATE_EMAIL)));

    // Nadawca: konto systemowe albo skrzynka M365 zalogowanego użytkownika
    $from_email = '';
    if ($channel === 'email' && ($body['send_as'] ?? 'system') === 'me') {
        $cu = current_user();
        if (!empty($cu['microsoft_id']) && !empty($cu['email']) && _mail_m365_configured()) {
            $from_email = trim($cu['email']);
        }
    }

    // Pierwsza grupa dla kompatybilności wstecznej
    $group_ids_arr = array_filter(array_map('intval', (array)($body['group_ids'] ?? [])));
    if (!$group_ids_arr && ($body['group_id'] ?? 0)) $group_ids_arr = [(int)$body['group_id']];

    $send_id = db_insert('crm_mass_sends', [
        'group_id'      => $group_ids_arr[0] ?? null,
        'channel'       => $channel,
        'subject'       => $subject ?: null,
        'body'          => $msg,
        'template_name' => $tpl ?: null,
        'recipients'    => count($ids),
        'sent_ok'       => 0,
        'sent_fail'     => 0,
        'status'        => 'ready',
        'dw'            => $dw_clean ? implode(',', $dw_clean) : null,
        'tag_filter'    => ($body['tag_filter'] ?? '') ?: null,
        'created_by'    => $uid,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);

    // Zapisz listę odbiorców i DW w body jako JSON
    db()->prepare("UPDATE crm_mass_sends SET body=? WHERE id=?")
        ->execute([json_encode([
            'body'       => $msg,
            'ids'        => $ids,
            'dw'         => $dw_clean,
            'subject'    => $subject,
            'from_email' => $from_email,
            // Wybór osoby kontaktowej per podmiot — zapisany razem z wysyłką,
            // żeby wykonanie (execute) trafiło dokładnie tam, co podgląd.
            'persons'    => recipient_overrides($body),
        ]), $send_id]);

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
    $from_email = $payload['from_email'] ?? '';
    $overrides  = [];
    foreach ((array)($payload['persons'] ?? []) as $k => $v) $overrides[(int)$k] = (int)$v;

    $ok = $fail = 0;
    $failed_cids = [];
    foreach ($ids as $cid) {
        $contact = db_one("SELECT * FROM crm_contacts WHERE id=? AND crm_active=1", [(int)$cid]);
        if (!$contact) { $fail++; $failed_cids[] = (int)$cid; continue; }

        // Ten sam resolver co w podglądzie — inaczej odbiorca z adresem tylko przy
        // osobie kontaktowej byłby tu policzony jako błąd.
        $pid = $overrides[(int)$cid] ?? null;
        $to  = crm_contact_recipient((int)$cid, $pid, $contact);
        if ($channel === 'email' && $to['email']   === '') { $fail++; $failed_cids[] = (int)$cid; continue; }
        if ($channel === 'sms'   && $to['telefon'] === '') { $fail++; $failed_cids[] = (int)$cid; continue; }

        $rendered_body    = CrmManager::renderTemplate($msg_body, $contact);
        $rendered_subject = CrmManager::renderTemplate($subject, $contact);

        try {
            CrmManager::sendAndLog((int)$cid, $channel, $rendered_body, $rendered_subject, $tpl, true, [], $from_email, $pid);
            $ok++;
        } catch (\Throwable $e) {
            $fail++;
            $failed_cids[] = (int)$cid;
        }
    }

    db()->prepare("UPDATE crm_mass_sends SET status='done',sent_ok=?,sent_fail=?,failed_ids=?,finished_at=? WHERE id=?")
        ->execute([$ok, $fail, $failed_cids ? json_encode($failed_cids) : null, date('Y-m-d H:i:s'), $send_id]);

    // ── DW: wyślij podsumowanie do adresatów Do Wiadomości ────────────────────
    $dw_list   = array_filter($payload['dw'] ?? []);
    $dw_sent   = 0;
    if ($dw_list && $channel === 'email') {
        $org       = defined('ORG_NAME') ? ORG_NAME : '';
        $dw_subj   = '[DW] ' . ($payload['subject'] ?? 'Wysyłka masowa') . ' — podsumowanie';
        $dw_body   = "
<html><body style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#1f2937'>
<div style='background:#1e293b;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0'>
  <h3 style='margin:0;font-size:1rem'>Do Wiadomości — podsumowanie wysyłki</h3>
  <p style='margin:.4rem 0 0;font-size:.82rem;opacity:.75'>{$org}</p>
</div>
<div style='border:1px solid #e5e7eb;border-top:none;padding:20px;border-radius:0 0 8px 8px'>
  <table style='width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:16px'>
    <tr><td style='padding:4px 0;color:#6b7280;width:140px'>Kanał:</td><td><strong>" . strtoupper(htmlspecialchars($channel)) . "</strong></td></tr>
    <tr><td style='padding:4px 0;color:#6b7280'>Temat:</td><td>" . htmlspecialchars($payload['subject'] ?? '—') . "</td></tr>
    <tr><td style='padding:4px 0;color:#6b7280'>Wysłano do:</td><td><strong style='color:#16a34a'>{$ok}</strong></td></tr>
    " . ($fail ? "<tr><td style='padding:4px 0;color:#6b7280'>Błędy:</td><td><strong style='color:#dc2626'>{$fail}</strong></td></tr>" : '') . "
    <tr><td style='padding:4px 0;color:#6b7280'>Data:</td><td>" . date('d.m.Y H:i') . "</td></tr>
  </table>
  <div style='border-top:1px solid #e5e7eb;padding-top:14px;margin-top:4px'>
    <p style='font-size:.78rem;color:#6b7280;margin:0 0 8px'>Treść wysłanej wiadomości:</p>
    <div style='background:#f9fafb;border-radius:6px;padding:14px;font-size:.88rem'>" . ($msg_body) . "</div>
  </div>
</div>
</body></html>";

        require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
        foreach ($dw_list as $dw_email) {
            $dw_email = trim($dw_email);
            if (!filter_var($dw_email, FILTER_VALIDATE_EMAIL)) continue;
            try {
                approval_send_email($dw_email, $dw_subj, $dw_body, 'crm_mass_dw', $send_id);
                $dw_sent++;
            } catch (\Throwable $e) {}
        }
    }

    api_ok(['status'=>'done','sent_ok'=>$ok,'sent_fail'=>$fail,'total'=>count($ids),'dw_sent'=>$dw_sent]);
}

if ($action === 'status') {
    $send_id = (int)($body['send_id'] ?? 0);
    $ms = db_one("SELECT status,sent_ok,sent_fail,recipients FROM crm_mass_sends WHERE id=?", [$send_id]);
    if (!$ms) api_err('Nie znaleziono.',404);
    api_ok($ms);
}

api_err('Nieznana akcja.');
