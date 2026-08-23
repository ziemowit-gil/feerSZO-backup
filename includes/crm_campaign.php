<?php
/**
 * includes/crm_campaign.php — silnik kampanii mailowych CRM: segmentacja
 * odbiorców, budowa treści, tracking (piksel otwarcia, przepisane linki,
 * stopka wypisania) i kolejkowanie wysyłki przez istniejący mail_queue.
 *
 * ŹRÓDŁA TREŚCI — dwa, w tej kolejności:
 *   1. design_json kampanii (albo szablonu) → renderowany przez
 *      includes/crm_email_render.php. To ścieżka edytora blokowego.
 *   2. crm_templates.body → surowy HTML (szablony ręczne i stare Mosaico).
 * Dzięki temu włączenie nowego edytora nie unieważnia niczego, co już działa.
 *
 * SEGMENTACJA reużywa logikę grup/tagów znaną z crm/api/mass_send.php, dokładając:
 *   - filtr warunkowy ('filter' → includes/crm_segment.php),
 *   - listę wykluczeń (crm_suppressions: twarde odbicia, skargi, wypisania),
 *   - wymóg zgody na cel, jeśli kampania ma purpose_id (includes/crm_consent.php).
 *
 * STATYSTYKI liczymy z crm_campaign_events (append-only), a liczniki na
 * crm_campaigns traktujemy jako cache do list. Wcześniej istniały tylko liczniki,
 * więc nie dawało się odpowiedzieć „kto kliknął" ani „w co".
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crm_consent.php';
require_once __DIR__ . '/crm_email_render.php';
require_once __DIR__ . '/crm_segment.php';
require_once __DIR__ . '/mail_queue.php';

// ─────────────────────────────────────────────────────────────────────────────
// Lista wykluczeń
// ─────────────────────────────────────────────────────────────────────────────

/** Normalizacja adresu: klucz listy wykluczeń musi być niewrażliwy na wielkość liter. */
function crm_suppression_key(string $email): string {
    return mb_strtolower(trim($email), 'UTF-8');
}

/**
 * Dopisuje adres do listy wykluczeń. Idempotentne — powtórne odbicie nie tworzy
 * duplikatu ani nie nadpisuje pierwotnego powodu (chcemy wiedzieć, co było pierwsze).
 */
function crm_suppression_add(string $email, string $reason, ?int $campaign_id = null, string $detail = ''): void {
    $key = crm_suppression_key($email);
    if ($key === '' || !str_contains($key, '@')) return;
    $allowed = ['unsubscribe', 'hard_bounce', 'soft_bounce', 'complaint', 'manual', 'rodo'];
    if (!in_array($reason, $allowed, true)) $reason = 'manual';
    try {
        db()->prepare(
            "INSERT INTO crm_suppressions (email, reason, campaign_id, detail, created_by)
             SELECT ?, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM crm_suppressions WHERE email = ?)"
        )->execute([$key, $reason, $campaign_id, $detail, function_exists('current_user') ? (int)(current_user()['id'] ?? 0) ?: null : null, $key]);
    } catch (\Throwable $e) {}
}

function crm_suppression_has(string $email): ?array {
    $key = crm_suppression_key($email);
    if ($key === '') return null;
    try { return db_one("SELECT * FROM crm_suppressions WHERE email = ?", [$key]) ?: null; }
    catch (\Throwable $e) { return null; }
}

