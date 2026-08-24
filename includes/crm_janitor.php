<?php
/**
 * includes/crm_janitor.php — bot sprzątający kartotekę CRM.
 *
 * Baza CRM brudzi się sama: auto-kartoteka ze Skrzynki zakłada rekord każdemu
 * nadawcy, import CSV wnosi spacje i wielkie litery w adresach, sync Outlooka
 * dokłada duplikaty, a kontakty z jednego urzędu rozłażą się na dwadzieścia
 * osobnych wpisów. Nikt tego nie ogarnia ręcznie, bo brud narasta po trochu.
 *
 * ─── PODZIAŁ NA DWIE KLASY REGUŁ ───────────────────────────────────────────
 *
 * NAPRAWIANE SAME (`auto`) — wyłącznie zmiany KOSMETYCZNE i odwracalne:
 * przycięcie spacji, adres e-mail małymi literami, NIP bez myślników. Nic
 * z tego nie zmienia znaczenia rekordu, a każda poprawka i tak przechodzi
 * przez CrmManager::updateContact(), więc ląduje w historii zmian kartoteki
 * i widać, co bot ruszył.
 *
 * TYLKO PROPONOWANE (`propose`) — wszystko, co zmienia sens albo czego nie
 * cofa jedno kliknięcie: scalenie duplikatów, przypięcie osoby do podmiotu,
 * usunięcie pustej kartoteki. Bot je ZNAJDUJE i opisuje, decyzję podejmuje
 * człowiek. Ta granica jest celowa: automat, który sam kasuje kontakty, przy
 * pierwszej pomyłce kosztuje więcej, niż oszczędził przez rok.
 *
 * Znaleziska trafiają do `crm_janitor_findings` i mają tam swój cykl życia
 * (otwarte → załatwione albo odrzucone). Odrzucone nie wracają — inaczej bot
 * co tydzień proponowałby to samo, co człowiek już świadomie odrzucił.
 */

declare(strict_types=1);

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crm_merge.php';
require_once __DIR__ . '/crm_domains.php';

/**
 * Katalog reguł.
 *
 * `mode`     auto = bot naprawia sam, propose = bot tylko zgłasza
 * `severity` high  = warto zająć się w tym tygodniu, low = porządki
 */
function crm_janitor_rules(): array
{
    return [
        // ── Kosmetyka naprawiana automatycznie ────────────────────────────
        'trim_spaces' => [
            'mode' => 'auto', 'severity' => 'low',
            'label' => 'Zbędne spacje w polach',
            'desc'  => 'Spacje na początku i końcu nazwy, e-maila, telefonu albo organizacji.',
        ],
        'lower_email' => [
            'mode' => 'auto', 'severity' => 'low',
            'label' => 'Adres e-mail wielkimi literami',
            'desc'  => 'Adresy sprowadzone do małych liter — inaczej ten sam adres wygląda na dwa różne.',
        ],
        'clean_nip' => [
            'mode' => 'auto', 'severity' => 'low',
            'label' => 'NIP i REGON ze znakami',
            'desc'  => 'Myślniki i spacje usunięte — numer ma być porównywalny.',
        ],
        'fix_www' => [
            'mode' => 'auto', 'severity' => 'low',
            'label' => 'Strona WWW bez protokołu',
            'desc'  => 'Dopisany https://, żeby odnośnik działał i dało się wyciągnąć domenę.',
        ],

        // ── Wymaga decyzji człowieka ──────────────────────────────────────
        'duplicates' => [
            'mode' => 'propose', 'severity' => 'high',
            'label' => 'Podejrzenie duplikatu',
            'desc'  => 'Dwie kartoteki o tym samym NIP-ie, adresie albo telefonie.',
            'link'  => '/crm/contact/merge.php',
        ],
        'domain_attach' => [
            'mode' => 'propose', 'severity' => 'high',
            'label' => 'Osoby do przypięcia pod podmiot',
            'desc'  => 'Podmiot jest w bazie, a osoby z jego domeny leżą jako osobne kartoteki.',
            'link'  => '/crm/contact/domains.php',
        ],
        'empty_contact' => [
            'mode' => 'propose', 'severity' => 'low',
            'label' => 'Kartoteka bez treści',
            'desc'  => 'Brak e-maila i telefonu, zero historii, wpis starszy niż 30 dni.',
        ],
        'no_owner' => [
            'mode' => 'propose', 'severity' => 'low',
            'label' => 'Kontakty bez opiekuna',
            'desc'  => 'Nikt nie prowadzi tej relacji.',
            'link'  => '/crm/index.php?owner=none',
        ],
        'stale_cases' => [
            'mode' => 'propose', 'severity' => 'low',
            'label' => 'Sprawy bez ruchu',
            'desc'  => 'Otwarte sprawy, w których od dawna nic się nie dzieje.',
            'link'  => '/crm/cases/stale.php',
        ],
    ];
}

