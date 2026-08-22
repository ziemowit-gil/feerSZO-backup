<?php
/**
 * seed_zlecenie_usun.php — sprzątanie po seedzie umowy zlecenie.
 *
 * Usuwa umowę utworzoną przez seed_test_zlecenie.php wraz z danymi pochodnymi:
 * rachunkami (plikami i komentarzami), rozliczeniami, dziennikiem, opiekunem,
 * pismami, zadaniami, ewidencją godzin, wiadomościami itd.
 *
 * Profil zależy od środowiska (zob. seed_zlecenie_common.php) — usuwana jest
 * DOKŁADNIE ta umowa, którą tworzy seed:
 *   - testowe   → UZ/TEST/001
 *   - PRODUKCJA → UZ/DEMO/001 (konto produkcja@feer.org.pl)
 *
 * Bezpieczniki:
 *   - kasuje wyłącznie umowę o numerze z profilu seeda — nigdy żadnej innej,
 *   - odmawia, gdy do umowy podpięty jest dokument w EOD Dok. Księgowych,
 *   - domyślnie pokazuje tylko podgląd; skasuje dopiero z --potwierdz,
 *   - konto użytkownika zostaje, chyba że podasz --konto.
 *
 * Uruchom:
 *   php seed_zlecenie_usun.php                    — podgląd, co zostanie usunięte
 *   php seed_zlecenie_usun.php --potwierdz        — usuwa umowę i dane pochodne
 *   php seed_zlecenie_usun.php --potwierdz --konto — dodatkowo usuwa konto seeda
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/seed_zlecenie_common.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/zlecenie_rachunki.php';

$opts     = array_slice($argv ?? [], 1);
$confirm  = in_array('--potwierdz', $opts, true);
$del_user = in_array('--konto',     $opts, true);

$profile = seed_zl_profile();
seed_zl_banner('Usuwanie danych seeda umowy zlecenie');

$contract = db_one("SELECT * FROM umowy_zlecenie WHERE numer_umowy=?", [$profile['numer']]);
if (!$contract) {
    echo "Nie ma czego usuwać — umowa {$profile['numer']} nie istnieje.\n";
    exit(0);
}
$cid = (int)$contract['id'];
echo "Umowa: {$profile['numer']} (id={$cid}) — {$contract['imie_nazwisko']}, status: {$contract['status']}\n";

// ── Bezpiecznik: dokumenty w obiegu księgowym ────────────────────────────────
$kdok_blockers = [];
foreach (get_rachunki('zlecenie', $cid) as $r) {
    if (!empty($r['kdok_doc_id'])) {
        $kdok_blockers[] = '#' . (int)$r['id'] . ' → ' . ($r['kdok_number'] ?: '#' . (int)$r['kdok_doc_id']);
    }
}
if ($kdok_blockers) {
    fwrite(STDERR, "\nBŁĄD: do umowy podpięte są dokumenty w EOD Dok. Księgowych:\n  "
        . implode("\n  ", $kdok_blockers)
        . "\nUsuń je najpierw ręcznie w module Księgowość — ten skrypt nie rusza obiegu księgowego.\n");
    exit(1);
}

// ── Co zostanie usunięte ─────────────────────────────────────────────────────
// Tabele powiązane przez (contract_type, contract_id). kdok_documents jest
// świadomie POMINIĘTE — obieg księgowy sprząta się osobno, ręcznie.
$tables = [
    'zlecenie_rozliczenia', 'contract_audit_log', 'contract_supervisors', 'contract_access',
    'contract_amendments', 'contract_approvals', 'contract_edit_requests', 'contract_extra_docs',
    'contract_letters', 'contract_termination_requests', 'approval_requests', 'certificate_requests',
    'ezd_zaswiadczenia_wlasne', 'it_accounts', 'it_service_passwords', 'pdf_do_druku',
    'rodo_authorizations', 'shipments', 'tasks', 'timesheets', 'user_applications', 'crm_cases',
];

$counts = [];
$rachunki = get_rachunki('zlecenie', $cid);
$counts['zlecenie_rachunki'] = count($rachunki);
$counts['zlecenie_rachunek_komentarze'] = array_sum(rachunek_comments_counts(array_column($rachunki, 'id')));
$counts['pliki rachunków'] = count(array_filter($rachunki,
    fn($r) => !empty($r['plik']) || !empty($r['plik_podpisany'])));

foreach ($tables as $t) {
    try {
        $counts[$t] = (int)(db_one(
            "SELECT COUNT(*) AS c FROM {$t} WHERE contract_type=? AND contract_id=?", ['zlecenie', $cid]
        )['c'] ?? 0);
    } catch (\Throwable $e) { /* tabela nie istnieje w tej instalacji */ }
}
try {
    $counts['messages'] = (int)(db_one(
        "SELECT COUNT(*) AS c FROM messages WHERE context_type='contract' AND context_id=?", [$cid]
    )['c'] ?? 0);
} catch (\Throwable $e) {}

echo "\nDo usunięcia:\n";
foreach ($counts as $what => $n) {
    if ($n > 0) printf("  · %-32s %d\n", $what, $n);
}
if (!array_filter($counts)) echo "  (brak danych pochodnych)\n";
printf("  · %-32s %d\n", 'umowa umowy_zlecenie', 1);

$user = db_one("SELECT id, name, email FROM users WHERE email=?", [$profile['email']]);
if ($del_user && $user) {
    printf("  · %-32s %s\n", 'konto użytkownika', $profile['email'] . " (id={$user['id']})");
} elseif ($user) {
    echo "\nKonto {$profile['email']} (id={$user['id']}) ZOSTAJE — dodaj --konto, aby je też usunąć.\n";
}

// ── Podgląd bez zmian ────────────────────────────────────────────────────────
if (!$confirm) {
    echo "\nTo tylko podgląd — nic nie zostało usunięte.\n";
    echo "Aby wykonać: php seed_zlecenie_usun.php --potwierdz" . ($del_user ? ' --konto' : '') . "\n";
    exit(0);
}

// ── Usuwanie ─────────────────────────────────────────────────────────────────
echo "\n";
$removed = 0;
foreach ($rachunki as $r) {
    delete_rachunek((int)$r['id']);   // usuwa też pliki i komentarze
    $removed++;
}
if ($removed) echo "✓ Usunięto rachunki (z plikami i komentarzami): {$removed}\n";

foreach ($tables as $t) {
    if (empty($counts[$t])) continue;
    try {
        db()->prepare("DELETE FROM {$t} WHERE contract_type=? AND contract_id=?")->execute(['zlecenie', $cid]);
        echo "✓ Wyczyszczono {$t}: {$counts[$t]}\n";
    } catch (\Throwable $e) {
        echo "· Pominięto {$t}: " . $e->getMessage() . "\n";
    }
}
if (!empty($counts['messages'])) {
    try {
        db()->prepare("DELETE FROM messages WHERE context_type='contract' AND context_id=?")->execute([$cid]);
        echo "✓ Wyczyszczono messages: {$counts['messages']}\n";
    } catch (\Throwable $e) {}
}

db()->prepare("DELETE FROM umowy_zlecenie WHERE id=? AND numer_umowy=?")->execute([$cid, $profile['numer']]);
echo "✓ Usunięto umowę {$profile['numer']} (id={$cid})\n";

if ($del_user && $user) {
    db()->prepare("DELETE FROM users WHERE id=? AND email=?")->execute([(int)$user['id'], $profile['email']]);
    echo "✓ Usunięto konto {$profile['email']} (id={$user['id']})\n";
}

echo "\nGotowe. Ponowne utworzenie danych: php seed_test_zlecenie.php\n";