/** Zdjęcie z listy — wyłącznie ręczna decyzja operatora (i zawsze z audytem). */
function crm_suppression_remove(string $email): void {
    try { db()->prepare("DELETE FROM crm_suppressions WHERE email = ?")->execute([crm_suppression_key($email)]); }
    catch (\Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────────────────────
// Segmentacja
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Zbiera ID kontaktów wg segmentu, wzorem collect_recipients() z mass_send.php.
 *
 * Typy: 'all' | 'groups' | 'tags' | 'contacts' | 'ids' | 'filter'
 */
function crm_campaign_collect_ids(string $segment_type, array $cfg): array {
    $ids = [];

    if ($segment_type === 'all') {
        foreach (db_all("SELECT id FROM crm_contacts WHERE crm_active=1") as $r) $ids[] = (int)$r['id'];
        return array_values(array_unique($ids));
    }

    if ($segment_type === 'groups') {
        $group_ids = array_filter(array_map('intval', (array)($cfg['group_ids'] ?? [])));
        foreach ($group_ids as $group_id) {
            foreach (db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [$group_id]) as $r) $ids[] = (int)$r['contact_id'];
            $linked = db_all(
                "SELECT gm.contact_id FROM crm_group_members gm
                 JOIN crm_group_links gl ON gl.child_group_id=gm.group_id
                 WHERE gl.parent_group_id=?", [$group_id]
            );
            foreach ($linked as $r) $ids[] = (int)$r['contact_id'];
            foreach (db_all("SELECT id FROM crm_groups WHERE parent_id=?", [$group_id]) as $sg) {
                foreach (db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [(int)$sg['id']]) as $r) $ids[] = (int)$r['contact_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    if ($segment_type === 'tags') {
        $tags = array_filter(array_map('trim', (array)($cfg['tags'] ?? [])));
        foreach ($tags as $tag) {
            foreach (db_all("SELECT contact_id FROM crm_tags WHERE tag=?", [$tag]) as $r) $ids[] = (int)$r['contact_id'];
        }
        return array_values(array_unique($ids));
    }

    if ($segment_type === 'contacts' || $segment_type === 'ids') {
        foreach ((array)($cfg['contact_ids'] ?? []) as $cid) $ids[] = (int)$cid;
        return array_values(array_unique(array_filter($ids)));
    }

    // Segment warunkowy — drzewo reguł kompilowane w includes/crm_segment.php.
    if ($segment_type === 'filter') {
        $filter = (array)($cfg['filter'] ?? []);
        if (!$filter && isset($cfg['segment_id'])) {
            $seg = db_one("SELECT filter_json FROM crm_segments WHERE id=?", [(int)$cfg['segment_id']]);
            $filter = $seg ? (json_decode((string)$seg['filter_json'], true) ?: []) : [];
        }
        return $filter ? crm_segment_resolve_ids($filter) : [];
    }

    return [];
}

/**
 * Zwraca pełne wiersze kontaktów gotowe do wysyłki: aktywne, z e-mailem,
 * bez opt-outu i bez wpisu na liście wykluczeń.
 *
 * @param int $purpose_id Cel wysyłki; >0 zawęża do kontaktów ze zgodą na ten cel.
 */
function crm_campaign_resolve_recipients(string $segment_type, array $segment_config, int $purpose_id = 0): array {
    return crm_campaign_partition_recipients($segment_type, $segment_config, $purpose_id)['send'];
}

/**
 * Rozdziela segment na „do wysłania" i „pominięte z powodem".
 *
 * Pominięte są tak samo ważne jak wysłane: bez nich operator widzi
 * niewytłumaczony spadek liczby odbiorców i uznaje to za błąd systemu.
 *
 * @return array{send:array<int,array>, skipped:array<int,array{contact_id:int,email:string,reason:string}>}
 */
function crm_campaign_partition_recipients(string $segment_type, array $segment_config, int $purpose_id = 0): array {
    $ids = crm_campaign_collect_ids($segment_type, $segment_config);
    if (!$ids) return ['send' => [], 'skipped' => []];

    // Brak zgody odsiewamy PRZED zapytaniem o kontakty, ale zachowujemy ślad,
    // żeby dało się pokazać „X kontaktów bez zgody na ten cel".
    $no_consent = [];
    if ($purpose_id > 0) {
        $allowed = crm_consent_filter($ids, $purpose_id);
        $no_consent = array_values(array_diff($ids, $allowed));
        $ids = $allowed;
    }

    $skipped = [];
    foreach (array_chunk($no_consent, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (db_all("SELECT id, email FROM crm_contacts WHERE id IN ($ph)", $chunk) as $r) {
            $skipped[] = ['contact_id' => (int)$r['id'], 'email' => (string)$r['email'], 'reason' => 'no_consent'];
        }
    }

    $send = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $rows = db_all(
            "SELECT * FROM crm_contacts
             WHERE id IN ($ph) AND crm_active=1
             ORDER BY id", $chunk
        );
        foreach ($rows as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '' || !str_contains($email, '@')) {
                $skipped[] = ['contact_id' => (int)$r['id'], 'email' => $email, 'reason' => 'invalid_email'];
                continue;
            }
            if ((int)($r['email_opt_out'] ?? 0) === 1) {
                $skipped[] = ['contact_id' => (int)$r['id'], 'email' => $email, 'reason' => 'opt_out'];
                continue;
            }
            $sup = crm_suppression_has($email);
            if ($sup) {
                $skipped[] = ['contact_id' => (int)$r['id'], 'email' => $email, 'reason' => 'suppressed:' . $sup['reason']];
                continue;
            }
            $send[] = $r;
        }
    }

    return ['send' => $send, 'skipped' => $skipped];
}

/** Konfiguracja segmentu kampanii w formie tablicy (z kolumn segment_config/segment_filter). */
function crm_campaign_segment_config(array $campaign): array {
    $cfg = json_decode((string)($campaign['segment_config'] ?? '{}'), true) ?: [];
    if (($campaign['segment_type'] ?? '') === 'filter') {
        $cfg['filter']     = json_decode((string)($campaign['segment_filter'] ?? '{}'), true) ?: ($cfg['filter'] ?? []);
        $cfg['segment_id'] = (int)($campaign['segment_id'] ?? 0) ?: null;
    }
    return $cfg;
}

// ─────────────────────────────────────────────────────────────────────────────
// Treść kampanii
// ─────────────────────────────────────────────────────────────────────────────

/** Dokument bloków dla kampanii: własny, a w razie braku — z szablonu. */
function crm_campaign_design(array $campaign): ?array {
    $own = crm_email_design_decode($campaign['design_json'] ?? null);
    if ($own) return $own;
    if (!empty($campaign['template_id'])) {
        $tpl = db_one("SELECT design_json FROM crm_templates WHERE id=?", [(int)$campaign['template_id']]);
        if ($tpl) return crm_email_design_decode($tpl['design_json'] ?? null);
    }
    return null;
}

/**
 * Buduje treść bazową kampanii (jeszcze bez personalizacji odbiorcy).
 *
 * @return array{html:string,text:string,links:array,warnings:array,source:string,subject:string}
 */
function crm_campaign_build_body(array $campaign): array {
    $template = !empty($campaign['template_id'])
        ? db_one("SELECT * FROM crm_templates WHERE id=?", [(int)$campaign['template_id']])
        : null;

    $design = crm_campaign_design($campaign);
    if ($design) {
        $out = crm_email_render($design, [
            'subject'   => (string)($campaign['subject'] ?? ''),
            'preheader' => (string)($campaign['preheader'] ?? ''),
            'tracking'  => true,
        ]);
        $out['source']  = 'design';
        $out['subject'] = (string)($campaign['subject'] ?: ($template['subject'] ?? ''));
        return $out;
    }

    // Ścieżka zgodności: surowy HTML szablonu (ręczny lub Mosaico).
    $html = (string)($template['body'] ?? '');
    return [
        'html'     => $html,
        'text'     => '',
        'links'    => crm_campaign_extract_links($html),
        'warnings' => $html === '' ? ['Szablon nie ma treści.'] : [],
        'source'   => 'template',
        'subject'  => (string)($campaign['subject'] ?: ($template['subject'] ?? '')),
    ];
}

/** Wyciąga adresy http(s) z surowego HTML-a (dla szablonów spoza edytora). */
function crm_campaign_extract_links(string $html): array {
    $links = [];
    if (preg_match_all('~href\s*=\s*["\']?(https?://[^"\'\s>]+)~i', $html, $m)) {
        foreach ($m[1] as $u) $links[html_entity_decode($u, ENT_QUOTES, 'UTF-8')] = true;
    }
    return array_keys($links);
}

/**
 * Rejestruje linki kampanii i zwraca mapę URL → ID.
 *
 * Kliknięcie jest liczone po ID, nie po URL-u w query stringu — dzięki temu
 * /crm/track/click.php nie jest już otwartym przekierowaniem.
 *
 * @return array<string,int>
 */
function crm_campaign_register_links(int $campaign_id, array $urls): array {
    $map = [];
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if (!preg_match('~^https?://~i', $url)) continue;
        try {
            $row = db_one("SELECT id FROM crm_campaign_links WHERE campaign_id=? AND url=?", [$campaign_id, $url]);
            if (!$row) {
                $id = db_insert('crm_campaign_links', [
                    'campaign_id' => $campaign_id,
                    'url'         => $url,
                    'label'       => mb_substr(preg_replace('~^https?://~i', '', $url), 0, 80),
                ]);
            } else {
                $id = (int)$row['id'];
            }
            $map[$url] = (int)$id;
        } catch (\Throwable $e) {}
    }
    return $map;
}

/**
 * Wstrzykuje tracking w SUROWY HTML (ścieżka zgodności dla szablonów spoza
 * edytora blokowego). Nowe kampanie z design_json dostają placeholdery już
 * na etapie renderu i nie przechodzą tędy.
 *
 * Zmiana względem poprzedniej wersji: docelowy URL nie ląduje w query stringu,
 * tylko jest zamieniany na identyfikator z crm_campaign_links.
 *
 * @param array<string,int> $link_ids
 */
function crm_campaign_inject_tracking(string $html, string $token, array $link_ids = []): string {
    $base = rtrim(APP_URL, '/');
    $t    = urlencode($token);

    if ($link_ids) {
        $html = preg_replace_callback(
            '~href\s*=\s*(["\'])(https?://[^"\']+)\1~i',
            function (array $m) use ($link_ids, $base, $t): string {
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                $id  = $link_ids[$url] ?? null;
                if (!$id) return $m[0];
                return 'href="' . htmlspecialchars($base . '/crm/track/click.php?t=' . $t . '&l=' . (int)$id, ENT_QUOTES) . '"';
            },
            $html
        );
    }

    $unsub_url = htmlspecialchars($base . '/crm/track/unsub.php?t=' . $t, ENT_QUOTES);
    $pixel = '<img src="' . htmlspecialchars($base . '/crm/track/open.php?t=' . $t, ENT_QUOTES)
           . '" width="1" height="1" style="display:none" alt="">';
    $unsub = '<div style="font-size:11px;color:#9CA3AF;text-align:center;padding:12px 0">'
           . 'Nie chcesz otrzymywać takich wiadomości? '
           . '<a href="' . $unsub_url . '" style="color:#6B7280">Wypisz się</a>'
           . '</div>';

    // Szablon może już mieć własny placeholder wypisania — wtedy nie doklejamy drugiego.
    if (str_contains($html, '{unsubscribe_url}')) {
        $html = str_replace('{unsubscribe_url}', $unsub_url, $html);
        $unsub = '';
    }

    $extra = $unsub . $pixel;
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $extra . '</body>', $html, 1);
    }
    return $html . $extra;
}

