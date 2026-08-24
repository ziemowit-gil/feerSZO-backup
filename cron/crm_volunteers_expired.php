<?php
/**
 * cron/crm_volunteers_expired.php — porządkuje grupę CRM „Wolontariusze".
 *
 * Istniejąca synchronizacja (SyncService::syncVolunteerGroup) patrzy na STATUS
 * porozumienia. W praktyce status zostaje „w realizacji" długo po tym, jak projekt
 * się skończył — nikt nie wraca do kartoteki, żeby go przestawić. Efekt: w grupie
 * wolontariuszy siedzą ludzie po projekcie, bez obowiązującej umowy, i dostają
 * wysyłki jako wolontariusze.
 *
 * Ten skrypt patrzy na DATY, nie na deklarowany status:
 *   • porozumienie z datą zakończenia w przeszłości = wygasłe,
 *   • brak jakiegokolwiek porozumienia = brak podstawy,
 * i takie osoby przenosi z „Wolontariusze" do „Byli wolontariusze".
 *
 * Bezpieczniki:
 *   • KARENCJA (domyślnie 30 dni) — świeżo zakończone porozumienia zostają,
 *     bo aneks albo nowa umowa często wchodzą z opóźnieniem,
 *   • umowa bezterminowa (pusta data zakończenia) w aktywnym statusie = zostaje,
 *   • dopasowanie po e-mailu ORAZ po PESEL-u — sam e-mail gubi osoby, które
 *     zmieniły adres,
 *   • bez --apply skrypt tylko pokazuje, kogo by usunął.
 *
 * Użycie:
 *   php cron/crm_volunteers_expired.php              # podgląd
 *   php cron/crm_volunteers_expired.php --apply      # wykonanie
 *   php cron/crm_volunteers_expired.php --apply --grace=60
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

define('APP_CLI', true);
$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/crm.php';

$apply = in_array('--apply', $argv, true);
$grace = 30;
foreach ($argv as $a) {
    if (preg_match('/^--grace=(\d+)$/', $a, $m)) $grace = max(0, (int)$m[1]);
}

echo '[' . date('Y-m-d H:i:s') . '] Start: crm_volunteers_expired'
   . ($apply ? ' (ZAPIS)' : ' (podgląd)') . ", karencja {$grace} dni\n";

if (!module_enabled('crm_enabled')) { echo "[SKIP] Moduł CRM wyłączony.\n"; exit(0); }
crm_migrate();

$vol = db_one("SELECT id, name FROM crm_groups WHERE auto_source='wolontariusze'");
if (!$vol) { echo "[SKIP] Brak grupy Wolontariusze (auto_source='wolontariusze').\n"; exit(0); }
$gid = (int)$vol['id'];

$former = db_one("SELECT id FROM crm_groups WHERE auto_source='byli_wolontariusze'");
$fgid   = $former ? (int)$former['id'] : 0;

$members = db_all(
    "SELECT c.id, c.imie_nazwisko, c.email, c.pesel
       FROM crm_group_members gm
       JOIN crm_contacts c ON c.id = gm.contact_id AND c.crm_active = 1
      WHERE gm.group_id = ?
   ORDER BY c.imie_nazwisko", [$gid]
);
echo '  Członków grupy „' . $vol['name'] . "\": " . count($members) . "\n";

$ACTIVE_STATUSES = ['projekt', 'podpisana', 'w realizacji', 'obowiązująca'];
$cutoff = date('Y-m-d', strtotime("-{$grace} days"));

/**
 * Czy kontakt ma podstawę, żeby zostać w grupie?
 * @return array{keep:bool, why:string}
 */
$check = static function (array $c) use ($ACTIVE_STATUSES, $cutoff): array {
    $where  = [];
    $params = [];
    if (trim((string)$c['email']) !== '') { $where[] = 'LOWER(email)=LOWER(?)'; $params[] = trim((string)$c['email']); }
    $pesel = preg_replace('/\D/', '', (string)($c['pesel'] ?? ''));
    if (strlen($pesel) === 11) { $where[] = "REPLACE(COALESCE(pesel,''),' ','')=?"; $params[] = $pesel; }
    if (!$where) return ['keep' => true, 'why' => 'brak e-maila i PESEL-u — nie da się dopasować umowy'];

    try {
        $rows = db_all(
            "SELECT numer_umowy, status, data_zakonczenia
               FROM umowy_wolontariat WHERE " . implode(' OR ', $where), $params
        );
    } catch (\Throwable $e) {
        return ['keep' => true, 'why' => 'błąd odczytu umów: ' . $e->getMessage()];
    }

    if (!$rows) return ['keep' => false, 'why' => 'brak jakiegokolwiek porozumienia wolontariackiego'];

    foreach ($rows as $r) {
        $status_ok = in_array((string)$r['status'], $ACTIVE_STATUSES, true);
        $end       = trim((string)($r['data_zakonczenia'] ?? ''));

        // Bezterminowa w aktywnym statusie — zostaje
        if ($status_ok && $end === '') {
            return ['keep' => true, 'why' => 'porozumienie ' . ($r['numer_umowy'] ?: '—') . ' bez daty końca'];
        }
        // Data końca jeszcze nie minęła (z karencją) — zostaje, nawet przy dziwnym statusie
        if ($end !== '' && $end >= $cutoff) {
            return ['keep' => true, 'why' => 'porozumienie ' . ($r['numer_umowy'] ?: '—') . ' do ' . $end];
        }
    }

    $last = '';
    foreach ($rows as $r) {
        $e = trim((string)($r['data_zakonczenia'] ?? ''));
        if ($e !== '' && $e > $last) $last = $e;
    }
    return ['keep' => false, 'why' => $last !== ''
        ? 'ostatnie porozumienie zakończone ' . $last . ' (po karencji)'
        : 'żadne porozumienie nie obowiązuje'];
};

$now = date('Y-m-d H:i:s');
$out = ['removed' => 0, 'kept' => 0, 'moved' => 0];

foreach ($members as $c) {
    $r = $check($c);
    if ($r['keep']) { $out['kept']++; continue; }

    echo '  ' . ($apply ? '✓' : '·') . ' ' . $c['imie_nazwisko']
       . ' <' . ($c['email'] ?: 'brak e-maila') . '> — ' . $r['why'] . "\n";
    $out['removed']++;

    if (!$apply) continue;

    try {
        db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
            ->execute([$gid, (int)$c['id']]);

        if ($fgid) {
            db()->prepare("INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at)
                           VALUES (?,?,0,?)")->execute([$fgid, (int)$c['id'], $now]);
            $out['moved']++;
        }

        // Ślad w kartotece — inaczej za pół roku nikt nie odtworzy, czemu ktoś wypadł z grupy
        db_insert('crm_notes', [
            'contact_id' => (int)$c['id'],
            'body'       => 'Automatycznie usunięto z grupy „Wolontariusze": ' . $r['why'] . '.',
            'created_by' => null,
            'created_at' => $now,
        ]);
    } catch (\Throwable $e) {
        echo '    ✗ ' . $e->getMessage() . "\n";
    }
}

echo '  Podsumowanie: ' . ($apply ? 'usunięto' : 'do usunięcia') . " {$out['removed']}, "
   . "zostaje {$out['kept']}, przeniesiono do Byli wolontariusze: {$out['moved']}\n";
if (!$apply && $out['removed']) echo "  Uruchom ponownie z --apply, żeby wykonać.\n";
echo '[' . date('Y-m-d H:i:s') . "] Koniec: crm_volunteers_expired\n";
