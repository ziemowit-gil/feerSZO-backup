<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/LicenceRequest.php — wniosek o płatną licencję (Claude, NordVPN) z kreatora w panelu licencji (polecenie 2026-11-09).
 *
 * 1. Uczestnik w kreatorze (panel licencji, 3 kroki, potwierdzenie kodem SMS) składa wniosek: powstaje abonament w stanie „wniosek”
 *    (status requested), pierwsza opłata z PROFORMĄ z Fakturowni (zw), pozycja w portalu płatności i zgłoszenie w Helpdesku (kategoria it_licencje_am).
 *    Licencja nie musi być wcześniej przypisana. Claude bez konta @edu: w zgłoszeniu proponowany adres l…@edu do założenia.
 * 2. Helpdesk w karcie zgłoszenia: „Wpłata dotarła” (przelew), „Wystaw fakturę VAT” (zamówienie + faktura w Fakturowni, proforma opłacona),
 *    „Aktywuj dostęp” (abonament aktywny od dziś, licencja z puli dla NordVPN, cykl od dziś), „Odrzuć wniosek”.
 *    Dopóki wniosek czeka, faktura po wpłacie NIE wystawia się sama (robi to Helpdesk). Kolejne miesiące — jak dotąd (CRON, 1. dnia).
 * Konto testowe (wirtualne): dokumenty w symulacji, zgłoszenie w Helpdesku powstaje z dopiskiem [TEST] i bez e-maili.
 */
require_once __DIR__ . '/claudeBilling.php';

