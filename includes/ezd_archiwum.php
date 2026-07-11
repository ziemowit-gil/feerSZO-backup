<?php
/**
 * includes/ezd_archiwum.php — Archiwum zakładowe / składnica akt.
 *
 * Cykl życia dokumentacji po zakończeniu sprawy:
 *   segregator (teczka aktowa) zamknięty  →  przekazanie do archiwum zakładowego
 *   (spis zdawczo-odbiorczy)  →  po upływie okresu przechowywania: brakowanie
 *   (protokół brakowania) lub — dla kat. A — przekazanie do archiwum państwowego.
 *
 * Kategoria archiwalna dziedziczona jest z JRWA (ezd_jrwa.kat_arch → ezd_teczki):
 *   A       materiały archiwalne — przechowywane wieczyście, do AP (nie brakuje się)
 *   B<n>    dokumentacja niearchiwalna — przechowywana n lat, potem brakowanie
 *   Bc      dokumentacja o krótkotrwałym znaczeniu — brakowanie po wykorzystaniu
 *   BE<n>   po n latach ekspertyza archiwum państwowego (możliwa rekwalifikacja na A)
 *
 * Schemat (ezd_arch_spisy, ezd_arch_pozycje, kolumny arch_* na ezd_teczki) tworzony
 * jest w centralnej auto-migracji includes/ezd.php.
 */

// ── Stałe ──────────────────────────────────────────────────────────────────
const EZD_ARCH_SPIS_TYPY = [
    'zdawczo_odbiorczy' => ['label' => 'Spis zdawczo-odbiorczy', 'icon' => 'bi-box-arrow-in-down', 'class' => 'primary'],
    'brakowanie'        => ['label' => 'Protokół brakowania',    'icon' => 'bi-trash3',           'class' => 'danger'],
];
const EZD_ARCH_SPIS_STATUSY = [
    'projekt'      => ['label' => 'Projekt',      'class' => 'secondary'],
    'zatwierdzony' => ['label' => 'Zatwierdzony', 'class' => 'warning'],
    'zrealizowany' => ['label' => 'Zrealizowany', 'class' => 'success'],
];
const EZD_ARCH_TECZKA_STATUSY = [
    ''           => ['label' => 'W komórce',            'class' => 'light',   'icon' => 'bi-briefcase'],
    'archiwum'   => ['label' => 'W archiwum zakładowym','class' => 'info',    'icon' => 'bi-archive'],
    'ap'         => ['label' => 'Przekazana do AP',     'class' => 'primary', 'icon' => 'bi-bank'],
    'wybrakowana'=> ['label' => 'Wybrakowana',          'class' => 'dark',    'icon' => 'bi-trash3'],
];

// ── Kategoria archiwalna → retencja ──────────────────────────────────────────

/** Okres przechowywania w latach z symbolu kat. arch. (B5→5, BE10→10). null = wieczyste/nieokreślone. */
function ezd_kat_arch_retencja(?string $kat): ?int {
    $kat = strtoupper(trim((string)$kat));
    if ($kat === '' || $kat === 'A') return null;
    if ($kat === 'BC')                return 0;
    if (preg_match('/^BE?(\d+)$/', $kat, $m)) return (int)$m[1];
    return null;
}

/** Kat. A — materiały archiwalne, przechowywane wieczyście (do AP). */
function ezd_kat_arch_wieczysta(?string $kat): bool {
    return strtoupper(trim((string)$kat)) === 'A';
}

/** Kat. BE — po okresie przechowywania podlega ekspertyzie archiwum państwowego. */
function ezd_kat_arch_ekspertyza(?string $kat): bool {
    return str_starts_with(strtoupper(trim((string)$kat)), 'BE');
}

/**
 * Rok, w którym dokumentacja może zostać wybrakowana. Okres przechowywania
 * liczony od 1 stycznia roku następującego po zakończeniu sprawy, stąd +1.
 * Zwraca null dla kat. A (wieczysta) oraz gdy brak roku zamknięcia.
 */
function ezd_rok_brakowania(?string $kat, ?int $rok_zam): ?int {
    if (!$rok_zam) return null;
    if (ezd_kat_arch_wieczysta($kat)) return null;
    $ret = ezd_kat_arch_retencja($kat);
    if ($ret === null) return null;
    return $rok_zam + $ret + 1;
}

/** Rok zamknięcia segregatora (z closed_at, w razie braku — rok teczki). */
function ezd_teczka_rok_zam(array $t): ?int {
    if (!empty($t['closed_at'])) return (int)substr($t['closed_at'], 0, 4);
    return !empty($t['rok']) ? (int)$t['rok'] : null;
}

// ── Spisy (zdawczo-odbiorcze / protokoły brakowania) ──────────────────────────

