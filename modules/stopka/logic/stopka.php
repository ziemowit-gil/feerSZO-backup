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
    try { db()->exec("ALTER TABLE stopka_settings ADD COLUMN design_json TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) { /* jest */ }
    db()->exec("CREATE TABLE IF NOT EXISTS stopka_profile (
        user_id   INTEGER PRIMARY KEY,
        job_title TEXT NOT NULL DEFAULT '',
        phone     TEXT NOT NULL DEFAULT ''
    )");
}

function stopkaSettings(): array {
    $s = db_one("SELECT org_name, address, nip, krs, website, promo_html, design_json FROM stopka_settings WHERE id=1") ?? [];
    $s['design'] = stopkaDesign($s);
    unset($s['design_json']);
    return $s;
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


// ── Wygląd stopki (konfigurator graficzny) ──────────────────────────────────

const STOPKA_FONTS = ['Arial', 'Calibri', 'Verdana', 'Tahoma', 'Georgia', 'Trebuchet MS', 'Segoe UI'];

function stopkaDesignDefaults(): array {
    return [
        'font' => 'Arial', 'size' => 13, 'nameSize' => 16,
        'color' => '#222222', 'accent' => '#1d4ed8', 'nameAccent' => false,
        'sepStyle' => 'solid', 'sepColor' => '#999999', 'gap' => 8,
        'logoUrl' => '', 'logoWidth' => 120,
        'show' => ['title' => true, 'phone' => true, 'email' => true, 'org' => true, 'address' => true,
                   'nip' => true, 'krs' => true, 'website' => true, 'promo' => true],
    ];
}

/** Whitelist + przycięcie wartości: z bazy lub z formularza trafia tu dowolny JSON. */
function stopkaDesignSanitize($in): array {
    $d = stopkaDesignDefaults();
    if (!is_array($in)) return $d;
    $clamp = fn($v, int $lo, int $hi, int $def) => is_numeric($v) ? max($lo, min($hi, (int)$v)) : $def;
    $hex   = fn($v, string $def) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $def;
    if (isset($in['font']) && in_array($in['font'], STOPKA_FONTS, true)) $d['font'] = $in['font'];
    $d['size']     = $clamp($in['size'] ?? null, 11, 16, $d['size']);
    $d['nameSize'] = $clamp($in['nameSize'] ?? null, 13, 24, $d['nameSize']);
    $d['color']    = $hex($in['color'] ?? null, $d['color']);
    $d['accent']   = $hex($in['accent'] ?? null, $d['accent']);
    $d['sepColor'] = $hex($in['sepColor'] ?? null, $d['sepColor']);
    $d['nameAccent'] = !empty($in['nameAccent']);
    if (isset($in['sepStyle']) && in_array($in['sepStyle'], ['solid', 'dashed', 'dotted', 'none'], true)) $d['sepStyle'] = $in['sepStyle'];
    $d['gap']       = $clamp($in['gap'] ?? null, 0, 24, $d['gap']);
    $d['logoWidth'] = $clamp($in['logoWidth'] ?? null, 40, 300, $d['logoWidth']);
    $logo = is_string($in['logoUrl'] ?? null) ? trim($in['logoUrl']) : '';
    $d['logoUrl'] = (preg_match('~^https://[^\s"\'<>]+$~i', $logo)) ? $logo : '';
    foreach (array_keys($d['show']) as $k) {
        $d['show'][$k] = isset($in['show']) && is_array($in['show']) && array_key_exists($k, $in['show']) ? !empty($in['show'][$k]) : $d['show'][$k];
    }
    return $d;
}

function stopkaDesign(array $s): array {
    $raw = json_decode((string)($s['design_json'] ?? ''), true);
    return stopkaDesignSanitize($raw);
}

/**
 * Jedyny generator HTML stopki po stronie PHP (podgląd serwerowy + szablon Exchange).
 * $person: już ZESCAPOWANE (lub placeholdery %%…%%) pola name/title/phone/email.
 * UWAGA: odpowiednik w JS to stopkaGen().html() w index.php — zmieniając jedno, zmień drugie
 * (test zgodności: cli/stopkaParity.php).
 */
function stopkaRenderHtml(array $s, array $person): string {
    $d  = stopkaDesignSanitize($s['design'] ?? json_decode((string)($s['design_json'] ?? ''), true));
    $e  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sh = $d['show'];
    $sm = $d['size'] - 1;
    $row = fn(string $t, string $st) => $t === '' ? '' : '<div style="' . $st . '">' . $t . '</div>';
    $link = fn(string $href, string $label) => '<a href="' . $href . '" style="color:' . $d['accent'] . ';text-decoration:none;">' . $label . '</a>';

    $h = '<div style="font-family:' . $d['font'] . ',Arial,Helvetica,sans-serif;color:' . $d['color'] . ';font-size:' . $d['size'] . 'px;line-height:1.5;">';
    if ($d['logoUrl'] !== '') {
        $h .= '<div style="margin-bottom:' . $d['gap'] . 'px;"><img src="' . $e($d['logoUrl']) . '" width="' . $d['logoWidth'] . '" alt="' . $e($s['org_name'] ?? '') . '" style="display:block;border:0;"></div>';
    }
    $h .= $row($person['name'], 'font-size:' . $d['nameSize'] . 'px;font-weight:bold;line-height:1.4;color:' . ($d['nameAccent'] ? $d['accent'] : $d['color']) . ';');
    if ($sh['title']) $h .= $row($person['title'], 'font-size:' . $d['size'] . 'px;');
    $parts = [];
    if ($sh['phone'] && $person['phone'] !== '') $parts[] = 'tel. ' . $person['phone'];
    if ($sh['email'] && $person['email'] !== '') $parts[] = $link('mailto:' . $person['email'], $person['email']);
    $h .= $row(implode(' &nbsp;|&nbsp; ', $parts), 'font-size:' . $d['size'] . 'px;');
    if ($sh['org']) $h .= $row($e($s['org_name'] ?? ''), 'margin-top:' . $d['gap'] . 'px;font-size:' . $d['size'] . 'px;font-weight:bold;');
    if ($sh['address']) $h .= $row($e($s['address'] ?? ''), 'font-size:' . $sm . 'px;');
    if ($sh['nip'] && trim((string)($s['nip'] ?? '')) !== '') $h .= $row('NIP: ' . $e($s['nip']), 'font-size:' . $sm . 'px;');
    if ($sh['krs'] && trim((string)($s['krs'] ?? '')) !== '') $h .= $row('KRS: ' . $e($s['krs']), 'font-size:' . $sm . 'px;');
    $url = trim((string)($s['website'] ?? ''));
    if ($sh['website'] && $url !== '') {
        $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
        $h .= $row($link($e($href), $e($url)), 'font-size:' . $sm . 'px;');
    }
    $promo = trim((string)($s['promo_html'] ?? ''));
    if ($sh['promo'] && $promo !== '') {
        $bd = $d['sepStyle'] === 'none' ? '' : 'border-top:1px ' . $d['sepStyle'] . ' ' . $d['sepColor'] . ';padding-top:8px;';
        $h .= '<div style="margin-top:' . ($d['gap'] + 2) . 'px;' . $bd . 'font-size:' . $sm . 'px;line-height:1.5;">' . $promo . '</div>';
    }
    return $h . '</div>';
}
