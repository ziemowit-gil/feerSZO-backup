<?php
/**
 * includes/ti_virtual.php — „Kursant wirtualny”: konta TI oznaczone k30_ti_student_accounts.is_virtual.
 * Wirtualny kursant nie dostaje żadnych SMS-ów ani e-maili. Blokada działa centralnie (sms_send,
 * mail_queue_add): adres/numer jest blokowany tylko wtedy, gdy należy WYŁĄCZNIE do kursantów
 * wirtualnych — jeśli dzieli go osoba rzeczywista, wiadomość idzie normalnie.
 */

/** @return array{emails:array<string,true>, phones:array<string,true>} kontakty wyłącznie wirtualnych kursantów */
function ti_virtual_contacts(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    $cache = ['emails' => [], 'phones' => []];
    try {
        $rows = db_all("SELECT c.id, c.email, c.phone, a.notify_phone2 p2, a.notify_phone3 p3, (COALESCE(a.is_virtual,0) OR COALESCE(a.no_billing,0)) v
                          FROM k30_clients c JOIN k30_ti_student_accounts a ON a.client_id=c.id");
    } catch (\Throwable $e) { return $cache; }   // brak kolumny / tabeli — nic nie blokujemy
    $virt = []; $real = [];
    foreach ($rows as $r) {
        $set = empty($r['v']) ? 'real' : 'virt';
        foreach ([$r['email']] as $e) { $e = strtolower(trim((string)$e)); if ($e !== '') ${$set}['e:' . $e] = true; }
        foreach ([$r['phone'], $r['p2'], $r['p3']] as $p) { $p = substr(preg_replace('/\D/', '', (string)$p), -9); if (strlen($p) === 9) ${$set}['p:' . $p] = true; }
    }
    foreach (array_diff_key($virt, $real) as $k => $_) {
        if ($k[0] === 'e') $cache['emails'][substr($k, 2)] = true; else $cache['phones'][substr($k, 2)] = true;
    }
    // Konto rzeczywiste tej samej osoby (client_id) z innym kontem wirtualnym: kontakt wspólny = rzeczywisty (array_diff_key powyżej)
    return $cache;
}

/** Czy wysyłka na ten e-mail / numer ma być pominięta (kontakt wyłącznie wirtualnego kursanta). */
function ti_virtual_blocked(string $email = '', string $phone = ''): bool {
    $c = ti_virtual_contacts();
    $e = strtolower(trim($email));
    if ($e !== '' && isset($c['emails'][$e])) return true;
    $p = substr(preg_replace('/\D/', '', $phone), -9);
    return strlen($p) === 9 && isset($c['phones'][$p]);
}

/** Czy wszystkie konta TI tej osoby są oznaczone „bez rozliczeń” (nie naliczamy jej należności). */
function ti_client_no_billing(int $client_id): bool {
    try {
        $r = db_one("SELECT COUNT(*) n, SUM(COALESCE(no_billing,0)) v FROM k30_ti_student_accounts WHERE client_id=?", [$client_id]);
    } catch (\Throwable $e) { return false; }
    return (int)($r['n'] ?? 0) > 0 && (int)$r['n'] === (int)($r['v'] ?? 0);
}