function ezd_arch_next_nr(string $typ, int $rok): int {
    $n = db_one("SELECT MAX(nr) AS m FROM ezd_arch_spisy WHERE typ=? AND rok=?", [$typ, $rok]);
    return (int)($n['m'] ?? 0) + 1;
}

function ezd_arch_spis_get(int $id): ?array {
    return db_one(
        "SELECT s.*, u.name AS created_name, a.name AS approved_name,
                (SELECT COUNT(*) FROM ezd_arch_pozycje p WHERE p.spis_id=s.id) AS poz_count
         FROM ezd_arch_spisy s
         LEFT JOIN users u ON u.id=s.created_by
         LEFT JOIN users a ON a.id=s.approved_by
         WHERE s.id=?", [$id]
    );
}

function ezd_arch_spisy_all(array $f = []): array {
    $w = []; $p = [];
    if (!empty($f['typ']))    { $w[] = "s.typ=?";    $p[] = $f['typ']; }
    if (!empty($f['status'])) { $w[] = "s.status=?"; $p[] = $f['status']; }
    if (!empty($f['rok']))    { $w[] = "s.rok=?";    $p[] = (int)$f['rok']; }
    $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    return db_all(
        "SELECT s.*, u.name AS created_name,
                (SELECT COUNT(*) FROM ezd_arch_pozycje p WHERE p.spis_id=s.id) AS poz_count
         FROM ezd_arch_spisy s
         LEFT JOIN users u ON u.id=s.created_by
         $where
         ORDER BY s.rok DESC, s.typ, s.nr DESC", $p
    );
}

/** Sygnatura spisu np. „ZO 3/2026" lub „BR 1/2026". */
function ezd_arch_spis_sygnatura(array $s): string {
    $pref = $s['typ'] === 'brakowanie' ? 'BR' : 'ZO';
    return sprintf('%s %d/%d', $pref, (int)$s['nr'], (int)$s['rok']);
}

function ezd_arch_spis_create(array $d, int $user_id): int {
    $typ = in_array($d['typ'] ?? '', array_keys(EZD_ARCH_SPIS_TYPY), true) ? $d['typ'] : 'zdawczo_odbiorczy';
    $rok = (int)($d['rok'] ?? date('Y')) ?: (int)date('Y');
    db()->prepare(
        "INSERT INTO ezd_arch_spisy (typ,nr,rok,tytul,komorka,uwagi,zgoda_ap,created_by)
         VALUES (:typ,:nr,:rok,:tytul,:komorka,:uwagi,:zgoda,:by)"
    )->execute([
        ':typ'    => $typ,
        ':nr'     => ezd_arch_next_nr($typ, $rok),
        ':rok'    => $rok,
        ':tytul'  => trim($d['tytul'] ?? ''),
        ':komorka'=> trim($d['komorka'] ?? ''),
        ':uwagi'  => trim($d['uwagi'] ?? ''),
        ':zgoda'  => trim($d['zgoda_ap'] ?? ''),
        ':by'     => $user_id,
    ]);
    $id = (int)db()->lastInsertId();
    ezd_log(null, null, null, null, $user_id, 'arch_spis_create',
        EZD_ARCH_SPIS_TYPY[$typ]['label'] . ' #' . $id);
    return $id;
}

function ezd_arch_spis_update(int $id, array $d, int $user_id): void {
    $s = ezd_arch_spis_get($id);
    if (!$s) throw new \RuntimeException('Spis nie istnieje.');
    if ($s['status'] === 'zrealizowany') throw new \RuntimeException('Spis zrealizowany — edycja zablokowana.');
    db()->prepare(
        "UPDATE ezd_arch_spisy SET tytul=:tytul, komorka=:komorka, uwagi=:uwagi, zgoda_ap=:zgoda WHERE id=:id"
    )->execute([
        ':tytul'  => trim($d['tytul'] ?? ''),
        ':komorka'=> trim($d['komorka'] ?? ''),
        ':uwagi'  => trim($d['uwagi'] ?? ''),
        ':zgoda'  => trim($d['zgoda_ap'] ?? ''),
        ':id'     => $id,
    ]);
    ezd_log(null, null, null, null, $user_id, 'arch_spis_update', 'Spis #' . $id);
}

function ezd_arch_pozycje(int $spis_id): array {
    return db_all(
        "SELECT p.*, t.status AS teczka_status, t.arch_status
         FROM ezd_arch_pozycje p
         LEFT JOIN ezd_teczki t ON t.id=p.teczka_id
         WHERE p.spis_id=? ORDER BY p.lp, p.id", [$spis_id]
    );
}

