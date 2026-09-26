<?php
/**
 * modules/gdpr_clauses/logic/gdpr_clauses.php — Klauzule RODO (informacyjne).
 *
 * Dwie tabele:
 *  - global_variables  klucz→wartość (company_name, address, dpo_email…);
 *                      zmiana w jednym miejscu aktualizuje WSZYSTKIE klauzule,
 *                      bo podstawienie odbywa się przy każdym renderowaniu,
 *  - gdpr_clauses      treść z tagami {{klucz}}, publiczna pod slugiem
 *                      (/klauzula/{slug} → modules/gdpr_clauses/public/clause.php).
 *  - gdpr_clause_history poprzednie wersje treści — przy każdym zapisie.
 *  - gdpr_clause_acceptances + gdpr_clause_snapshots — rejestr akceptacji:
 *                      kto, kiedy, w jakim formularzu i DOKŁADNIE jaki tekst
 *                      (migawka HTML po podstawieniu zmiennych, dedup po sha256).
 *                      Rozliczalność (RODO art. 5 ust. 2): wiadomo, jaki
 *                      tekst obowiązywał w chwili zebrania danych.
 *
 * Format treści — celowo NIE surowy HTML, tylko prosty, bezpieczny zapis
 * (GdprClauseService::renderBody()): akapity oddzielone pustą linią, „## Nagłówek", listy
 * „- punkt" / „1. punkt", **pogrubienie**, *kursywa*, adresy e-mail
 * i http(s) zamieniane na linki. Wszystko jest escapowane, więc ani treść,
 * ani wartość zmiennej nie wstrzyknie skryptu na stronę publiczną (która
 * bywa osadzana w iframe na obcych serwisach).
 *
 * Zmienne lokalne (gdpr_clauses.local_vars, JSON klucz→wartość) — szczegóły
 * jednej klauzuli (cel, okres przechowywania…); nadpisują globalne o tym
 * samym kluczu. Szablony startowe: logic/templates.php.
 *
 * Tagi wbudowane (nie trzeba ich definiować): {{updated_at}} — data
 * ostatniej zmiany klauzuli, {{today}} — dzisiejsza data.
 * Nieznany tag: na stronie publicznej znika (pusty tekst), w podglądzie
 * admina jest podświetlony i wymieniony w ostrzeżeniu.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once __DIR__ . '/templates.php';

const GDPR_SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';
const GDPR_VAR_KEY_RE = '/^[a-z][a-z0-9_]{0,63}$/';
const GDPR_BUILTIN_VARS = ['updated_at', 'today'];
const GDPR_DEFAULT_LANG = 'pl';
/** Obsługiwane wersje językowe (kod ISO 639-1 → nazwa w tym języku). */
const GDPR_LANGS = [
    'pl' => 'polski', 'en' => 'English', 'uk' => 'українська',
    'de' => 'Deutsch', 'fr' => 'français', 'es' => 'español',
];

/** Dokłada kolumnę, jeśli jej brak (samonaprawa schematu). */
function gdpr_clauses_add_column(PDO $pdo, string $table, string $col, string $ddl): void {
    $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$ddl}");
}

