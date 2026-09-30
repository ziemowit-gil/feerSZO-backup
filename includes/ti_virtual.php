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
        $rows = db_all("SELECT c.id, c.email, c.phone, a.notify_phone2 p2, a.notify_phone3 p3, a.is_virtual v
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
