<?php
/**
 * modules/ti_products/logic/tiProductReminders.php — przypomnienia o wygasających domenach i hostingu kursantów TI.
 *
 * Codziennie (agent CRON ti_product_reminders, 8:00; cron/ti_product_reminders.php) dla usług typu domena / hosting
 * w statusie „aktywny” lub „wygasający” z datą wygaśnięcia: gdy do końca zostało ≤ jednego z progów (ustawienie
 * ti_prodrem_days, domyślnie 30,14,7,1,0 dni), wysyła e-mail do kursanta (małoletni — także do opiekuna; reguły
 * TiLicencjeNotifier::recipients) dla NAJBLIŻSZEGO progu, którego jeszcze nie wysłano dla tej daty wygaśnięcia
 * (dziennik k30_ti_product_reminders — przedłużenie = nowa data = przypomnienia od nowa; dzień przerwy CRON-a
 * nadrabia się bez dubli). Status „aktywny” przechodzi wtedy na „wygasający”. Administrator dostaje jedno zestawienie
 * z przebiegu (TiProducts::adminEmails). Włącznik: ti_prodrem_enabled (domyślnie włączone). Szablony e-mail:
 * ti_product_expiring, ti_product_expiring_admin.
 */
require_once __DIR__ . '/tiProducts.php';

final class TiProductReminders
{
    public const TYPES = ['domain', 'hosting'];
    public const DEFAULT_DAYS = '30,14,7,1,0';

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        TiProducts::migrate();
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_product_reminders (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id  INTEGER NOT NULL REFERENCES k30_ti_client_products(id) ON DELETE CASCADE,
            expires_at  DATE    NOT NULL,
            stage       INTEGER NOT NULL,                 -- próg w dniach (30, 14, 7, 1, 0)
            days_left   INTEGER NOT NULL,
            recipients  TEXT    NOT NULL DEFAULT '',      -- zamaskowane adresy albo powód braku wysyłki
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_ti_prod_rem ON k30_ti_product_reminders(product_id, expires_at, stage)");
    }

    public static function enabled(): bool
    {
        $v = db_one("SELECT value FROM settings WHERE key_ = 'ti_prodrem_enabled'");
        return $v === null || $v === false || (string)$v['value'] !== '0';   // domyślnie włączone
    }