// ─────────────────────────────────────────────────────────────────────────────
// Zdarzenia
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Zapisuje zdarzenie kampanii. Wywoływane z publicznych endpointów trackingu,
 * więc nigdy nie rzuca — brak statystyki nie może zepsuć maila odbiorcy.
 */
function crm_campaign_log_event(
    int $campaign_id, ?int $recipient_id, ?int $contact_id, string $type,
    ?int $link_id = null, array $meta = []
): void {
    $types = ['queued', 'sent', 'delivered', 'open', 'click', 'unsubscribe',
              'hard_bounce', 'soft_bounce', 'complaint', 'failed', 'skipped'];
    if (!in_array($type, $types, true)) return;
    try {
        db_insert('crm_campaign_events', [
            'campaign_id'  => $campaign_id,
            'recipient_id' => $recipient_id,
            'contact_id'   => $contact_id,
            'type'         => $type,
            'link_id'      => $link_id,
            'ip'           => isset($meta['ip']) ? mb_substr((string)$meta['ip'], 0, 45) : null,
            'user_agent'   => isset($meta['ua']) ? mb_substr((string)$meta['ua'], 0, 255) : null,
            'detail'       => isset($meta['detail']) ? mb_substr((string)$meta['detail'], 0, 255) : null,
            'occurred_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {}
}

/** Odbiorca po tokenie trackingowym (wspólne dla open/click/unsub). */
function crm_campaign_recipient_by_token(string $token): ?array {
    if ($token === '') return null;
    try { return db_one("SELECT * FROM crm_campaign_recipients WHERE tracking_token=?", [$token]) ?: null; }
    catch (\Throwable $e) { return null; }
}

// ─────────────────────────────────────────────────────────────────────────────
// Wysyłka
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Kolejkuje wysyłkę kampanii: zamraża treść, rejestruje linki, tworzy wiersze
 * crm_campaign_recipients i zadania w mail_queue.
 *
 * Idempotentne — powtórne wywołanie (wznowienie po błędzie) pomija kontakty,
 * które już mają wiersz odbiorcy.
 */
function crm_campaign_queue_send(int $campaign_id): array {
    $campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]);
    if (!$campaign) return ['queued' => 0, 'error' => 'Nie znaleziono kampanii.'];
    if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
        return ['queued' => 0, 'error' => 'Kampania jest już w trakcie/została wysłana.'];
    }

    // 1) Treść — zamrażamy w chwili startu. Od tego momentu edycja szablonu ani
    //    designu nie zmienia tego, co poszło do odbiorców (i co mierzą statystyki).
    $built = crm_campaign_build_body($campaign);
    if (trim($built['html']) === '') {
        return ['queued' => 0, 'error' => 'Kampania nie ma treści — dodaj bloki w edytorze albo wybierz szablon.'];
    }
    $subject_tpl = $built['subject'] ?: 'Wiadomość';

    // 2) Linki → identyfikatory (klik po ID, nie po URL-u).
    $link_ids = crm_campaign_register_links($campaign_id, $built['links']);

    // 3) Odbiorcy + pominięci.
    $part      = crm_campaign_partition_recipients(
        (string)$campaign['segment_type'], crm_campaign_segment_config($campaign), (int)($campaign['purpose_id'] ?? 0)
    );
    $recipients = $part['send'];
    $skipped    = $part['skipped'];

    db()->prepare(
        "UPDATE crm_campaigns
            SET status='sending', recipients_count=?, skipped_count=?,
                html_snapshot=?, text_snapshot=?, send_started_at=COALESCE(send_started_at, datetime('now'))
          WHERE id=?"
    )->execute([count($recipients), count($skipped), $built['html'], $built['text'], $campaign_id]);

    // 4) Pominięci — zapisani jako odbiorcy ze statusem 'skipped'. Trafiają na
    //    listę w widoku kampanii razem z powodem, więc nikt nie musi zgadywać,
    //    dlaczego segment liczył 500 osób, a wyszło 470 maili.
    foreach ($skipped as $sk) {
        $exists = db_one("SELECT id FROM crm_campaign_recipients WHERE campaign_id=? AND contact_id=?", [$campaign_id, $sk['contact_id']]);
        if ($exists) continue;
        try {
            $rid = db_insert('crm_campaign_recipients', [
                'campaign_id'    => $campaign_id,
                'contact_id'     => $sk['contact_id'],
                'tracking_token' => bin2hex(random_bytes(32)),
                'status'         => 'skipped',
                'skip_reason'    => $sk['reason'],
            ]);
            crm_campaign_log_event($campaign_id, $rid, $sk['contact_id'], 'skipped', null, ['detail' => $sk['reason']]);
        } catch (\Throwable $e) {}
    }

    $base       = rtrim(APP_URL, '/');
    $use_design = $built['source'] === 'design';
    $queued     = 0;

    foreach ($recipients as $contact) {
        // Idempotencja: pomiń kontakt jeśli już zakolejkowany dla tej kampanii.
        $exists = db_one("SELECT id FROM crm_campaign_recipients WHERE campaign_id=? AND contact_id=?", [$campaign_id, $contact['id']]);
        if ($exists) continue;

        $token = bin2hex(random_bytes(32));

        $rendered_subject = CrmManager::renderTemplate($subject_tpl, $contact);
        $body_html = $use_design
            ? crm_email_personalize($built['html'], $contact, $token, $link_ids)
            : crm_campaign_inject_tracking(CrmManager::renderTemplate($built['html'], $contact), $token, $link_ids);
        $body_text = $built['text'] !== ''
            ? crm_email_personalize_text($built['text'], $contact, $token)
            : '';

        // Jednoklikowy wypis (RFC 8058). Nagłówek trafia do maila tylko na
        // ścieżce SMTP — Graph API nie pozwala ustawiać List-Unsubscribe.
        $headers = [
            'List-Unsubscribe'      => '<' . $base . '/crm/track/unsub.php?t=' . urlencode($token) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Campaign-Id'         => (string)$campaign_id,
        ];

        $mail_id = mail_queue_add(
            $contact['email'], (string)($contact['imie_nazwisko'] ?? ''), $rendered_subject ?: 'Wiadomość',
            $body_html, $body_text, 'crm_campaign', $campaign_id, '', false,
            [], '', [], [], $headers
        );

        $rid = db_insert('crm_campaign_recipients', [
            'campaign_id'    => $campaign_id,
            'contact_id'     => $contact['id'],
            'mail_queue_id'  => $mail_id,
            'tracking_token' => $token,
            'status'         => 'queued',
            'merge_data'     => json_encode(crm_email_merge_data($contact), JSON_UNESCAPED_UNICODE),
        ]);
        crm_campaign_log_event($campaign_id, $rid, (int)$contact['id'], 'queued');

        db_insert('crm_communications', [
            'contact_id'    => $contact['id'],
            'channel'       => 'email',
            'direction'     => 'out',
            'template_name' => 'kampania:' . $campaign['name'],
            'subject'       => $rendered_subject,
            'body'          => $body_html,
            'status'        => 'w kolejce',
            'sent_by'       => $campaign['created_by'],
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);

        $queued++;
    }

    crm_campaign_refresh_stats($campaign_id);
    return ['queued' => $queued, 'skipped' => count($skipped), 'warnings' => $built['warnings']];
}

/**
 * Wysyła pojedynczą wiadomość testową na wskazany adres — bez tworzenia
 * odbiorców, bez trackingu i bez dotykania statystyk kampanii.
 */
function crm_campaign_send_test(int $campaign_id, string $email): array {
    $campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]);
    if (!$campaign) return ['ok' => false, 'error' => 'Nie znaleziono kampanii.'];
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Nieprawidłowy adres e-mail.'];

    $built = crm_campaign_build_body($campaign);
    if (trim($built['html']) === '') return ['ok' => false, 'error' => 'Kampania nie ma treści.'];

    // Przykładowe dane personalizacji — z pierwszego kontaktu segmentu, a gdy
    // segment jest pusty, z danych zastępczych. Test ma pokazać, jak wygląda
    // wiadomość ze WSTAWIONYMI zmiennymi, a nie z surowymi {imie}.
    $sample = crm_campaign_resolve_recipients(
        (string)$campaign['segment_type'], crm_campaign_segment_config($campaign), (int)($campaign['purpose_id'] ?? 0)
    )[0] ?? ['imie_nazwisko' => 'Anna Przykładowa', 'email' => $email, 'organizacja' => 'Organizacja Testowa'];

    $token = 'test-' . bin2hex(random_bytes(8));
    $html  = crm_email_personalize($built['html'], $sample, $token);
    $html  = '<div style="background:#FEF3C7;color:#92400E;font:13px Arial,sans-serif;padding:10px;text-align:center">'
           . 'WIADOMOŚĆ TESTOWA — tracking nieaktywny, link wypisania nie działa.</div>' . $html;
    $text  = $built['text'] !== '' ? crm_email_personalize_text($built['text'], $sample, $token) : '';

    try {
        mail_queue_add(
            $email, '', '[TEST] ' . (CrmManager::renderTemplate($built['subject'] ?: 'Wiadomość', $sample)),
            $html, $text, 'crm_campaign_test', $campaign_id, '', true
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Nie udało się wysłać: ' . $e->getMessage()];
    }
    return ['ok' => true, 'warnings' => $built['warnings']];
}