function gdpr_clauses_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS global_variables (
        key_        VARCHAR(64) PRIMARY KEY,
        value       TEXT NOT NULL DEFAULT '',
        label       VARCHAR(200) NOT NULL DEFAULT '',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        updated_at  DATETIME
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gdpr_clauses (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        slug          VARCHAR(64) NOT NULL,
        lang          VARCHAR(5) NOT NULL DEFAULT 'pl',
        tytul         VARCHAR(255) NOT NULL,
        content       TEXT NOT NULL DEFAULT '',
        is_published  INTEGER NOT NULL DEFAULT 1,
        created_at    DATETIME,
        updated_at    DATETIME,
        updated_by    INTEGER,
        UNIQUE(slug, lang)
    )");
    // Pierwsza wersja tabeli miała UNIQUE na samym slugu i nie miała lang —
    // SQLite nie zdejmie ograniczenia przez ALTER, więc przebudowa tabeli.
    $cols = array_column($pdo->query("PRAGMA table_info(gdpr_clauses)")->fetchAll(), 'name');
    if (!in_array('lang', $cols, true)) {
        $pdo->beginTransaction();
        $pdo->exec("CREATE TABLE gdpr_clauses_v2 (
            id INTEGER PRIMARY KEY AUTOINCREMENT, slug VARCHAR(64) NOT NULL,
            lang VARCHAR(5) NOT NULL DEFAULT 'pl', tytul VARCHAR(255) NOT NULL,
            content TEXT NOT NULL DEFAULT '', is_published INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME, updated_at DATETIME, updated_by INTEGER, UNIQUE(slug, lang))");
        $pdo->exec("INSERT INTO gdpr_clauses_v2 (id, slug, lang, tytul, content, is_published, created_at, updated_at, updated_by)
                    SELECT id, slug, 'pl', tytul, content, is_published, created_at, updated_at, updated_by FROM gdpr_clauses");
        $pdo->exec("DROP TABLE gdpr_clauses");
        $pdo->exec("ALTER TABLE gdpr_clauses_v2 RENAME TO gdpr_clauses");
        $pdo->commit();
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS gdpr_clause_history (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        clause_id   INTEGER NOT NULL,
        tytul       VARCHAR(255) NOT NULL,
        content     TEXT NOT NULL,
        valid_from  DATETIME,
        valid_to    DATETIME NOT NULL,
        changed_by  INTEGER
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gdpr_hist_clause ON gdpr_clause_history(clause_id, valid_to)");
    gdpr_clauses_add_column($pdo, 'gdpr_clauses', 'local_vars', "TEXT NOT NULL DEFAULT '{}'");
    gdpr_clauses_add_column($pdo, 'gdpr_clause_history', 'local_vars', "TEXT NOT NULL DEFAULT '{}'");
    // Numer wersji: klauzula ma bieżący, wiersz historii — numer wersji, którą archiwizuje.
    $hadVersion = in_array('version', array_column($pdo->query("PRAGMA table_info(gdpr_clause_history)")->fetchAll(), 'name'), true);
    gdpr_clauses_add_column($pdo, 'gdpr_clauses', 'version', "INTEGER NOT NULL DEFAULT 1");
    gdpr_clauses_add_column($pdo, 'gdpr_clause_history', 'version', "INTEGER NOT NULL DEFAULT 0");
    // Obieg akceptacji: wersja robocza czekająca na zatwierdzenie (opublikowana
    // treść zostaje bez zmian) + kto zatwierdził bieżącą/archiwalną wersję.
    foreach (['draft_data' => 'TEXT', 'draft_status' => "VARCHAR(10) NOT NULL DEFAULT ''", 'draft_by' => 'INTEGER',
              'draft_at' => 'DATETIME', 'draft_note' => 'TEXT', 'approved_by' => 'INTEGER', 'approved_at' => 'DATETIME'] as $c => $ddl) {
        gdpr_clauses_add_column($pdo, 'gdpr_clauses', $c, $ddl);
    }
    gdpr_clauses_add_column($pdo, 'gdpr_clause_history', 'approved_by', 'INTEGER');
    gdpr_clauses_add_column($pdo, 'gdpr_clause_history', 'approved_at', 'DATETIME');
    $pdo->exec("CREATE TABLE IF NOT EXISTS gdpr_clause_snapshots (
        hash        CHAR(64) PRIMARY KEY,
        clause_id   INTEGER NOT NULL,
        version     INTEGER NOT NULL,
        lang        VARCHAR(5) NOT NULL,
        tytul       VARCHAR(255) NOT NULL,
        html        TEXT NOT NULL,
        created_at  DATETIME NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gdpr_clause_acceptances (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        clause_id     INTEGER NOT NULL,
        slug          VARCHAR(64) NOT NULL,
        lang          VARCHAR(5) NOT NULL,
        version       INTEGER NOT NULL,
        snapshot_hash CHAR(64) NOT NULL,
        context       VARCHAR(40) NOT NULL,
        ref_type      VARCHAR(40),
        ref_id        INTEGER,
        subject_name  VARCHAR(255) NOT NULL DEFAULT '',
        subject_email VARCHAR(255) NOT NULL DEFAULT '',
        accepted_at   DATETIME NOT NULL,
        ip            VARCHAR(64) NOT NULL DEFAULT '',
        user_agent    VARCHAR(255) NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gdpr_acc_clause ON gdpr_clause_acceptances(clause_id, accepted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gdpr_acc_ref ON gdpr_clause_acceptances(ref_type, ref_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gdpr_acc_email ON gdpr_clause_acceptances(subject_email)");
    if (!$hadVersion) {
        // Uzupełnienie wstecz: kolejne wiersze historii = wersje 1..n, bieżąca = n+1.
        $pdo->exec("UPDATE gdpr_clause_history SET version = (
                        SELECT COUNT(*) FROM gdpr_clause_history h2
                        WHERE h2.clause_id = gdpr_clause_history.clause_id AND h2.id <= gdpr_clause_history.id)");
        $pdo->exec("UPDATE gdpr_clauses SET version = 1 + (SELECT COUNT(*) FROM gdpr_clause_history h WHERE h.clause_id = gdpr_clauses.id)");
    }

    // Pierwsze uruchomienie: zmienne z danych organizacji (settings), żeby
    // klauzule od razu miały poprawną nazwę/adres. Później to global_variables
    // jest jedynym źródłem dla klauzul — settings nie są już czytane.
    if ((int)$pdo->query("SELECT COUNT(*) FROM global_variables")->fetchColumn() === 0) {
        $s = static function (string $k): string {
            try {
                $r = db_one("SELECT value FROM settings WHERE key_ = ?", [$k]);
                return trim((string)($r['value'] ?? ''));
            } catch (\Throwable $e) { return ''; }
        };
        $defaults = [
            ['company_name', $s('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''), 'Nazwa administratora danych'],
            ['address',      $s('org_adres'),  'Adres siedziby'],
            ['nip',          $s('org_nip'),    'NIP'],
            ['krs',          $s('org_krs'),    'KRS'],
            ['contact_email', '',              'E-mail kontaktowy administratora'],
            ['dpo_email',    '',               'E-mail Inspektora Ochrony Danych (IOD)'],
        ];
        $st = $pdo->prepare("INSERT INTO global_variables (key_, value, label, sort_order, updated_at) VALUES (?,?,?,?,datetime('now','localtime'))");
        foreach ($defaults as $i => [$k, $v, $l]) $st->execute([$k, $v, $l, ($i + 1) * 10]);
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM gdpr_clauses")->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO gdpr_clauses (slug, tytul, content, is_published, created_at, updated_at)
                       VALUES (?,?,?,0,datetime('now','localtime'),datetime('now','localtime'))")
            ->execute(['rekrutacja', 'Klauzula informacyjna — rekrutacja', gdpr_clauses_example_recruitment()]);
    }
}

function gdpr_clauses_example_recruitment(): string {
    return <<<TXT
Zgodnie z art. 13 ust. 1 i 2 Rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679 z dnia 27 kwietnia 2016 r. (RODO) informujemy, że:

## Administrator danych
Administratorem Pani/Pana danych osobowych jest **{{company_name}}** z siedzibą: {{address}}, NIP {{nip}}, KRS {{krs}}. Kontakt: {{contact_email}}.

## Inspektor Ochrony Danych
We wszystkich sprawach dotyczących przetwarzania danych osobowych można kontaktować się z Inspektorem Ochrony Danych: {{dpo_email}}.

## Cele i podstawy przetwarzania
- przeprowadzenie bieżącego procesu rekrutacji — art. 6 ust. 1 lit. b RODO oraz art. 22¹ Kodeksu pracy (art. 6 ust. 1 lit. c RODO),
- w zakresie danych podanych dobrowolnie ponad wymagane przepisami — na podstawie zgody (art. 6 ust. 1 lit. a RODO),
- udział w przyszłych rekrutacjach — wyłącznie na podstawie odrębnej zgody (art. 6 ust. 1 lit. a RODO).

## Okres przechowywania
Dane będą przechowywane do zakończenia procesu rekrutacji, a w przypadku zgody na przyszłe rekrutacje — nie dłużej niż 12 miesięcy lub do jej wycofania.

## Odbiorcy danych
Odbiorcami danych mogą być podmioty świadczące na rzecz administratora usługi IT i hostingowe, wyłącznie na podstawie umów powierzenia przetwarzania.

## Przysługujące prawa
Ma Pani/Pan prawo dostępu do swoich danych, ich sprostowania, usunięcia lub ograniczenia przetwarzania, prawo do przenoszenia danych oraz prawo do cofnięcia zgody w dowolnym momencie bez wpływu na zgodność z prawem przetwarzania dokonanego przed jej cofnięciem. Przysługuje Pani/Panu także prawo wniesienia skargi do Prezesa Urzędu Ochrony Danych Osobowych (ul. Stawki 2, 00-193 Warszawa).

## Informacja o wymogu podania danych
Podanie danych w zakresie określonym przepisami prawa pracy jest niezbędne do udziału w rekrutacji. Podanie pozostałych danych jest dobrowolne. Dane nie będą wykorzystywane do zautomatyzowanego podejmowania decyzji, w tym profilowania.
TXT;
}

final class GdprClauseService
{
    private PDO $pdo;
    /** @var array<string,string>|null */
    private ?array $varsCache = null;

    public function __construct(?PDO $pdo = null)
    {
        gdpr_clauses_migrate();
        $this->pdo = $pdo ?? db();
    }

    // ── Zmienne globalne ────────────────────────────────────────────────────

    /** Pełne wiersze (do panelu). */
    public function listVariables(): array
    {
        return $this->pdo->query("SELECT key_, value, label, sort_order, updated_at
                                  FROM global_variables ORDER BY sort_order, key_")->fetchAll();
    }

    /** @return array<string,string> klucz → wartość, bez cache między żądaniami. */
    public function variables(): array
    {
        if ($this->varsCache === null) {
            $this->varsCache = [];
            foreach ($this->pdo->query("SELECT key_, value FROM global_variables") as $r) {
                $this->varsCache[$r['key_']] = (string)$r['value'];
            }
        }
        return $this->varsCache;
    }

    public function saveVariable(string $key, string $value, string $label = '', ?int $sort = null): void
    {
        if (!preg_match(GDPR_VAR_KEY_RE, $key) || in_array($key, GDPR_BUILTIN_VARS, true)) {
            throw new InvalidArgumentException("Nieprawidłowy klucz zmiennej: {$key}");
        }
        $st = $this->pdo->prepare("INSERT INTO global_variables (key_, value, label, sort_order, updated_at)
                VALUES (:k, :v, :l, :s, datetime('now','localtime'))
                ON CONFLICT(key_) DO UPDATE SET value = excluded.value, label = excluded.label,
                    sort_order = excluded.sort_order,
                    updated_at = CASE WHEN global_variables.value <> excluded.value
                                      THEN excluded.updated_at ELSE global_variables.updated_at END");
        $st->execute([
            ':k' => $key, ':v' => $value, ':l' => $label,
            ':s' => $sort ?? (int)$this->pdo->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM global_variables")->fetchColumn(),
        ]);
        $this->varsCache = null;
    }

    public function deleteVariable(string $key): void
    {
        $this->pdo->prepare("DELETE FROM global_variables WHERE key_ = ?")->execute([$key]);
        $this->varsCache = null;
    }

    // ── Klauzule ────────────────────────────────────────────────────────────

    /** Wiersze posortowane: slug, potem język domyślny jako pierwszy. */
    public function listClauses(): array
    {
        return $this->pdo->query("SELECT id, slug, lang, tytul, is_published, updated_at, version, draft_status,
                    (SELECT COUNT(*) FROM gdpr_clause_history h WHERE h.clause_id = c.id) AS versions
                FROM gdpr_clauses c
                ORDER BY slug, CASE WHEN lang = '" . GDPR_DEFAULT_LANG . "' THEN 0 ELSE 1 END, lang")->fetchAll();
    }

    /** Opublikowane języki danego sluga: kod → tytuł. */
    public function languagesFor(string $slug): array
    {
        $st = $this->pdo->prepare("SELECT lang, tytul FROM gdpr_clauses WHERE slug = ? AND is_published = 1
                                   ORDER BY CASE WHEN lang = ? THEN 0 ELSE 1 END, lang");
        $st->execute([$slug, GDPR_DEFAULT_LANG]);
        return array_column($st->fetchAll(), 'tytul', 'lang');
    }

    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM gdpr_clauses WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Klauzula po slugu w żądanym języku; gdy tej wersji nie ma — język
     * domyślny, a gdy i jego brak — dowolna opublikowana wersja.
     */
    public function getBySlug(string $slug, bool $publishedOnly = true, ?string $lang = null): ?array
    {
        if (!preg_match(GDPR_SLUG_RE, $slug)) return null;
        $lang = isset(GDPR_LANGS[$lang ?? '']) ? $lang : GDPR_DEFAULT_LANG;
        $st = $this->pdo->prepare("SELECT * FROM gdpr_clauses WHERE slug = ?"
            . ($publishedOnly ? " AND is_published = 1" : "")
            . " ORDER BY CASE WHEN lang = ? THEN 0 WHEN lang = ? THEN 1 ELSE 2 END, lang LIMIT 1");
        $st->execute([$slug, $lang, GDPR_DEFAULT_LANG]);
        return $st->fetch() ?: null;
    }

    /** Konkretna wersja (bieżąca albo z historii) — do dowodu akceptacji i porównań. */
    public function version(int $clauseId, int $version): ?array
    {
        $cur = $this->getById($clauseId);
        if ($cur && (int)$cur['version'] === $version) return $cur;
        $st = $this->pdo->prepare("SELECT h.*, c.slug, c.lang FROM gdpr_clause_history h
                                   JOIN gdpr_clauses c ON c.id = h.clause_id
                                   WHERE h.clause_id = ? AND h.version = ?");
        $st->execute([$clauseId, $version]);
        $row = $st->fetch();
        if (!$row) return null;
        // Kształt jak wiersz gdpr_clauses (id, updated_at), żeby render/snapshot działały tak samo.
        $row['id'] = (int)$row['clause_id'];
        $row['updated_at'] = $row['valid_from'];
        return $row;
    }

    public function history(int $clauseId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM gdpr_clause_history WHERE clause_id = ? ORDER BY version DESC, valid_to DESC");
        $st->execute([$clauseId]);
        return $st->fetchAll();
    }

    /** @return array<string,string> zmienne lokalne klauzuli (wiersz z bazy). */
    public static function localVars(?array $clause): array
    {
        $v = json_decode((string)($clause['local_vars'] ?? '{}'), true);
        return is_array($v) ? array_map('strval', $v) : [];
    }

    /**
     * Zapis (id=0 → nowa). $d: slug, lang, tytul, content, is_published, local_vars (array).
     * Zwraca id. Rzuca InvalidArgumentException z komunikatem dla użytkownika.
     */
    /**
     * Walidacja i normalizacja pól klauzuli (wspólna dla zapisu i wersji roboczej).
     * @return array{slug:string,lang:string,tytul:string,content:string,is_published:bool,local_vars:array,local_json:string}
     */
    private function normalize(int $id, array $d): array
    {
        $lang  = (string)($d['lang'] ?? GDPR_DEFAULT_LANG);
        $slug  = trim(mb_strtolower((string)($d['slug'] ?? '')));
        $tytul = trim((string)($d['tytul'] ?? ''));
        $content = str_replace("\r\n", "\n", (string)($d['content'] ?? ''));
        $published = !empty($d['is_published']);
        $local = [];
        foreach ((array)($d['local_vars'] ?? []) as $k => $v) {
            $k = strtolower(trim((string)$k));
            if ($k === '') continue;
            if (!preg_match(GDPR_VAR_KEY_RE, $k) || in_array($k, GDPR_BUILTIN_VARS, true)) {
                throw new InvalidArgumentException("Nieprawidłowy klucz zmiennej lokalnej: {$k} (małe litery, cyfry i „_”, zaczyna się od litery).");
            }
            $local[$k] = trim((string)$v);
        }
        ksort($local);
        $localJson = json_encode($local, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

        if (!isset(GDPR_LANGS[$lang])) throw new InvalidArgumentException('Nieobsługiwany język klauzuli.');
        if (!preg_match(GDPR_SLUG_RE, $slug)) {
            throw new InvalidArgumentException('Slug: tylko małe litery a–z, cyfry i myślniki (np. „rekrutacja", „newsletter-2026").');
        }
        if ($tytul === '') throw new InvalidArgumentException('Podaj tytuł klauzuli.');

        $dup = $this->pdo->prepare("SELECT id FROM gdpr_clauses WHERE slug = ? AND lang = ? AND id <> ?");
        $dup->execute([$slug, $lang, $id]);
        if ($dup->fetchColumn()) throw new InvalidArgumentException("Klauzula „{$slug}\" w języku „" . GDPR_LANGS[$lang] . "\" już istnieje.");

        return ['slug' => $slug, 'lang' => $lang, 'tytul' => $tytul, 'content' => $content,
                'is_published' => $published, 'local_vars' => $local, 'local_json' => $localJson];
    }

    /**
     * Bezpośredni zapis (id=0 → nowa). $d: slug, lang, tytul, content, is_published, local_vars (array).
     * $approvedBy — kto zatwierdził tę wersję (obieg akceptacji); null = zapis bez obiegu.
     */
    public function saveClause(int $id, array $d, ?int $userId, ?int $approvedBy = null): int
    {
        ['slug' => $slug, 'lang' => $lang, 'tytul' => $tytul, 'content' => $content,
         'is_published' => $published, 'local_json' => $localJson] = $this->normalize($id, $d);
        $appr = $approvedBy !== null ? ", approved_by = " . (int)$approvedBy . ", approved_at = datetime('now','localtime')" : '';

        $this->pdo->beginTransaction();
        try {
            if ($id > 0) {
                $old = $this->getById($id);
                if (!$old) throw new InvalidArgumentException('Klauzula nie istnieje.');
                if ($old['content'] !== $content || $old['tytul'] !== $tytul || ($old['local_vars'] ?? '{}') !== $localJson) {
                    $this->pdo->prepare("INSERT INTO gdpr_clause_history (clause_id, version, tytul, content, local_vars, valid_from, valid_to, changed_by, approved_by, approved_at)
                                         VALUES (?,?,?,?,?,?,datetime('now','localtime'),?,?,?)")
                        ->execute([$id, (int)$old['version'], $old['tytul'], $old['content'], $old['local_vars'] ?? '{}', $old['updated_at'], $userId,
                                   $old['approved_by'] ?? null, $old['approved_at'] ?? null]);
                    // Nowa wersja: zatwierdzenie poprzedniej już nie dotyczy.
                    $touch = ", updated_at = datetime('now','localtime'), version = version + 1"
                           . ($appr !== '' ? $appr : ", approved_by = NULL, approved_at = NULL");
                } elseif ($appr !== '') {
                    $touch = $appr;
                } else {
                    $touch = '';
                }
                $this->pdo->prepare("UPDATE gdpr_clauses SET slug = ?, lang = ?, tytul = ?, content = ?, local_vars = ?, is_published = ?, updated_by = ? {$touch} WHERE id = ?")
                    ->execute([$slug, $lang, $tytul, $content, $localJson, $published ? 1 : 0, $userId, $id]);
            } else {
                $this->pdo->prepare("INSERT INTO gdpr_clauses (slug, lang, tytul, content, local_vars, is_published, created_at, updated_at, updated_by)
                                     VALUES (?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'),?)")
                    ->execute([$slug, $lang, $tytul, $content, $localJson, $published ? 1 : 0, $userId]);
                $id = (int)$this->pdo->lastInsertId();
                if ($appr !== '') $this->pdo->exec("UPDATE gdpr_clauses SET " . ltrim($appr, ', ') . " WHERE id = {$id}");
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    // ── Obieg akceptacji ────────────────────────────────────────────────────

    /** Czy zmiany opublikowanych klauzul wymagają zatwierdzenia (settings.gdpr_require_approval). */
    public static function approvalRequired(): bool
    {
        try { return (db_one("SELECT value FROM settings WHERE key_ = 'gdpr_require_approval'")['value'] ?? '') === '1'; }
        catch (\Throwable $e) { return false; }
    }

    /** Zatwierdzający: lista z settings.gdpr_approvers (id po przecinku), pusta → wszyscy aktywni admini. */
    public static function approverIds(): array
    {
        $raw = '';
        try { $raw = (string)(db_one("SELECT value FROM settings WHERE key_ = 'gdpr_approvers'")['value'] ?? ''); } catch (\Throwable $e) {}
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
        if ($ids) return $ids;
        return array_map('intval', array_column(db_all("SELECT id FROM users WHERE role = 'admin' AND COALESCE(is_active,1) = 1"), 'id'));
    }

    public static function canApprove(int $userId): bool
    {
        return in_array($userId, self::approverIds(), true);
    }

    /**
     * Czy ten zapis musi przejść przez akceptację. Bez obiegu, gdy obieg wyłączony,
     * zapisuje zatwierdzający, albo zmiana dotyczy nieopublikowanego szkicu i nie
     * prosi o publikację (szkic nie jest widoczny publicznie).
     */
    public function needsApproval(?array $current, array $d, int $userId): bool
    {
        if (!self::approvalRequired() || self::canApprove($userId)) return false;
        $wasPublished = $current && (int)$current['is_published'] === 1;
        return $wasPublished || !empty($d['is_published']);
    }

    /**
     * Zapis jako wersja robocza do akceptacji. Nowa klauzula powstaje jako szkic
     * (niepubliczny), a prośba o publikację czeka w draft_data. Zwraca id.
     */
    public function submitDraft(int $id, array $d, int $userId): int
    {
        $n = $this->normalize($id, $d);
        if ($id === 0) {
            $id = $this->saveClause(0, ['is_published' => 0] + $d, $userId);
        }
        $data = json_encode(['slug' => $n['slug'], 'lang' => $n['lang'], 'tytul' => $n['tytul'], 'content' => $n['content'],
                             'local_vars' => $n['local_vars'], 'is_published' => $n['is_published']], JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare("UPDATE gdpr_clauses SET draft_data = ?, draft_status = 'pending', draft_by = ?,
                             draft_at = datetime('now','localtime'), draft_note = NULL WHERE id = ?")
            ->execute([$data, $userId, $id]);
        $this->notify(self::approverIds(), $id, 'Klauzula RODO do akceptacji', 'Zmiana czeka na zatwierdzenie: ' . $n['tytul'], $userId);
        return $id;
    }

    /** Wersja robocza jako tablica pól (jak $d w saveClause) albo null. */
    public static function draft(?array $clause): ?array
    {
        if (!$clause || empty($clause['draft_data'])) return null;
        $d = json_decode((string)$clause['draft_data'], true);
        return is_array($d) ? $d : null;
    }

    public function approveDraft(int $id, int $approverId, string $note = ''): void
    {
        $c = $this->getById($id);
        $d = self::draft($c);
        if (!$d || $c['draft_status'] !== 'pending') throw new InvalidArgumentException('Brak zmiany oczekującej na akceptację.');
        if (!self::canApprove($approverId)) throw new InvalidArgumentException('Nie masz uprawnień do zatwierdzania klauzul.');
        $this->saveClause($id, $d, (int)$c['draft_by'], $approverId);
        $this->pdo->prepare("UPDATE gdpr_clauses SET draft_data = NULL, draft_status = '', draft_note = ? WHERE id = ?")
            ->execute([$note !== '' ? $note : null, $id]);
        $this->notify([(int)$c['draft_by']], $id, 'Klauzula RODO zatwierdzona', $d['tytul'] . ($note !== '' ? ' — ' . $note : ''), $approverId);
    }

    public function rejectDraft(int $id, int $approverId, string $note): void
    {
        $c = $this->getById($id);
        if (!$c || $c['draft_status'] !== 'pending') throw new InvalidArgumentException('Brak zmiany oczekującej na akceptację.');
        if (!self::canApprove($approverId)) throw new InvalidArgumentException('Nie masz uprawnień do zatwierdzania klauzul.');
        if (trim($note) === '') throw new InvalidArgumentException('Podaj powód odrzucenia — autor zobaczy go przy klauzuli.');
        $this->pdo->prepare("UPDATE gdpr_clauses SET draft_status = 'rejected', draft_note = ? WHERE id = ?")->execute([trim($note), $id]);
        $this->notify([(int)$c['draft_by']], $id, 'Klauzula RODO odrzucona', $c['tytul'] . ' — ' . trim($note), $approverId);
    }

    /** Wycofanie własnej wersji roboczej (lub przez zatwierdzającego). */
    public function discardDraft(int $id): void
    {
        $this->pdo->prepare("UPDATE gdpr_clauses SET draft_data = NULL, draft_status = '', draft_note = NULL WHERE id = ?")->execute([$id]);
    }

    public function pendingCount(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM gdpr_clauses WHERE draft_status = 'pending'")->fetchColumn();
    }

    /** Powiadomienie w dzwonku (includes/notifications.php), bez autora zdarzenia. */
    private function notify(array $userIds, int $clauseId, string $title, string $body, int $actorId): void
    {
        $f = dirname(__DIR__, 3) . '/includes/notifications.php';
        if (!is_file($f)) return;
        require_once $f;
        if (!function_exists('notif_create')) return;
        $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/modules/gdpr_clauses/edit.php?id=' . $clauseId;
        foreach (array_unique($userIds) as $uid) {
            if ($uid > 0 && $uid !== $actorId) {
                try { notif_create($uid, 'gdpr_clause', $title, $body, $url); } catch (\Throwable $e) {}
            }
        }
    }

    // ── Rejestr akceptacji ──────────────────────────────────────────────────

    /** Konteksty akceptacji (skąd przyszła) → etykieta w panelu. */
    public const CONTEXTS = [
        'volunteer_offer' => 'Zgłoszenie na ofertę wolontariatu',
        'event'           => 'Zapis na wydarzenie',
        'crm_form'        => 'Formularz CRM',
        'onboarding'      => 'Onboarding',
        'api'             => 'API (system zewnętrzny)',
        'manual'          => 'Wpis ręczny',
    ];

    /** Migawka dokładnie wyświetlonego tekstu (HTML) — zwraca hash; zapis tylko przy nowej treści. */
    public function snapshot(array $clause): string
    {
        $html = $this->renderClause($clause);
        $hash = hash('sha256', $clause['tytul'] . "\n" . $html);
        $this->pdo->prepare("INSERT OR IGNORE INTO gdpr_clause_snapshots (hash, clause_id, version, lang, tytul, html, created_at)
                             VALUES (?,?,?,?,?,?,datetime('now','localtime'))")
            ->execute([$hash, (int)$clause['id'], (int)$clause['version'], $clause['lang'], $clause['tytul'], $html]);
        return $hash;
    }

    /**
     * Zapis akceptacji klauzuli. $subject: name, email; $ref: [typ, id] rekordu,
     * do którego akceptacja należy (np. ['volunteer_application', 123]).
     * IP i user-agent z bieżącego żądania (w CLI puste).
     */
    public function recordAcceptance(array $clause, string $context, array $subject = [], ?array $ref = null): int
    {
        $hash = $this->snapshot($clause);
        $this->pdo->prepare("INSERT INTO gdpr_clause_acceptances
                (clause_id, slug, lang, version, snapshot_hash, context, ref_type, ref_id, subject_name, subject_email, accepted_at, ip, user_agent)
                VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now','localtime'),?,?)")
            ->execute([
                (int)$clause['id'], $clause['slug'], $clause['lang'], (int)$clause['version'], $hash,
                mb_substr($context, 0, 40), $ref[0] ?? null, isset($ref[1]) ? (int)$ref[1] : null,
                mb_substr(trim((string)($subject['name'] ?? '')), 0, 255),
                mb_substr(mb_strtolower(trim((string)($subject['email'] ?? ''))), 0, 255),
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Akceptacje z filtrami: clause_id, context, q (imię/e-mail), from, to (Y-m-d). */
    public function acceptances(array $f = [], int $limit = 200, int $offset = 0): array
    {
        [$where, $params] = $this->acceptanceWhere($f);
        $st = $this->pdo->prepare("SELECT a.*, c.tytul FROM gdpr_clause_acceptances a
                                   LEFT JOIN gdpr_clauses c ON c.id = a.clause_id
                                   WHERE {$where} ORDER BY a.accepted_at DESC, a.id DESC
                                   LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset));
        $st->execute($params);
        return $st->fetchAll();
    }

    public function acceptanceCount(array $f = []): int
    {
        [$where, $params] = $this->acceptanceWhere($f);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM gdpr_clause_acceptances a WHERE {$where}");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    private function acceptanceWhere(array $f): array
    {
        $w = ['1=1']; $p = [];
        if (!empty($f['clause_id'])) { $w[] = 'a.clause_id = CAST(? AS INTEGER)'; $p[] = (int)$f['clause_id']; }
        if (!empty($f['slug']))      { $w[] = 'a.slug = ?'; $p[] = (string)$f['slug']; }
        if (!empty($f['context']))   { $w[] = 'a.context = ?'; $p[] = (string)$f['context']; }
        if (!empty($f['q']))         { $w[] = '(a.subject_email LIKE ? OR a.subject_name LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%'; }
        if (!empty($f['from']))      { $w[] = 'a.accepted_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']))        { $w[] = 'a.accepted_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['ref_type']))  { $w[] = 'a.ref_type = ?'; $p[] = (string)$f['ref_type']; }
        if (!empty($f['ref_id']))    { $w[] = 'a.ref_id = CAST(? AS INTEGER)'; $p[] = (int)$f['ref_id']; }
        return [implode(' AND ', $w), $p];
    }

    public function acceptanceById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT a.*, s.html, s.tytul AS snap_tytul FROM gdpr_clause_acceptances a
                                   LEFT JOIN gdpr_clause_snapshots s ON s.hash = a.snapshot_hash WHERE a.id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Usunięcie niszczyłoby dowód akceptacji — dozwolone tylko bez akceptacji. */
    public function deleteClause(int $id): void
    {
        if ($this->acceptanceCount(['clause_id' => $id]) > 0) {
            throw new InvalidArgumentException('Tej klauzuli nie można usunąć — ma zarejestrowane akceptacje (dowód dla RODO art. 7 ust. 1). Wycofaj ją z publikacji.');
        }
        $this->pdo->prepare("DELETE FROM gdpr_clause_history WHERE clause_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM gdpr_clauses WHERE id = ?")->execute([$id]);
    }

    // ── Renderowanie ────────────────────────────────────────────────────────

    /** Tagi użyte w treści, których nie ma w zmiennych (do ostrzeżenia w panelu). */
    public function unknownTags(string $content, array $localVars = []): array
    {
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', $content, $m);
        $known = array_merge(array_keys($this->variables()), array_keys($localVars), GDPR_BUILTIN_VARS);
        return array_values(array_unique(array_diff(array_map('strtolower', $m[1]), $known)));
    }

    /** Pełny render klauzuli z bazy (zmienne lokalne z wiersza). */
    public function renderClause(array $clause, bool $highlightUnknown = false): string
    {
        return $this->render($clause['content'], $clause['updated_at'] ?? null, $highlightUnknown, self::localVars($clause));
    }

    /**
     * Treść → bezpieczny HTML z podstawionymi zmiennymi.
     * $highlightUnknown: w podglądzie admina nieznane tagi zostają widoczne (<mark>).
     */
    public function render(string $content, ?string $updatedAt = null, bool $highlightUnknown = false, array $localVars = []): string
    {
        $vars = array_merge($this->variables(), $localVars) + [
            'updated_at' => $updatedAt ? date('d.m.Y', strtotime($updatedAt)) : '',
            'today'      => date('d.m.Y'),
        ];
        // Zmienne podstawiamy jako znaczniki-zastępniki PRZED formatowaniem, żeby
        // wartość (np. adres z gwiazdką) nie była interpretowana jako **pogrubienie**,
        // a jej treść była escapowana dokładnie raz.
        $slots = [];
        $text = preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', function ($m) use ($vars, $highlightUnknown, &$slots) {
            $k = strtolower($m[1]);
            if (array_key_exists($k, $vars)) {
                $v = $vars[$k];
                $html = gdpr_clauses_h_multiline($v);
                if (filter_var(trim($v), FILTER_VALIDATE_EMAIL)) {
                    $html = '<a href="mailto:' . htmlspecialchars(trim($v), ENT_QUOTES) . '">' . $html . '</a>';
                }
                if ($highlightUnknown) {
                    $cls = array_key_exists($k, $localVars) ? 'gdpr-var gdpr-var-local' : 'gdpr-var';
                    $html = '<span class="' . $cls . '" title="{{' . $k . '}}">' . $html . '</span>';
                }
            } else {
                $html = $highlightUnknown ? '<mark class="gdpr-unknown">{{' . htmlspecialchars($k) . '}}</mark>' : '';
            }
            $slots[] = $html;
            return "\x1A" . (count($slots) - 1) . "\x1A";
        }, $content);

        $html = self::renderBody($text);
        return preg_replace_callback('/\x1A(\d+)\x1A/', fn($m) => $slots[(int)$m[1]] ?? '', $html);
    }

    /** Prosty, bezpieczny format blokowy (patrz nagłówek pliku). */
    private static function renderBody(string $text): string
    {
        $blocks = preg_split("/\n\s*\n/", trim(str_replace("\r\n", "\n", $text)));
        $out = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn($l) => $l !== ''));
            if (!$lines) continue;
            $para = [];
            $list = null; // ['ul'|'ol', items[]]
            $flushPara = function () use (&$para, &$out) {
                if ($para) { $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>'; $para = []; }
            };
            $flushList = function () use (&$list, &$out) {
                if ($list) {
                    $out[] = "<{$list[0]}>" . implode('', array_map(fn($i) => '<li>' . self::inline($i) . '</li>', $list[1])) . "</{$list[0]}>";
                    $list = null;
                }
            };
            foreach ($lines as $line) {
                if (preg_match('/^(#{2,3})\s+(.+)$/', $line, $m)) {
                    $flushPara(); $flushList();
                    $tag = strlen($m[1]) === 2 ? 'h2' : 'h3';
                    $out[] = "<{$tag}>" . self::inline($m[2]) . "</{$tag}>";
                } elseif (preg_match('/^\s*[-*•]\s+(.+)$/u', $line, $m)) {
                    $flushPara();
                    if ($list && $list[0] !== 'ul') $flushList();
                    $list ??= ['ul', []];
                    $list[1][] = $m[1];
                } elseif (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $m)) {
                    $flushPara();
                    if ($list && $list[0] !== 'ol') $flushList();
                    $list ??= ['ol', []];
                    $list[1][] = $m[1];
                } else {
                    $flushList();
                    $para[] = $line;
                }
            }
            $flushPara(); $flushList();
        }
        return implode("\n", $out);
    }

    private static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<em>$1</em>', $s);
        $s = preg_replace('~\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:)]~u', '<a href="$0" target="_blank" rel="noopener">$0</a>', $s);
        $s = preg_replace('/(?<![\w.\/:="])([\w.+-]+@[\w-]+(?:\.[\w-]+)+)(?![\w"])/u', '<a href="mailto:$1">$1</a>', $s);
        return $s;
    }
}

function gdpr_clauses_h_multiline(string $v): string {
    return nl2br(htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
}

/** Publiczny adres klauzuli (czysty URL obsługiwany przez .htaccess). */
function gdpr_clauses_public_url(string $slug, bool $embed = false, string $lang = GDPR_DEFAULT_LANG): string {
    return rtrim(APP_URL, '/') . '/klauzula/' . rawurlencode($slug)
        . ($lang !== GDPR_DEFAULT_LANG && isset(GDPR_LANGS[$lang]) ? '/' . $lang : '')
        . ($embed ? '?embed=1' : '');
}

/** Teksty interfejsu strony publicznej w danym języku (fallback: polski). */
function gdpr_clauses_ui(string $lang): array {
    static $t = [
        'pl' => ['updated' => 'Ostatnia aktualizacja', 'print' => 'Drukuj', 'kicker' => 'Ochrona danych osobowych',
                 'nf_title' => 'Nie znaleziono klauzuli', 'nf_body' => 'Adres jest nieprawidłowy lub klauzula nie jest już publikowana.',
                 'unavailable' => 'Klauzula jest niedostępna.', 'lang' => 'Język', 'skip' => 'Przejdź do treści'],
        'en' => ['updated' => 'Last updated', 'print' => 'Print', 'kicker' => 'Personal data protection',
                 'nf_title' => 'Notice not found', 'nf_body' => 'The address is invalid or this notice is no longer published.',
                 'unavailable' => 'This notice is unavailable.', 'lang' => 'Language', 'skip' => 'Skip to content'],
        'uk' => ['updated' => 'Останнє оновлення', 'print' => 'Друкувати', 'kicker' => 'Захист персональних даних',
                 'nf_title' => 'Клаузулу не знайдено', 'nf_body' => 'Адреса неправильна або клаузула більше не публікується.',
                 'unavailable' => 'Клаузула недоступна.', 'lang' => 'Мова', 'skip' => 'Перейти до змісту'],
        'de' => ['updated' => 'Zuletzt aktualisiert', 'print' => 'Drucken', 'kicker' => 'Datenschutz',
                 'nf_title' => 'Hinweis nicht gefunden', 'nf_body' => 'Die Adresse ist ungültig oder der Hinweis wird nicht mehr veröffentlicht.',
                 'unavailable' => 'Der Hinweis ist nicht verfügbar.', 'lang' => 'Sprache', 'skip' => 'Zum Inhalt springen'],
        'fr' => ['updated' => 'Dernière mise à jour', 'print' => 'Imprimer', 'kicker' => 'Protection des données personnelles',
                 'nf_title' => 'Clause introuvable', 'nf_body' => "L'adresse est invalide ou la clause n'est plus publiée.",
                 'unavailable' => 'Clause indisponible.', 'lang' => 'Langue', 'skip' => 'Aller au contenu'],
        'es' => ['updated' => 'Última actualización', 'print' => 'Imprimir', 'kicker' => 'Protección de datos personales',
                 'nf_title' => 'Cláusula no encontrada', 'nf_body' => 'La dirección no es válida o la cláusula ya no está publicada.',
                 'unavailable' => 'Cláusula no disponible.', 'lang' => 'Idioma', 'skip' => 'Ir al contenido'],
    ];
    return $t[$lang] ?? $t['pl'];
}

/**
 * Tekst porównywalny wersji: tytuł + treść + zmienne lokalne (klucz: wartość),
 * bo zmiana zmiennej lokalnej zmienia tekst widziany przez odbiorcę.
 */
function gdpr_clauses_diff_source(array $row): string {
    $out = '# ' . $row['tytul'] . "\n\n" . $row['content'];
    $lv = GdprClauseService::localVars($row);
    if ($lv) {
        $out .= "\n\n— zmienne lokalne —";
        foreach ($lv as $k => $v) $out .= "\n{$k}: {$v}";
    }
    return $out;
}

/**
 * Porównanie dwóch tekstów na poziomie słów (LCS) → HTML z <del>/<ins>.
 * Dla bardzo długich tekstów (limit komórek tablicy) — porównanie wierszami.
 */
function gdpr_clauses_diff_html(string $old, string $new): string {
    $tok = fn(string $t) => preg_split('/(\s+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    $a = $tok($old); $b = $tok($new);
    if (count($a) * count($b) > 4_000_000) {
        $a = preg_split('/(?<=\n)/', $old) ?: []; $b = preg_split('/(?<=\n)/', $new) ?: [];
    }
    $n = count($a); $m = count($b);
    // Wspólny początek/koniec poza tablicą LCS — zwykle zmiana dotyczy małego fragmentu.
    $pre = 0; while ($pre < $n && $pre < $m && $a[$pre] === $b[$pre]) $pre++;
    $suf = 0; while ($suf < $n - $pre && $suf < $m - $pre && $a[$n - 1 - $suf] === $b[$m - 1 - $suf]) $suf++;
    $A = array_slice($a, $pre, $n - $pre - $suf); $B = array_slice($b, $pre, $m - $pre - $suf);
    $x = count($A); $y = count($B);
    $L = array_fill(0, $x + 1, array_fill(0, $y + 1, 0));
    for ($i = $x - 1; $i >= 0; $i--) for ($j = $y - 1; $j >= 0; $j--)
        $L[$i][$j] = $A[$i] === $B[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    $e = fn(string $t) => nl2br(htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    $html = $e(implode('', array_slice($a, 0, $pre)));
    $i = $j = 0; $del = $ins = '';
    $flush = function () use (&$del, &$ins, &$html, $e) {
        if ($del !== '') $html .= '<del>' . $e($del) . '</del>';
        if ($ins !== '') $html .= '<ins>' . $e($ins) . '</ins>';
        $del = $ins = '';
    };
    while ($i < $x || $j < $y) {
        if ($i < $x && $j < $y && $A[$i] === $B[$j]) { $flush(); $html .= $e($A[$i]); $i++; $j++; }
        elseif ($j < $y && ($i >= $x || $L[$i][$j + 1] >= $L[$i + 1][$j])) { $ins .= $B[$j++]; }
        else { $del .= $A[$i++]; }
    }
    $flush();
    return $html . $e(implode('', array_slice($a, $n - $suf)));
}

// ── Integracja z formularzami ───────────────────────────────────────────────

/** Miejsca w systemie, które mogą brać klauzulę z modułu (klucz = settings gdpr_default_{klucz}). */
const GDPR_INTEGRATION_CONTEXTS = [
    'volunteer_offer' => 'Oferty wolontariatu — formularz zgłoszeniowy',
    'event'           => 'Zapisy na wydarzenia',
    'onboarding'      => 'Onboarding (formularz danych)',
    'crm_form'        => 'Formularze CRM (cele zgód bez własnej klauzuli)',
    'mail_footer'     => 'Stopka korespondencji CRM — link do pełnej klauzuli',
    'public_footer'   => 'Stopki publicznych formularzy — link „Klauzula informacyjna”',
];

/** Slug klauzuli domyślnej dla miejsca w systemie ('' = brak, zostaje tekst wbudowany). */
function gdpr_clauses_default_slug(string $context): string {
    try {
        $v = (string)(db_one("SELECT value FROM settings WHERE key_ = ?", ['gdpr_default_' . $context])['value'] ?? '');
        return preg_match(GDPR_SLUG_RE, $v) ? $v : '';
    } catch (\Throwable $e) {
        return '';
    }
}

/** Opublikowane klauzule do wyboru w innych modułach: slug → tytuł (wersja domyślnego języka). */
function gdpr_clauses_options(): array {
    try {
        $rows = db_all("SELECT slug, tytul, lang FROM gdpr_clauses WHERE is_published = 1
                        ORDER BY slug, CASE WHEN lang = ? THEN 0 ELSE 1 END", [GDPR_DEFAULT_LANG]);
    } catch (\Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[$r['slug']] ??= $r['tytul'];
    return $out;
}

/** <select> klauzul do formularzy innych modułów. $emptyLabel — opis opcji „brak”. */
function gdpr_clauses_select(string $name, string $selected, string $emptyLabel = '— bez klauzuli z rejestru —', string $idAttr = ''): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $out = '<select name="' . $h($name) . '"' . ($idAttr !== '' ? ' id="' . $h($idAttr) . '"' : '') . ' class="form-select">'
         . '<option value="">' . $h($emptyLabel) . '</option>';
    $opts = gdpr_clauses_options();
    if ($selected !== '' && !isset($opts[$selected])) $opts[$selected] = $selected . ' (nieopublikowana!)';
    foreach ($opts as $slug => $t) {
        $out .= '<option value="' . $h($slug) . '"' . ($slug === $selected ? ' selected' : '') . '>' . $h($t) . '</option>';
    }
    return $out . '</select>';
}

/**
 * Klauzula dla formularza: wskazany slug, a gdy pusty — domyślna dla miejsca.
 * null = nic nie skonfigurowano / nieopublikowana → formularz zostaje przy swoim tekście.
 */
function gdpr_clause_resolve(?string $slug, string $context, ?string $lang = null): ?array {
    $slug = trim((string)$slug);
    if ($slug === '') $slug = gdpr_clauses_default_slug($context);
    return $slug !== '' ? gdpr_clause_for_form($slug, $lang) : null;
}

/**
 * Klauzula do pokazania w formularzu: ['clause', 'html', 'url', 'field'] albo null
 * (brak opublikowanej — formularz używa wtedy własnego tekstu). 'field' to ukryte
 * pole z wersją, którą zobaczył użytkownik — zapis akceptacji wskaże tę wersję,
 * nawet jeśli klauzula zmieniła się, zanim formularz został wysłany.
 */
function gdpr_clause_for_form(string $slug, ?string $lang = null): ?array {
    try {
        $svc = new GdprClauseService();
        $c = $svc->getBySlug($slug, true, $lang);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$c) return null;
    return [
        'clause' => $c,
        'html'   => $svc->renderClause($c),
        'url'    => gdpr_clauses_public_url($c['slug'], false, $c['lang']),
        'field'  => '<input type="hidden" name="gdpr_shown[' . htmlspecialchars($c['slug'], ENT_QUOTES) . ']" value="'
                    . (int)$c['id'] . ':' . (int)$c['version'] . '">',
    ];
}

/**
 * Zapis akceptacji po wysłaniu formularza — wersja z pola gdpr_shown (gdy nadal
 * istnieje), inaczej bieżąca. Zwraca id akceptacji albo null (brak klauzuli).
 * Nigdy nie rzuca: rejestr nie może zablokować zapisu zgłoszenia.
 */
function gdpr_clause_accept_from_post(string $slug, string $context, array $subject = [], ?array $ref = null, ?string $lang = null): ?int {
    try {
        $svc = new GdprClauseService();
        $row = null;
        $shown = (string)($_POST['gdpr_shown'][$slug] ?? '');
        if (preg_match('/^(\d+):(\d+)$/', $shown, $m)) {
            $row = $svc->version((int)$m[1], (int)$m[2]);
            if ($row && $row['slug'] !== $slug) $row = null;
        }
        $row ??= $svc->getBySlug($slug, true, $lang);
        return $row ? $svc->recordAcceptance($row, $context, $subject, $ref) : null;
    } catch (\Throwable $e) {
        error_log('gdpr_clause_accept_from_post: ' . $e->getMessage());
        return null;
    }
}