/** Dodaje segregator jako pozycję spisu (snapshot metryki + wyliczony rok brakowania). */
function ezd_arch_spis_add_teczka(int $spis_id, int $teczka_id, int $user_id): void {
    $s = ezd_arch_spis_get($spis_id);
    if (!$s) throw new \RuntimeException('Spis nie istnieje.');
    if ($s['status'] === 'zrealizowany') throw new \RuntimeException('Spis zrealizowany — nie można zmieniać pozycji.');
    $t = ezd_teczka_get($teczka_id);
    if (!$t) throw new \RuntimeException('Segregator nie istnieje.');

    $dup = db_one("SELECT id FROM ezd_arch_pozycje WHERE spis_id=? AND teczka_id=?", [$spis_id, $teczka_id]);
    if ($dup) return; // już na liście — idempotentnie

    $lp  = (int)(db_one("SELECT MAX(lp) AS m FROM ezd_arch_pozycje WHERE spis_id=?", [$spis_id])['m'] ?? 0) + 1;
    $rzam = ezd_teczka_rok_zam($t);
    $rbr  = ezd_rok_brakowania($t['kat_arch'] ?? '', $rzam);

    db()->prepare(
        "INSERT INTO ezd_arch_pozycje (spis_id,teczka_id,lp,znak,tytul,rok_od,rok_do,kat_arch,liczba_teczek,rok_brakowania)
         VALUES (:spis,:tecz,:lp,:znak,:tytul,:rod,:rdo,:kat,1,:rbr)"
    )->execute([
        ':spis' => $spis_id,
        ':tecz' => $teczka_id,
        ':lp'   => $lp,
        ':znak' => $t['symbol'],
        ':tytul'=> $t['title'],
        ':rod'  => $t['rok'] ?: null,
        ':rdo'  => $rzam,
        ':kat'  => $t['kat_arch'] ?? '',
        ':rbr'  => $rbr,
    ]);
    ezd_log(null, null, null, null, $user_id, 'arch_poz_add', 'Spis #' . $spis_id . ' + ' . $t['symbol']);
}

function ezd_arch_poz_delete(int $poz_id, int $user_id): void {
    $p = db_one("SELECT * FROM ezd_arch_pozycje WHERE id=?", [$poz_id]);
    if (!$p) return;
    $s = ezd_arch_spis_get((int)$p['spis_id']);
    if ($s && $s['status'] === 'zrealizowany') throw new \RuntimeException('Spis zrealizowany — nie można zmieniać pozycji.');
    db()->prepare("DELETE FROM ezd_arch_pozycje WHERE id=?")->execute([$poz_id]);
    ezd_log(null, null, null, null, $user_id, 'arch_poz_del', 'Spis #' . $p['spis_id'] . ' − ' . $p['znak']);
}

