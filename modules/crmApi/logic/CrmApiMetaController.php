<?php
/**
 * modules/crmApi/logic/CrmApiMetaController.php — dane wspólne aplikacji CRM:
 *   GET /me            — kto jestem, uprawnienia obszarów i pól, liczniki do menu
 *   GET /dictionaries  — słowniki do filtrów i formularzy (statusy, typy, tagi, grupy, opiekunowie, sprawy…)
 *   GET /dashboard     — pulpit (to samo co crm/dashboard.php, z ograniczeniem do dostępnych grup)
 */
final class CrmApiMetaController
{
    public function me(): array
    {
        $u = current_user();
        $perms = [];
        foreach (array_keys(crm_perm_areas()) as $area) {
            $perms[$area] = ['read' => crm_can($area, 'read'), 'write' => crm_can($area, 'write'), 'delete' => crm_can($area, 'delete')];
        }
        $fields = [];
        foreach (array_keys(crm_perm_fields()) as $f) {
            $fields[$f] = ['view' => crm_field_can_view($f), 'edit' => crm_field_can_edit($f)];
        }
        $uid = (int)$u['id'];
        $badge = static function (string $sql, array $p = []): int {
            try { return (int)(db_one($sql, $p)['c'] ?? 0); } catch (\Throwable $e) { return 0; }
        };
        $nameRow = db_one("SELECT first_name, last_name, name FROM users WHERE id=?", [$uid]) ?: [];
        $display = trim(($nameRow['first_name'] ?? '') . ' ' . ($nameRow['last_name'] ?? '')) ?: (string)$u['name'];
        return ['data' => [
            'user' => ['id' => $uid, 'name' => $display, 'email' => (string)$u['email'], 'role' => (string)$u['role'],
                       'is_admin' => is_admin(), 'restricted' => crm_accessible_group_ids() !== null],
            'perms' => $perms,
            'fields' => $fields,
            'can_mailing' => can_write('crm_mailing') || is_admin(),
            'badges' => [
                'inbox_unread' => crm_can('inbox', 'read') ? $badge("SELECT COUNT(*) c FROM crm_communications WHERE direction='in' AND is_read=0 AND inbox_status='active'") : 0,
                'queue_due'    => $badge("SELECT COUNT(*) c FROM crm_activities a JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
                                          WHERE a.status='planned' AND a.assigned_to=? AND a.scheduled_at IS NOT NULL
                                            AND date(a.scheduled_at) <= date('now','localtime')", [$uid]),
                'cases_stale'  => crm_can('cases', 'read') ? $badge("SELECT COUNT(*) c FROM crm_cases WHERE " . crm_case_active_sql() .
                                          " AND updated_at < datetime('now', ?)", ['-' . CRM_CASE_STALE_DAYS . ' days']) : 0,
            ],
            'token_expires_at' => CrmApiAuth::$token['expires_at'] ?? null,
            'legacy_url' => rtrim(APP_URL, '/') . '/crm/',
            'szo_url' => rtrim(APP_URL, '/'),
            'org_name' => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''),
        ]];
    }

