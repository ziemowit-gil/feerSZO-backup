<?php
/**
 * cli/seedFormularzRedaktor.php — formularz webowy CRM „Redaktor/ka tekstów internetowych — wolontariat”.
 *
 *   php cli/seedFormularzRedaktor.php              # podgląd (nic nie zapisuje)
 *   php cli/seedFormularzRedaktor.php --apply      # zapis (formularz aktywny)
 *   php cli/seedFormularzRedaktor.php --apply --notify=redakcja@feer.org.pl   # + powiadomienie o zgłoszeniu
 *
 * Odpowiednik ogłoszenia z cli/seedOfertaRedaktor.php, ale jako lead capture w CRM (crm_web_forms):
 * zgłoszenie zakłada / uzupełnia kontakt CRM, odpowiedzi trafiają do pól dodatkowych kontaktu
 * (crm_contact_field_defs — szukane po etykiecie, brakujące tworzone), kontakt dostaje tagi.
 * Publicznie: /crm/form/redaktor-www. Edycja: CRM → Formularze webowe.
 * Ponowne uruchomienie aktualizuje formularz o tym slugu (nie tworzy drugiego).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

$opts   = getopt('', ['apply', 'notify::']);
$apply  = array_key_exists('apply', $opts);
$notify = trim((string)($opts['notify'] ?? ''));
if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Zły adres --notify: {$notify}\n"); exit(1); }

crm_migrate();

$SLUG  = 'redaktor-www';
$TITLE = 'Redaktor/ka tekstów internetowych — wolontariat w FEER';

$description = <<<'TXT'
FEER działa na rzecz osób z niepełnosprawnościami. Szukamy osoby, która pomoże nam przebudować komunikację na stronie feer.org.pl od podstaw — przeanalizuje obecne teksty, przepisze wybrane podstrony („O nas”, opisy projektów, aktualności) i wypracuje z nami spójny ton.

Preferujemy osoby z wykształceniem lub doświadczeniem w dziennikarstwie bądź komunikacji społecznej.
Zdalnie, elastycznie, ok. 3–5 godzin miesięcznie.
Zyskujesz: portfolio opublikowanych tekstów, doświadczenie w komunikacji NGO, zaświadczenie o wolontariacie.
TXT;

// Pola dodatkowe: [etykieta, typ crm_contact_field_defs, wymagane, placeholder]
$customDefs = [
    ['Wykształcenie / doświadczenie w dziennikarstwie lub komunikacji społecznej', 'textarea', true,  'Kierunek studiów, redakcja, projekty, publikacje…'],
    ['Portfolio / przykładowe teksty',                                         'url',      false, 'https://…'],
    ['Motywacja do wolontariatu',                                              'textarea', false, 'Dlaczego chcesz pisać dla FEER?'],
    ['Preferowany kontakt',                                                    'select',   false, ''],
];
$selectOptions = ['Preferowany kontakt' => 'E-mail,Teams,WhatsApp,Messenger,Telefon'];

$plan = [];
foreach ($customDefs as [$label, $type]) {
    $def = db_one("SELECT id, field_type FROM crm_contact_field_defs WHERE lower(label)=lower(?) ORDER BY is_active DESC, id LIMIT 1", [$label]);
    $plan[] = [$label, $def ? "istnieje #{$def['id']} ({$def['field_type']})" : "nowe ({$type})"];
}

$existing = db_one("SELECT id FROM crm_web_forms WHERE slug=?", [$SLUG]);
echo ($existing ? "Aktualizacja formularza #{$existing['id']}" : 'Nowy formularz') . ": {$TITLE}\n";
echo "Adres: /crm/form/{$SLUG}\n";
echo "Pola dodatkowe kontaktu:\n";
foreach ($plan as [$l, $s]) echo "  • {$l} — {$s}\n";
echo 'Powiadomienie: ' . ($notify ?: '— (brak, ustaw --notify=…)') . "\n";

if (!$apply) { echo "\nPodgląd — nic nie zapisano. Dodaj --apply.\n"; exit(0); }

// ── Pola formularza ──────────────────────────────────────────────────────────
$fields = [
    ['name' => 'imie_nazwisko', 'required' => true],
    ['name' => 'email',         'required' => true],
    ['name' => 'telefon',       'required' => false],
];
foreach ($customDefs as [$label, $type, $required, $placeholder]) {
    $def   = db_one("SELECT id FROM crm_contact_field_defs WHERE lower(label)=lower(?) ORDER BY is_active DESC, id LIMIT 1", [$label]);
    $defId = $def ? (int)$def['id'] : CrmManager::saveFieldDef([
        'label'      => $label,
        'field_type' => $type,
        'options'    => $selectOptions[$label] ?? '',
        'applies_to' => 'osoba',
        'sort_order' => 900,
    ]);
    if (!$defId) { fwrite(STDERR, "Nie udało się utworzyć pola: {$label}\n"); exit(1); }
    $fields[] = ['type' => 'custom', 'field_def_id' => $defId, 'required' => $required, 'placeholder' => $placeholder];
}

$consents = [[
    'id'         => 'c1',
    'text'       => 'Wyrażam zgodę na przetwarzanie moich danych osobowych przez FEER w celu przeprowadzenia rekrutacji na wolontariat.',
    'required'   => true,
    'link'       => null,
    'link_text'  => 'Więcej informacji',
    'purpose_id' => null, // cele z katalogu zgód są marketingowe — rekrutacja to osobna podstawa
]];

$automations = [
    ['id' => 'a1', 'type' => 'add_tag', 'enabled' => true, 'config' => ['tags' => 'wolontariat,rekrutacja,redakcja-www']],
];

$style = [
    'accent'      => '#0176D3',
    'bg'          => '#f8fafc',
    'max_width'   => 680,
    'btn_text'    => 'Zgłaszam się',
    'logo_url'    => null,
    'header_text' => 'Wolontariat',
    'font'        => 'system',
    'rounded'     => 14,
];

$data = [
    'slug'             => $SLUG,
    'title'            => $TITLE,
    'description'      => $description,
    'fields_json'      => json_encode($fields,      JSON_UNESCAPED_UNICODE),
    'consents_json'    => json_encode($consents,    JSON_UNESCAPED_UNICODE),
    'style_json'       => json_encode($style,       JSON_UNESCAPED_UNICODE),
    'automations_json' => json_encode($automations, JSON_UNESCAPED_UNICODE),
    'group_id'         => null,
    'default_status'   => 'prospect',
    'success_msg'      => 'Dziękujemy za zgłoszenie! Odezwiemy się mailowo w ciągu kilku dni.',
    'notify_email'     => $notify ?: null,
    'is_active'        => 1,
];

if ($existing) {
    $id = (int)$existing['id'];
    if ($notify === '') unset($data['notify_email']); // ponowny seed nie kasuje adresu ustawionego w CRM
    db_update('crm_web_forms', $data, $id);
} else {
    $data['created_at'] = date('Y-m-d H:i:s');
    $id = (int)db_insert('crm_web_forms', $data);
}

echo "\nZapisano formularz #{$id} (aktywny).\n";
echo "Publiczny:  /crm/form/{$SLUG}\n";
echo "Edycja:     /crm/form/manage.php?edit={$id}\n";
