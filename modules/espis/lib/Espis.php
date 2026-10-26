<?php
declare(strict_types=1);

/**
 * modules/espis/lib/Espis.php — eSpis: elektroniczny spis dokumentów koszulki EZD + kod QR w stopkach dokumentów.
 *
 * Mechanizm (wspólny dla AM, kart konsultacji KT1 i dokumentów kursanta):
 *   1. KOSZULKA EZD po odniesieniu (ref_type/ref_id): jedna na sprawę AM (`licencja_am`), operację AM (`operacja_am`), kartę konsultacji
 *      (`ti_konsultacja`) albo kursanta (`ti_kursant`). Zakładana na żądanie w rocznym segregatorze właściwej klasy JRWA
 *      (AM → 512 „Licencje / SaaS”, konsultacje → 381, dokumenty kursanta → 383; nadpisanie: org_setting espis_jrwa_{rodzaj}).
 *   2. Każdy wygenerowany dokument trafia do koszulki jako pismo wewnętrzne z załącznikiem PDF (Espis::file) i jest wpisany do rejestru
 *      espis_documents (kod Code128, rewizja, stan obowiązuje | unieważniony). Nowa rewizja tego samego dokumentu unieważnia poprzednią.
 *   3. W stopce dokumentu: QR do PUBLICZNEGO eSpisu koszulki (/espis/?t=TOKEN, token HMAC, bez logowania) — lista dokumentów z numerami,
 *      kodami, rewizją i stanem; BEZ danych osobowych (tytuł koszulki i nazwiska nie są pokazywane).
 * QR w stopce pojawia się, gdy koszulka już istnieje (ensureSprawa wołane przed renderem); bez EZD / bez koszulki druk jest bez QR.
 */
final class Espis
{
    public const JRWA = ['licencja_am' => '512', 'operacja_am' => '512', 'ti_konsultacja' => '381', 'ti_kursant' => '383'];
    public const LABELS = ['licencja_am' => 'Sprawa AM — licencje i oprogramowanie', 'operacja_am' => 'Operacja AM na licencji', 'ti_konsultacja' => 'Karta konsultacji',
                           'ti_kursant' => 'Dokumenty uczestnika'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        db()->exec("CREATE TABLE IF NOT EXISTS espis_documents (
            id {$pk},
            sprawa_id INTEGER NOT NULL,
            ref_type VARCHAR(30) NOT NULL DEFAULT '',
            ref_id INTEGER NOT NULL DEFAULT 0,
            doc_key VARCHAR(60) NOT NULL DEFAULT '',
            label VARCHAR(255) NOT NULL DEFAULT '',
            doc_number VARCHAR(160) NOT NULL DEFAULT '',
            code VARCHAR(100) NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 1,
            status VARCHAR(8) NOT NULL DEFAULT 'valid',
            pismo_id INTEGER,
            zalacznik_id INTEGER,
            sha256 VARCHAR(64) NOT NULL DEFAULT '',
            created_name VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19),
            voided_at VARCHAR(19)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_espis_sprawa ON espis_documents(sprawa_id, doc_key, status)");
    }

    public static function available(): bool
    {
        return function_exists('module_enabled') ? module_enabled('ezd_enabled') : is_file(dirname(__DIR__, 3) . '/includes/ezd.php');
    }

    private static function ezd(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
    }

    // ── Token i adres eSpisu ──────────────────────────────────────────────────────

    public static function token(int $sprawaId): string
    {
        return 'E' . $sprawaId . '.' . substr(hash_hmac('sha256', 'espis:' . $sprawaId, (string)APP_KEY), 0, 20);
    }

    public static function parseToken(string $token): ?int
    {
        if (!preg_match('/^E(\d{1,9})\.([0-9a-f]{20})$/', $token, $m)) return null;
        return hash_equals(self::token((int)$m[1]), $token) ? (int)$m[1] : null;
    }

    public static function url(int $sprawaId): string
    {
        return rtrim(APP_URL, '/') . '/espis/?t=' . self::token($sprawaId);
    }

    /** Użytkownik zapisów w EZD: podany (gdy istnieje) albo pierwszy aktywny administrator — klucze obce EZD wymagają istniejącego konta (zapisy systemowe mają uid 0). */
    private static function actorId(int $uid): int
    {
        static $cache = [];
        if ($uid > 0 && !array_key_exists($uid, $cache)) $cache[$uid] = (bool)db_one("SELECT 1 FROM users WHERE id = ?", [$uid]);
        if ($uid > 0 && $cache[$uid]) return $uid;
        $a = db_one("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1") ?: db_one("SELECT id FROM users ORDER BY id LIMIT 1");
        return (int)($a['id'] ?? 0);
    }

