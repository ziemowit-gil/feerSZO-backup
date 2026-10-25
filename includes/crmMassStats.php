<?php
/**
 * includes/crmMassStats.php — śledzenie i statystyki wysyłek masowych CRM (e-mail): otwarcia i kliknięcia.
 * Opt-in per wysyłka (pole „Śledź otwarcia i kliknięcia” w kroku Wyślij). Piksel i przepisane linki idą przez
 * crm/track/mopen.php i crm/track/mclick.php; kliknięcie liczone po ID linku z tabeli (bez otwartego przekierowania).
 */
function crm_mass_stats_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_mass_track (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        send_id INTEGER NOT NULL,
        contact_id INTEGER NOT NULL,
        token TEXT NOT NULL UNIQUE,
        opened_at TEXT, open_count INTEGER NOT NULL DEFAULT 0,
        clicked_at TEXT, click_count INTEGER NOT NULL DEFAULT 0,
        created_at TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_mass_track_send ON crm_mass_track(send_id)");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_mass_track_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        send_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        clicks INTEGER NOT NULL DEFAULT 0,
        UNIQUE(send_id, url)
    )");
}

/** Token śledzenia odbiorcy wysyłki (jeden na kontakt i wysyłkę). */
function crm_mass_track_token(int $send_id, int $contact_id): string {
    crm_mass_stats_schema();
    $r = db_one("SELECT token FROM crm_mass_track WHERE send_id=? AND contact_id=?", [$send_id, $contact_id]);
    if ($r) return (string)$r['token'];
    $t = bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO crm_mass_track (send_id, contact_id, token, created_at) VALUES (?,?,?,?)")
        ->execute([$send_id, $contact_id, $t, date('Y-m-d H:i:s')]);
    return $t;
}

/** Dokleja piksel otwarcia i podmienia linki http(s) na przekierowania z licznikiem. */
function crm_mass_inject_tracking(string $html, int $send_id, string $token): string {
    crm_mass_stats_schema();
    $base = rtrim(APP_URL, '/');
    $html = preg_replace_callback('~href\s*=\s*(["\'])(https?://[^"\']+)\1~i', function (array $m) use ($send_id, $base, $token): string {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        // własne adresy śledzenia i wypisania zostają bez zmian
        if (str_contains($url, '/crm/track/')) return $m[0];
        db()->prepare("INSERT OR IGNORE INTO crm_mass_track_links (send_id, url) VALUES (?,?)")->execute([$send_id, $url]);
        $lid = (int)(db_one("SELECT id FROM crm_mass_track_links WHERE send_id=? AND url=?", [$send_id, $url])['id'] ?? 0);
        if (!$lid) return $m[0];
        return 'href="' . htmlspecialchars($base . '/crm/track/mclick.php?t=' . urlencode($token) . '&l=' . $lid, ENT_QUOTES) . '"';
    }, $html) ?? $html;
    $pixel = '<img src="' . htmlspecialchars($base . '/crm/track/mopen.php?t=' . urlencode($token), ENT_QUOTES) . '" width="1" height="1" style="display:none" alt="">';
    if (stripos($html, '</body>') !== false) return preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    return $html . $pixel;
}

/** Podsumowanie wysyłek do tabeli statystyk. */
function crm_mass_stats_rows(int $limit = 100): array {
    crm_mass_stats_schema();
    return db_all("SELECT ms.id, ms.created_at, ms.channel, ms.subject, ms.recipients, ms.sent_ok, ms.sent_fail, ms.status, ms.tag_filter, u.name AS user_name,
            g.name AS group_name,
            (SELECT COUNT(*) FROM crm_mass_track t WHERE t.send_id=ms.id) AS tracked,
            (SELECT COUNT(*) FROM crm_mass_track t WHERE t.send_id=ms.id AND t.opened_at IS NOT NULL) AS opened,
            (SELECT COUNT(*) FROM crm_mass_track t WHERE t.send_id=ms.id AND t.clicked_at IS NOT NULL) AS clicked,
            (SELECT COALESCE(SUM(t.click_count),0) FROM crm_mass_track t WHERE t.send_id=ms.id) AS clicks_total
        FROM crm_mass_sends ms LEFT JOIN users u ON u.id=ms.created_by LEFT JOIN crm_groups g ON g.id=ms.group_id
        ORDER BY ms.created_at DESC LIMIT " . (int)$limit);
}

/** Wysyłki wg miesięcy: [YYYY-MM => [sends, recipients, ok, fail, email, sms]]. */
function crm_mass_stats_monthly(int $months = 12): array {
    $out = [];
    foreach (db_all("SELECT substr(created_at,1,7) AS ym, COUNT(*) AS n, COALESCE(SUM(recipients),0) AS rec, COALESCE(SUM(sent_ok),0) AS ok,
                            COALESCE(SUM(sent_fail),0) AS fail,
                            COALESCE(SUM(CASE WHEN channel='email' THEN sent_ok ELSE 0 END),0) AS ok_email,
                            COALESCE(SUM(CASE WHEN channel='sms' THEN sent_ok ELSE 0 END),0) AS ok_sms
                     FROM crm_mass_sends GROUP BY ym ORDER BY ym DESC LIMIT " . (int)$months) as $r) {
        $out[$r['ym']] = $r;
    }
    return array_reverse($out, true);
}

function crm_mass_stats_links(int $send_id): array {
    crm_mass_stats_schema();
    return db_all("SELECT url, clicks FROM crm_mass_track_links WHERE send_id=? ORDER BY clicks DESC, id", [$send_id]);
}

function crm_mass_stats_openers(int $send_id, int $limit = 200): array {
    crm_mass_stats_schema();
    return db_all("SELECT t.opened_at, t.open_count, t.clicked_at, t.click_count, c.id AS contact_id, c.imie_nazwisko, c.email
                   FROM crm_mass_track t JOIN crm_contacts c ON c.id=t.contact_id
                   WHERE t.send_id=? AND (t.opened_at IS NOT NULL OR t.clicked_at IS NOT NULL)
                   ORDER BY COALESCE(t.clicked_at, t.opened_at) DESC LIMIT " . (int)$limit, [$send_id]);
}
