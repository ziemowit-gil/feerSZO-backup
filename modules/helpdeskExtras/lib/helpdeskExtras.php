<?php
/**
 * modules/helpdeskExtras/lib/helpdeskExtras.php — dodatkowe funkcje Helpdesku:
 *   1. cele SLA ze Słownika Centralnego (helpdesk_sla),
 *   2. @wzmianki operatorów w notatkach wewnętrznych (powiadomienie e-mail),
 *   3. problemy nadrzędne (powiązane zgłoszenia, zbiorcze rozwiązanie),
 *   4. powiązanie zgłoszenia ze sprzętem / zasobem (rejestr Zasoby) lub wpisem ręcznym (np. nr inwentarzowy),
 *   5. baza wiedzy: podpowiedzi artykułów Portalu Usług IT i wstawianie linku do odpowiedzi,
 *   6. obsługa licencji AM: karta wniosku na zgłoszeniu i zapis zmian statusu w historii wniosku (modules/ti_licencje_dydaktyczne).
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';

// ── 1. Cele SLA ze słownika ──────────────────────────────────────────────────

const HD_SLA_DICT = 'helpdesk_sla';

function hdSlaSeed(): string
{
    $entries = []; $i = 0;
    foreach (HD_PRIORITIES as $code => $p)
        $entries[] = ['code' => $code, 'label' => $p['label'], 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true, 'description' => null,
                      'attributes' => ['sla_response' => (int)$p['sla_response'], 'sla_resolve' => (int)$p['sla_resolve']]];
    return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
        ['code' => HD_SLA_DICT, 'name' => 'Helpdesk — cele SLA wg priorytetu', 'kind' => 'system', 'parent_code' => null, 'is_protected' => false, 'sort_order' => 72,
         'description' => 'Cele czasowe SLA w minutach dla priorytetów zgłoszeń. Atrybuty: sla_response = czas na pierwszą odpowiedź, sla_resolve = czas na rozwiązanie. Liczone w godzinach pracy lub kalendarzowo — zob. Helpdesk › Ustawienia › SLA. Kody wpisów (niski, normalny, wysoki, krytyczny) są używane w kodzie.',
         'entries' => $entries],
    ]], JSON_UNESCAPED_UNICODE);
}

/** [reakcja, rozwiązanie] w minutach dla priorytetu — ze słownika, zapas: HD_PRIORITIES. Nigdy nie rzuca wyjątku. */
function hdSlaTargets(string $priority): array
{
    static $dict = null;
    if ($dict === null) {
        $dict = [];
        require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
        foreach (dictItems(HD_SLA_DICT) as $code => $it) {
            $a = (array)($it['attributes'] ?? []);
            $r = (int)($a['sla_response'] ?? 0); $s = (int)($a['sla_resolve'] ?? 0);
            if ($r > 0 && $s > 0) $dict[$code] = [$r, $s];
        }
    }
    $d = HD_PRIORITIES[$priority] ?? null;
    return $dict[$priority] ?? ($d ? [(int)$d['sla_response'], (int)$d['sla_resolve']] : [0, 0]);
}

// ── 2. @wzmianki ─────────────────────────────────────────────────────────────

/** Operatorzy wskazani w treści jako @imię, @imie.nazwisko albo @login-z-maila. Zwraca wiersze users (id, name, email). */
function hdMentionsFind(string $body): array
{
    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], ' ', $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match_all('/(?<![\p{L}\p{N}.@])@([\p{L}][\p{L}\p{N}._-]{1,40})/u', $text, $m)) return [];
    $ops = db_all("SELECT id, name, email FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL AND email<>''");
    $found = [];
    foreach (array_unique(array_map(fn($t) => mb_strtolower(rtrim($t, '._-')), $m[1])) as $tok) {
        $cands = [];
        foreach ($ops as $o) {
            $name = mb_strtolower($o['name']);
            $local = mb_strtolower(strstr($o['email'], '@', true));
            $flat = str_replace([' ', '.', '_', '-'], '', $name);
            $tokFlat = str_replace(['.', '_', '-'], '', $tok);
            if ($tok === $local || $tokFlat === $flat || str_replace(['.', '_', '-'], ' ', $tok) === $name) { $cands = [$o]; break; }      // dokładne dopasowanie
            if ($tok === (explode(' ', $name)[0] ?? '')) $cands[] = $o;                                                                       // samo imię
        }
        if (count($cands) === 1) $found[(int)$cands[0]['id']] = $cands[0];     // niejednoznaczne imię (kilka osób) pomijamy
    }
    return array_values($found);
}

