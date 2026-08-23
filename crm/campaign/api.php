<?php
/**
 * crm/campaign/api.php — zaplecze XHR edytora newsletterów.
 *
 * Wzorzec projektu: POST + `_csrf`, odpowiedź JSON, jedna akcja na `_action`
 * (jak crm/api/mass_send.php). Wyjątkiem jest `export`, które wprost oddaje
 * plik HTML do pobrania.
 *
 * Akcje:
 *   save             — zapis dokumentu bloków i metadanych kampanii
 *   render           — render treści (podgląd w edytorze / kontrola przed wysyłką)
 *   audience         — ilu odbiorców obejmie segment i ilu odpada (z powodami)
 *   test             — wysyłka testowa na wskazany adres
 *   schedule         — start wysyłki teraz albo plan na termin
 *   save_as_template — zapis dokumentu jako szablon wielokrotnego użytku
 *   export           — pobranie gotowego HTML-a
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_campaign.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$can_write = can_write('crm') || is_admin();

/** Odpowiedź JSON + koniec. */
function api_out(array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_POST['_action'] ?? $_GET['_action'] ?? '');

// ── Eksport HTML (GET, bez ciała JSON) ───────────────────────────────────────
if ($action === 'export') {
    $id = (int)($_GET['id'] ?? 0);
    $campaign = $id ? db_one("SELECT * FROM crm_campaigns WHERE id=?", [$id]) : null;
    if (!$campaign) { http_response_code(404); exit('Nie znaleziono kampanii.'); }

    // Eksport zawsze z aktualnego dokumentu, bez trackingu — plik ma być
    // przenośny, a nie zawierać tokeny konkretnego odbiorcy.
    $design = crm_campaign_design($campaign);
    $html = $design
        ? crm_email_render($design, ['subject' => (string)$campaign['subject'], 'preheader' => (string)($campaign['preheader'] ?? ''), 'tracking' => false])['html']
        : crm_campaign_build_body($campaign)['html'];

    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$campaign['name']);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($name ?: 'newsletter') . '.html"');
    echo $html;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_out(['error' => 'Wymagany POST.'], 405);
csrf_check();
if (!$can_write) api_out(['error' => 'Brak uprawnień do zapisu w CRM.'], 403);

$campaign_id = (int)($_POST['id'] ?? 0);
$campaign    = $campaign_id ? db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]) : null;
if (!$campaign) api_out(['error' => 'Nie znaleziono kampanii.'], 404);

/** Dokument z requestu, po sanityzacji. Nigdy nie zapisujemy surowego wejścia. */
function posted_design(): ?array {
    $raw = (string)($_POST['design'] ?? '');
    if (trim($raw) === '') return null;
    $in = json_decode($raw, true);
    if (!is_array($in)) return null;
    return crm_email_design_sanitize($in);
}