// ─────────────────────────────────────────────────────────────────────────────
// Statystyki
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Przelicza liczniki kampanii: status wysyłki z mail_queue, zaangażowanie
 * z crm_campaign_events. Liczniki na crm_campaigns są cache'em dla list —
 * źródłem prawdy są zdarzenia.
 */
function crm_campaign_refresh_stats(int $campaign_id): void {
    $campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]);
    if (!$campaign) return;

    $rows = db_all(
        "SELECT cr.id, cr.contact_id, cr.status AS rcpt_status, mq.status AS mq_status, mq.last_error
         FROM crm_campaign_recipients cr
         LEFT JOIN mail_queue mq ON mq.id = cr.mail_queue_id
         WHERE cr.campaign_id=? AND cr.status <> 'skipped'", [$campaign_id]
    );

    $sent = $failed = $pending = 0;
    foreach ($rows as $r) {
        // Wiersz kolejki mógł już zostać wyczyszczony (mail_queue jest sprzątane),
        // a wtedy mq_status jest NULL. Dla domkniętych odbiorców opieramy się
        // wówczas na ich własnym statusie — inaczej stara kampania po czyszczeniu
        // kolejki pokazywałaby zero wysłanych.
        if ($r['mq_status'] === null && in_array($r['rcpt_status'], ['sent', 'failed'], true)) {
            if ($r['rcpt_status'] === 'sent') $sent++; else $failed++;
            continue;
        }
        if ($r['mq_status'] === 'sent') {
            $sent++;
            if ($r['rcpt_status'] === 'queued') {
                db()->prepare("UPDATE crm_campaign_recipients SET status='sent', sent_at=datetime('now') WHERE id=?")->execute([$r['id']]);
                crm_campaign_log_event($campaign_id, (int)$r['id'], (int)$r['contact_id'], 'sent');
            }
        } elseif ($r['mq_status'] === 'failed') {
            $failed++;
            if ($r['rcpt_status'] === 'queued') {
                db()->prepare("UPDATE crm_campaign_recipients SET status='failed' WHERE id=?")->execute([$r['id']]);
                crm_campaign_log_event($campaign_id, (int)$r['id'], (int)$r['contact_id'], 'failed', null, ['detail' => (string)($r['last_error'] ?? '')]);
                // Trwały błąd wysyłki traktujemy jak twarde odbicie tylko wtedy,
                // gdy tak brzmi komunikat serwera — inaczej wykluczylibyśmy adres
                // za chwilową awarię naszej własnej skrzynki.
                $err = mb_strtolower((string)($r['last_error'] ?? ''));
                if (preg_match('/(user unknown|no such user|mailbox (unavailable|not found)|does not exist|550|5\.1\.1)/', $err)) {
                    $c = db_one("SELECT email FROM crm_contacts WHERE id=?", [(int)$r['contact_id']]);
                    if ($c) crm_suppression_add((string)$c['email'], 'hard_bounce', $campaign_id, mb_substr($err, 0, 200));
                }
            }
        } else {
            $pending++;
        }
    }

    $ev = crm_campaign_stats($campaign_id);

    $status = $campaign['status'];
    if ($status === 'sending' && $pending === 0 && count($rows) > 0) $status = 'sent';

    db()->prepare(
        "UPDATE crm_campaigns
            SET sent_count=?, failed_count=?, opened_count=?, clicked_count=?,
                unsubscribed_count=?, bounced_count=?, status=?,
                sent_at = CASE WHEN ?='sent' AND sent_at IS NULL THEN datetime('now') ELSE sent_at END
          WHERE id=?"
    )->execute([
        $sent, $failed, $ev['unique_opens'], $ev['unique_clicks'],
        $ev['unsubscribes'], $ev['bounces'], $status, $status, $campaign_id,
    ]);
}