/** Powiadamia wskazanych operatorów (poza autorem). Zwraca listę imion powiadomionych. */
function hdMentionsNotify(array $ticket, string $body, int $authorUid, string $authorName): array
{
    $sent = [];
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        foreach (hdMentionsFind($body) as $u) {
            if ((int)$u['id'] === $authorUid || !hdNotifyAllows($u['email'], 'mention', $ticket)) continue;
            mail_queue_add($u['email'], $u['name'], '[' . $ticket['number'] . '] ' . $authorName . ' oznaczył(a) Cię w notatce',
                hd_mail_layout(['title' => 'Wzmianka w notatce', 'preheader' => $authorName . ' oznaczył(a) Cię w zgłoszeniu ' . $ticket['number'],
                    'facts' => [['Zgłoszenie', $ticket['number']], ['Temat', $ticket['title']]],
                    'quote' => ['label' => $authorName, 'html' => hdMailText($body)],
                    'cta' => ['label' => 'Otwórz zgłoszenie', 'url' => hd_base_url() . '/helpdesk/view.php?id=' . (int)$ticket['id']]]));
            $sent[] = $u['name'];
        }
    } catch (\Throwable $e) { error_log('[helpdeskExtras] wzmianki: ' . $e->getMessage()); }
    return $sent;
}

// ── 3. Problemy nadrzędne ────────────────────────────────────────────────────

function hdExtrasMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_assets (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        resource_id INTEGER,
        label      TEXT NOT NULL,
        added_by   INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hd_assets_ticket ON helpdesk_assets(ticket_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hd_assets_label ON helpdesk_assets(label)");
}

function hdProblemChildren(int $parentId): array
{
    hdExtrasMigrate();
    return db_all("SELECT id, number, title, status FROM helpdesk_tickets WHERE problem_id=? AND merged_into IS NULL ORDER BY id", [$parentId]);
}

/** Powiązanie z problemem nadrzędnym po numerze. Jeden poziom (problem nie może sam być zgłoszeniem podrzędnym). Zwraca błąd albo null. */
function hdProblemSet(int $ticketId, string $parentNumber): ?string
{
    hdExtrasMigrate();
    require_once dirname(__DIR__, 2) . '/helpdeskMigrate/lib/helpdeskMigrate.php';
    $p = hdTicketByAnyNumber(trim($parentNumber));
    if (!$p) return 'Nie znaleziono zgłoszenia o numerze: ' . $parentNumber;
    if ((int)$p['id'] === $ticketId) return 'Zgłoszenie nie może być własnym problemem nadrzędnym.';
    if (!empty($p['problem_id'])) return 'To zgłoszenie samo jest podrzędne — wybierz jego problem nadrzędny.';
    if (db_one("SELECT 1 FROM helpdesk_tickets WHERE problem_id=?", [$ticketId])) return 'To zgłoszenie ma własne zgłoszenia podrzędne — nie może być podrzędne.';
    db_update('helpdesk_tickets', ['problem_id' => (int)$p['id'], 'updated_at' => date('Y-m-d H:i:s')], $ticketId);
    db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => null, 'user_name' => 'System', 'is_internal' => 1, 'body' => 'Powiązano z problemem nadrzędnym ' . $p['number'] . '.']);
    db_insert('helpdesk_messages', ['ticket_id' => (int)$p['id'], 'user_id' => null, 'user_name' => 'System', 'is_internal' => 1,
        'body' => 'Dołączono zgłoszenie podrzędne ' . (db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$ticketId])['number'] ?? '#' . $ticketId) . '.']);
    return null;
}

function hdProblemClear(int $ticketId): void
{
    hdExtrasMigrate();
    db_update('helpdesk_tickets', ['problem_id' => null, 'updated_at' => date('Y-m-d H:i:s')], $ticketId);
    db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => null, 'user_name' => 'System', 'is_internal' => 1, 'body' => 'Odłączono od problemu nadrzędnego.']);
}

/**
 * Rozwiązuje wszystkie otwarte zgłoszenia podrzędne problemu: status „rozwiązane”, wiadomość publiczna do zgłaszających
 * (z numerem problemu), powiadomienia jak przy zwykłej zmianie. Zwraca liczbę rozwiązanych.
 */