/** Czy reguła jest włączona (domyślnie tak). Wyłączanie: settings crm_janitor_off. */
function crm_janitor_rule_enabled(string $rule, bool $refresh = false): bool
{
    static $off = null;
    if ($off === null || $refresh) {
        $raw = '';
        try { $raw = (string)(db_one("SELECT value FROM settings WHERE key_='crm_janitor_off'")['value'] ?? ''); }
        catch (\Throwable $e) {}
        $off = array_filter(array_map('trim', explode(',', $raw)));
    }
    return !in_array($rule, $off, true);
}

/**
 * Zapisuje, które reguły są WŁĄCZONE. Przechowujemy listę wyłączonych, nie
 * włączonych — dzięki temu reguła dopisana w kolejnej wersji działa od razu
 * u wszystkich, zamiast czekać, aż ktoś ją sobie zaznaczy.
 */
function crm_janitor_set_enabled(array $enabled_rules): void
{
    $off = [];
    foreach (array_keys(crm_janitor_rules()) as $rule) {
        if (!in_array($rule, $enabled_rules, true)) $off[] = $rule;
    }
    org_setting_set('crm_janitor_off', implode(',', $off));
    crm_janitor_rule_enabled('', true);   // odśwież pamięć podręczną w tym żądaniu
}

/** Ostatnie przebiegi bota. */
function crm_janitor_runs(int $limit = 20): array
{
    crm_janitor_migrate();
    try { return db_all("SELECT * FROM crm_janitor_runs ORDER BY id DESC LIMIT ?", [$limit]); }
    catch (\Throwable $e) { return []; }
}

function crm_janitor_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    // ref_key trzyma to, czego znalezisko dotyczy (id kontaktu, para id, klucz
    // grupy). UNIQUE po (rule, ref_key) sprawia, że kolejny przebieg nie mnoży
    // tego samego zgłoszenia — a odrzucone zostaje odrzucone.
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_janitor_findings (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        rule        TEXT    NOT NULL,
        ref_key     TEXT    NOT NULL,
        contact_id  INTEGER REFERENCES crm_contacts(id) ON DELETE CASCADE,
        severity    TEXT    NOT NULL DEFAULT 'low',
        title       TEXT    NOT NULL DEFAULT '',
        detail      TEXT    NOT NULL DEFAULT '',
        payload     TEXT    NOT NULL DEFAULT '',
        status      TEXT    NOT NULL DEFAULT 'open',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME,
        resolved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE(rule, ref_key)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_janitor_status ON crm_janitor_findings(status, severity)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_janitor_runs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        started_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        fixed       INTEGER NOT NULL DEFAULT 0,
        found       INTEGER NOT NULL DEFAULT 0,
        summary     TEXT    NOT NULL DEFAULT '',
        dry_run     INTEGER NOT NULL DEFAULT 0
    )");
}