/**
 * Statystyki kampanii liczone ze zdarzeń.
 *
 * Współczynniki liczy warstwa prezentacji — tutaj tylko liczby, żeby nie
 * ukrywać dzielenia przez zero w SQL-u.
 */
function crm_campaign_stats(int $campaign_id): array {
    $one = function (string $sql, array $p = []) use ($campaign_id): int {
        try { return (int)(db_one($sql, array_merge([$campaign_id], $p))['n'] ?? 0); }
        catch (\Throwable $e) { return 0; }
    };

    return [
        'recipients'    => $one("SELECT COUNT(*) n FROM crm_campaign_recipients WHERE campaign_id=? AND status <> 'skipped'"),
        'skipped'       => $one("SELECT COUNT(*) n FROM crm_campaign_recipients WHERE campaign_id=? AND status = 'skipped'"),
        'sent'          => $one("SELECT COUNT(*) n FROM crm_campaign_recipients WHERE campaign_id=? AND status IN ('sent')"),
        'failed'        => $one("SELECT COUNT(*) n FROM crm_campaign_recipients WHERE campaign_id=? AND status='failed'"),
        'pending'       => $one("SELECT COUNT(*) n FROM crm_campaign_recipients WHERE campaign_id=? AND status='queued'"),
        'unique_opens'  => $one("SELECT COUNT(DISTINCT recipient_id) n FROM crm_campaign_events WHERE campaign_id=? AND type='open'"),
        'total_opens'   => $one("SELECT COUNT(*) n FROM crm_campaign_events WHERE campaign_id=? AND type='open'"),
        'unique_clicks' => $one("SELECT COUNT(DISTINCT recipient_id) n FROM crm_campaign_events WHERE campaign_id=? AND type='click'"),
        'total_clicks'  => $one("SELECT COUNT(*) n FROM crm_campaign_events WHERE campaign_id=? AND type='click'"),
        'unsubscribes'  => $one("SELECT COUNT(DISTINCT recipient_id) n FROM crm_campaign_events WHERE campaign_id=? AND type='unsubscribe'"),
        'bounces'       => $one("SELECT COUNT(DISTINCT recipient_id) n FROM crm_campaign_events WHERE campaign_id=? AND type IN ('hard_bounce','soft_bounce')"),
        'complaints'    => $one("SELECT COUNT(DISTINCT recipient_id) n FROM crm_campaign_events WHERE campaign_id=? AND type='complaint'"),
    ];
}