function hdProblemResolveChildren(int $parentId, string $message, int $uid, string $uname): int
{
    $parent = db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$parentId]);
    if (!$parent) return 0;
    $n = 0; $now = date('Y-m-d H:i:s');
    foreach (hdProblemChildren($parentId) as $c) {
        if (in_array($c['status'], ['rozwiązane', 'zamknięte'], true)) continue;
        $old = $c['status'];
        db_update('helpdesk_tickets', ['status' => 'rozwiązane', 'resolved_at' => $now, 'updated_at' => $now], (int)$c['id']);
        $body = trim($message) !== '' ? trim($message) : 'Problem ' . $parent['number'] . ', z którym powiązane jest to zgłoszenie, został rozwiązany.';
        $mid = db_insert('helpdesk_messages', ['ticket_id' => (int)$c['id'], 'user_id' => $uid ?: null, 'user_name' => $uname, 'body' => $body, 'is_internal' => 0]);
        $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$c['id']]);
        if ($t) {
            try { hd_notify_status_change($t, $old, 'rozwiązane', $body); } catch (\Throwable $e) {}
        }
        $n++;
    }
    if ($n) db_insert('helpdesk_messages', ['ticket_id' => $parentId, 'user_id' => $uid ?: null, 'user_name' => $uname, 'is_internal' => 1, 'body' => 'Rozwiązano zgłoszenia podrzędne: ' . $n . '.']);
    return $n;
}

// ── 4. Sprzęt / zasób ────────────────────────────────────────────────────────

function hdAssetsList(int $ticketId): array
{
    hdExtrasMigrate();
    $rows = db_all("SELECT a.*, r.name AS resource_name FROM helpdesk_assets a LEFT JOIN resources r ON r.id=a.resource_id WHERE a.ticket_id=? ORDER BY a.id", [$ticketId]);
    foreach ($rows as &$r) {
        $r['others'] = db_all("SELECT DISTINCT t.id, t.number, t.status FROM helpdesk_assets a2 JOIN helpdesk_tickets t ON t.id=a2.ticket_id
                               WHERE a2.ticket_id<>? AND (" . ($r['resource_id'] ? 'a2.resource_id=?' : 'lower(a2.label)=lower(?)') . ") ORDER BY t.id DESC LIMIT 5",
                              [$ticketId, $r['resource_id'] ?: $r['label']]);
    }
    return $rows;
}

