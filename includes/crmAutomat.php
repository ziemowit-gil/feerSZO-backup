<?php
/**
 * includes/crmAutomat.php — rozpoznawanie AUTOMATÓW: nadawców i kontaktów, które nie są ludźmi
 * (no-reply, powiadomienia systemowe, bramki płatności, serwisy, mailer-daemon).
 *
 * Rozpoznanie po samym adresie i nazwie wyświetlanej (nigdy po treści). Wynik używany do:
 *  • kategorii kontaktu „Automat” (crmCategories — przeliczana razem z pozostałymi),
 *  • wykluczenia z wysyłek masowych (do no-reply nikt nie odpisze i nie jest adresatem komunikacji),
 *  • znaczka w Skrzynce CRM i warunku reguł skrzynki („nadawca to automat”).
 * Wzorce celowo wąskie — skrzynki typu info@, biuro@ to osoby/zespoły, nie automaty.
 */
const CRM_AUTOMAT_LOCAL = [
    'noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply', 'do_not_reply', 'dontreply', 'nie-odpowiadaj', 'nieodpowiadaj',
    'mailer-daemon', 'mailerdaemon', 'postmaster', 'bounce', 'bounces', 'notification', 'notifications', 'notify', 'notifier',
    'powiadomienie', 'powiadomienia', 'alert', 'alerts', 'automat', 'automatic', 'auto', 'autoresponder', 'robot', 'bot', 'system',
    'daemon', 'mailer', 'newsletter', 'digest', 'reply',
];
const CRM_AUTOMAT_NAME = [
    'automatyczne powiadomienie', 'nie odpowiadaj', 'do not reply', 'no-reply', 'noreply', 'mailer-daemon', 'mail delivery',
    'automatyczna odpowied', 'auto-reply', 'autoreply', 'notification', 'powiadomienia systemowe', 'system administrator',
];
const CRM_AUTOMAT_DOMAIN_PREFIX = ['noreply.', 'no-reply.', 'notifications.', 'notify.', 'mailer.', 'bounce.', 'bounces.', 'mg.', 'em.', 'e.'];

function crm_is_automat(?string $email, ?string $name = null): bool {
    $email = mb_strtolower(trim((string)$email));
    $name  = mb_strtolower(trim((string)$name));
    if ($email !== '' && str_contains($email, '@')) {
        [$local, $domain] = explode('@', $email, 2);
        // lokalna część: dokładnie wzorzec albo wzorzec z liczbą/separatorem (noreply+abc, no-reply-123, notifications2)
        $base = preg_replace('/[+._\-]?\d+$|\+.*$/', '', $local) ?? $local;
        if (in_array($local, CRM_AUTOMAT_LOCAL, true) || in_array($base, CRM_AUTOMAT_LOCAL, true)) return true;
        if (preg_match('/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon)/', $local)) return true;
        foreach (CRM_AUTOMAT_DOMAIN_PREFIX as $p) {
            // prefiksy transakcyjne (mg., em.) liczą się tylko razem z lokalną częścią „nie-ludzką” — same w sobie za szerokie
            if (str_starts_with($domain, $p) && in_array($p, ['noreply.', 'no-reply.', 'notifications.', 'notify.', 'mailer.', 'bounce.', 'bounces.'], true)) return true;
        }
    }
    foreach (CRM_AUTOMAT_NAME as $n) if ($name !== '' && str_contains($name, $n)) return true;
    return false;
}

/** @return list<int> aktywne kontakty rozpoznane jako automaty (po e-mailu lub nazwie). */
function crm_automat_contact_ids(): array {
    $ids = [];
    foreach (db_all("SELECT id, email, imie_nazwisko FROM crm_contacts WHERE crm_active=1 AND (COALESCE(email,'')<>'' OR COALESCE(imie_nazwisko,'')<>'')") as $r) {
        if (crm_is_automat($r['email'], $r['imie_nazwisko'])) $ids[] = (int)$r['id'];
    }
    return $ids;
}

/** Zostawia w liście tylko kontakty, które NIE są automatami (i NIE mają kategorii „automat”). */
function crm_drop_automats(array $ids): array {
    if (!$ids) return $ids;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $keep = [];
    foreach (db_all("SELECT id, email, imie_nazwisko FROM crm_contacts WHERE id IN ($ph)", array_map('intval', $ids)) as $r) {
        if (!crm_is_automat($r['email'], $r['imie_nazwisko'])) $keep[] = (int)$r['id'];
    }
    return $keep;
}