    /** Progi w dniach, malejąco. */
    public static function days(): array
    {
        $raw = (string)(db_one("SELECT value FROM settings WHERE key_ = 'ti_prodrem_days'")['value'] ?? self::DEFAULT_DAYS);
        $d = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw)), fn($x) => $x >= 0 && $x <= 365)));
        rsort($d);
        return $d ?: array_map('intval', explode(',', self::DEFAULT_DAYS));
    }

    /** Co dziś należy wysłać: lista [product + stage + days_left]. */
    public static function due(string $today = ''): array
    {
        self::migrate();
        $today = $today ?: date('Y-m-d');
        $days = self::days(); $max = max($days);
        $rows = db_all("SELECT p.*, c.name AS client_name FROM k30_ti_client_products p JOIN k30_clients c ON c.id = p.client_id
                         WHERE p.product_type IN ('domain','hosting') AND p.status IN ('active','expiring')
                           AND p.expires_at IS NOT NULL AND p.expires_at <> ''
                           AND DATE(p.expires_at) >= DATE(?) AND DATE(p.expires_at) <= DATE(?, ?)
                         ORDER BY p.expires_at", [$today, $today, '+' . $max . ' days']);
        $out = [];
        foreach ($rows as $p) {
            $left = (int)floor((strtotime((string)$p['expires_at']) - strtotime($today)) / 86400);
            $stages = array_filter($days, fn($t) => $left <= $t);
            if (!$stages) continue;
            $stage = min($stages);   // najbliższy (najpilniejszy) próg
            if (db_one("SELECT 1 FROM k30_ti_product_reminders WHERE product_id = ? AND expires_at = ? AND stage <= ?", [(int)$p['id'], substr((string)$p['expires_at'], 0, 10), $stage])) continue;
            $out[] = ['product' => $p, 'stage' => $stage, 'days_left' => $left];
        }
        return $out;
    }

    /** @return array{note:string, items:list<array>, sent:int, admin:int} */
    public static function run(bool $dry = false, string $today = ''): array
    {
        self::migrate();
        $res = ['note' => '', 'items' => [], 'sent' => 0, 'admin' => 0];
        if (!self::enabled() && !$dry) { $res['note'] = 'Przypomnienia o wygasających usługach są wyłączone.'; return $res; }
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
        require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiLicencjeNotify.php';
        $org = defined('ORG_NAME') ? (string)ORG_NAME : 'Dydaktyka TI';
        foreach (self::due($today) as $d) {
            $p = $d['product']; $left = $d['days_left'];
            $item = ['client' => (string)$p['client_name'], 'name' => (string)$p['name'], 'type' => TiProducts::TYPES[$p['product_type']]['label'],
                     'provider' => TiProducts::providerName($p), 'expires' => date('d.m.Y', strtotime((string)$p['expires_at'])),
                     'left' => $left, 'stage' => $d['stage'], 'to' => [], 'skip' => []];
            $vars = [
                'org' => h($org), 'student' => h($item['client']), 'type' => h($item['type']), 'name' => h($item['name']),
                'provider' => h($item['provider']), 'expires' => $item['expires'],
                'days_text' => $left === 0 ? 'dziś' : ($left === 1 ? 'jutro' : 'za ' . $left . ' dni'),
                'details_row' => $p['product_type'] === 'hosting' && $p['server_details'] !== ''
                    ? '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;width:38%"><strong>Pakiet / serwer</strong></td><td style="padding:8px 12px;border:1px solid #e2e8f0">' . h((string)$p['server_details']) . '</td></tr>' : '',
            ];
            $seen = [];
            foreach (TiLicencjeNotifier::recipients((int)$p['client_id']) as $rc) {
                if ($rc['email_skip'] !== '') { $item['skip'][] = $rc['email_skip']; continue; }
                $to = (string)$rc['email'];
                if (isset($seen[strtolower($to)])) continue;
                $seen[strtolower($to)] = true;
                $first = trim(explode(' ', trim((string)$rc['name']))[0] ?? '');
                $r = email_tpl_render('ti_product_expiring', $vars + ['greeting' => $rc['kind'] === 'opiekun' ? ($rc['name'] !== '' ? ' ' . h($rc['name']) : '') : ($first !== '' ? ' ' . h($first) : '')]);
                if (empty($r['enabled'])) { $item['skip'][] = 'szablon e-mail jest wyłączony'; break; }
                if ($dry) { $item['to'][] = TiLicencjeNotifier::maskEmail($to); continue; }
                try { if (mail_queue_add($to, (string)$rc['name'], $r['subject'], $r['html'], '', 'ti_product_reminder', (int)$p['id']) > 0) { $item['to'][] = TiLicencjeNotifier::maskEmail($to); $res['sent']++; } }
                catch (\Throwable $e) { $item['skip'][] = 'błąd kolejki: ' . $e->getMessage(); }
            }
            if (!$dry) {
                try {
                    db()->prepare("INSERT OR IGNORE INTO k30_ti_product_reminders (product_id, expires_at, stage, days_left, recipients, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
                        ->execute([(int)$p['id'], substr((string)$p['expires_at'], 0, 10), $d['stage'], $left, $item['to'] ? implode(', ', $item['to']) : (implode('; ', array_unique($item['skip'])) ?: 'brak adresu')]);
                    if ($p['status'] === 'active') db()->prepare("UPDATE k30_ti_client_products SET status = 'expiring', updated_at = datetime('now') WHERE id = ? AND status = 'active'")->execute([(int)$p['id']]);
                } catch (\Throwable $e) {}
            }
            $res['items'][] = $item;
        }
        if ($res['items'] && !$dry && ($admins = TiProducts::adminEmails())) {
            $rows = '';
            foreach ($res['items'] as $i) {
                $rows .= '<tr>' . implode('', array_map(fn($v) => '<td style="padding:6px 10px;border:1px solid #e2e8f0">' . $v . '</td>', [
                    h($i['client']), h($i['type']) . ': <strong>' . h($i['name']) . '</strong>', h($i['provider']), h($i['expires']),
                    $i['left'] === 0 ? 'dziś' : h((string)$i['left']) . ' dni', h($i['to'] ? implode(', ', $i['to']) : 'nikt — ' . (implode('; ', array_unique($i['skip'])) ?: 'brak adresu')),
                ])) . '</tr>';
            }
            $r = email_tpl_render('ti_product_expiring_admin', ['org' => h($org), 'count' => (string)count($res['items']), 'rows' => $rows, 'date' => date('d.m.Y', strtotime($today ?: date('Y-m-d')))]);
            if (!empty($r['enabled'])) foreach ($admins as $a) {
                try { if (mail_queue_add($a, '', $r['subject'], $r['html'], '', 'ti_product_reminder_admin', null) > 0) $res['admin']++; } catch (\Throwable $e) {}
            }
        }
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            if (!$dry && $res['items']) audit_log('ti.product.reminders', ['items' => count($res['items']), 'sent' => $res['sent'], 'admin' => $res['admin']], null);
        } catch (\Throwable $e) {}
        return $res;
    }
}