    public function dictionaries(): array
    {
        $opt = static fn(array $map) => array_map(fn($k, $v) => ['value' => (string)$k] + $v, array_keys($map), $map);
        $statuses = [];
        foreach (crm_statuses() as $slug => $s) $statuses[] = ['value' => (string)$slug, 'label' => (string)$s['label'], 'color' => $s['color'] ?? null];
        $types = [];
        foreach (CRM_CONTACT_TYPES as $k => $t) $types[] = ['value' => $k, 'label' => $t['label'], 'short' => $t['short'], 'org_like' => (bool)$t['org_like']];
        $categories = [];
        try {
            foreach (crm_categories() as $c) $categories[] = ['value' => (string)$c['slug'], 'label' => (string)$c['label'], 'color' => $c['color'] ?? null];
        } catch (\Throwable $e) {}
        $groups = array_map(fn($g) => ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'color' => $g['color'] ?? null,
                                       'member_count' => (int)($g['member_count'] ?? 0)], CrmManager::getGroups());
        $owners = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['n']], db_all(
            "SELECT id, CASE WHEN COALESCE(first_name,'')<>'' AND COALESCE(last_name,'')<>'' THEN first_name||' '||last_name ELSE name END AS n
               FROM users WHERE is_active=1 ORDER BY n"));
        $tags = array_map(fn($t) => ['tag' => (string)$t['tag'], 'count' => (int)$t['cnt']], db_all(
            "SELECT tag, COUNT(*) cnt FROM crm_tags t JOIN crm_contacts c ON c.id=t.contact_id AND c.crm_active=1
              GROUP BY tag ORDER BY cnt DESC, tag LIMIT 100"));
        $caseStatuses = [];
        foreach (crm_case_statuses() as $code => $s) {
            $caseStatuses[] = ['value' => (string)$code, 'label' => (string)$s['label'], 'color' => $s['color'] ?? null,
                               'closed' => crm_case_is_closed((string)$code), 'active' => !empty($s['active'])];
        }
        $caseTypes = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'color' => $t['color'] ?? null],
            function_exists('crm_case_types') ? crm_case_types() : []);
        $closeReasons = [];
        foreach (crm_case_close_reasons() as $k => $l) $closeReasons[] = ['value' => (string)$k, 'label' => (string)$l];
        $deleteReasons = [];
        foreach (CRM_DELETE_REASONS as $k => $l) $deleteReasons[] = ['value' => (string)$k, 'label' => (string)$l];
        $woj = function_exists('terytWojNames') ? terytWojNames() : [];

        return ['data' => [
            'statuses' => $statuses, 'types' => $types, 'categories' => $categories, 'groups' => $groups,
            'owners' => $owners, 'tags' => $tags, 'wojewodztwa' => array_values($woj),
            'case_statuses' => $caseStatuses, 'case_types' => $caseTypes,
            'case_priorities' => [['value' => 'low', 'label' => 'Niski'], ['value' => 'medium', 'label' => 'Średni'], ['value' => 'high', 'label' => 'Wysoki']],
            'case_close_reasons' => $closeReasons, 'delete_reasons' => $deleteReasons,
        ]];
    }

    public function dashboard(): array
    {
        $uid = CrmApiAuth::userId();
        [$acl, $aclP] = CrmApiAuth::contactAcl('c.id');
        $count = static function (string $sql, array $p = []): int {
            try { return (int)(db_one($sql, $p)['n'] ?? 0); } catch (\Throwable $e) { return 0; }
        };
        $today = date('Y-m-d');
        $canCases = crm_can('cases', 'read');
        $active = crm_case_active_sql('cs.status');

        $tiles = [
            'acts_late'  => $count("SELECT COUNT(*) n FROM crm_activities a JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
                                    WHERE a.status='planned' AND a.assigned_to=? AND date(a.scheduled_at) < ? $acl", array_merge([$uid, $today], $aclP)),
            'acts_today' => $count("SELECT COUNT(*) n FROM crm_activities a JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
                                    WHERE a.status='planned' AND a.assigned_to=? AND date(a.scheduled_at) = ? $acl", array_merge([$uid, $today], $aclP)),
            'inbox_unread' => crm_can('inbox', 'read') ? $count("SELECT COUNT(*) n FROM crm_communications WHERE direction='in' AND is_read=0 AND inbox_status='active'") : null,
            'inbox_mine'   => crm_can('inbox', 'read') ? $count("SELECT COUNT(*) n FROM crm_communications WHERE direction='in' AND inbox_status='active' AND assigned_to=?", [$uid]) : null,
            'cases_open'  => $canCases ? $count("SELECT COUNT(*) n FROM crm_cases cs LEFT JOIN crm_contacts c ON c.id=cs.contact_id WHERE $active $acl", $aclP) : null,
            'cases_high'  => $canCases ? $count("SELECT COUNT(*) n FROM crm_cases cs LEFT JOIN crm_contacts c ON c.id=cs.contact_id WHERE $active AND cs.priority='high' $acl", $aclP) : null,
            'cases_stale' => $canCases ? $count("SELECT COUNT(*) n FROM crm_cases cs LEFT JOIN crm_contacts c ON c.id=cs.contact_id WHERE $active AND cs.updated_at < datetime('now', ?) $acl",
                                                array_merge(['-' . CRM_CASE_STALE_DAYS . ' days'], $aclP)) : null,
        ];

        $myActs = array_map([CrmApiPresenter::class, 'activity'], db_all(
            "SELECT a.*, c.imie_nazwisko FROM crm_activities a JOIN crm_contacts c ON c.id=a.contact_id AND c.crm_active=1
              WHERE a.status='planned' AND a.assigned_to=? AND (a.scheduled_at IS NULL OR date(a.scheduled_at) <= ?) $acl
              ORDER BY a.scheduled_at IS NULL, a.scheduled_at LIMIT 8", array_merge([$uid, $today], $aclP)));

        $openCases = $canCases ? array_map([CrmApiPresenter::class, 'caseRow'], db_all(
            "SELECT cs.*, c.imie_nazwisko contact_name, u.name owner_name,
                    (SELECT COUNT(*) FROM crm_case_notes n WHERE n.case_id=cs.id) notes_count
               FROM crm_cases cs LEFT JOIN crm_contacts c ON c.id=cs.contact_id LEFT JOIN users u ON u.id=cs.owner_id
              WHERE $active $acl
              ORDER BY CASE cs.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END, cs.updated_at DESC LIMIT 6", $aclP)) : [];

        $recent = array_map(fn($r) => CrmApiPresenter::contactRow(crm_mask_contact($r)), db_all(
            "SELECT c.* FROM crm_contacts c WHERE c.crm_active=1 $acl ORDER BY c.updated_at DESC LIMIT 6", $aclP));

        $recentComms = array_map(fn($m) => CrmApiPresenter::communication($m) + ['contact_id' => (int)$m['contact_id'], 'contact_name' => $m['imie_nazwisko']],
            db_all("SELECT cc.*, c.imie_nazwisko FROM crm_communications cc JOIN crm_contacts c ON c.id=cc.contact_id AND c.crm_active=1
                     WHERE COALESCE(cc.crm_hidden,0)=0 $acl ORDER BY cc.sent_at DESC LIMIT 6", $aclP));

        $byStatus = [];
        foreach (db_all("SELECT c.status, COUNT(*) n FROM crm_contacts c WHERE c.crm_active=1 $acl GROUP BY c.status", $aclP) as $r) {
            $byStatus[(string)$r['status']] = (int)$r['n'];
        }
        $statusBars = [];
        foreach (crm_statuses() as $slug => $s) $statusBars[] = ['value' => (string)$slug, 'label' => (string)$s['label'], 'color' => $s['color'] ?? null, 'count' => $byStatus[$slug] ?? 0];

        $stats = [
            'total'          => $count("SELECT COUNT(*) n FROM crm_contacts c WHERE c.crm_active=1 $acl", $aclP),
            'persons'        => $count("SELECT COUNT(*) n FROM crm_contacts c WHERE c.crm_active=1 AND c.type='osoba' $acl", $aclP),
            'orgs'           => $count("SELECT COUNT(*) n FROM crm_contacts c WHERE c.crm_active=1 AND c.type<>'osoba' $acl", $aclP),
            'new_this_month' => $count("SELECT COUNT(*) n FROM crm_contacts c WHERE c.crm_active=1 AND c.created_at >= ? $acl", array_merge([date('Y-m-01')], $aclP)),
            'comms_today'    => $count("SELECT COUNT(*) n FROM crm_communications cc JOIN crm_contacts c ON c.id=cc.contact_id WHERE date(cc.sent_at)=? $acl", array_merge([$today], $aclP)),
            'comms_week'     => $count("SELECT COUNT(*) n FROM crm_communications cc JOIN crm_contacts c ON c.id=cc.contact_id WHERE cc.sent_at >= datetime('now','-7 days') $acl", $aclP),
        ];

        $offers = null;
        if (crm_can('offers', 'read')) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/crm_offers.php';
                $os = crm_offer_stats();
                $offers = ['pipeline' => (float)($os['pipeline'] ?? 0), 'won_value' => (float)($os['won_value'] ?? 0), 'win_rate' => $os['win_rate'] ?? null];
            } catch (\Throwable $e) {}
        }

        return ['data' => compact('tiles', 'stats', 'offers') + [
            'my_activities' => $myActs, 'open_cases' => $openCases, 'recent_contacts' => $recent,
            'recent_communications' => $recentComms, 'status_bars' => $statusBars,
        ]];
    }
}