/** Ranking klikniętych linków — najbardziej praktyczna metryka kampanii. */
function crm_campaign_top_links(int $campaign_id, int $limit = 20): array {
    try {
        return db_all(
            "SELECT l.id, l.url, l.label,
                    COUNT(e.id) AS clicks,
                    COUNT(DISTINCT e.recipient_id) AS unique_clicks
               FROM crm_campaign_links l
               LEFT JOIN crm_campaign_events e ON e.link_id = l.id AND e.type='click'
              WHERE l.campaign_id = ?
              GROUP BY l.id, l.url, l.label
              HAVING clicks > 0
              ORDER BY clicks DESC
              LIMIT " . max(1, min(100, $limit)), [$campaign_id]
        );
    } catch (\Throwable $e) { return []; }
}

/** Rozbicie pominiętych odbiorców na powody — do wyjaśnienia „gdzie reszta". */
function crm_campaign_skip_breakdown(int $campaign_id): array {
    try {
        $rows = db_all(
            "SELECT skip_reason, COUNT(*) AS n FROM crm_campaign_recipients
              WHERE campaign_id=? AND status='skipped' GROUP BY skip_reason ORDER BY n DESC", [$campaign_id]
        );
    } catch (\Throwable $e) { return []; }

    $labels = [
        'no_consent'    => 'brak zgody na cel wysyłki',
        'opt_out'       => 'wypisani (opt-out)',
        'invalid_email' => 'brak / błędny adres e-mail',
    ];
    return array_map(function (array $r) use ($labels): array {
        $code = (string)$r['skip_reason'];
        $label = $labels[$code] ?? null;
        if ($label === null && str_starts_with($code, 'suppressed:')) {
            $sub = substr($code, strlen('suppressed:'));
            $label = 'lista wykluczeń — ' . ([
                'hard_bounce' => 'adres nie istnieje',
                'soft_bounce' => 'adres tymczasowo niedostępny',
                'complaint'   => 'zgłoszenie spamu',
                'unsubscribe' => 'wypisanie',
                'manual'      => 'decyzja operatora',
                'rodo'        => 'żądanie RODO',
            ][$sub] ?? $sub);
        }
        return ['reason' => $code, 'label' => $label ?? $code, 'n' => (int)$r['n']];
    }, $rows);
}

