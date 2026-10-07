<?php
/**
 * modules/stopka/logic/stopka.php — generator stopek e-mail.
 *
 * Dane organizacji i treść promocyjna: stopka_settings (jeden wiersz, id=1).
 * Stanowisko/telefon pracownika: stopka_profile (users nie ma kolumny stanowiska);
 * imię, nazwisko i e-mail zawsze z users. Synchronizacja z Microsoft 365 idzie
 * tokenem aplikacji (M365Graph, client credentials) po e-mailu zalogowanego —
 * nie potrzeba osobnego tokenu użytkownika.
 */

function stopkaMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS stopka_settings (
        id         INTEGER PRIMARY KEY,
        org_name   TEXT NOT NULL DEFAULT '',
        address    TEXT NOT NULL DEFAULT '',
        nip        TEXT NOT NULL DEFAULT '',
        krs        TEXT NOT NULL DEFAULT '',
        website    TEXT NOT NULL DEFAULT '',
        promo_html TEXT NOT NULL DEFAULT ''
    )");
    if (!db_one("SELECT id FROM stopka_settings WHERE id=1")) {
        db_exec("INSERT INTO stopka_settings (id, org_name) VALUES (1, ?)", [defined('ORG_NAME') ? (string)ORG_NAME : '']);
    }
    db()->exec("CREATE TABLE IF NOT EXISTS stopka_profile (
        user_id   INTEGER PRIMARY KEY,
        job_title TEXT NOT NULL DEFAULT '',
        phone     TEXT NOT NULL DEFAULT ''
    )");
}

function stopkaSettings(): array {
    return db_one("SELECT org_name, address, nip, krs, website, promo_html FROM stopka_settings WHERE id=1") ?? [];
}

/** Dane pracownika do stopki: users + stopka_profile (telefon: profil, a w razie braku users.phone_number). */
function stopkaProfile(array $user): array {
    $p = db_one("SELECT job_title, phone FROM stopka_profile WHERE user_id=?", [(int)$user['id']]) ?? [];
    $first = trim((string)($user['first_name'] ?? ''));
    $last  = trim((string)($user['last_name'] ?? ''));
    if ($first === '' && $last === '') {
        $parts = preg_split('/\s+/', trim((string)($user['name'] ?? '')), 2);
        $first = $parts[0] ?? ''; $last = $parts[1] ?? '';
    }
    return [
        'first_name' => $first,
        'last_name'  => $last,
        'job_title'  => (string)($p['job_title'] ?? ''),
        'phone'      => (string)(($p['phone'] ?? '') !== '' ? $p['phone'] : ($user['phone_number'] ?? '')),
        'email'      => (string)($user['email'] ?? ''),
    ];
}

function stopkaSaveProfile(int $userId, string $jobTitle, string $phone): void {
    if (db_one("SELECT user_id FROM stopka_profile WHERE user_id=?", [$userId])) {
        db_exec("UPDATE stopka_profile SET job_title=?, phone=? WHERE user_id=?", [$jobTitle, $phone, $userId]);
    } else {
        db_exec("INSERT INTO stopka_profile (user_id, job_title, phone) VALUES (?,?,?)", [$userId, $jobTitle, $phone]);
    }
}

/** Synchronizacja z M365: [komunikat, czy_błąd]. Imię/nazwisko zostają w users (zarządza nimi SZO). */
function stopkaSyncFromGraph(array $user): array {
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') return ['Konto nie ma adresu e-mail.', true];
    $g = new M365Graph();
    if (!$g->is_configured()) return ['Microsoft 365 nie jest skonfigurowany (Graph).', true];
    $prof = $g->get_user_signature_profile($email);
    if (!$prof) {
        $found = $g->find_by_email_or_upn($email);
        if (!empty($found['id'])) $prof = $g->get_user_signature_profile($found['id']);
    }
    if (!$prof) return ['Nie znaleziono konta Microsoft 365 dla ' . $email . '.', true];
    $cur   = stopkaProfile($user);
    $title = trim((string)($prof['jobTitle'] ?? '')) ?: $cur['job_title'];
    $phone = trim((string)($prof['mobilePhone'] ?? ($prof['businessPhones'][0] ?? ''))) ?: $cur['phone'];
    stopkaSaveProfile((int)$user['id'], $title, $phone);
    return ['Zsynchronizowano z Microsoft 365 (' . ($prof['displayName'] ?? $email) . ').', false];
}

/** Allowlista znaczników treści promocyjnej; usuwa on*, javascript: i wszystko spoza listy. */
function stopkaSanitizePromo(string $html): string {
    if (trim($html) === '') return '';
    $allowed = ['a' => ['href'], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'br' => [],
                'p' => [], 'span' => [], 'img' => ['src', 'alt', 'width', 'height']];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $walk = function (DOMNode $n) use (&$walk, $allowed): void {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->tagName);
            if (!isset($allowed[$tag])) { $n->removeChild($c); continue; }
            foreach (iterator_to_array($c->attributes) as $a) {
                $ok = in_array($a->name, $allowed[$tag], true);
                if ($ok && in_array($a->name, ['href', 'src'], true) && !preg_match('~^(https?:|mailto:)~i', trim($a->value))) $ok = false;
                if (!$ok) $c->removeAttribute($a->name);
            }
            if ($tag === 'a') { $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener'); }
            $walk($c);
        }
    };
    $root = $doc->getElementById('r');
    if (!$root) return '';
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}
