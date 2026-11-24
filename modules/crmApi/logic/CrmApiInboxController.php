<?php
/**
 * modules/crmApi/logic/CrmApiInboxController.php — Skrzynka CRM w API (odpowiednik crm/inbox.php).
 *
 *   GET  /inbox/meta                 widoki z licznikami, skrzynki, kolejki, tagi, konta nadawcy, szablony, osoby do przypisania
 *   GET  /inbox                      lista (view, mailbox_id, queue_id, tag, q, unread, att, threads, page)
 *   GET  /inbox/poll                 liczniki + najnowsze id (odświeżanie co 30 s)
 *   GET  /inbox/{id}                 wiadomość: dokument do ramki (zabezpieczony jak w klasycznej Skrzynce), załączniki, kontekst
 *   POST /inbox/{id}/action          {op, …} — read, unread, archive, spam, restore, assign, hide, unhide, queue, tag_add,
 *                                    tag_remove, add_contact, link_contact, case, attach_case, forward, fetch_body
 *   POST /inbox/{id}/reply           {subject, body, send_as, template_name?} — odpowiedź do kontaktu, w tym samym wątku
 *   POST /inbox/bulk                 {op: read|unread|hide|unhide|archive|queue|tag, ids[], value?}
 *   POST /inbox/mark-all-read        {view, mailbox_id}
 *   GET  /inbox/attachments/{id}     plik załącznika (strumień)
 *   GET  /inbox/ezd-options?q=       teczki i sprawy EZD do rejestracji wiadomości
 *   POST /inbox/{id}/assist          asystent AI (intencja, streszczenie, szkic odpowiedzi)
 *   POST /inbox/ooo-back             koniec nieobecności zalogowanej osoby
 *   POST /inbox/admin/scan           pobranie poczty ze skrzynek (admin, prawo zarządzania skrzynką)
 *   POST /inbox/admin/autocreate     {on} — nieznani nadawcy zakładają kontakt (admin)
 * Dodatkowe operacje /action: fol_me, fol_add, fol_remove (obserwujący), nozbe, ezd.
 * Odpowiedź przyjmuje też multipart/form-data z files[] (maks. 5 plików, 15 MB, typy jak w klasycznym oknie).
 *
 * W odróżnieniu od crm/inbox.php każda operacja na wiadomości sprawdza dostęp do jej skrzynki (crm_mailbox_message ≠ null),
 * a otwarcie wiadomości jej NIE oznacza jako przeczytanej — aplikacja robi to jawnie akcją „read”.
 */
final class CrmApiInboxController
{
    private const OPS_WRITE = ['read', 'unread', 'archive', 'spam', 'restore', 'assign', 'hide', 'unhide', 'queue', 'tag_add',
        'tag_remove', 'add_contact', 'link_contact', 'case', 'attach_case', 'forward', 'fetch_body',
        'fol_me', 'fol_add', 'fol_remove', 'nozbe', 'ezd'];