function ezd_arch_spis_approve(int $id, int $user_id): void {
    $s = ezd_arch_spis_get($id);
    if (!$s) throw new \RuntimeException('Spis nie istnieje.');
    if ($s['status'] !== 'projekt') throw new \RuntimeException('Tylko projekt można zatwierdzić.');
    if ((int)$s['poz_count'] === 0) throw new \RuntimeException('Spis jest pusty — dodaj pozycje.');
    db()->prepare("UPDATE ezd_arch_spisy SET status='zatwierdzony', approved_by=?, approved_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$user_id, $id]);
    ezd_log(null, null, null, null, $user_id, 'arch_spis_approve', 'Spis #' . $id);
}

/**
 * Realizacja spisu — nanosi skutki na segregatory:
 *   zdawczo-odbiorczy → arch_status='archiwum' (lub 'ap' dla kat. A), zamyka teczkę,
 *                       zapisuje rok brakowania i powiązanie ze spisem.
 *   brakowanie        → arch_status='wybrakowana'.
 */
function ezd_arch_spis_realize(int $id, int $user_id): void {
    $s = ezd_arch_spis_get($id);
    if (!$s) throw new \RuntimeException('Spis nie istnieje.');
    if ($s['status'] !== 'zatwierdzony') throw new \RuntimeException('Najpierw zatwierdź spis.');
    $pdo = db();
    $poz = ezd_arch_pozycje($id);
    $pdo->beginTransaction();
    try {
        foreach ($poz as $p) {
            if (empty($p['teczka_id'])) continue;
            if ($s['typ'] === 'brakowanie') {
                $pdo->prepare("UPDATE ezd_teczki SET arch_status='wybrakowana', arch_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([(int)$p['teczka_id']]);
            } else {
                $target = ezd_kat_arch_wieczysta($p['kat_arch']) ? 'ap' : 'archiwum';
                $pdo->prepare(
                    "UPDATE ezd_teczki
                     SET arch_status=?, arch_spis_id=?, rok_brakowania=?, arch_at=CURRENT_TIMESTAMP,
                         status='closed', closed_at=COALESCE(closed_at, CURRENT_TIMESTAMP)
                     WHERE id=?"
                )->execute([$target, $id, $p['rok_brakowania'] ?: null, (int)$p['teczka_id']]);
            }
        }
        $pdo->prepare("UPDATE ezd_arch_spisy SET status='zrealizowany', realized_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    ezd_log(null, null, null, null, $user_id, 'arch_spis_realize',
        EZD_ARCH_SPIS_TYPY[$s['typ']]['label'] . ' #' . $id . ' — ' . count($poz) . ' poz.');
}

function ezd_arch_spis_delete(int $id, int $user_id): void {
    $s = ezd_arch_spis_get($id);
    if (!$s) return;
    if ($s['status'] === 'zrealizowany') throw new \RuntimeException('Spis zrealizowany — usunięcie zablokowane.');
    db()->prepare("DELETE FROM ezd_arch_spisy WHERE id=?")->execute([$id]); // pozycje kaskadą
    ezd_log(null, null, null, null, $user_id, 'arch_spis_delete', 'Spis #' . $id);
}

// ── Kandydaci ─────────────────────────────────────────────────────────────

/** Zamknięte segregatory jeszcze nieprzekazane do archiwum. */
function ezd_teczki_do_przekazania(): array {
    return db_all(
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title, j.kat_arch AS kat_arch
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE t.status='closed' AND COALESCE(t.arch_status,'')=''
         ORDER BY t.rok, t.symbol"
    );
}

/** Segregatory w archiwum, którym upłynął okres przechowywania (kat. B, bez BE/A). */
function ezd_teczki_do_brakowania(?int $rok = null): array {
    $rok = $rok ?: (int)date('Y');
    $rows = db_all(
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title, j.kat_arch AS kat_arch
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE t.arch_status='archiwum' AND t.rok_brakowania IS NOT NULL AND t.rok_brakowania<=?
         ORDER BY t.rok_brakowania, t.symbol", [$rok]
    );
    // BE wymaga ekspertyzy — odfiltruj z listy do zwykłego brakowania
    return array_values(array_filter($rows, fn($t) => !ezd_kat_arch_ekspertyza($t['kat_arch'] ?? '')));
}

/** Segregatory w archiwum z kat. BE, którym upłynął termin — do ekspertyzy AP. */
function ezd_teczki_do_ekspertyzy(?int $rok = null): array {
    $rok = $rok ?: (int)date('Y');
    return db_all(
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title, j.kat_arch AS kat_arch
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE t.arch_status='archiwum' AND UPPER(COALESCE(j.kat_arch,'')) LIKE 'BE%'
               AND t.rok_brakowania IS NOT NULL AND t.rok_brakowania<=?
         ORDER BY t.rok_brakowania, t.symbol", [$rok]
    );
}

/** Segregatory w archiwum (aktualnie przechowywane). */
function ezd_teczki_w_archiwum(): array {
    return db_all(
        "SELECT t.*, j.symbol AS jrwa_symbol, j.title AS jrwa_title, j.kat_arch AS kat_arch
         FROM ezd_teczki t
         LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id
         WHERE t.arch_status IN ('archiwum','ap')
         ORDER BY t.rok_brakowania IS NULL, t.rok_brakowania, t.symbol"
    );
}

function ezd_arch_stats(): array {
    $rok = (int)date('Y');
    return [
        'do_przekazania' => (int)(db_one("SELECT COUNT(*) c FROM ezd_teczki WHERE status='closed' AND COALESCE(arch_status,'')=''")['c'] ?? 0),
        'w_archiwum'     => (int)(db_one("SELECT COUNT(*) c FROM ezd_teczki WHERE arch_status IN ('archiwum','ap')")['c'] ?? 0),
        'do_brakowania'  => count(ezd_teczki_do_brakowania($rok)),
        'do_ekspertyzy'  => count(ezd_teczki_do_ekspertyzy($rok)),
        'wybrakowane'    => (int)(db_one("SELECT COUNT(*) c FROM ezd_teczki WHERE arch_status='wybrakowana'")['c'] ?? 0),
        'spisy'          => (int)(db_one("SELECT COUNT(*) c FROM ezd_arch_spisy")['c'] ?? 0),
    ];
}

// ── Badge / etykiety ─────────────────────────────────────────────────────────

function ezd_arch_spis_status_badge(string $st): string {
    $m = EZD_ARCH_SPIS_STATUSY[$st] ?? ['label' => $st, 'class' => 'secondary'];
    return '<span class="badge bg-' . $m['class'] . '">' . h($m['label']) . '</span>';
}

function ezd_arch_teczka_badge(?string $st): string {
    $st = (string)$st;
    $m = EZD_ARCH_TECZKA_STATUSY[$st] ?? EZD_ARCH_TECZKA_STATUSY[''];
    return '<span class="badge bg-' . $m['class'] . ($m['class'] === 'light' ? ' text-dark border' : '') . '">'
        . '<i class="bi ' . $m['icon'] . ' me-1"></i>' . h($m['label']) . '</span>';
}
