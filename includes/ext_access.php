<?php
/**
 * includes/ext_access.php — Materiały zewnętrzne: dostęp, bilety, ślad.
 *
 * Pięć warstw decyzji, odmowa wygrywa:
 *   1. moduł włączony i podmiot zalogowany,
 *   2. zakaz jawny (deny) na dowolnym poziomie hierarchii,
 *   3. poziom dostępu tytułu + grant albo ważna licencja z miejscem,
 *   4. reguły skuteczne zasobu (embargo, pobieranie, druk) — ext_effective_rules(),
 *   5. limity użycia (pobrania na dobę).
 *
 * Decyzja NIE jest wartością logiczną. Odpowiedź brzmi „tak, ale strumieniowo,
 * ze znakiem wodnym, bez druku, przez 180 sekund", więc ext_decide() zwraca
 * tablicę z ograniczeniami, a strona się do nich stosuje.
 *
 * TRZY SESJE: aplikacja SZO, panel kursanta i panel prowadzącego są od siebie
 * niezależne. Funkcje decydujące dostają podmiot w argumencie i nigdy nie
 * dobierają go sobie same — w panelach current_user() i is_admin() patrzą na
 * sesję SZO, której tam nie ma.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ext_materials.php';

/** Schemat części „dostępowej" — wołany z ext_migrate(). */
function ext_access_migrate(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_licenses (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        publisher_id  INTEGER NOT NULL REFERENCES k30_ext_publishers(id) ON DELETE CASCADE,
        kind          TEXT    NOT NULL DEFAULT 'institutional',
        name          TEXT    NOT NULL,
        valid_from    DATE    NOT NULL,
        valid_to      DATE    NOT NULL,
        seats         INTEGER,
        max_downloads INTEGER,
        contract_sum  TEXT    NOT NULL DEFAULT '',
        note          TEXT    NOT NULL DEFAULT '',
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_license_seats (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        license_id   INTEGER NOT NULL REFERENCES k30_ext_licenses(id) ON DELETE CASCADE,
        subject_type TEXT    NOT NULL,
        subject_id   INTEGER NOT NULL,
        assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        released_at  DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_seat ON k30_ext_license_seats(license_id, subject_type, subject_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_grants (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        scope_type   TEXT    NOT NULL,
        scope_id     INTEGER NOT NULL,
        subject_type TEXT    NOT NULL,
        subject_id   INTEGER NOT NULL DEFAULT 0,
        effect       TEXT    NOT NULL DEFAULT 'allow',
        abilities    TEXT    NOT NULL DEFAULT 'view,stream',
        starts_at    DATETIME,
        ends_at      DATETIME,
        license_id   INTEGER,
        note         TEXT    NOT NULL DEFAULT '',
        created_by   INTEGER,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_grants_subj  ON k30_ext_grants(subject_type, subject_id, effect)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_grants_scope ON k30_ext_grants(scope_type, scope_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_tickets (
        id           TEXT    PRIMARY KEY,
        resource_id  INTEGER NOT NULL,
        subject_type TEXT    NOT NULL,
        subject_id   INTEGER NOT NULL,
        subject_name TEXT    NOT NULL DEFAULT '',
        ability      TEXT    NOT NULL,
        watermark    TEXT    NOT NULL DEFAULT 'both',
        ip           TEXT    NOT NULL DEFAULT '',
        ua_hash      TEXT    NOT NULL DEFAULT '',
        license_id   INTEGER,
        expires_at   DATETIME NOT NULL,
        used_at      DATETIME,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_tickets_exp ON k30_ext_tickets(expires_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_access_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_type TEXT    NOT NULL,
        subject_id   INTEGER NOT NULL,
        subject_name TEXT    NOT NULL DEFAULT '',
        resource_id  INTEGER,
        title_id     INTEGER,
        ability      TEXT    NOT NULL DEFAULT '',
        decision     TEXT    NOT NULL DEFAULT '',
        reason       TEXT    NOT NULL DEFAULT '',
        ticket_id    TEXT,           -- NULL-owalne: db_insert() zamienia '' na NULL w kolumnach *_id
        ip           TEXT    NOT NULL DEFAULT '',
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_log_subj ON k30_ext_access_log(subject_type, subject_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_log_res  ON k30_ext_access_log(resource_id, created_at)");
}

// ── Podmiot ──────────────────────────────────────────────────────────────────

/**
 * Podmiot z KONKRETNEJ warstwy sesji: 'dyd' | 'student' | 'szo'.
 *
 * Rozbite na warstwy, bo wołający musi móc powiedzieć, którą sesję otwiera.
 * PHP utrzymuje w żądaniu jedną sesję, więc funkcja pytająca „po kolei" sama
 * ją zajmuje — a wtedy druga próba trafia już na cudzą, otwartą sesję i zwraca
 * pustkę (na tym wykładał się moduł, gdy w przeglądarce leżały dwa ciasteczka).
 */
function ext_subject_layer(string $kind): ?array
{
    if ($kind === 'dyd' && function_exists('dyd_current')) {
        $d = dyd_current();
        return $d ? ['type' => 'user', 'id' => (int)$d['user_id'], 'name' => (string)($d['name'] ?? ''),
                     'role' => (string)($d['role'] ?? ''), 'staff' => !empty($d['is_staff'])] : null;
    }

    if ($kind === 'student' && function_exists('student_current')) {
        $s = student_current();
        if (!$s) return null;
        $cl = db_one("SELECT name FROM k30_clients WHERE id=?", [(int)($s['client_id'] ?? 0)]);
        return ['type' => 'student', 'id' => (int)$s['id'], 'name' => (string)($cl['name'] ?? ''),
                'role' => '', 'staff' => false, 'client_id' => (int)($s['client_id'] ?? 0)];
    }

    if ($kind === 'szo' && function_exists('current_user')) {
        $u = current_user();
        if (!$u) return null;
        $staff = (function_exists('can_write') && can_write('karty30'))
              || (function_exists('is_admin') && is_admin());
        return ['type' => 'user', 'id' => (int)$u['id'], 'name' => (string)($u['name'] ?? ''),
                'role' => (string)($u['role'] ?? ''), 'staff' => $staff];
    }

    return null;
}

/**
 * Podmiot z którejkolwiek z trzech sesji — dla stron, które mają już otwartą
 * sesję i nie wybierają jej same (np. panele wołające helpery modułu).
 */
function ext_subject(): ?array
{
    foreach (['dyd', 'student', 'szo'] as $kind) {
        $s = ext_subject_layer($kind);
        if ($s) return $s;
    }
    return null;
}
/** Czy podmiot może zarządzać modułem (wgrywać, nadawać dostęp). */
function ext_can_manage(?array $subject): bool
{
    return $subject !== null && !empty($subject['staff']);
}

/**
 * Klucze, na które może być wystawiony grant: konkretna osoba, jej rola,
 * grupy TI kursanta oraz „każdy zalogowany".
 */
function ext_subject_keys(array $subject): array
{
    $keys = ['all:0', $subject['type'] . ':' . (int)$subject['id']];

    if ($subject['type'] === 'user' && ($subject['role'] ?? '') !== '') {
        $keys[] = 'role:0:' . $subject['role'];        // rola nie ma ID — trzymamy nazwę
    }
    if ($subject['type'] === 'student' && !empty($subject['client_id'])) {
        foreach (db_all("SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active'",
                        [(int)$subject['client_id']]) as $e) {
            $keys[] = 'course:' . (int)$e['course_id'];
        }
    }
    return array_values(array_unique($keys));
}

// ── Granty ───────────────────────────────────────────────────────────────────

/**
 * Granty dotyczące tego zasobu, na wszystkich poziomach hierarchii.
 * Kategorie dziedziczą w dół po ścieżce materializowanej — grant na „Prawo"
 * obejmuje „Prawo/Cywilne/Zobowiązania" bez rekurencji.
 *
 * PUŁAPKA SQLite: przy porównaniu wyrażenia z parametrem PDO wiąże go jako
 * TEKST i warunek nie trafia — stąd CAST(? AS INTEGER) wszędzie tam, gdzie po
 * lewej stronie nie stoi goła kolumna INTEGER.
 */
function ext_grants_for(array $subject, array $ctx): array
{
    $keys = ext_subject_keys($subject);
    $ph   = implode(',', array_fill(0, count($keys), '?'));

    $title = $ctx['title'];
    $res   = $ctx['resource'];

    $params = array_merge($keys, [
        (int)$res['id'], (int)$res['edition_id'], (int)$title['id'],
        (int)$title['publisher_id'], (int)$title['id'],
    ]);

    return db_all(
        "SELECT * FROM k30_ext_grants
         WHERE (subject_type || ':' || subject_id) IN ($ph)
           AND (starts_at IS NULL OR starts_at <= datetime('now'))
           AND (ends_at   IS NULL OR ends_at   >= datetime('now'))
           AND (
                (scope_type='resource'  AND scope_id = CAST(? AS INTEGER))
             OR (scope_type='edition'   AND scope_id = CAST(? AS INTEGER))
             OR (scope_type='title'     AND scope_id = CAST(? AS INTEGER))
             OR (scope_type='publisher' AND scope_id = CAST(? AS INTEGER))
             OR (scope_type='category'  AND scope_id IN (
                    SELECT a.id
                    FROM k30_ext_categories a
                    JOIN k30_ext_categories c  ON c.path LIKE a.path || '%'
                    JOIN k30_ext_category_title ct ON ct.category_id = c.id
                    WHERE ct.title_id = CAST(? AS INTEGER)
                 ))
           )",
        $params
    );
}

/** Czy w łańcuchu grantów jest wpis danego rodzaju na tę czynność. */
function ext_chain_has(array $grants, string $effect, string $ability): bool
{
    foreach ($grants as $g) {
        if ($g['effect'] !== $effect) continue;
        $ab = array_map('trim', explode(',', (string)$g['abilities']));
        if (in_array($ability, $ab, true) || in_array('*', $ab, true)) return true;
    }
    return false;
}

// ── Licencje ─────────────────────────────────────────────────────────────────

/** Ważna licencja wydawcy tego tytułu (albo null). */
function ext_license_for(array $subject, array $ctx): ?array
{
    return db_one(
        "SELECT * FROM k30_ext_licenses
         WHERE publisher_id = CAST(? AS INTEGER)
           AND is_active = 1
           AND valid_from <= date('now') AND valid_to >= date('now')
         ORDER BY valid_to DESC LIMIT 1",
        [(int)$ctx['title']['publisher_id']]
    );
}

/**
 * Czy podmiot ma miejsce w licencji. Licencja bez limitu miejsc (seats NULL)
 * obejmuje wszystkich — instytucjonalna dla całej organizacji.
 */
function ext_license_has_seat(array $license, array $subject): bool
{
    if ($license['seats'] === null || $license['seats'] === '') return true;

    $seat = db_one(
        "SELECT id FROM k30_ext_license_seats
         WHERE license_id = CAST(? AS INTEGER) AND subject_type=? AND subject_id = CAST(? AS INTEGER)
           AND released_at IS NULL",
        [(int)$license['id'], $subject['type'], (int)$subject['id']]
    );
    return (bool)$seat;
}

/** Limity użycia: liczba pobrań na dobę z licencji. */
function ext_limits_ok(array $subject, array $ctx, string $ability, ?array $license): bool
{
    if ($ability !== 'download' || !$license || empty($license['max_downloads'])) return true;

    $n = db_one(
        "SELECT COUNT(*) AS n FROM k30_ext_access_log
         WHERE subject_type=? AND subject_id = CAST(? AS INTEGER)
           AND ability='download' AND decision='served'
           AND created_at >= datetime('now','-1 day')",
        [$subject['type'], (int)$subject['id']]
    );
    return (int)($n['n'] ?? 0) < (int)$license['max_downloads'];
}

// ── Decyzja ──────────────────────────────────────────────────────────────────

/**
 * Decyzja o dostępie do zasobu.
 * Zwraca ['ok'=>bool,'reason'=>string,'abilities'=>[],'watermark'=>string,
 *         'ttl'=>int,'license_id'=>?int].
 * `reason` jest kodem — trafia do logu i do komunikatu (ext_reason_text()).
 */
function ext_decide(?array $subject, array $ctx, string $ability): array
{
    $deny = fn(string $r): array => ['ok' => false, 'reason' => $r, 'abilities' => [],
                                     'watermark' => 'both', 'ttl' => 0, 'license_id' => null];

    if (!ext_enabled())  return $deny('module_off');
    if (!$subject)       return $deny('not_logged_in');

    $title = $ctx['title'];
    $rules = ext_effective_rules($ctx['publisher'], $title, $ctx['edition'], $ctx['resource']);

    if (!empty($title['embargo_until']) && $title['embargo_until'] > date('Y-m-d H:i:s')) {
        return $deny('embargo');
    }

    // Zakaz jawny kończy sprawę — także dla pracownika. To jedyny sposób, żeby
    // wyłączyć komuś dostęp do materiału, którego reszta zespołu używa.
    $grants = ext_grants_for($subject, $ctx);
    if (ext_chain_has($grants, 'deny', $ability)) return $deny('explicit_deny');

    $license = null;
    if (!ext_can_manage($subject)) {
        // Pracownik z prawem zapisu widzi wszystko, czego mu jawnie nie zabroniono —
        // inaczej nie dałoby się modułem administrować. W logu jest to widoczne.
        if ($title['access_level'] !== 'public' && !ext_chain_has($grants, 'allow', $ability)) {
            if ($title['access_level'] === 'registered' && in_array($ability, ['view', 'stream'], true)) {
                // materiał otwarty dla zalogowanych — przechodzi dalej
            } else {
                $license = ext_license_for($subject, $ctx);
                if (!$license)                                  return $deny('no_license');
                if (!ext_license_has_seat($license, $subject))  return $deny('no_seat');
            }
        }
    }

    if ($ability === 'download' && empty($rules['allow_download'])) return $deny('download_forbidden');
    if ($ability === 'print'    && empty($rules['allow_print']))    return $deny('print_forbidden');

    if (!ext_limits_ok($subject, $ctx, $ability, $license)) return $deny('limit_exceeded');

    return [
        'ok'         => true,
        'reason'     => 'ok',
        'abilities'  => $rules['abilities'],
        // Pracownik też dostaje stempel — tyle że dyskretny. Wyciek z konta
        // administracji ma być tak samo rozpoznawalny jak każdy inny.
        'watermark'  => ext_can_manage($subject) ? 'footer' : (string)$rules['watermark_policy'],
        'ttl'        => (int)$rules['ticket_ttl'],
        'license_id' => $license['id'] ?? null,
    ];
}

/** Kod odmowy → zdanie dla użytkownika. */
function ext_reason_text(string $reason): string
{
    return [
        'module_off'         => 'Moduł materiałów zewnętrznych jest wyłączony.',
        'not_logged_in'      => 'Zaloguj się, aby korzystać z materiałów.',
        'not_found'          => 'Materiał nie istnieje albo został wycofany.',
        'embargo'            => 'Materiał będzie dostępny po zakończeniu embarga wydawcy.',
        'explicit_deny'      => 'Dostęp do tego materiału został dla Ciebie zablokowany.',
        'no_license'         => 'Ten materiał wymaga ważnej licencji — zgłoś się do sekretariatu.',
        'no_seat'            => 'Licencja nie obejmuje Twojego konta — poproś o przydzielenie miejsca.',
        'download_forbidden' => 'Wydawca nie zezwala na pobieranie tego materiału — czytaj online.',
        'print_forbidden'    => 'Wydawca nie zezwala na drukowanie tego materiału.',
        'limit_exceeded'     => 'Wyczerpano dzienny limit pobrań z tej licencji.',
    ][$reason] ?? 'Brak dostępu do materiału.';
}

// ── Bilety ───────────────────────────────────────────────────────────────────

/**
 * Wystawia bilet do zasobu. Bilet jest rekordem, nie podpisanym adresem: da się
 * go unieważnić, ma krótki termin i jest przypisany do osoby oraz przeglądarki.
 */
function ext_ticket_issue(array $subject, array $ctx, string $ability): array
{
    $d = ext_decide($subject, $ctx, $ability);
    ext_log($subject, $ctx, $ability, $d['ok'] ? 'granted' : 'denied', $d['reason']);
    if (!$d['ok']) return ['ok' => false, 'reason' => $d['reason'], 'msg' => ext_reason_text($d['reason'])];

    $id = bin2hex(random_bytes(16));
    db_insert('k30_ext_tickets', [
        'id'           => $id,
        'resource_id'  => (int)$ctx['resource']['id'],
        'subject_type' => $subject['type'],
        'subject_id'   => (int)$subject['id'],
        'subject_name' => (string)$subject['name'],
        'ability'      => $ability,
        'watermark'    => $d['watermark'],
        'ip'           => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua_hash'      => hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'license_id'   => $d['license_id'],
        'expires_at'   => date('Y-m-d H:i:s', time() + max(30, (int)$d['ttl'])),
    ]);

    return ['ok' => true, 'ticket' => $id, 'ttl' => (int)$d['ttl']];
}

function ext_ticket_get(string $id): ?array
{
    $id = preg_replace('/[^a-f0-9]/', '', $id);
    return $id === '' ? null : db_one("SELECT * FROM k30_ext_tickets WHERE id=?", [$id]);
}

/** Kasuje bilety wygasłe ponad dobę temu — wołane przez agenta. */
function ext_tickets_prune(): int
{
    $n = db_one("SELECT COUNT(*) AS n FROM k30_ext_tickets WHERE expires_at < datetime('now','-1 day')");
    db_exec("DELETE FROM k30_ext_tickets WHERE expires_at < datetime('now','-1 day')");
    return (int)($n['n'] ?? 0);
}

// ── Ślad ─────────────────────────────────────────────────────────────────────

/**
 * Zapis do dziennika dostępu. Logujemy TAKŻE odmowy — przy materiałach
 * licencjonowanych dziennik jest dowodem wykonania umowy z wydawcą, a odmowy
 * pokazują, że kontrola działa. Nazwa podmiotu jest kopiowana, żeby wpis
 * przeżył skasowanie konta.
 */
function ext_log(?array $subject, ?array $ctx, string $ability, string $decision,
                 string $reason = '', string $ticket = ''): void
{
    try {
        db_insert('k30_ext_access_log', [
            'subject_type' => $subject['type'] ?? 'anon',
            'subject_id'   => (int)($subject['id'] ?? 0),
            'subject_name' => (string)($subject['name'] ?? ''),
            'resource_id'  => isset($ctx['resource']) ? (int)$ctx['resource']['id'] : null,
            'title_id'     => isset($ctx['title']) ? (int)$ctx['title']['id'] : null,
            'ability'      => $ability,
            'decision'     => $decision,
            'reason'       => $reason,
            'ticket_id'    => $ticket !== '' ? $ticket : null,
            'ip'           => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (\Throwable $e) {
        error_log('[ext_log] ' . $e->getMessage());   // dziennik nie może wywrócić odczytu
    }
}

// ── Katalog ──────────────────────────────────────────────────────────────────

/**
 * Tytuły widoczne dla podmiotu — jednym zapytaniem, nie w pętli.
 * Metadane pokazujemy szerzej niż treść: katalog widzi każdy zalogowany,
 * pliku broni dopiero ext_decide().
 */
function ext_titles_visible(array $subject, array $f = [], int $limit = 60, int $offset = 0): array
{
    $keys = ext_subject_keys($subject);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    // Parametry dokładamy DOKŁADNIE tam, gdzie w zapytaniu stoi znak zapytania —
    // klucze podmiotu wchodzą tylko z warunkiem widoczności, którego pracownik nie ma.
    $p    = [];

    $where = ["t.is_active = 1"];
    if (!ext_can_manage($subject)) {
        $where[] = "(t.access_level IN ('public','registered')
                     OR EXISTS (SELECT 1 FROM k30_ext_grants g
                                WHERE (g.subject_type || ':' || g.subject_id) IN ($ph)
                                  AND g.effect='allow'
                                  AND (g.ends_at IS NULL OR g.ends_at >= datetime('now'))
                                  AND ((g.scope_type='title'     AND g.scope_id=t.id)
                                    OR (g.scope_type='publisher' AND g.scope_id=t.publisher_id)))
                     OR EXISTS (SELECT 1 FROM k30_ext_licenses l
                                WHERE l.publisher_id = t.publisher_id AND l.is_active=1
                                  AND l.valid_from <= date('now') AND l.valid_to >= date('now')))";
        $p = array_merge($p, $keys);   // jeden komplet na jedno $ph w zapytaniu
    }
    if (!empty($f['publisher_id'])) { $where[] = "t.publisher_id = CAST(? AS INTEGER)"; $p[] = (int)$f['publisher_id']; }
    if (!empty($f['category_id'])) {
        $where[] = "EXISTS (SELECT 1 FROM k30_ext_category_title ct
                            JOIN k30_ext_categories c  ON c.id = ct.category_id
                            JOIN k30_ext_categories a  ON c.path LIKE a.path || '%'
                            WHERE ct.title_id = t.id AND a.id = CAST(? AS INTEGER))";
        $p[] = (int)$f['category_id'];
    }
    if (!empty($f['q'])) {
        $where[] = "(t.title LIKE ? OR t.authors LIKE ? OR t.description LIKE ?)";
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like);
    }

    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    return db_all(
        "SELECT t.*, p.name AS publisher_name,
                (SELECT COUNT(*) FROM k30_ext_editions e WHERE e.title_id=t.id AND e.is_active=1) AS n_editions
         FROM k30_ext_titles t
         JOIN k30_ext_publishers p ON p.id = t.publisher_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY t.title COLLATE NOCASE
         LIMIT $limit OFFSET $offset",
        $p
    );
}

// ── Przypięcie materiału do lekcji ───────────────────────────────────────────

/**
 * Przypina zasób do lekcji i — jeśli wolno — otwiera go uczestnikom grupy.
 *
 * Samo przypięcie jest tylko wskazaniem („to jest lektura do tych zajęć").
 * Dostęp do treści to osobna sprawa, bo kosztuje licencję. Dlatego uprawnienie
 * dla grupy zakłada się tylko wtedy, gdy nie oznacza to obejścia umowy:
 *   • pracownik (kierownik, administracja) — zawsze może, to jego decyzja,
 *   • prowadzący bez uprawnień D3 — tylko gdy materiał jest otwarty albo gdy
 *     wydawca ma już ważną licencję; inaczej przypięcie zostaje bez dostępu,
 *     a prowadzący dostaje wprost informację, czego brakuje.
 *
 * Zwraca ['ok'=>bool,'msg'=>string,'granted'=>bool].
 */
function ext_pin_add(array $subject, int $resource_id, int $session_id, string $note = '', bool $share = true): array
{
    $ctx = ext_resource_context($resource_id);
    if (!$ctx) return ['ok' => false, 'msg' => 'Nie znaleziono materiału.', 'granted' => false];

    $ses = db_one("SELECT id, course_id FROM k30_ti_sessions WHERE id = CAST(? AS INTEGER)", [$session_id]);
    if (!$ses) return ['ok' => false, 'msg' => 'Nie znaleziono lekcji.', 'granted' => false];

    // Prowadzący przypina do swoich zajęć; pracownik D3 do dowolnych
    if (!ext_can_manage($subject)
        && (!function_exists('dyd_owns_course')
            || !dyd_owns_course((int)$subject['id'], (int)$ses['course_id']))) {
        return ['ok' => false, 'msg' => 'Możesz przypinać materiały tylko do swoich zajęć.', 'granted' => false];
    }

    if (db_one("SELECT id FROM k30_ext_pins WHERE resource_id=CAST(? AS INTEGER) AND session_id=CAST(? AS INTEGER)",
               [$resource_id, $session_id])) {
        return ['ok' => false, 'msg' => 'Ten materiał jest już przypięty do tej lekcji.', 'granted' => false];
    }

    $grantId = null;
    if ($share) {
        $open    = in_array($ctx['title']['access_level'], ['public', 'registered'], true);
        $licence = ext_license_for($subject, $ctx);
        if (ext_can_manage($subject) || $open || $licence) {
            // Uprawnienie dla grupy, nie dla osób — kursanci dochodzą i odchodzą,
            // a zapis do kursu jest tym, co ma decydować o dostępie.
            $grantId = db_insert('k30_ext_grants', [
                'scope_type'   => 'resource',
                'scope_id'     => $resource_id,
                'subject_type' => 'course',
                'subject_id'   => (int)$ses['course_id'],
                'effect'       => 'allow',
                'abilities'    => 'view,stream',   // pobieranie i druk zostają przy regułach wydawcy
                'license_id'   => $licence['id'] ?? null,
                'note'         => 'przypięcie do lekcji #' . $session_id,
                'created_by'   => (int)$subject['id'],
            ]);
        }
    }

    db_insert('k30_ext_pins', [
        'resource_id'  => $resource_id,
        'session_id'   => $session_id,
        'course_id'    => (int)$ses['course_id'],
        'note'         => trim($note),
        'grant_id'     => $grantId,
        'created_type' => (string)$subject['type'],
        'created_id'   => (int)$subject['id'],
        'created_name' => (string)$subject['name'],
    ]);

    ext_log($subject, $ctx, 'pin', 'granted', $grantId ? 'ok' : 'pin_without_access');

    return [
        'ok'      => true,
        'granted' => (bool)$grantId,
        'msg'     => $grantId
            ? 'Materiał przypięty — uczestnicy grupy mogą go czytać.'
            : 'Materiał przypięty, ale bez dostępu dla grupy: tytuł wymaga licencji, '
              . 'której nie ma. Poproś kierownika o uprawnienie albo o zakup dostępu.',
    ];
}

/** Odpina materiał i cofa uprawnienie, które powstało razem z przypięciem. */
function ext_pin_remove(array $subject, int $pin_id): bool
{
    $pin = db_one("SELECT * FROM k30_ext_pins WHERE id = CAST(? AS INTEGER)", [$pin_id]);
    if (!$pin) return false;

    if (!ext_can_manage($subject)
        && (!function_exists('dyd_owns_course')
            || !dyd_owns_course((int)$subject['id'], (int)$pin['course_id']))) {
        return false;
    }

    // Uprawnienie zniknie razem z przypięciem tylko wtedy, gdy to ono je założyło —
    // ręcznie nadanych uprawnień odpinanie nie rusza.
    if ($pin['grant_id']) {
        db_exec("DELETE FROM k30_ext_grants WHERE id = CAST(? AS INTEGER)", [(int)$pin['grant_id']]);
    }
    db_exec("DELETE FROM k30_ext_pins WHERE id = CAST(? AS INTEGER)", [$pin_id]);
    return true;
}