switch ($action) {

    // ── Zapis ────────────────────────────────────────────────────────────────
    case 'save': {
        if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            api_out(['error' => 'Kampania jest w trakcie wysyłki — treść jest zamrożona.'], 409);
        }
        $design = posted_design();
        if ($design === null) api_out(['error' => 'Nieprawidłowy dokument.'], 422);

        $fields = [
            'design_json' => json_encode($design, JSON_UNESCAPED_UNICODE),
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        if (isset($_POST['name']))      $fields['name']      = mb_substr(trim((string)$_POST['name']), 0, 160) ?: $campaign['name'];
        if (isset($_POST['subject']))   $fields['subject']   = mb_substr(trim((string)$_POST['subject']), 0, 250);
        if (isset($_POST['preheader'])) $fields['preheader'] = mb_substr(trim((string)$_POST['preheader']), 0, 250);

        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $fields['id'] = $campaign_id;
        db()->prepare("UPDATE crm_campaigns SET $set WHERE id = :id")->execute($fields);

        api_out(['ok' => true, 'saved_at' => date('H:i:s')]);
    }

    // ── Render podglądu ──────────────────────────────────────────────────────
    case 'render': {
        $design = posted_design() ?? crm_campaign_design($campaign);
        if (!$design) {
            $built = crm_campaign_build_body($campaign);
            api_out(['html' => $built['html'], 'warnings' => $built['warnings'], 'source' => $built['source']]);
        }
        // Tryb kanwy (editable) każe rendererowi oznaczyć bloki atrybutem
        // data-cem-block. Znaczniki powstają w rendererze, nie przez doklejanie
        // regexem po fakcie — inaczej kolejność bloków i kolejność podmian
        // rozjeżdżałyby się przy pierwszym nowym typie bloku.
        $out = crm_email_render($design, [
            'subject'   => (string)($_POST['subject'] ?? $campaign['subject']),
            'preheader' => (string)($_POST['preheader'] ?? ($campaign['preheader'] ?? '')),
            'tracking'  => false,
            'editable'  => !empty($_POST['editable']),
        ]);

        $html = $out['html'];

        // Podgląd personalizacji: pierwszy kontakt z segmentu albo dane zastępcze.
        if (!empty($_POST['personalize'])) {
            $sample = crm_campaign_resolve_recipients(
                (string)$campaign['segment_type'], crm_campaign_segment_config($campaign), (int)($campaign['purpose_id'] ?? 0)
            )[0] ?? ['imie_nazwisko' => 'Anna Przykładowa', 'email' => 'anna@example.org', 'organizacja' => 'Organizacja Testowa'];
            $html = crm_email_personalize($html, $sample, 'podglad', []);
        }

        api_out(['html' => $html, 'warnings' => $out['warnings'], 'links' => $out['links'], 'source' => 'design']);
    }

    // ── Segment: ilu odbiorców ───────────────────────────────────────────────
    case 'audience': {
        $type = (string)($_POST['segment_type'] ?? $campaign['segment_type']);
        if (!in_array($type, ['all', 'tags', 'groups', 'contacts', 'filter'], true)) $type = 'tags';

        $cfg = [];
        if ($type === 'tags')     $cfg['tags']        = array_values(array_filter((array)($_POST['tags'] ?? [])));
        if ($type === 'groups')   $cfg['group_ids']   = array_values(array_filter(array_map('intval', (array)($_POST['group_ids'] ?? []))));
        if ($type === 'contacts') $cfg['contact_ids'] = array_values(array_filter(array_map('intval', (array)($_POST['contact_ids'] ?? []))));
        if ($type === 'filter') {
            $f = json_decode((string)($_POST['filter'] ?? '{}'), true);
            $cfg['filter'] = crm_segment_sanitize(is_array($f) ? $f : []);
        }

        $purpose = (int)($_POST['purpose_id'] ?? 0);
        $part = crm_campaign_partition_recipients($type, $cfg, $purpose);

        $by_reason = [];
        foreach ($part['skipped'] as $s) {
            $key = str_starts_with($s['reason'], 'suppressed:') ? 'suppressed' : $s['reason'];
            $by_reason[$key] = ($by_reason[$key] ?? 0) + 1;
        }
        $labels = [
            'no_consent'    => 'bez zgody na wybrany cel',
            'opt_out'       => 'wypisani z wysyłek',
            'invalid_email' => 'bez poprawnego adresu e-mail',
            'suppressed'    => 'na liście wykluczeń (odbicia, skargi)',
        ];
        $skipped = [];
        foreach ($by_reason as $k => $n) $skipped[] = ['label' => $labels[$k] ?? $k, 'n' => $n];

        api_out([
            'sendable'    => count($part['send']),
            'skipped'     => count($part['skipped']),
            'breakdown'   => $skipped,
            'description' => $type === 'filter' ? crm_segment_describe($cfg['filter'] ?? []) : '',
        ]);
    }

    // ── Wysyłka testowa ──────────────────────────────────────────────────────
    case 'test': {
        // Zapis przed testem: inaczej test pokazuje poprzednią wersję treści.
        $design = posted_design();
        if ($design) {
            db()->prepare("UPDATE crm_campaigns SET design_json=?, subject=?, preheader=? WHERE id=?")->execute([
                json_encode($design, JSON_UNESCAPED_UNICODE),
                mb_substr(trim((string)($_POST['subject'] ?? $campaign['subject'])), 0, 250),
                mb_substr(trim((string)($_POST['preheader'] ?? ($campaign['preheader'] ?? ''))), 0, 250),
                $campaign_id,
            ]);
        }
        $res = crm_campaign_send_test($campaign_id, (string)($_POST['email'] ?? ''));
        api_out($res['ok'] ? ['ok' => true, 'warnings' => $res['warnings'] ?? []] : ['error' => $res['error']], $res['ok'] ? 200 : 422);
    }

    // ── Start / harmonogram ──────────────────────────────────────────────────
    case 'schedule': {
        if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            api_out(['error' => 'Kampania jest już w trakcie wysyłki lub wysłana.'], 409);
        }

        // 1) Segment i cel zapisujemy zanim cokolwiek zwalidujemy — walidacja
        //    musi widzieć to, co operator właśnie wybrał.
        $type = (string)($_POST['segment_type'] ?? 'tags');
        if (!in_array($type, ['all', 'tags', 'groups', 'contacts', 'filter'], true)) $type = 'tags';
        $cfg = [];
        $filter_json = null;
        if ($type === 'tags')     $cfg['tags']        = array_values(array_filter((array)($_POST['tags'] ?? [])));
        if ($type === 'groups')   $cfg['group_ids']   = array_values(array_filter(array_map('intval', (array)($_POST['group_ids'] ?? []))));
        if ($type === 'contacts') $cfg['contact_ids'] = array_values(array_filter(array_map('intval', (array)($_POST['contact_ids'] ?? []))));
        if ($type === 'filter') {
            $f = json_decode((string)($_POST['filter'] ?? '{}'), true);
            $filter_json = json_encode(crm_segment_sanitize(is_array($f) ? $f : []), JSON_UNESCAPED_UNICODE);
        }

        $design = posted_design();
        $purpose_id = (int)($_POST['purpose_id'] ?? 0) ?: null;
        $when = (string)($_POST['when'] ?? 'now');
        $at   = trim((string)($_POST['scheduled_at'] ?? ''));

        $scheduled_at = null;
        if ($when === 'schedule') {
            if ($at === '') api_out(['error' => 'Podaj datę i godzinę wysyłki.'], 422);
            $ts = strtotime(str_replace('T', ' ', $at));
            if (!$ts) api_out(['error' => 'Nieprawidłowa data wysyłki.'], 422);
            if ($ts < time() - 60) api_out(['error' => 'Termin wysyłki jest w przeszłości.'], 422);
            $scheduled_at = date('Y-m-d H:i:s', $ts);
        }

        db()->prepare(
            "UPDATE crm_campaigns
                SET segment_type=?, segment_config=?, segment_filter=?, purpose_id=?,
                    subject=?, preheader=?, design_json=COALESCE(?, design_json), updated_at=datetime('now')
              WHERE id=?"
        )->execute([
            $type, json_encode($cfg, JSON_UNESCAPED_UNICODE), $filter_json, $purpose_id,
            mb_substr(trim((string)($_POST['subject'] ?? $campaign['subject'])), 0, 250),
            mb_substr(trim((string)($_POST['preheader'] ?? ($campaign['preheader'] ?? ''))), 0, 250),
            $design ? json_encode($design, JSON_UNESCAPED_UNICODE) : null,
            $campaign_id,
        ]);

        // 2) Walidacja na świeżym stanie.
        $fresh = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$campaign_id]);
        $problems = crm_campaign_validate($fresh);
        if ($problems) api_out(['problems' => $problems], 422);

        if ($when === 'schedule') {
            db()->prepare("UPDATE crm_campaigns SET status='scheduled', scheduled_at=? WHERE id=?")->execute([$scheduled_at, $campaign_id]);
            api_out(['ok' => true, 'status' => 'scheduled', 'scheduled_at' => $scheduled_at]);
        }

        $res = crm_campaign_queue_send($campaign_id);
        if (!empty($res['error'])) api_out(['error' => $res['error']], 422);
        api_out(['ok' => true, 'status' => 'sending', 'queued' => $res['queued'], 'skipped' => $res['skipped'] ?? 0]);
    }

    // ── Wczytanie dokumentu z szablonu ───────────────────────────────────────
    case 'load_template': {
        $tid = (int)($_POST['template_id'] ?? 0);
        $tpl = $tid ? db_one("SELECT id, name, subject, design_json FROM crm_templates WHERE id=?", [$tid]) : null;
        if (!$tpl) api_out(['error' => 'Nie znaleziono szablonu.'], 404);
        $design = crm_email_design_decode($tpl['design_json'] ?? null);
        if (!$design) api_out(['error' => 'Ten szablon nie ma dokumentu bloków (powstał w innym edytorze).'], 422);
        api_out(['ok' => true, 'design' => $design, 'subject' => (string)$tpl['subject']]);
    }

    // ── Zapis jako szablon ───────────────────────────────────────────────────
    case 'save_as_template': {
        $design = posted_design();
        if (!$design) api_out(['error' => 'Nieprawidłowy dokument.'], 422);
        $name = mb_substr(trim((string)($_POST['template_name'] ?? '')), 0, 120);
        if ($name === '') api_out(['error' => 'Podaj nazwę szablonu.'], 422);
        if (db_one("SELECT id FROM crm_templates WHERE name=?", [$name])) {
            api_out(['error' => 'Szablon o tej nazwie już istnieje.'], 422);
        }
        $out = crm_email_render($design, ['subject' => (string)$campaign['subject'], 'tracking' => true]);
        $tid = db_insert('crm_templates', [
            'name'        => $name,
            'channel'     => 'email',
            'subject'     => (string)$campaign['subject'],
            'body'        => $out['html'],
            'design_json' => json_encode($design, JSON_UNESCAPED_UNICODE),
            'source'      => 'blocks',
            'is_active'   => 1,
            'created_by'  => (int)(current_user()['id'] ?? 0) ?: null,
        ]);
        api_out(['ok' => true, 'template_id' => $tid]);
    }
}

api_out(['error' => 'Nieznana akcja.'], 400);