/** Zapisuje znalezisko. Istniejące otwarte tylko odświeża (liczby się zmieniają). */
function crm_janitor_add(string $rule, string $ref_key, array $data): void
{
    $cfg = crm_janitor_rules()[$rule] ?? [];
    try {
        db()->prepare(
            "INSERT INTO crm_janitor_findings (rule, ref_key, contact_id, severity, title, detail, payload)
             VALUES (?,?,?,?,?,?,?)
             ON CONFLICT(rule, ref_key) DO UPDATE SET
                detail  = excluded.detail,
                payload = excluded.payload
              WHERE crm_janitor_findings.status = 'open'"
        )->execute([
            $rule, $ref_key,
            $data['contact_id'] ?? null,
            $cfg['severity'] ?? 'low',
            (string)($data['title'] ?? ($cfg['label'] ?? $rule)),
            (string)($data['detail'] ?? ''),
            json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) { /* znalezisko nie może wywrócić przebiegu */ }
}

// ─────────────────────────────────────────────────────────────────────────────
// REGUŁY KOSMETYCZNE — bot naprawia sam
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Poprawki kosmetyczne na jednym kontakcie.
 *
 * @return array<string,array{0:string,1:string}> pole => [przed, po]
 */
function crm_janitor_cosmetic_fixes(array $c): array
{
    $fix = [];

    if (crm_janitor_rule_enabled('trim_spaces')) {
        foreach (['imie_nazwisko', 'email', 'telefon', 'organizacja', 'stanowisko'] as $f) {
            $v = (string)($c[$f] ?? '');
            if ($v === '') continue;
            // Zbijamy też podwójne spacje w środku — „Jan  Kowalski" i „Jan Kowalski"
            // to dla wyszukiwarki i dla wykrywania duplikatów dwa różne napisy.
            $n = trim(preg_replace('~\s{2,}~u', ' ', $v) ?? $v);
            if ($n !== $v) $fix[$f] = [$v, $n];
        }
    }

    if (crm_janitor_rule_enabled('lower_email')) {
        $v = (string)($c['email'] ?? '');
        // Uwaga: lokalna część adresu bywa formalnie wrażliwa na wielkość liter,
        // ale w praktyce żaden używany serwer tego nie egzekwuje, a rozjazd
        // wielkości psuje dopasowanie nadawcy do kartoteki.
        $n = mb_strtolower(trim($v));
        if ($v !== '' && $n !== $v) $fix['email'] = [$v, $n];
    }

    if (crm_janitor_rule_enabled('clean_nip')) {
        foreach (['nip', 'regon', 'krs'] as $f) {
            $v = (string)($c[$f] ?? '');
            if ($v === '') continue;
            $n = preg_replace('~\D~', '', $v) ?? $v;
            // Poprawiamy tylko wtedy, gdy zostaje sensowny numer — „brak"
            // albo „nie dotyczy" ma zostać, jak jest, a nie zamienić się w pustkę.
            if ($n !== '' && $n !== $v && strlen($n) >= 9) $fix[$f] = [$v, $n];
        }
    }

    if (crm_janitor_rule_enabled('fix_www')) {
        $v = trim((string)($c['strona_www'] ?? ''));
        if ($v !== '' && !preg_match('~^https?://~i', $v) && str_contains($v, '.')) {
            $fix['strona_www'] = [$v, 'https://' . ltrim($v, '/')];
        }
    }

    return $fix;
}

// ─────────────────────────────────────────────────────────────────────────────
// PRZEBIEG
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Jeden przebieg bota.
 *
 * @param bool $dry_run true = niczego nie zapisuj, tylko policz (podgląd przed
 *                      pierwszym uruchomieniem na prawdziwej bazie)
 * @return array{fixed:int,found:int,per_rule:array<string,int>,samples:array}
 */
function crm_janitor_run(bool $dry_run = false): array
{
    crm_migrate();
    crm_janitor_migrate();

    $fixed = 0; $found = 0; $per = []; $samples = [];
    $bump = function (string $rule, int $n = 1) use (&$per) { $per[$rule] = ($per[$rule] ?? 0) + $n; };

    // ── 1. Kosmetyka ──────────────────────────────────────────────────────
    $rows = db_all(
        "SELECT id, imie_nazwisko, email, telefon, organizacja, stanowisko, nip, regon, krs, strona_www
           FROM crm_contacts WHERE crm_active=1"
    );
    foreach ($rows as $c) {
        $fix = crm_janitor_cosmetic_fixes($c);
        if (!$fix) continue;

        $data = [];
        foreach ($fix as $f => [$before, $after]) $data[$f] = $after;

        if (!$dry_run) {
            // Przez updateContact, nie przez UPDATE — dzięki temu każda poprawka
            // bota jest widoczna w historii zmian kartoteki.
            CrmManager::updateContact((int)$c['id'], $data);
        }
        $fixed += count($fix);
        foreach (array_keys($fix) as $f) {
            $rule = match ($f) {
                'email'       => 'lower_email',
                'nip', 'regon', 'krs' => 'clean_nip',
                'strona_www'  => 'fix_www',
                default       => 'trim_spaces',
            };
            $bump($rule);
        }
        if (count($samples) < 10) {
            $samples[] = ['id' => (int)$c['id'], 'name' => $c['imie_nazwisko'], 'fix' => $fix];
        }
    }

    // ── 2. Duplikaty ──────────────────────────────────────────────────────
    if (crm_janitor_rule_enabled('duplicates')) {
        foreach (crm_find_duplicates(3000) as $g) {
            if ($g['strength'] === 'słabe') continue;   // sama zbieżność nazwy to za mało na zgłoszenie
            if (count($g['contacts']) < 2) continue;
            $ids = array_map(fn($c) => (int)$c['id'], $g['contacts']);
            sort($ids);
            $found++; $bump('duplicates');
            if ($dry_run) continue;
            crm_janitor_add('duplicates', implode('-', $ids), [
                'contact_id' => $ids[0],
                'title'      => 'Duplikat: ' . $g['contacts'][0]['imie_nazwisko'],
                'detail'     => sprintf('%s (%s) — %s', $g['reason'], $g['strength'],
                                 implode(', ', array_map(fn($c) => $c['imie_nazwisko'] . ' #' . $c['id'], $g['contacts']))),
                'payload'    => ['ids' => $ids, 'reason' => $g['reason'], 'strength' => $g['strength']],
            ]);
        }
    }

    // ── 3. Osoby do przypięcia pod podmiot ────────────────────────────────
    if (crm_janitor_rule_enabled('domain_attach')) {
        foreach (crm_domain_org_suggestions() as $s) {
            $found++; $bump('domain_attach');
            if ($dry_run) continue;
            crm_janitor_add('domain_attach', 'dom:' . $s['domain'], [
                'contact_id' => (int)$s['org']['id'],
                'title'      => $s['org']['imie_nazwisko'] . ' — ' . count($s['candidates']) . ' osób z domeny',
                'detail'     => sprintf('Domena %s. Kartoteki do przypięcia: %s.', $s['domain'],
                                 implode(', ', array_map(fn($c) => $c['imie_nazwisko'], array_slice($s['candidates'], 0, 6)))),
                'payload'    => ['domain' => $s['domain'], 'org_id' => (int)$s['org']['id']],
            ]);
        }
    }

    // ── 4. Kartoteki bez treści ───────────────────────────────────────────
    if (crm_janitor_rule_enabled('empty_contact')) {
        $empties = db_all(
            "SELECT c.id, c.imie_nazwisko, c.created_at, c.source
               FROM crm_contacts c
              WHERE c.crm_active=1
                AND COALESCE(c.email,'')='' AND COALESCE(c.telefon,'')=''
                AND COALESCE(c.nip,'')='' AND COALESCE(c.adres,'')=''
                AND c.created_at < datetime('now','-30 days')
                AND NOT EXISTS (SELECT 1 FROM crm_communications x WHERE x.contact_id=c.id)
                AND NOT EXISTS (SELECT 1 FROM crm_cases        x WHERE x.contact_id=c.id)
                AND NOT EXISTS (SELECT 1 FROM crm_notes        x WHERE x.contact_id=c.id)
              LIMIT 200"
        );
        foreach ($empties as $e) {
            $found++; $bump('empty_contact');
            if ($dry_run) continue;
            crm_janitor_add('empty_contact', 'c:' . (int)$e['id'], [
                'contact_id' => (int)$e['id'],
                'title'      => 'Pusta kartoteka: ' . $e['imie_nazwisko'],
                'detail'     => sprintf('Brak danych kontaktowych i historii. Dodana %s, źródło: %s.',
                                 substr((string)$e['created_at'], 0, 10), $e['source'] ?: 'brak'),
                'payload'    => ['id' => (int)$e['id']],
            ]);
        }
    }

    // ── 5. Zbiorcze: bez opiekuna, sprawy bez ruchu ───────────────────────
    $aggregates = [];
    if (crm_janitor_rule_enabled('no_owner')) {
        $n = (int)(db_one("SELECT COUNT(*) AS c FROM crm_contacts WHERE crm_active=1 AND (owner_id IS NULL OR owner_id=0)")['c'] ?? 0);
        if ($n) $aggregates['no_owner'] = [$n, "Kontaktów bez przypisanego opiekuna: {$n}."];
    }
    if (crm_janitor_rule_enabled('stale_cases')) {
        try {
            $n = (int)(db_one(
                "SELECT COUNT(*) AS c FROM crm_cases WHERE status IN ('open','in_progress') AND updated_at < datetime('now', ?)",
                ['-' . CRM_CASE_STALE_DAYS . ' days'])['c'] ?? 0);
            if ($n) $aggregates['stale_cases'] = [$n, "Spraw bez ruchu ponad " . CRM_CASE_STALE_DAYS . " dni: {$n}."];
        } catch (\Throwable $e) {}
    }
    foreach ($aggregates as $rule => [$n, $detail]) {
        $found++; $bump($rule, 1);
        if ($dry_run) continue;
        crm_janitor_add($rule, 'all', [
            'title'   => crm_janitor_rules()[$rule]['label'],
            'detail'  => $detail,
            'payload' => ['count' => $n],
        ]);
    }

    // ── Zapis przebiegu ───────────────────────────────────────────────────
    if (!$dry_run) {
        try {
            db_insert('crm_janitor_runs', [
                'fixed'   => $fixed,
                'found'   => $found,
                'summary' => json_encode($per, JSON_UNESCAPED_UNICODE),
                'dry_run' => 0,
            ]);
        } catch (\Throwable $e) {}
    }

    return ['fixed' => $fixed, 'found' => $found, 'per_rule' => $per, 'samples' => $samples];
}

/** Otwarte znaleziska, najpoważniejsze na górze. */
function crm_janitor_open(string $rule = '', int $limit = 200): array
{
    crm_janitor_migrate();
    $sql = "SELECT * FROM crm_janitor_findings WHERE status='open'";
    $par = [];
    if ($rule !== '') { $sql .= " AND rule=?"; $par[] = $rule; }
    $sql .= " ORDER BY CASE severity WHEN 'high' THEN 0 ELSE 1 END, created_at DESC LIMIT ?";
    $par[] = $limit;
    return db_all($sql, $par);
}

/** Liczniki otwartych znalezisk per reguła. */
function crm_janitor_counts(): array
{
    crm_janitor_migrate();
    $out = ['ALL' => 0];
    try {
        foreach (db_all("SELECT rule, COUNT(*) AS n FROM crm_janitor_findings WHERE status='open' GROUP BY rule") as $r) {
            $out[$r['rule']] = (int)$r['n'];
            $out['ALL'] += (int)$r['n'];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** Zamyka znalezisko: 'done' (zajęto się) albo 'dismissed' (świadomie odrzucone). */
function crm_janitor_close(int $id, string $status, ?int $uid): bool
{
    if (!in_array($status, ['done', 'dismissed'], true)) return false;
    crm_janitor_migrate();
    try {
        db()->prepare("UPDATE crm_janitor_findings SET status=?, resolved_at=?, resolved_by=? WHERE id=? AND status='open'")
            ->execute([$status, date('Y-m-d H:i:s'), $uid, $id]);
        return true;
    } catch (\Throwable $e) { return false; }
}

// ─────────────────────────────────────────────────────────────────────────────
// AKCJE MASOWE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Katalog akcji masowych na znaleziskach.
 *
 * `confirm` = akcja zmienia dane i nie cofa jej jedno kliknięcie, więc
 * interfejs musi o nią dopytać. `rules` ogranicza akcję do znalezisk, dla
 * których ma sens — „scal duplikaty" na „kontaktach bez opiekuna" byłoby
 * bezsensowne, a przy zaznaczeniu „wszystkie" wręcz groźne.
 */
function crm_janitor_bulk_actions(): array
{
    return [
        'dismiss' => [
            'label' => 'Odrzuć zaznaczone', 'icon' => 'bi-x-lg', 'confirm' => false,
            'desc'  => 'Zgłoszenia znikają z listy i nie wracają przy kolejnym przebiegu.',
            'rules' => [],
        ],
        'done' => [
            'label' => 'Oznacz jako załatwione', 'icon' => 'bi-check-lg', 'confirm' => false,
            'desc'  => 'Dla spraw obsłużonych poza tą listą.',
            'rules' => [],
        ],
        'merge' => [
            'label' => 'Scal duplikaty', 'icon' => 'bi-intersect', 'confirm' => true,
            'desc'  => 'Zachowuje kartotekę STARSZĄ (niższe ID) i przenosi na nią historię młodszej.',
            'rules' => ['duplicates'],
        ],
        'delete_empty' => [
            'label' => 'Usuń puste kartoteki', 'icon' => 'bi-trash', 'confirm' => true,
            'desc'  => 'Usunięcie miękkie — kartoteki znikają z list, dane zostają w bazie.',
            'rules' => ['empty_contact'],
        ],
    ];
}

/**
 * Wykonuje akcję masową na wskazanych znaleziskach.
 *
 * Każde znalezisko obsługiwane osobno i w try — jedna kartoteka, której nie da
 * się scalić (bo ktoś ją w międzyczasie usunął), nie może przerwać operacji na
 * pozostałych czterdziestu. Wynik mówi WPROST, ile się nie udało i dlaczego,
 * zamiast raportować sukces na podstawie liczby kliknięć.
 *
 * @return array{done:int,failed:int,errors:string[]}
 */
function crm_janitor_bulk(string $action, array $ids, ?int $uid): array
{
    crm_janitor_migrate();

    $actions = crm_janitor_bulk_actions();
    if (!isset($actions[$action])) {
        return ['done' => 0, 'failed' => 0, 'errors' => ['Nieznana akcja: ' . $action]];
    }

    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return ['done' => 0, 'failed' => 0, 'errors' => ['Nie zaznaczono żadnego zgłoszenia.']];

    $allowed = $actions[$action]['rules'];
    $done = 0; $failed = 0; $errors = [];

    if (in_array($action, ['merge', 'delete_empty'], true)) {
        require_once __DIR__ . '/crm_merge.php';
    }

    foreach ($ids as $fid) {
        $f = db_one("SELECT * FROM crm_janitor_findings WHERE id=? AND status='open'", [$fid]);
        if (!$f) { $failed++; continue; }

        // Akcja niepasująca do reguły jest pomijana po cichu — użytkownik mógł
        // zaznaczyć „wszystkie" i wybrać akcję sensowną tylko dla części.
        if ($allowed && !in_array($f['rule'], $allowed, true)) continue;

        $payload = json_decode((string)$f['payload'], true) ?: [];

        try {
            switch ($action) {
                case 'dismiss':
                case 'done':
                    crm_janitor_close($fid, $action === 'done' ? 'done' : 'dismissed', $uid);
                    $done++;
                    break;

                case 'merge':
                    $pair = array_values(array_map('intval', (array)($payload['ids'] ?? [])));
                    if (count($pair) < 2) { $failed++; $errors[] = 'Zgłoszenie bez pary kartotek.'; break; }
                    // Grupa może mieć więcej niż dwie kartoteki — scalamy parami,
                    // od najstarszej, bo to ona zwykle ma najwięcej historii.
                    sort($pair);
                    $keep = array_shift($pair);
                    $ok = true;
                    foreach ($pair as $drop) {
                        $r = crm_merge_contacts($keep, $drop);
                        if (empty($r['ok'])) { $ok = false; $errors[] = (string)($r['error'] ?? 'Nie udało się scalić.'); }
                    }
                    if ($ok) { crm_janitor_close($fid, 'done', $uid); $done++; }
                    else     { $failed++; }
                    break;

                case 'delete_empty':
                    $cid = (int)($payload['id'] ?? $f['contact_id'] ?? 0);
                    if (!$cid) { $failed++; break; }
                    $c = db_one("SELECT imie_nazwisko FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
                    if (!$c) { crm_janitor_close($fid, 'done', $uid); $done++; break; }
                    CrmManager::addNote($cid, 'Kartoteka usunięta przez bota sprzątającego: brak danych '
                        . 'kontaktowych i historii. ' . date('Y-m-d H:i') . '.', $uid);
                    CrmManager::deleteContact($cid);
                    crm_janitor_close($fid, 'done', $uid);
                    $done++;
                    break;
            }
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = $e->getMessage();
        }
    }

    return ['done' => $done, 'failed' => $failed, 'errors' => array_values(array_unique($errors))];
}