    public function __construct()
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/crm_mailbox.php';
        require_once $root . '/includes/ezd_mail.php';          // kolumny inbox_status, assigned_to, thread_key, from_* (samonaprawa)
        require_once $root . '/includes/crm_sender.php';
        require_once $root . '/modules/crm_inbox/lib/inboxView.php';
        require_once $root . '/includes/crm_attachments.php';
        require_once $root . '/includes/crm_sender_trust.php';
        CrmApiAuth::need('inbox', 'read');
        if (!crm_mailbox_ready()) api_error('Skrzynka CRM nie jest skonfigurowana (brak skrzynek pocztowych).', 503);
    }

    public function meta(): array
    {
        $uid = CrmApiAuth::userId();
        $counts = crm_mailbox_counts();
        $views = [];
        foreach (CRM_MAILBOX_VIEWS as $k => $v) $views[] = ['value' => $k, 'label' => $v['label'], 'count' => (int)($counts[$k] ?? 0)];
        $view = $this->view();
        $byMb = crm_mailbox_counts_by_mailbox($view);
        $mailboxes = array_map(fn($m) => ['id' => (int)$m['id'], 'name' => (string)($m['mailbox'] ?? ''),
            'color' => crm_mailbox_color((int)$m['id']), 'count' => (int)($byMb[(int)$m['id']] ?? 0)], crm_mailbox_list());
        $qc = crm_mq_counts($view, (int)($_GET['mailbox_id'] ?? 0));
        $queues = array_map(fn($q) => ['id' => (int)$q['id'], 'name' => (string)$q['name'], 'color' => $q['color'] ?? null,
            'count' => (int)($qc[(int)$q['id']] ?? 0)], crm_mq_list());
        $templates = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'subject' => $t['subject'] ?? '', 'body' => (string)$t['body']],
            db_all("SELECT id, name, subject, body FROM crm_templates WHERE is_active=1 AND channel='email' ORDER BY name"));
        $accounts = array_map(fn($a) => ['key' => $a['key'], 'label' => $a['label'], 'ready' => (bool)$a['ready']], crm_sender_accounts());
        $folders = crm_mf_list();
        $fc = $folders ? crm_mf_counts($folders) : [];
        $away = uav_get($uid);
        $awayOn = uav_row_active($away);
        return ['data' => [
            'folders' => array_map(fn($f) => ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'color' => $f['color'] ?? null,
                'shared' => !empty($f['is_shared']), 'count' => (int)($fc[(int)$f['id']] ?? 0)], $folders),
            'is_admin' => is_admin(),
            'autocreate' => crm_mailbox_autocreate(),
            'away' => $awayOn ? ['label' => uav_period_label($away)] : null,
            'ezd_available' => crm_mailbox_ezd_available(),
            'nozbe_available' => $this->nozbeReady(),
            'assist_available' => crm_can('inbox', 'write'),
            'views' => $views, 'mailboxes' => $mailboxes, 'queues' => $queues, 'tags' => crm_mt_suggest(40),
            'accounts' => $accounts, 'send_as_default' => crm_sender_default(), 'templates' => $templates,
            'can_write' => crm_can('inbox', 'write'), 'threads_default' => user_pref('crm_inbox_threads', '1') === '1',
            'me' => $uid,
        ]];
    }

    public function index(): array
    {
        if (function_exists('crm_ooo_sweep')) { try { crm_ooo_sweep(); } catch (\Throwable $e) {} }
        $f = $this->filters();
        $f['page'] = max(1, (int)($_GET['page'] ?? 1));
        $f['per_page'] = 30;
        $res = crm_mailbox_inbox($f);
        $rows = $res['rows'] ?? [];
        $tags = $rows ? crm_mt_for_rows($rows) : [];
        $fol = $rows ? crm_fol_flags_for_rows($rows, CrmApiAuth::userId()) : [];
        $trust = $rows ? crm_sender_trust_bulk(array_column($rows, 'from_email')) : [];
        $data = [];
        foreach ($rows as $r) {
            $t = $trust[strtolower(trim((string)($r['from_email'] ?? '')))] ?? null;
            $data[] = $this->row($r) + ['tags' => array_values($tags[(int)$r['id']] ?? []), 'followed' => !empty($fol[(int)$r['id']]),
                'trust' => $t && ($t['label'] ?? '') !== '' ? (string)$t['label'] : null];
        }
        $total = (int)($res['total'] ?? 0);
        return ['data' => $data, 'meta' => ['total' => $total, 'page' => $f['page'], 'per_page' => 30,
            'pages' => max(1, (int)ceil($total / 30)), 'grouped' => !empty($res['grouped'])]];
    }

    public function poll(): array
    {
        $f = $this->filters();
        $f['per_page'] = 1;
        $res = crm_mailbox_inbox($f);
        return ['data' => ['max_id' => (int)($res['rows'][0]['id'] ?? 0), 'total' => (int)($res['total'] ?? 0),
            'counts' => crm_mailbox_counts()]];
    }

    public function show(int $id): array
    {
        $m = $this->load($id);
        $ctx = $m['ctx'] ?? [];
        $contact = $ctx['contact'] ?? null;
        $cases = [];
        if (!empty($m['contact_id'])) {
            foreach (crm_cases_for_contact((int)$m['contact_id']) as $c) {
                $cases[] = ['id' => (int)$c['id'], 'title' => (string)$c['title'], 'case_number' => $c['case_number'] ?? null,
                            'is_closed' => crm_case_is_closed($c['status'] ?? '')];
            }
        }
        $q = crm_mq_of($id);
        $uid = CrmApiAuth::userId();
        $followers = array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']], crm_fol_list($id));
        $conversation = null;
        if (!empty($_GET['conv']) && !empty($m['thread_key'])) {
            $conversation = [];
            foreach ($this->scopedThread($ctx['thread'] ?? []) as $t) {
                $tm = (int)$t['id'] === $id ? $m : crm_mailbox_message((int)$t['id']);
                if (!$tm) continue;
                $conversation[] = ['id' => (int)$tm['id'], 'direction' => ($tm['direction'] ?? '') === 'in' ? 'in' : 'out',
                    'from' => ($tm['direction'] ?? '') === 'in' ? ($tm['from_name'] ?: ($tm['from_email'] ?? null)) : $this->senderName($tm),
                    'sent_at' => $tm['sent_at'] ?? null,
                    'subject' => $tm['subject'] ?? null, 'frame' => crmInboxFrameDoc($tm)];
            }
        }
        $trust = crm_sender_trust_bulk([(string)($m['from_email'] ?? '')]);
        $t = $trust[strtolower(trim((string)($m['from_email'] ?? '')))] ?? null;
        return ['data' => $this->row($m) + [
            'followers' => $followers,
            'following' => in_array($uid, array_column($followers, 'id'), true),
            'conversation' => $conversation,
            'ezd_sprawa_id' => !empty($m['ezd_sprawa_id']) ? (int)$m['ezd_sprawa_id'] : null,
            'trust' => $t && ($t['label'] ?? '') !== '' ? ['label' => (string)$t['label'], 'title' => (string)($t['title'] ?? '')] : null,
            'to' => $m['to_email'] ?? null,
            'frame' => crmInboxFrameDoc($m),
            'has_remote_images' => crmInboxHasRemote((string)($m['body_html'] ?? '')),
            'is_preview' => crmInboxBodyIsPreview($m),
            'case_id' => !empty($m['case_id']) ? (int)$m['case_id'] : null,
            'queue' => $q ? ['id' => (int)$q['id'], 'name' => (string)$q['name']] : null,
            'tags' => array_values(crm_mt_for_rows([$m])[$id] ?? []),
            'attachments' => array_map(fn($a) => ['id' => (int)$a['id'], 'name' => (string)$a['original_name'],
                'mime' => (string)($a['mime_type'] ?? ''), 'size' => (int)($a['size_bytes'] ?? 0)], $m['attachments'] ?? []),
            'contact' => $contact ? ['id' => (int)$contact['id'], 'name' => $contact['imie_nazwisko'] ?? null, 'email' => $contact['email'] ?? null,
                'messages' => (int)($contact['n_msgs'] ?? 0), 'cases' => (int)($contact['n_cases'] ?? 0),
                'owner_name' => $contact['owner_name'] ?? null, 'last_at' => $contact['last_at'] ?? null] : null,
            'candidates' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['imie_nazwisko'] ?? null, 'email' => $c['email'] ?? null,
                'organizacja' => $c['organizacja'] ?? null], $ctx['candidates'] ?? []),
            'contact_cases' => $cases,
            'thread' => array_map(fn($t) => ['id' => (int)$t['id'], 'direction' => ($t['direction'] ?? '') === 'in' ? 'in' : 'out',
                'subject' => $t['subject'] ?? null, 'sent_at' => $t['sent_at'] ?? null, 'is_read' => !empty($t['is_read'])],
                $this->scopedThread($ctx['thread'] ?? [])),
        ], 'legacy_url' => rtrim(APP_URL, '/') . '/crm/inbox.php?id=' . $id];
    }

    public function action(int $id): array
    {
        CrmApiAuth::need('inbox', 'write');
        $m = $this->load($id);
        $in = CrmApiAuth::input();
        $op = (string)($in['op'] ?? '');
        if (!in_array($op, self::OPS_WRITE, true)) api_error('Nieznana operacja.', 422);
        $uid = CrmApiAuth::userId();
        $msg = 'Gotowe.';
        $extra = [];
        switch ($op) {
            case 'read': case 'unread':
                crm_mailbox_mark_read($id, $op === 'read');
                $msg = $op === 'read' ? 'Oznaczono jako przeczytaną.' : 'Oznaczono jako nieprzeczytaną.';
                break;
            case 'archive': case 'spam': case 'restore':
                crm_mailbox_set_status($id, ['archive' => 'archived', 'spam' => 'spam', 'restore' => 'active'][$op]);
                $msg = ['archive' => 'Przeniesiono do załatwionych.', 'spam' => 'Oznaczono jako spam.', 'restore' => 'Przywrócono do skrzynki.'][$op];
                break;
            case 'assign':
                $to = (int)($in['user_id'] ?? 0) ?: null;
                if ($to && !db_one("SELECT 1 FROM users WHERE id=? AND is_active=1", [$to])) api_error('Nie ma takiego użytkownika.', 422);
                if (!crm_mailbox_assign($id, $to)) api_error('Ta osoba jest nieobecna — wybierz kogoś innego.', 409);
                $msg = $to ? ($to === $uid ? 'Przypisano do Ciebie.' : 'Przypisano.') : 'Zdjęto przypisanie.';
                break;
            case 'hide': case 'unhide':
                crm_mailbox_set_hidden($id, $op === 'hide');
                $msg = $op === 'hide' ? 'Ukryto w CRM.' : 'Przywrócono widoczność.';
                break;
            case 'queue':
                $qid = (int)($in['queue_id'] ?? 0) ?: null;
                if ($qid && !in_array($qid, array_map(fn($q) => (int)$q['id'], crm_mq_list()), true)) api_error('Nieznana kolejka.', 422);
                crm_mq_move($id, $qid);
                $msg = 'Przeniesiono do kolejki.';
                break;
            case 'tag_add':
                $n = 0;
                foreach (preg_split('/[,;]+/', (string)($in['tag'] ?? '')) ?: [] as $t) if (trim($t) !== '' && crm_mt_add($id, trim($t))) $n++;
                if (!$n) api_json(['error' => 'Podaj tag.', 'fields' => ['tag' => 'Podaj tag.']], 422);
                $msg = 'Dodano tag.';
                break;
            case 'tag_remove':
                crm_mt_remove($id, (string)($in['tag'] ?? ''));
                $msg = 'Usunięto tag.';
                break;
            case 'add_contact':
                CrmApiAuth::need('contacts', 'write');
                $r = crm_mailbox_add_contact_from_message($id, $uid);
                if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się dodać kontaktu.'), 409);
                $extra['contact_id'] = (int)($r['id'] ?? 0);
                $msg = 'Dodano nadawcę do kontaktów' . (!empty($r['linked']) && (int)$r['linked'] > 1 ? ' i powiązano ' . (int)$r['linked'] . ' wiadomości.' : '.');
                break;
            case 'link_contact':
                CrmApiAuth::need('contacts', 'write');
                $cid = (int)($in['contact_id'] ?? 0);
                if ($cid <= 0 || !crm_can_access_contact($cid) || !db_one("SELECT 1 FROM crm_contacts WHERE id=? AND crm_active=1", [$cid])) api_error('Wybierz kontakt.', 422);
                $email = crm_mailbox_peer_email($m);
                if ($email === '') api_error('Wiadomość nie ma adresu nadawcy.', 409);
                if (empty($m['contact_id'])) db()->prepare("UPDATE crm_communications SET contact_id=? WHERE id=?")->execute([$cid, $id]);
                $n = crm_mailbox_link_sender_to_contact($email, $cid);
                $msg = 'Powiązano nadawcę z kontaktem' . ($n > 1 ? " ({$n} wiadomości)." : '.');
                $extra['contact_id'] = $cid;
                break;
            case 'case':
                CrmApiAuth::need('cases', 'write');
                $r = crm_mailbox_create_case($id, [
                    'title' => (string)($in['title'] ?? ''), 'priority' => (string)($in['priority'] ?? 'medium'),
                    'assign_to' => (int)($in['owner_id'] ?? 0), 'keep_open' => !empty($in['keep_open']),
                    'contact_id' => (int)($in['contact_id'] ?? 0),
                ]);
                if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się założyć sprawy.'), 409);
                $extra['case_id'] = (int)$r['case_id'];
                $msg = 'Założono sprawę.';
                break;
            case 'attach_case':
                $cid = (int)($in['case_id'] ?? 0);
                $r = crm_case_attach_message($id, $cid);
                if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się dołączyć do sprawy.'), 409);
                $msg = $cid ? 'Dołączono do sprawy.' : 'Odłączono od sprawy.';
                break;
            case 'forward':
                $to = trim((string)($in['to'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) api_json(['error' => 'Podaj adres e-mail.', 'fields' => ['to' => 'Wpisz adres w formacie nazwa@domena.pl.']], 422);
                $r = crm_mailbox_forward($id, $to, trim((string)($in['note'] ?? '')));
                if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się przekazać.'), 409);
                $msg = 'Przekazano do ' . $to . '.';
                break;
            case 'fetch_body':
                $r = crmInboxFetchFullBody($id);
                if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się pobrać pełnej treści.'), 502);
                $msg = 'Pobrano pełną treść.';
                break;
            case 'fol_me':
                if (in_array($uid, array_map('intval', array_column(crm_fol_list($id), 'id')), true)) {
                    crm_fol_remove($id, $uid);
                    $msg = 'Przestajesz obserwować tę rozmowę.';
                } else {
                    if (!crm_fol_add($id, $uid, $uid)) api_error('Nie możesz obserwować tej rozmowy (brak dostępu do skrzynki).', 409);
                    $msg = 'Obserwujesz rozmowę — dostaniesz powiadomienia o odpowiedziach i zmianach.';
                }
                break;
            case 'fol_add':
                if (!crm_fol_add($id, (int)($in['user_id'] ?? 0), $uid)) api_error('Nie dodano: ta osoba już obserwuje albo nie ma dostępu do tej skrzynki.', 409);
                $msg = 'Dodano obserwującego.';
                break;
            case 'fol_remove':
                crm_fol_remove($id, (int)($in['user_id'] ?? 0));
                $msg = 'Usunięto obserwującego.';
                break;
            case 'nozbe':
                if (!$this->nozbeReady()) api_error('Integracja z Nozbe nie jest skonfigurowana.', 409);
                $r = nozbe_push_crm_message($id);
                if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Nie udało się utworzyć zadania w Nozbe.'), 502);
                $msg = !empty($r['existing']) ? 'To zadanie jest już w Nozbe.' : 'Utworzono zadanie w Nozbe.';
                if (!empty($r['url'])) $extra['url'] = (string)$r['url'];
                break;
            case 'ezd':
                if (!crm_mailbox_ezd_available()) api_error('Rejestracja w EZD jest niedostępna (moduł wyłączony albo brak prawa zapisu w EZD).', 403);
                $sprawa = (int)($in['sprawa_id'] ?? 0) ?: null;
                $teczka = (int)($in['teczka_id'] ?? 0) ?: null;
                if (!$sprawa && !$teczka) api_json(['error' => 'Wybierz sprawę albo teczkę.', 'fields' => ['sprawa_id' => 'Wybierz istniejącą sprawę albo teczkę dla nowej.']], 422);
                $r = crm_mailbox_to_ezd($id, $sprawa, $teczka, ['name' => (string)($in['strona_name'] ?? ''),
                    'crm_id' => (int)($in['strona_crm_id'] ?? 0) ?: null, 'rola' => (string)($in['strona_rola'] ?? '')]);
                if (empty($r['ok'])) api_error((string)($r['error'] ?: 'Nie udało się zarejestrować w dzienniku EZD.'), 409);
                $msg = 'Zarejestrowano w dzienniku EZD' . (!empty($r['znak']) ? ' — ' . $r['znak'] : '') . '.'
                     . (!empty($r['notified']) ? ' Nadawca dostał e-mail o zarejestrowaniu.' : '');
                if (!empty($r['url'])) $extra['url'] = (string)$r['url'];
                break;
        }
        api_audit('inbox_' . $op, 'crm_communications', $id);
        return ['data' => ['op' => $op, 'id' => $id, 'message' => $msg] + $extra];
    }

    public function reply(int $id): array
    {
        CrmApiAuth::need('inbox', 'write');
        $m = $this->load($id);
        $cid = (int)($m['contact_id'] ?? 0);
        if ($cid <= 0) api_error('Najpierw dodaj nadawcę do kontaktów albo powiąż go z kontaktem.', 409);
        if (!crm_can_access_contact($cid)) api_error('Brak dostępu do kontaktu.', 403);
        $in = CrmApiAuth::input();
        $subject = trim((string)($in['subject'] ?? ''));
        $body = trim((string)($in['body'] ?? ''));
        $err = [];
        if ($subject === '') $err['subject'] = 'Wpisz temat.';
        if ($body === '') $err['body'] = 'Wpisz treść odpowiedzi.';
        if ($err) api_json(['error' => 'Popraw zaznaczone pola.', 'fields' => $err], 422);
        $contact = db_one("SELECT * FROM crm_contacts WHERE id=?", [$cid]);
        $sendAs = (string)($in['send_as'] ?? crm_sender_default());
        if (($p = crm_sender_problem($sendAs)) !== '') api_error($p, 409);
        // Treść z pola tekstowego: zachowujemy akapity i łamania linii, bez wstrzykiwania HTML
        $html = nl2br(htmlspecialchars(CrmManager::renderTemplate($body, $contact), ENT_QUOTES, 'UTF-8'));
        $attachments = $this->replyAttachments((int)($in['template_id'] ?? 0));
        $commId = CrmManager::sendAndLog($cid, 'email', $html, CrmManager::renderTemplate($subject, $contact),
            trim((string)($in['template_name'] ?? '')), true, $attachments, crm_sender_email($sendAs));
        // Odpowiedź zostaje w wątku, skrzynce i sprawie oryginału (klasyczne okno tego nie robiło)
        if ($commId > 0) {
            db()->prepare("UPDATE crm_communications SET thread_key=COALESCE(thread_key, ?), mailbox_id=COALESCE(mailbox_id, ?), case_id=COALESCE(case_id, ?) WHERE id=?")
                ->execute([$m['thread_key'] ?: null, $m['mailbox_id'] ?: null, $m['case_id'] ?: null, $commId]);
        }
        crm_mailbox_mark_read($id, true);
        api_audit('inbox_reply', 'crm_communications', $id);
        http_response_code(201);
        return ['data' => ['id' => $commId, 'message' => 'Wysłano odpowiedź do ' . ($contact['imie_nazwisko'] ?? '') . '.']];
    }

    public function bulk(): array
    {
        CrmApiAuth::need('inbox', 'write');
        $in = CrmApiAuth::input();
        $op = (string)($in['op'] ?? '');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? []))))), 0, 500);
        if (!$ids) api_error('Zaznacz wiadomości.', 422);
        $ok = 0;
        foreach ($ids as $mid) {
            if (!crm_mailbox_message($mid)) continue;   // tylko wiadomości ze skrzynek użytkownika
            switch ($op) {
                case 'read': case 'unread': crm_mailbox_mark_read($mid, $op === 'read'); break;
                case 'hide': case 'unhide': crm_mailbox_set_hidden($mid, $op === 'hide'); break;
                case 'archive': crm_mailbox_set_status($mid, 'archived'); break;
                case 'queue': crm_mq_move($mid, (int)($in['value'] ?? 0) ?: null); break;
                case 'tag': if (!crm_mt_add($mid, (string)($in['value'] ?? ''))) continue 2; break;
                default: api_error('Nieznana operacja.', 422);
            }
            $ok++;
        }
        api_audit('inbox_bulk_' . $op, 'crm_communications', null, ['ids:' . count($ids)]);
        return ['data' => ['op' => $op, 'affected' => $ok]];
    }

    public function markAllRead(): array
    {
        CrmApiAuth::need('inbox', 'write');
        $in = CrmApiAuth::input();
        $n = crm_mailbox_mark_all_read(['view' => isset(CRM_MAILBOX_VIEWS[$in['view'] ?? '']) ? $in['view'] : 'new',
            'mailbox_id' => (int)($in['mailbox_id'] ?? 0)]);
        return ['data' => ['affected' => $n]];
    }

    public function attachment(int $attId): ?array
    {
        $a = db_one("SELECT pa.*, c.id AS comm_id FROM poczta_attachments pa JOIN crm_communications c ON c.id=pa.communication_id WHERE pa.id=?", [$attId]);
        if (!$a) api_error('Taki załącznik nie istnieje.', 404);
        $this->load((int)$a['comm_id']);                  // dostęp do skrzynki (także NULL = tylko admin)
        $root = (string)realpath(rtrim(UPLOAD_DIR, '/'));
        $real = !empty($a['stored_path']) ? realpath($root . '/' . ltrim((string)$a['stored_path'], '/')) : false;
        if (!$real || !str_starts_with($real, $root . '/') || !is_readable($real)) {
            api_error('Tego załącznika nie ma jeszcze na serwerze. Otwórz go raz w klasycznej Skrzynce (pobierze go ze skrzynki pocztowej).', 404);
        }
        $name = preg_replace('/[\r\n"]/', '_', (string)$a['original_name']) ?: 'plik';
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($real));
        header("Content-Disposition: attachment; filename=\"" . $name . "\"; filename*=UTF-8''" . rawurlencode((string)$a['original_name']));
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        return null;
    }

    public function ezdOptions(): array
    {
        if (!crm_mailbox_ezd_available()) return ['data' => ['available' => false, 'teczki' => [], 'sprawy' => []]];
        return ['data' => ['available' => true,
            'teczki' => array_map(fn($t) => ['id' => (int)$t['id'], 'label' => trim(($t['symbol'] ?? '') . ' ' . ($t['title'] ?? ''))], crm_mailbox_ezd_teczki()),
            'sprawy' => array_map(fn($t) => ['id' => (int)$t['id'], 'label' => trim(($t['znak_sprawy'] ?? '') . ' — ' . ($t['title'] ?? ''))],
                crm_mailbox_ezd_sprawy(mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100))),
            'roles' => ['Wnioskodawca', 'Strona', 'Pełnomocnik', 'Uczestnik']]];
    }

    public function assist(int $id): array
    {
        CrmApiAuth::need('inbox', 'write');
        $m = $this->load($id);                              // klasyczny endpoint czytał wiadomość bez sprawdzenia skrzynki
        require_once dirname(__DIR__, 3) . '/includes/crm_inbox_assistant.php';
        $r = crm_inbox_assist($m);
        $intent = CRM_ASSIST_INTENTS[$r['intent'] ?? 'inne'] ?? CRM_ASSIST_INTENTS['inne'];
        if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Asystent jest niedostępny.'), 502);
        return ['data' => [
            'intent' => (string)($r['intent'] ?? 'inne'), 'intent_label' => (string)$intent['label'],
            'summary' => (string)($r['summary'] ?? ''), 'escalate' => !empty($r['escalate']),
            'escalate_reason' => (string)($r['escalate_reason'] ?? ''), 'missing' => array_values((array)($r['missing'] ?? [])),
            'sensitive' => !empty($r['sensitive']), 'reply' => (string)($r['reply'] ?? ''),
        ]];
    }

    public function oooBack(): array
    {
        uav_set(CrmApiAuth::userId(), false);
        return ['data' => ['message' => 'Witaj z powrotem — nieobecność wyłączona.']];
    }

    public function adminScan(): array
    {
        if (!is_admin()) api_error('Tylko administrator.', 403);
        $r = crm_mailbox_scan((int)(CrmApiAuth::input()['mailbox_id'] ?? 0) ?: null);
        if (empty($r['ok'])) api_error((string)($r['error'] ?? 'Skanowanie nieudane.'), 409);
        return ['data' => ['message' => sprintf('Pobrano %d, dopasowano %d, zapisano %d, pominięto %d.',
            (int)($r['fetched'] ?? 0), (int)($r['matched'] ?? 0), (int)($r['created'] ?? 0), (int)($r['skipped'] ?? 0))
            . (!empty($r['errors']) ? ' Część skrzynek zgłosiła błędy.' : '')]];
    }

    public function adminAutocreate(): array
    {
        if (!is_admin()) api_error('Tylko administrator.', 403);
        $on = !empty(CrmApiAuth::input()['on']);
        crm_setting_save('poczta_autocreate_contacts', $on ? '1' : '0');
        return ['data' => ['autocreate' => $on, 'message' => $on ? 'Nieznani nadawcy będą zakładać kontakty.' : 'Nieznani nadawcy nie będą zakładać kontaktów.']];
    }

    // ── pomocnicze ─────────────────────────────────────────────────────────────

    /** Autor wiadomości wychodzącej: użytkownik SZO (sent_by), inaczej skrzynka. */
    private function senderName(array $m): ?string
    {
        if (!empty($m['sent_by'])) {
            $u = db_one("SELECT name FROM users WHERE id=?", [(int)$m['sent_by']]);
            if ($u) return (string)$u['name'];
        }
        return $m['mailbox_name'] ?? ($m['from_email'] ?: null);
    }

    private function nozbeReady(): bool
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/nozbe.php';
            return nozbe_configured();
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Załączniki odpowiedzi: pliki z formularza (files[]) zapisane w poczekalni crm_attachments/ + załączniki szablonu.
     * Te same limity co klasyczne okno (crm/api/attachments.php): 5 plików, 15 MB, lista rozszerzeń.
     * @return list<array{path:string,name:string,mime:string,size:int}>
     */
    private function replyAttachments(int $templateId): array
    {
        $out = $templateId > 0 ? crm_tpl_attachments($templateId) : [];
        $f = $_FILES['files'] ?? null;
        if (!$f || !is_array($f['name'] ?? null)) return array_slice($out, 0, CRM_ATT_MAX_FILES);
        $total = array_sum(array_column($out, 'size'));
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i])) api_error('Nie udało się przesłać pliku „' . $name . '”.', 422);
            $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            if (!in_array($ext, crm_att_allowed_ext(), true)) api_json(['error' => 'Niedozwolony typ pliku: .' . $ext, 'fields' => ['files' => 'Dozwolone: ' . implode(', ', crm_att_allowed_ext()) . '.']], 422);
            $size = (int)$f['size'][$i];
            $total += $size;
            if (count($out) >= CRM_ATT_MAX_FILES) api_json(['error' => 'Najwyżej ' . CRM_ATT_MAX_FILES . ' załączników.', 'fields' => ['files' => 'Najwyżej ' . CRM_ATT_MAX_FILES . ' plików.']], 422);
            if ($total > CRM_ATT_MAX_BYTES) api_json(['error' => 'Załączniki są za duże (razem do 15 MB).', 'fields' => ['files' => 'Razem do 15 MB.']], 422);
            $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'][$i], crm_att_dir() . $stored)) api_error('Nie udało się zapisać pliku „' . $name . '”.', 500);
            $out[] = ['path' => 'crm_attachments/' . $stored, 'name' => (string)$name,
                      'mime' => (string)(mime_content_type(crm_att_dir() . $stored) ?: 'application/octet-stream'), 'size' => $size];
        }
        return $out;
    }

    private function view(): string
    {
        $v = (string)($_GET['view'] ?? 'new');
        return isset(CRM_MAILBOX_VIEWS[$v]) ? $v : 'new';
    }

    private function filters(): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $threads = isset($_GET['threads']) ? $_GET['threads'] === '1' : user_pref('crm_inbox_threads', '1') === '1';
        return [
            'view' => $this->view(),
            'mailbox_id' => (int)($_GET['mailbox_id'] ?? 0),
            'queue_id' => (int)($_GET['queue_id'] ?? 0) ?: null,
            'tag' => trim((string)($_GET['tag'] ?? '')),
            'q' => mb_substr($q, 0, 200),
            'unread' => !empty($_GET['unread']),
            'att' => !empty($_GET['att']),
            'group_threads' => $threads && $q === '',
            'folder_rules' => ($fid = (int)($_GET['folder_id'] ?? 0)) && ($fo = crm_mf_get($fid)) ? $fo['rules_arr'] : null,
        ];
    }

    private function load(int $id): array
    {
        $m = crm_mailbox_message($id);
        if (!$m) api_error('Nie znaleziono wiadomości albo nie masz dostępu do jej skrzynki.', 404);
        return $m;
    }

    /** Wątek tylko z wiadomości, do których użytkownik ma dostęp (zapytanie w crm_mailbox_message nie filtruje skrzynek). */
    private function scopedThread(array $thread): array
    {
        if (is_admin()) return $thread;
        $ids = array_map(fn($t) => (int)$t['id'], $thread);
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ok = array_map(fn($r) => (int)$r['id'], db_all("SELECT id FROM crm_communications c WHERE id IN ($ph) AND " . poczta_scope_sql('c.mailbox_id'), $ids));
        return array_values(array_filter($thread, fn($t) => in_array((int)$t['id'], $ok, true)));
    }

    private function row(array $r): array
    {
        $snippet = trim(preg_replace('/\s+/u', ' ', (string)($r['body'] ?? '')) ?? '');
        return [
            'id' => (int)$r['id'],
            'msg_no' => $r['msg_no'] ?? null,
            'direction' => ($r['direction'] ?? 'in') === 'out' ? 'out' : 'in',
            'subject' => $r['subject'] ?? null,
            'snippet' => mb_substr($snippet, 0, 180),
            'from_name' => $r['from_name'] ?? null,
            'from_email' => $r['from_email'] ?? null,
            'sent_at' => $r['sent_at'] ?? null,
            'is_read' => !empty($r['is_read']),
            'status' => $r['inbox_status'] ?? null,
            'hidden' => !empty($r['crm_hidden']),
            'has_attachments' => !empty($r['has_attachments']),
            'contact_id' => !empty($r['contact_id']) ? (int)$r['contact_id'] : null,
            'contact_name' => $r['contact_name'] ?? null,
            'assigned_to' => !empty($r['assigned_to']) ? (int)$r['assigned_to'] : null,
            'assigned_name' => $r['assigned_name'] ?? null,
            'mailbox_id' => !empty($r['mailbox_id']) ? (int)$r['mailbox_id'] : null,
            'mailbox_name' => $r['mailbox_name'] ?? null,
            'queue_name' => $r['queue_name'] ?? null,
            'queue_color' => $r['queue_color'] ?? null,
            'thread_count' => (int)($r['thread_count'] ?? 1),
        ];
    }
}
