<?php
/**
 * modules/doc_generator/logic/docGenStore.php — wzory (gen_templates) i wygenerowane dokumenty (gen_documents) generatora dokumentów.
 * Wzory wbudowane leżą w modules/doc_generator/templates/*.php (zasiew: brakujące są dopisywane, istniejących nie nadpisujemy —
 * „Przywróć wzór wbudowany” robi to świadomie). Dokument zapamiętuje WARTOŚCI pól, a nie treść: zmiana wzoru nie psuje starych
 * dokumentów, ale ponowne wygenerowanie bierze aktualny wzór.
 */
require_once __DIR__ . '/docGenEngine.php';

final class DocGenStore
{
    public const STATUSES = ['szkic' => 'Szkic', 'gotowy' => 'Gotowy do podpisu', 'podpisany' => 'Podpisany', 'anulowany' => 'Anulowany'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        db()->exec("CREATE TABLE IF NOT EXISTS gen_templates (
            id {$pk}, slug VARCHAR(80) NOT NULL, name VARCHAR(255) NOT NULL, category VARCHAR(80) NOT NULL DEFAULT '',
            description TEXT, body TEXT NOT NULL, fields TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, builtin INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(19), updated_at VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_gen_templates_slug ON gen_templates(slug)");
        db()->exec("CREATE TABLE IF NOT EXISTS gen_documents (
            id {$pk}, template_id INTEGER NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '', data TEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'szkic', created_by INTEGER, created_by_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19), updated_at VARCHAR(19)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_gen_documents_tpl ON gen_documents(template_id)");
        self::seed();
    }

    /** Dopisuje brakujące wzory wbudowane. */
    public static function seed(): void
    {
        foreach (glob(dirname(__DIR__) . '/templates/*.php') ?: [] as $f) {
            $t = require $f;
            if (!is_array($t) || empty($t['slug'])) continue;
            if (db_one("SELECT 1 FROM gen_templates WHERE slug = ?", [$t['slug']])) continue;
            self::insertBuiltin($t);
        }
    }

    private static function insertBuiltin(array $t): int
    {
        $now = date('Y-m-d H:i:s');
        return db_insert('gen_templates', ['slug' => $t['slug'], 'name' => $t['name'], 'category' => (string)($t['category'] ?? ''), 'description' => (string)($t['description'] ?? ''),
            'body' => $t['body'], 'fields' => json_encode(array_values(DocGenEngine::normalizeFields($t['fields'] ?? [])), JSON_UNESCAPED_UNICODE),
            'is_active' => 1, 'builtin' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Przywraca wzór wbudowany do postaci z pliku (nadpisuje zmiany). */
    public static function restoreBuiltin(string $slug): bool
    {
        foreach (glob(dirname(__DIR__) . '/templates/*.php') ?: [] as $f) {
            $t = require $f;
            if (($t['slug'] ?? '') !== $slug) continue;
            $row = db_one("SELECT id FROM gen_templates WHERE slug = ?", [$slug]);
            if (!$row) { self::insertBuiltin($t); return true; }
            db_update('gen_templates', ['name' => $t['name'], 'category' => (string)($t['category'] ?? ''), 'description' => (string)($t['description'] ?? ''), 'body' => $t['body'],
                'fields' => json_encode(array_values(DocGenEngine::normalizeFields($t['fields'] ?? [])), JSON_UNESCAPED_UNICODE), 'builtin' => 1, 'updated_at' => date('Y-m-d H:i:s')], (int)$row['id']);
            return true;
        }
        return false;
    }

    public static function templates(bool $onlyActive = false): array
    {
        self::migrate();
        return db_all("SELECT t.*, (SELECT COUNT(*) FROM gen_documents d WHERE d.template_id = t.id) AS docs FROM gen_templates t" . ($onlyActive ? " WHERE t.is_active = 1" : "") . " ORDER BY t.category, t.name");
    }

    public static function template(int $id): ?array
    {
        self::migrate();
        $t = db_one("SELECT * FROM gen_templates WHERE id = ?", [$id]);
        if (!$t) return null;
        $t['fields_def'] = DocGenEngine::normalizeFields((array)(json_decode((string)$t['fields'], true) ?: []));
        return $t;
    }

    /** Zapis wzoru z formularza. Pola: z wiersza formularza + brakujące klucze z treści (etykieta = klucz). Zwraca [id, błąd]. */
    public static function saveTemplate(int $id, array $in): array
    {
        self::migrate();
        $name = trim((string)($in['name'] ?? ''));
        $body = str_replace("\r", '', (string)($in['body'] ?? ''));
        if ($name === '') return [0, 'Podaj nazwę wzoru.'];
        if (trim($body) === '') return [0, 'Treść wzoru nie może być pusta.'];
        $defs = [];
        foreach ((array)($in['f'] ?? []) as $row) {
            $k = (string)($row['key'] ?? '');
            if ($k === '') continue;
            $defs[$k] = ['key' => $k, 'label' => trim((string)($row['label'] ?? '')) ?: $k, 'type' => (string)($row['type'] ?? 'text'), 'required' => !empty($row['required']),
                         'default' => (string)($row['default'] ?? ''), 'group' => trim((string)($row['group'] ?? '')) ?: 'Dane', 'hint' => (string)($row['hint'] ?? ''), 'options' => (string)($row['options'] ?? '')];
        }
        foreach (DocGenEngine::fieldKeys($body) as $k) if (!isset($defs[$k])) $defs[$k] = ['key' => $k, 'label' => $k, 'type' => 'text', 'group' => 'Dane'];
        $defs = DocGenEngine::normalizeFields($defs);
        $now = date('Y-m-d H:i:s');
        $data = ['name' => mb_substr($name, 0, 255), 'category' => mb_substr(trim((string)($in['category'] ?? '')), 0, 80), 'description' => trim((string)($in['description'] ?? '')),
                 'body' => $body, 'fields' => json_encode(array_values($defs), JSON_UNESCAPED_UNICODE), 'is_active' => !empty($in['is_active']) ? 1 : 0, 'updated_at' => $now];
        if ($id > 0) {
            if (!db_one("SELECT 1 FROM gen_templates WHERE id = ?", [$id])) return [0, 'Nie ma takiego wzoru.'];
            db_update('gen_templates', $data, $id);
            return [$id, ''];
        }
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: 'wzor'))), '-') ?: 'wzor';
        $base = $slug; $n = 2;
        while (db_one("SELECT 1 FROM gen_templates WHERE slug = ?", [$slug])) $slug = $base . '-' . $n++;
        return [db_insert('gen_templates', $data + ['slug' => $slug, 'builtin' => 0, 'created_at' => $now]), ''];
    }