/** Historia wysyłek dla karty kontaktu (timeline). */
function crm_campaign_contact_history(int $contact_id, int $limit = 50): array {
    try {
        return db_all(
            "SELECT e.type, e.occurred_at, e.link_id, c.id AS campaign_id, c.name AS campaign_name, l.url AS link_url
               FROM crm_campaign_events e
               JOIN crm_campaigns c ON c.id = e.campaign_id
               LEFT JOIN crm_campaign_links l ON l.id = e.link_id
              WHERE e.contact_id = ? AND e.type IN ('sent','open','click','unsubscribe','hard_bounce','soft_bounce','failed')
              ORDER BY e.occurred_at DESC, e.id DESC
              LIMIT " . max(1, min(200, $limit)), [$contact_id]
        );
    } catch (\Throwable $e) { return []; }
}

/** Walidacja przed wysyłką — zwraca listę problemów blokujących start. */
function crm_campaign_validate(array $campaign): array {
    $problems = [];
    if (trim((string)($campaign['name'] ?? '')) === '') $problems[] = 'Kampania nie ma nazwy.';
    if (trim((string)($campaign['subject'] ?? '')) === '') $problems[] = 'Brak tematu wiadomości.';

    $built = crm_campaign_build_body($campaign);
    if (trim($built['html']) === '') {
        $problems[] = 'Kampania nie ma treści — dodaj bloki w edytorze albo wybierz szablon.';
    }
    // Brak linku wypisania blokuje wysyłkę. Nie jest to ostrzeżenie „do rozważenia":
    // masowa wysyłka bez możliwości wypisania jest po prostu niedopuszczalna.
    if ($built['source'] === 'design' && !str_contains($built['html'], '{unsubscribe_url}')) {
        $problems[] = 'Treść nie zawiera linku wypisania — dodaj blok „Stopka".';
    }

    $part = crm_campaign_partition_recipients(
        (string)($campaign['segment_type'] ?? 'tags'),
        crm_campaign_segment_config($campaign),
        (int)($campaign['purpose_id'] ?? 0)
    );
    if (!$part['send']) {
        $problems[] = 'Segment nie zawiera ani jednego odbiorcy, do którego wolno napisać.';
    }

    return $problems;
}