    // ── Koszulka po odniesieniu ───────────────────────────────────────────────────

    /** Id koszulki po odniesieniu albo null (bez zakładania). */
    public static function sprawaId(string $refType, int $refId): ?int
    {
        try {
            if (!self::available()) return null;
            self::ezd();
            $rows = ezd_sprawy_by_ref($refType, $refId);
            return $rows ? (int)$rows[0]['id'] : null;
        } catch (\Throwable $e) { return null; }
    }

    private static function teczka(string $refType, int $uid): ?int
    {
        $sym = (string)(function_exists('org_setting') ? (org_setting('espis_jrwa_' . $refType) ?: '') : '') ?: (self::JRWA[$refType] ?? '');
        if ($sym === '') return null;
        $rok = (int)date('Y');
        $t = db_one("SELECT id FROM ezd_teczki WHERE symbol = ? AND rok = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$sym, $rok]);
        if ($t) return (int)$t['id'];
        $j = db_one("SELECT id, title FROM ezd_jrwa WHERE symbol = ?", [$sym]);
        if (!$j) return null;
        return (int)ezd_teczka_create(['jrwa_id' => (int)$j['id'], 'symbol' => $sym, 'title' => $j['title'] . ' ' . $rok, 'rok' => $rok, 'owner_id' => null], $uid);
    }

    /** Koszulka po odniesieniu (zakładana, gdy brak). null — EZD wyłączone / brak klasy JRWA / błąd (druki powstają bez eSpisu). */
    public static function ensureSprawa(string $refType, int $refId, string $title, string $desc, int $uid = 0): ?int
    {
        try {
            if (!self::available()) return null;
            self::ezd(); self::migrate();
            if ($sid = self::sprawaId($refType, $refId)) return $sid;
            $uid = self::actorId($uid);
            $teczka = self::teczka($refType, $uid);
            if (!$teczka) return null;
            return (int)ezd_sprawa_create(['teczka_id' => $teczka, 'title' => $title, 'description' => $desc, 'status' => 'open', 'priority' => 'normal',
                'owner_id' => $uid ?: null, 'ref_type' => $refType, 'ref_id' => $refId], $uid);
        } catch (\Throwable $e) {
            error_log('espis ensureSprawa ' . $refType . '#' . $refId . ': ' . $e->getMessage());
            return null;
        }
    }

    // ── Wpis dokumentu do koszulki ────────────────────────────────────────────────

    /**
     * Wpisuje dokument do koszulki EZD (pismo wewnętrzne + załącznik PDF) i do rejestru eSpisu; poprzednie wersje tego samego doc_key unieważnia.
     * @param array $o ref_type, ref_id, sprawa_title, sprawa_desc, doc_key, label, number, code, revision, bytes, file_name, uid, user_name
     * @return array{sprawa_id:int,znak:string,pismo_id:int}|null
     */
    public static function file(array $o): ?array
    {
        try {
            self::migrate();
            $sid = self::ensureSprawa((string)$o['ref_type'], (int)$o['ref_id'], (string)($o['sprawa_title'] ?? 'Dokumenty'), (string)($o['sprawa_desc'] ?? ''), (int)($o['uid'] ?? 0));
            if (!$sid) return null;
            $uid = self::actorId((int)($o['uid'] ?? 0));
            $pid = (int)ezd_pismo_create(['sprawa_id' => $sid, 'kierunek' => 'wewnetrzne', 'title' => (string)$o['label'] . ' — ' . (string)$o['number'],
                'tresc' => 'Dokument dołączony automatycznie (eSpis). Kod: ' . $o['code'] . ', rewizja R' . (int)$o['revision'] . '.', 'data_pisma' => date('Y-m-d'), 'status' => 'zakonczone',
                'owner_id' => $uid ?: null, 'rodzaj_medium' => 'inne'], $uid);
            $zid = 0;
            $tmp = tempnam(sys_get_temp_dir(), 'espis_');
            if ($tmp !== false) {
                file_put_contents($tmp, (string)$o['bytes']);
                try { $zid = (int)ezd_attach_path($tmp, (string)$o['file_name'], $sid, $pid, $uid); } finally { @unlink($tmp); }
            }
            $now = date('Y-m-d H:i:s');
            db()->prepare("UPDATE espis_documents SET status = 'void', voided_at = ? WHERE sprawa_id = ? AND doc_key = ? AND status = 'valid' AND revision < ?")
               ->execute([$now, $sid, (string)$o['doc_key'], (int)$o['revision']]);
            $same = db_one("SELECT id FROM espis_documents WHERE sprawa_id = ? AND doc_key = ? AND status = 'valid' AND revision = ? ORDER BY id DESC LIMIT 1", [$sid, (string)$o['doc_key'], (int)$o['revision']]);
            if ($same) {   // regeneracja tej samej rewizji: podmiana wpisu (w EZD zostaje nowe pismo z załącznikiem)
                db()->prepare("UPDATE espis_documents SET label = ?, doc_number = ?, code = ?, pismo_id = ?, zalacznik_id = ?, sha256 = ?, created_name = ?, created_at = ? WHERE id = ?")
                   ->execute([(string)$o['label'], (string)$o['number'], (string)$o['code'], $pid, $zid ?: null, hash('sha256', (string)$o['bytes']), (string)($o['user_name'] ?? ''), $now, (int)$same['id']]);
                $z = ezd_sprawa_get($sid);
                return ['sprawa_id' => $sid, 'znak' => (string)($z['znak_sprawy'] ?? ''), 'pismo_id' => $pid];
            }
            db_insert('espis_documents', ['sprawa_id' => $sid, 'ref_type' => (string)$o['ref_type'], 'ref_id' => (int)$o['ref_id'], 'doc_key' => (string)$o['doc_key'], 'label' => (string)$o['label'],
                'doc_number' => (string)$o['number'], 'code' => (string)$o['code'], 'revision' => (int)$o['revision'], 'status' => 'valid', 'pismo_id' => $pid, 'zalacznik_id' => $zid ?: null,
                'sha256' => hash('sha256', (string)$o['bytes']), 'created_name' => (string)($o['user_name'] ?? ''), 'created_at' => $now]);
            $z = ezd_sprawa_get($sid);
            return ['sprawa_id' => $sid, 'znak' => (string)($z['znak_sprawy'] ?? ''), 'pismo_id' => $pid];
        } catch (\Throwable $e) {
            error_log('espis file ' . ($o['ref_type'] ?? '') . '#' . ($o['ref_id'] ?? '') . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Dokumenty koszulki do publicznego spisu: obowiązujące najpierw, potem unieważnione. */
    public static function documents(int $sprawaId): array
    {
        self::migrate();
        return db_all("SELECT * FROM espis_documents WHERE sprawa_id = ? ORDER BY CASE status WHEN 'valid' THEN 0 ELSE 1 END, id DESC", [$sprawaId]);
    }

    // ── QR w stopce ───────────────────────────────────────────────────────────────

    private static function qr(int $sprawaId): string
    {
        return '<barcode code="' . htmlspecialchars(self::url($sprawaId), ENT_QUOTES, 'UTF-8') . '" type="QR" class="barcode" size="0.42" error="L" disableborder="1" />';
    }

    /** QR eSpisu dla kodu AM (LD…/OP…); '' gdy koszulka jeszcze nie istnieje. */
    public static function qrForAmCode(string $code): string
    {
        if (!preg_match('~^(LD|OP)(\d{1,9})~', $code, $m)) return '';
        $sid = self::sprawaId($m[1] === 'OP' ? 'operacja_am' : 'licencja_am', (int)$m[2]);
        return $sid ? self::qr($sid) : '';
    }

    /** QR eSpisu dla kodu dokumentu kursanta KD…: karta konsultacji (KONS) → jej koszulka; inne → koszulka uczestnika. */
    public static function qrForStudentCode(string $code): string
    {
        if (!preg_match('~^KD(\d{1,9})(?:/([A-Z0-9-]+))?(?:/([A-Z0-9-]+))?~', $code, $m)) return '';
        $sid = (($m[2] ?? '') === 'KONS' && ($m[3] ?? '') !== '') ? self::sprawaId('ti_konsultacja', (int)$m[3]) : self::sprawaId('ti_kursant', (int)$m[1]);
        return $sid ? self::qr($sid) : '';
    }
}