final class LicenceRequest
{
    /** Czy można złożyć wniosek. @return array{ok:bool,reason:string,needEdu?:bool,assignment:?array} */
    public static function eligibility(int $cid, string $product): array
    {
        ClaudeBilling::migrate();
        $P = ClaudeBilling::P($product);
        $s = ClaudeBilling::settings($product);
        $a = ClaudeBilling::assignment($cid, $product);
        $open = db_one("SELECT status FROM claude_subscriptions WHERE client_id=? AND product=? AND status IN ('requested','active','suspended') AND is_test=0", [$cid, $P['key']]);
        if ($open) return ['ok' => false, 'reason' => $open['status'] === 'requested' ? 'Wniosek o ' . $P['label'] . ' już złożony.' : 'Masz już włączony ' . $P['label'] . '.', 'assignment' => $a];
        if (!$s['enabled'] || !$s['priced']) return ['ok' => false, 'reason' => 'Wnioski o ' . $P['label'] . ' są teraz wyłączone. Napisz do biura.', 'assignment' => $a];
        if ($s['available_until'] !== '' && $s['available_until'] < date('Y-m-d')) return ['ok' => false, 'reason' => 'Wnioski o ' . $P['label'] . ' przyjmowaliśmy do ' . ClaudeBilling::dayMonth($s['available_until']) . '. Napisz do biura.', 'assignment' => $a];
        if ($s['available_from'] !== '' && $s['available_from'] > date('Y-m-d')) return ['ok' => false, 'reason' => 'Wniosek o ' . $P['label'] . ' złożysz od ' . ClaudeBilling::dayMonth($s['available_from']) . '.', 'assignment' => $a, 'from' => $s['available_from']];
        if (ClaudeBilling::isMinor($cid)) return ['ok' => false, 'reason' => 'Za osobę niepełnoletnią wniosek składa opiekun w biurze.', 'assignment' => $a];
        if ($P['uniqueEmail']) {
            $email = trim((string)(ClaudeBilling::client($cid)['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'reason' => $P['label'] . ' wymaga adresu e-mail. Uzupełnij go w „Moje konto”.', 'assignment' => $a];
            if (ClaudeBilling::emailTaken($email, $P['key'], $cid)) return ['ok' => false, 'reason' => 'Na adres ' . $email . ' jest już włączona licencja ' . $P['label'] . '. Jedna osoba może mieć jedną.', 'assignment' => $a];
        }
        $debt = db_one("SELECT COUNT(*) n FROM claude_charges WHERE client_id=? AND product=? AND status='pending' AND is_test=0 AND kind='month'", [$cid, $P['key']]);
        if ((int)($debt['n'] ?? 0) > 0) return ['ok' => false, 'reason' => 'Masz niezapłaconą fakturę za ' . $P['label'] . '. Najpierw ją zapłać.', 'assignment' => $a];
        return ['ok' => true, 'reason' => '', 'assignment' => $a, 'needEdu' => !empty($P['requiresEdu']) && !ClaudeBilling::eduAccount($cid)];
    }

    /** Złożenie wniosku: abonament „wniosek”, proforma z Fakturowni, zgłoszenie w Helpdesku. */
    public static function submit(int $cid, string $product, array $buyerIn, array $actor): array
    {
        $product = ClaudeBilling::product($product);
        $P = ClaudeBilling::P($product);
        $s = ClaudeBilling::settings($product);
        $el = self::eligibility($cid, $product);
        if (!$el['ok']) throw new DomainException($el['reason']);
        [$buyer, $err] = ClaudeBilling::buyerFromInput($buyerIn + ClaudeBilling::buyerDefaults($cid));
        if ($err) throw new InvalidArgumentException('Brak danych do faktury: ' . implode(', ', array_keys($err)) . '.');
        ClaudeBilling::quote(null, $product);   // bez kursu PKO BP wniosek nie powstaje w połowie
        $today = date('Y-m-d');
        $id = db_insert('claude_subscriptions', $buyer + [
            'product' => $product, 'client_id' => $cid, 'status' => 'requested', 'activated_on' => $today, 'cycle_start' => $today,
            'assignment_id' => $el['assignment']['id'] ?? null, 'terms_text' => ClaudeBilling::termsText($s), 'terms_accepted_at' => date('Y-m-d H:i:s'),
            'terms_ip' => (string)($actor['ip'] ?? ''), 'activated_by' => trim((string)($actor['name'] ?? '') . ' (' . ($actor['via'] ?? '') . ')'), 'is_test' => 0,
        ]);
        ClaudeBilling::event($id, null, 'requested', 'Wniosek o ' . $P['label'] . ' (kreator w panelu licencji). Warunki zaakceptowane ' . date('d.m.Y H:i') . (($actor['ip'] ?? '') !== '' ? ', IP ' . $actor['ip'] : '') . '.', (string)($actor['name'] ?? ''));
        $edu = '';
        if (!empty($el['needEdu'])) {   // konto Office (inicjał.nazwisko@edu): sprawdzamy w tenancie EDU; gdy go nie ma, SZO założy je przy aktywacji
            require_once __DIR__ . '/LicenceOffice.php';
            $o = LicenceOffice::check($cid, (string)($actor['name'] ?? ''));
            $edu = in_array($o['state'], ['exists', 'created'], true) ? '' : 'Konto Office ' . $o['upn'] . ': ' . $o['text'] . ' SZO założy je przy aktywacji dostępu.';
        }
        $sub = ClaudeBilling::subscription($id);
        $charge = ClaudeBilling::createCharge($sub, substr($today, 0, 7), $today);   // proforma (Fakturownia) + portal płatności + e-mail
        ClaudeBilling::helpdesk(ClaudeBilling::subscription($id), 'wniosek', $actor, $charge, $edu);
        require_once __DIR__ . '/LicenceCrm.php';
        LicenceCrm::addClient($cid);   // grupa CRM „TI - płatne licencje i oprogramowanie” (bez wyjątków)
        return ClaudeBilling::subscription($id) + ['charge' => $charge];
    }

    /** Wniosek przypięty do zgłoszenia Helpdesk (karta w zgłoszeniu) albo null. */
    public static function forTicket(int $ticketId): ?array
    {
        try { ClaudeBilling::migrate(); } catch (\Throwable $e) { return null; }
        $sub = db_one("SELECT id FROM claude_subscriptions WHERE helpdesk_ticket_id=? ORDER BY id DESC LIMIT 1", [$ticketId]);
        if (!$sub) return null;
        $sub = ClaudeBilling::subscription((int)$sub['id']);
        $ch = db_one("SELECT id FROM claude_charges WHERE subscription_id=? AND status<>'cancelled' AND kind='month' ORDER BY id LIMIT 1", [(int)$sub['id']]);
        require_once __DIR__ . '/ClaudeInvoice.php';
        return ['sub' => $sub, 'P' => ClaudeBilling::P(ClaudeBilling::productOf($sub)), 'charge' => $ch ? ClaudeBilling::charge((int)$ch['id']) : null,
                'edu' => ClaudeBilling::eduAccount((int)$sub['client_id']), 'eduReq' => ClaudeBilling::eduRequest((int)$sub['client_id']),
                'office' => (function () use ($sub) { require_once __DIR__ . '/LicenceOffice.php'; return LicenceOffice::summary((int)$sub['client_id']); })(),
                'assignment' => ClaudeBilling::assignment((int)$sub['client_id'], ClaudeBilling::productOf($sub)), 'virtual' => ClaudeBilling::isVirtual((int)$sub['client_id']),
                'live' => ClaudeInvoice::live(), 'pool' => ClaudeBilling::poolProduct(ClaudeBilling::productOf($sub)) ? ClaudeBilling::poolHasFree(ClaudeBilling::productOf($sub)) : null];
    }

    private static function requested(int $subId): array
    {
        $sub = ClaudeBilling::subscription($subId);
        if (!$sub) throw new DomainException('Nie ma takiego wniosku.');
        if ($sub['status'] !== 'requested') throw new DomainException('Ten wniosek jest już załatwiony (' . (ClaudeBilling::STATUS[$sub['status']] ?? $sub['status']) . ').');
        return $sub;
    }

    private static function firstCharge(int $subId): ?array
    {
        $ch = db_one("SELECT id FROM claude_charges WHERE subscription_id=? AND status<>'cancelled' AND kind='month' ORDER BY id LIMIT 1", [$subId]);
        return $ch ? ClaudeBilling::charge((int)$ch['id']) : null;
    }

    /** Notatka wewnętrzna w zgłoszeniu Helpdesk. Nigdy nie rzuca wyjątku. */
    public static function note(?int $ticketId, string $text, string $by = 'Płatne licencje'): void
    {
        if (!$ticketId) return;
        try { db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => null, 'user_name' => $by, 'body' => $text, 'is_internal' => 1]); } catch (\Throwable $e) { error_log('licencje hd note: ' . $e->getMessage()); }
    }

    /** Helpdesk: wpłata przelewem dotarła (ręczne potwierdzenie). */
    public static function markPaid(int $subId, array $actor, string $ref = ''): string
    {
        $sub = self::requested($subId);
        $ch = self::firstCharge($subId);
        if (!$ch) throw new DomainException('Wniosek nie ma opłaty do potwierdzenia.');
        if ($ch['status'] === 'paid') throw new DomainException('Wpłata jest już zaksięgowana.');
        ClaudeBilling::markPaid((int)$ch['id'], 'przelew (potwierdził Helpdesk: ' . ($actor['name'] ?? '') . ')', $ref, $actor);   // notatkę w zgłoszeniu dodaje afterPayment()
        return 'Wpłata ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' zaksięgowana. Teraz wystaw fakturę VAT.';
    }

    /** Helpdesk: faktura VAT (z zamówieniem, proforma oznaczona jako opłacona) — tylko po wpłacie. */
    public static function issueInvoice(int $subId, array $actor): string
    {
        $sub = self::requested($subId);
        $ch = self::firstCharge($subId);
        if (!$ch) throw new DomainException('Wniosek nie ma opłaty.');
        if ($ch['status'] !== 'paid') throw new DomainException('Najpierw musi dotrzeć wpłata (albo potwierdź ją przyciskiem „Wpłata dotarła”).');
        if ((string)$ch['invoice_number'] !== '' && !str_starts_with((string)$ch['invoice_number'], 'SYM-')) throw new DomainException('Faktura ' . $ch['invoice_number'] . ' jest już wystawiona.');
        require_once __DIR__ . '/ClaudeInvoice.php';
        $r = ClaudeInvoice::afterPaid((int)$ch['id'], true);
        $ch = ClaudeBilling::charge((int)$ch['id']);
        if ((string)$ch['invoice_number'] === '') throw new DomainException('Faktura nie powstała: ' . ($ch['invoice_error'] ?: ($r['error'] ?? 'nieznany błąd')));
        $sim = str_starts_with((string)$ch['invoice_number'], 'SYM-');
        ClaudeBilling::event($subId, (int)$ch['id'], 'invoice', 'Faktura VAT ' . $ch['invoice_number'] . ' wystawiona z Helpdesku (' . ($actor['name'] ?? '') . ').', (string)($actor['name'] ?? ''));
        self::note((int)$sub['helpdesk_ticket_id'], 'Faktura VAT ' . $ch['invoice_number'] . ($sim ? ' (SYMULACJA, bez Fakturowni)' : ' w Fakturowni') . ($ch['order_number'] ? ', zamówienie ' . $ch['order_number'] : '') . '. Następny krok: aktywuj dostęp.', (string)($actor['name'] ?? ''));
        return 'Faktura VAT ' . $ch['invoice_number'] . ($sim ? ' (symulacja)' : '') . ' wystawiona. Teraz aktywuj dostęp.';
    }

    /** Helpdesk: aktywacja dostępu — po wpłacie i fakturze; NordVPN z puli licencji Fundacji, Claude wymaga konta @edu. */
    public static function activate(int $subId, array $actor): string
    {
        $sub = self::requested($subId);
        $product = ClaudeBilling::productOf($sub);
        $P = ClaudeBilling::P($product);
        $cid = (int)$sub['client_id'];
        $ch = self::firstCharge($subId);
        if ($ch && $ch['status'] !== 'paid') throw new DomainException('Najpierw musi dotrzeć wpłata.');
        if ($ch && (string)$ch['invoice_number'] === '') throw new DomainException('Najpierw wystaw fakturę VAT.');
        $officeNote = '';
        if (!empty($P['requiresEdu']) && !ClaudeBilling::eduAccount($cid)) {   // konto Office: sprawdź w tenancie EDU, a gdy go nie ma, załóż
            require_once __DIR__ . '/LicenceOffice.php';
            $o = LicenceOffice::ensure($cid, $actor);
            if (empty($o['ok'])) throw new DomainException($P['label'] . ' działa tylko z kontem @' . ClaudeBilling::EDU_DOMAIN . '. ' . $o['text']);
            $officeNote = ' ' . $o['text'] . (!empty($o['password']) ? ' Hasło tymczasowe do przekazania: ' . $o['password'] . '.' : '');
        }
        if (!empty($P['office'])) {   // Office A3/A5: licencja w tenancie edukacyjnym (SKU z ustawień produktu)
            require_once __DIR__ . '/LicenceOffice.php';
            $officeNote .= ' ' . LicenceOffice::assignSku($cid, (string)ClaudeBilling::settings($product)['tenant_sku'], $P['label'], (string)($actor['name'] ?? ''))['text'];
        }
        $a = ClaudeBilling::assignment($cid, $product);
        $poolNote = '';
        if (!$a && ($pid = ClaudeBilling::poolProduct($product))) {
            require_once dirname(__DIR__, 2) . '/license_pool/lib/LpPool.php';
            $c = ClaudeBilling::client($cid) ?? [];
            $r = LpPool::assign(['holder_type' => 'client', 'holder_id' => $cid, 'product_id' => $pid, 'context' => 'Płatne licencje — ' . $P['label'],
                                 'note' => 'aktywacja z Helpdesku (wniosek ' . ($sub['helpdesk_number'] ?: '#' . $subId) . ')', 'notify' => false, 'holder_email' => (string)($c['email'] ?? ''),
                                 'billing_form' => 'FAK', 'billing_ref' => 'subskrypcja ' . $P['label'] . ' — proforma/faktura co miesiąc (Płatne licencje)'], null);
            if (empty($r['ok'])) throw new DomainException('Brak wolnej licencji ' . $P['label'] . ' w puli Fundacji: ' . implode(' ', (array)($r['errors'] ?? [])));
            $a = ClaudeBilling::assignment($cid, $product);
            $poolNote = ' Licencja z puli Fundacji (przydział #' . (int)($r['id'] ?? 0) . ').';
        }
        $today = date('Y-m-d');
        db_exec("UPDATE claude_subscriptions SET status='active', activated_on=?, cycle_start=?, assignment_id=COALESCE(?, assignment_id), updated_at=datetime('now') WHERE id=? AND status='requested'",
            [$today, $today, $a['id'] ?? null, $subId]);
        if ($ch && $ch['period'] !== substr($today, 0, 7)) db_exec("UPDATE claude_charges SET period=? WHERE id=?", [substr($today, 0, 7), (int)$ch['id']]);   // pierwsza wpłata pokrywa miesiąc aktywacji
        ClaudeBilling::event($subId, null, 'activated', 'Dostęp ' . $P['label'] . ' aktywowany z Helpdesku od ' . ClaudeBilling::date($today) . ' (' . ($actor['name'] ?? '') . ').' . $poolNote . preg_replace('/ Hasło tymczasowe do przekazania: .*$/u', '', $officeNote), (string)($actor['name'] ?? ''));
        $sub = ClaudeBilling::subscription($subId);
        self::note((int)$sub['helpdesk_ticket_id'], 'Dostęp ' . $P['label'] . ' aktywowany od ' . ClaudeBilling::date($today) . '.' . $poolNote . preg_replace('/ Hasło tymczasowe do przekazania: .*$/u', '', $officeNote) . ' Kolejne opłaty: co miesiąc, automatycznie. Zgłoszenie można zamknąć.', (string)($actor['name'] ?? ''));
        ClaudeBilling::notifyParticipant($sub, 'aktywny', $ch);
        return 'Dostęp ' . $P['label'] . ' aktywny od dziś.' . $poolNote . $officeNote . ' Uczestnik dostał e-mail.';
    }

    /** Uczestnik wycofuje wniosek (przed aktywacją, bez wpłaty). */
    public static function withdraw(int $subId, array $actor): string
    {
        $sub = self::requested($subId);
        $ch = self::firstCharge($subId);
        if ($ch && $ch['status'] === 'paid') throw new DomainException('Wpłata już dotarła, więc wniosku nie da się wycofać tutaj. Napisz do biura.');
        if ($ch && $ch['status'] === 'pending') ClaudeBilling::cancelCharge((int)$ch['id'], $actor, 'wniosek wycofany');
        db_exec("UPDATE claude_subscriptions SET status='ended', ended_on=?, ended_reason='wniosek wycofany przez uczestnika', updated_at=datetime('now') WHERE id=?", [date('Y-m-d'), $subId]);
        ClaudeBilling::event($subId, null, 'withdrawn', 'Uczestnik wycofał wniosek.', (string)($actor['name'] ?? ''));
        self::note((int)$sub['helpdesk_ticket_id'], 'Uczestnik wycofał wniosek w panelu licencji. Proforma nieaktualna. Zgłoszenie można zamknąć.', (string)($actor['name'] ?? ''));
        return 'Wniosek wycofany. Proforma jest nieaktualna, nie płać jej.';
    }

    /** Helpdesk: odrzucenie wniosku (opłata anulowana, uczestnik dostaje e-mail z powodem). */
    public static function reject(int $subId, string $reason, array $actor): string
    {
        $sub = self::requested($subId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new InvalidArgumentException('Wpisz powód odrzucenia (uczestnik go zobaczy).');
        $ch = self::firstCharge($subId);
        if ($ch && $ch['status'] === 'paid') throw new DomainException('Wpłata już dotarła. Najpierw zwróć pieniądze, potem odrzuć wniosek w panelu Płatne licencje.');
        if ($ch && $ch['status'] === 'pending') ClaudeBilling::cancelCharge((int)$ch['id'], $actor, 'wniosek odrzucony');
        db_exec("UPDATE claude_subscriptions SET status='ended', ended_on=?, ended_reason=?, updated_at=datetime('now') WHERE id=?", [date('Y-m-d'), mb_substr('wniosek odrzucony: ' . $reason, 0, 300), $subId]);
        ClaudeBilling::event($subId, null, 'rejected', 'Wniosek odrzucony: ' . $reason, (string)($actor['name'] ?? ''));
        self::note((int)$sub['helpdesk_ticket_id'], 'Wniosek odrzucony: ' . $reason, (string)($actor['name'] ?? ''));
        ClaudeBilling::notifyParticipant(ClaudeBilling::subscription($subId), 'odrzucony', null, $reason);
        return 'Wniosek odrzucony. Uczestnik dostał e-mail z powodem.';
    }
}