    public static function deleteTemplate(int $id): string
    {
        self::migrate();
        if ((int)(db_one("SELECT COUNT(*) c FROM gen_documents WHERE template_id = ?", [$id])['c'] ?? 0) > 0) return 'Wzór ma wygenerowane dokumenty — wyłącz go zamiast usuwać.';
        db_exec("DELETE FROM gen_templates WHERE id = ?", [$id]);
        return '';
    }

    public static function document(int $id): ?array
    {
        self::migrate();
        $d = db_one("SELECT * FROM gen_documents WHERE id = ?", [$id]);
        if (!$d) return null;
        $d['values'] = (array)(json_decode((string)$d['data'], true) ?: []);
        return $d;
    }

    public static function documents(string $q = '', int $templateId = 0, string $status = '', int $limit = 300): array
    {
        self::migrate();
        $w = ['1=1']; $p = [];
        if ($q !== '') { $w[] = '(d.title LIKE ? OR d.data LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if ($templateId > 0) { $w[] = 'd.template_id = ?'; $p[] = $templateId; }
        if (isset(self::STATUSES[$status])) { $w[] = 'd.status = ?'; $p[] = $status; }
        return db_all("SELECT d.*, t.name AS template_name FROM gen_documents d LEFT JOIN gen_templates t ON t.id = d.template_id WHERE " . implode(' AND ', $w) . " ORDER BY d.id DESC LIMIT " . (int)$limit, $p);
    }

    /** Wartość domyślna pola przy nowym dokumencie; daty: „@dzisiaj”, „@+3” (dzisiaj + 3 dni), „@-1”. Pole „data_zawarcia” bez domyślnej = dzisiaj. */
    public static function defaultValue(array $f): string
    {
        $d = (string)$f['default'];
        if ($f['type'] === 'date') {
            if ($d === '@dzisiaj' || ($d === '' && $f['key'] === 'data_zawarcia')) return date('Y-m-d');
            if (preg_match('/^@([+-]\d{1,3})$/', $d, $m)) return date('Y-m-d', strtotime($m[1] . ' days'));
        }
        return $d;
    }

    /** Waliduje wartości wg definicji pól; zwraca [wartości, błędy]. */
    public static function collect(array $fields, array $post): array
    {
        $vals = []; $err = [];
        foreach ($fields as $k => $f) {
            $v = trim(str_replace("\r", '', (string)($post['v'][$k] ?? '')));
            if ($f['type'] === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $err[] = $f['label'] . ': zły format daty.'; $v = ''; }
            if ($f['type'] === 'gender') $v = $v === 'M' ? 'M' : 'K';
            if ($f['type'] === 'select' && $f['options'] && !in_array($v, $f['options'], true)) $v = $f['default'] !== '' ? $f['default'] : (string)($f['options'][0] ?? '');
            if ($f['required'] && $v === '') $err[] = $f['label'] . ' jest wymagane.';
            $vals[$k] = mb_substr($v, 0, 4000);
        }
        return [$vals, $err];
    }

    public static function saveDocument(int $id, int $templateId, string $title, array $values, string $status, int $uid, string $name): int
    {
        self::migrate();
        $now = date('Y-m-d H:i:s');
        $row = ['title' => mb_substr(trim($title), 0, 255), 'data' => json_encode($values, JSON_UNESCAPED_UNICODE), 'updated_at' => $now];
        if (isset(self::STATUSES[$status])) $row['status'] = $status;
        if ($id > 0) { db_update('gen_documents', $row, $id); return $id; }
        return db_insert('gen_documents', $row + ['template_id' => $templateId, 'created_by' => $uid ?: null, 'created_by_name' => mb_substr($name, 0, 190), 'created_at' => $now]);
    }

    public static function deleteDocument(int $id): void
    {
        self::migrate();
        db_exec("DELETE FROM gen_documents WHERE id = ?", [$id]);
    }

    /** Tekst dokumentu po rozwinięciu wzoru. @return array{blocks:array,title:string} */
    public static function build(array $template, array $values): array
    {
        $text = DocGenEngine::render((string)$template['body'], $values, $template['fields_def']);
        return ['blocks' => DocGenEngine::blocks($text), 'title' => (string)$template['name']];
    }

    /** Domyślny tytuł dokumentu: nazwa wzoru + pierwsze „nazwowe” pole (uczeń / rodzic / strona). */
    public static function defaultTitle(array $template, array $values): string
    {
        foreach (['uczen_nazwa', 'rodzic_nazwa', 'strona', 'imie_nazwisko', 'nazwa'] as $k) if (!empty($values[$k])) return mb_substr($template['name'], 0, 120) . ' — ' . $values[$k];
        return (string)$template['name'];
    }
}