function hdAssetsResources(): array
{
    try { return db_all("SELECT id, name FROM resources WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) { return []; }
}

/** Dodaje sprzęt: $resourceId (z rejestru Zasoby) albo $label (wpis ręczny, np. „Laptop Dell #123”). Zwraca błąd albo null. */
function hdAssetAdd(int $ticketId, int $resourceId, string $label, int $uid): ?string
{
    hdExtrasMigrate();
    $label = mb_substr(trim($label), 0, 120);
    if ($resourceId > 0) {
        $r = db_one("SELECT id, name FROM resources WHERE id=?", [$resourceId]);
        if (!$r) return 'Nie znaleziono zasobu.';
        if (db_one("SELECT 1 FROM helpdesk_assets WHERE ticket_id=? AND resource_id=?", [$ticketId, $resourceId])) return 'Ten zasób jest już powiązany ze zgłoszeniem.';
        db_insert('helpdesk_assets', ['ticket_id' => $ticketId, 'resource_id' => $resourceId, 'label' => (string)$r['name'], 'added_by' => $uid ?: null]);
        return null;
    }
    if ($label === '') return 'Wybierz zasób albo wpisz nazwę / numer sprzętu.';
    if (db_one("SELECT 1 FROM helpdesk_assets WHERE ticket_id=? AND resource_id IS NULL AND lower(label)=lower(?)", [$ticketId, $label])) return 'Ten sprzęt jest już powiązany ze zgłoszeniem.';
    db_insert('helpdesk_assets', ['ticket_id' => $ticketId, 'resource_id' => null, 'label' => $label, 'added_by' => $uid ?: null]);
    return null;
}

function hdAssetRemove(int $ticketId, int $assetId): void
{
    hdExtrasMigrate();
    db()->prepare("DELETE FROM helpdesk_assets WHERE id=? AND ticket_id=?")->execute([$assetId, $ticketId]);
}

// ── 5. Baza wiedzy (Portal Usług IT) ─────────────────────────────────────────

/**
 * Artykuły opublikowane w aktywnych usługach Portalu Usług IT pasujące do zapytania (słowa ≥3 znaki; punktacja: tytuł 3,
 * słowa kluczowe 2, treść 1). $publicOnly — tylko usługi bez ograniczenia do grup odbiorców (dla zgłaszających). @return list<array{id:int,title:string,category:string,url:string,excerpt:string}>
 */
function hdKbSearch(string $q, int $limit = 5, bool $publicOnly = false): array
{
    static $stop = ['brak','nie','jak','dla','oraz','przez','czy','jest','się','sie','mam','mój','moj','moje','nasz','który','która','gdy','kiedy','proszę','prosze','bardzo','tego','tym','ten','ale','lub','jestem','mogę','moge','chcę','chce','potrzebuję','potrzebuje','pomocy','pomoc','problem','problemy','zgłoszenie'];
    $words = array_values(array_unique(array_filter(preg_split('/[\s,.;:!?()\[\]"\'\/\\\\-]+/u', mb_strtolower($q)), fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stop, true))));
    if (!$words) return [];
    try {
        $rows = db_all("SELECT a.id, a.title, a.slug, a.keywords, a.body_plain, c.name AS cat_name, c.slug AS cat_slug
                        FROM itp_articles a JOIN itp_categories c ON c.id=a.category_id
                        WHERE a.is_published=1 AND c.is_active=1" . ($publicOnly ? " AND (c.audiences = '[]' OR c.audiences = '')" : ''));
    } catch (\Throwable $e) { return []; }
    $scored = [];
    foreach ($rows as $r) {
        $t = mb_strtolower($r['title']); $k = mb_strtolower($r['keywords']); $b = mb_strtolower($r['body_plain']);
        $sc = 0;
        foreach (array_slice($words, 0, 8) as $w) {
            if (mb_strpos($t, $w) !== false) $sc += 3;
            if (mb_strpos($k, $w) !== false) $sc += 2;
            if (mb_strpos($b, $w) !== false) $sc += 1;
        }
        if ($sc > 0) $scored[] = [$sc, $r];
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    $out = [];
    foreach (array_slice($scored, 0, $limit) as [$sc, $r]) {
        $plain = trim(preg_replace('/\s+/u', ' ', (string)$r['body_plain']));
        $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'category' => $r['cat_name'],
                  'url' => rtrim(APP_URL, '/') . '/uslugi-it/uslugi/' . rawurlencode($r['cat_slug']) . '/' . rawurlencode($r['slug']),
                  'excerpt' => mb_substr($plain, 0, 140) . (mb_strlen($plain) > 140 ? '…' : '')];
    }
    return $out;
}


// ── 6. Obsługa licencji AM ───────────────────────────────────────────────────

const HD_AM_PANEL = '/karty30/ti/dydaktyk/licencjeDydaktyczne.php';

/**
 * Wniosek AM powiązany ze zgłoszeniem: decyzja AM5/AM7 (tabela licenses) albo operacja AM11–AM24 (license_operations).
 * @return array{kind:string,id:int,number:string,title:string,person:string,status:string,due:string,url:string,label:string}|null
 */
function hdAmFor(int $ticketId): ?array
{
    try {
        $l = db_one("SELECT id, case_number, title, interested_name, request_status, due_on, license_kind FROM licenses WHERE helpdesk_ticket_id=?", [$ticketId]);
        if ($l) return ['kind' => 'license', 'id' => (int)$l['id'], 'number' => (string)$l['case_number'], 'title' => (string)$l['title'], 'person' => (string)$l['interested_name'],
            'status' => (string)$l['request_status'], 'due' => (string)$l['due_on'], 'label' => 'Decyzja AM' . ($l['license_kind'] !== '' ? ' (' . $l['license_kind'] . ')' : ''),
            'url' => APP_URL . HD_AM_PANEL . '?_route=' . rawurlencode('/licencje/' . (int)$l['id'])];
        $o = db_one("SELECT id, number, form, status, account_name, due_on FROM license_operations WHERE helpdesk_ticket_id=?", [$ticketId]);
        if ($o) return ['kind' => 'operation', 'id' => (int)$o['id'], 'number' => (string)$o['number'], 'title' => (string)$o['form'], 'person' => (string)$o['account_name'],
            'status' => (string)$o['status'], 'due' => (string)$o['due_on'], 'label' => 'Operacja ' . $o['form'],
            'url' => APP_URL . HD_AM_PANEL . '?_route=' . rawurlencode('/operacje/' . (int)$o['id'])];
    } catch (\Throwable $e) { /* brak modułu licencji — zgłoszenie jak każde inne */ }
    return null;
}

/** Zapis zmiany po stronie Helpdesku w historii wniosku AM (license_events). Nigdy nie rzuca wyjątku. */
function hdAmEvent(int $ticketId, string $note, string $actorName = ''): void
{
    try {
        $am = hdAmFor($ticketId);
        if (!$am) return;
        $t = db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        db_insert('license_events', [
            'license_id' => $am['kind'] === 'license' ? $am['id'] : 0, 'operation_id' => $am['kind'] === 'operation' ? $am['id'] : null,
            'action' => 'helpdesk.status', 'note' => mb_substr('Helpdesk ' . ($t['number'] ?? '#' . $ticketId) . ': ' . $note, 0, 2000),
            'actor_id' => null, 'actor_name' => $actorName !== '' ? $actorName : 'Helpdesk', 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { error_log('[helpdeskExtras] AM: ' . $e->getMessage()); }
}
