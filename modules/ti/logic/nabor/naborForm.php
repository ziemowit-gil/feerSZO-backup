<?php
/**
 * modules/ti/logic/nabor/naborForm.php — konfiguracja formularza naboru edytowana przez kierownika (edytor wizualny).
 *
 * Zapis: ustawienie ti_nabor_form (JSON). naborForm() = domyślne ⊕ zapisane, więc nowe pola w kodzie pojawiają się same.
 * Kierownik zmienia: tytuł, wstęp, podziękowanie, nazwy kroków, etykiety i podpowiedzi pól, widoczność i wymagalność
 * pól NIEKLUCZOWYCH (pola kluczowe — imię i nazwisko, PESEL/data, opiekun, e-mail, telefon — są zawsze wymagane:
 * od nich zależą weryfikacja, EZD i konto), treść zgód (zgoda na wizerunek i marketing — też ukrywalne),
 * listę sprzętu do zajęć online, widoczność płatności, mikroklauzulę RODO i pytania dodatkowe (tekst, dłuższy tekst,
 * pole wyboru, lista) przypięte do kroków 1–4. Odpowiedzi na pytania dodatkowe: ti_nabor_submissions.extra (JSON).
 */
require_once __DIR__ . '/nabor.php';

/** Pola formularza: klucz => [krok, etykieta, podpowiedź, kluczowe?, domyślnie wymagane?]. */
const NABOR_FORM_FIELDS = [
    'participant_name'                => [2, 'Imię i nazwisko', '', true, true],
    'participant_birth_date_or_pesel' => [2, 'PESEL lub data urodzenia', '11 cyfr PESEL albo data w formacie RRRR-MM-DD.', true, true],
    'participant_school_class'        => [2, 'Szkoła i klasa', '', false, false],
    'participant_medical_notes'       => [2, 'Uwagi medyczne i dostępnościowe', 'Alergie, leki, choroby przewlekłe, potrzeby wynikające z niepełnosprawności — wszystko, co pomoże zapewnić bezpieczeństwo i dostosować zajęcia.', false, false],
    'equipment'                       => [2, 'Sprzęt do zajęć online', 'Zajęcia odbywają się online. Zaznacz, co uczestnik ma do dyspozycji — pomożemy przygotować stanowisko.', false, false],
    'equipment_notes'                 => [2, 'Uwagi o sprzęcie', '', false, false],
    'guardian_name'                   => [3, 'Imię i nazwisko', '', true, true],
    'guardian_email'                  => [3, 'E-mail', 'Wyślemy na niego link potwierdzający — bez kliknięcia zgłoszenie nie zostanie rozpatrzone.', true, true],
    'guardian_phone'                  => [3, 'Telefon komórkowy', '', true, true],
    'guardian_address'                => [3, 'Adres zamieszkania', '', false, true],
    'payment_method'                  => [5, 'Forma płatności', 'To deklaracja — szczegóły płatności prześlemy po przyjęciu zgłoszenia.', false, true],
];
const NABOR_FORM_CUSTOM_TYPES = ['text' => 'Krótki tekst', 'textarea' => 'Dłuższy tekst', 'checkbox' => 'Pole wyboru (tak/nie)', 'select' => 'Lista wyboru'];

function naborFormDefaults(): array {
    $fields = [];
    foreach (NABOR_FORM_FIELDS as $k => [$step, $label, $help, $core, $req]) {
        $fields[$k] = ['label' => $label, 'help' => $help, 'visible' => true, 'required' => $req, 'core' => $core, 'step' => $step];
    }
    return [
        'texts' => [
            'title'  => 'Zapisy na zajęcia',
            'intro'  => '',
            'thanks' => 'Dziękujemy! Zgłoszenie zostało zarejestrowane',
            'clause' => '',   // '' = tekst wbudowany albo klauzula z rejestru RODO
        ],
        'steps' => [1 => 'Zajęcia', 2 => 'Uczestnik', 3 => 'Opiekun', 4 => 'Zgody', 5 => 'Podsumowanie'],
        'step_titles' => [1 => 'Wybierz zajęcia', 2 => 'Dane uczestnika', 3 => 'Rodzic / opiekun prawny', 4 => 'Zgody i oświadczenia', 5 => 'Podsumowanie'],
        'fields' => $fields,
        'consents' => [
            'terms'     => ['label' => 'Akceptuję regulamin zajęć.', 'visible' => true, 'required' => true, 'core' => true],
            'gdpr'      => ['label' => 'Wyrażam zgodę na przetwarzanie danych osobowych moich i uczestnika w celu rekrutacji i organizacji zajęć, w tym danych o zdrowiu podanych w uwagach.', 'visible' => true, 'required' => true, 'core' => true],
            'image'     => ['label' => 'Zgadzam się na nieodpłatne wykorzystanie wizerunku uczestnika w materiałach informacyjnych organizacji.', 'visible' => true, 'required' => false, 'core' => false],
            'marketing' => ['label' => 'Chcę otrzymywać e-mailem informacje o kolejnych zajęciach i wydarzeniach.', 'visible' => true, 'required' => false, 'core' => false],
        ],
        'equipment' => array_map(fn($k, $l) => ['key' => $k, 'label' => $l], array_keys(NABOR_EQUIPMENT), NABOR_EQUIPMENT),
        'custom' => [],
    ];
}

/** Konfiguracja bieżąca: domyślna nadpisana zapisaną (tylko znane klucze; pola kluczowe zawsze widoczne i wymagane). */
function naborForm(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $def = naborFormDefaults();
    $saved = json_decode(org_setting('ti_nabor_form') ?: '[]', true) ?: [];
    $f = $def;
    foreach (['title', 'intro', 'thanks', 'clause'] as $k) if (isset($saved['texts'][$k])) $f['texts'][$k] = (string)$saved['texts'][$k];
    foreach ([1, 2, 3, 4, 5] as $i) {
        if (trim((string)($saved['steps'][$i] ?? '')) !== '') $f['steps'][$i] = (string)$saved['steps'][$i];
        if (trim((string)($saved['step_titles'][$i] ?? '')) !== '') $f['step_titles'][$i] = (string)$saved['step_titles'][$i];
    }
    foreach ($f['fields'] as $k => &$fd) {
        $sv = $saved['fields'][$k] ?? [];
        if (trim((string)($sv['label'] ?? '')) !== '') $fd['label'] = (string)$sv['label'];
        if (isset($sv['help'])) $fd['help'] = (string)$sv['help'];
        if (!$fd['core']) {
            if (isset($sv['visible'])) $fd['visible'] = (bool)$sv['visible'];
            if (isset($sv['required'])) $fd['required'] = (bool)$sv['required'] && $fd['visible'];
        }
    }
    unset($fd);
    foreach ($f['consents'] as $k => &$c) {
        $sv = $saved['consents'][$k] ?? [];
        if (trim((string)($sv['label'] ?? '')) !== '') $c['label'] = (string)$sv['label'];
        if (!$c['core'] && isset($sv['visible'])) $c['visible'] = (bool)$sv['visible'];
    }
    unset($c);
    if (!empty($saved['equipment']) && is_array($saved['equipment'])) $f['equipment'] = $saved['equipment'];
    if (!empty($saved['custom']) && is_array($saved['custom'])) $f['custom'] = $saved['custom'];
    return $cache = $f;
}

/** Sprzęt do zajęć online wg konfiguracji: klucz => etykieta. */
function naborEquipmentOptions(): array {
    $o = [];
    foreach (naborForm()['equipment'] as $e) $o[(string)$e['key']] = (string)$e['label'];
    return $o;
}

/** Sanityzacja konfiguracji z edytora (JSON) i zapis. Zwraca zapisaną konfigurację. */
function naborFormSave(array $in): array {
    $t = fn($v, $max = 500) => mb_substr(trim(strip_tags((string)$v)), 0, $max);
    $def = naborFormDefaults();
    $out = ['texts' => [], 'steps' => [], 'step_titles' => [], 'fields' => [], 'consents' => [], 'equipment' => [], 'custom' => []];
    foreach (['title' => 120, 'intro' => 2000, 'thanks' => 300, 'clause' => 4000] as $k => $max) $out['texts'][$k] = $t($in['texts'][$k] ?? $def['texts'][$k], $max);
    foreach ([1, 2, 3, 4, 5] as $i) {
        $out['steps'][$i] = $t($in['steps'][$i] ?? '', 40) ?: $def['steps'][$i];
        $out['step_titles'][$i] = $t($in['step_titles'][$i] ?? '', 120) ?: $def['step_titles'][$i];
    }
    foreach ($def['fields'] as $k => $fd) {
        $sv = $in['fields'][$k] ?? [];
        $vis = $fd['core'] ? true : !empty($sv['visible']);
        $out['fields'][$k] = ['label' => $t($sv['label'] ?? '', 120) ?: $fd['label'], 'help' => $t($sv['help'] ?? '', 500),
                              'visible' => $vis, 'required' => $fd['core'] ? true : ($vis && !empty($sv['required']))];
    }
    foreach ($def['consents'] as $k => $c) {
        $sv = $in['consents'][$k] ?? [];
        $out['consents'][$k] = ['label' => $t($sv['label'] ?? '', 600) ?: $c['label'], 'visible' => $c['core'] ? true : !empty($sv['visible'])];
    }
    $seen = [];
    foreach ((array)($in['equipment'] ?? []) as $e) {
        $label = $t($e['label'] ?? '', 120);
        if ($label === '') continue;
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($e['key'] ?? ''))) ?: 'e' . substr(md5($label), 0, 6);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out['equipment'][] = ['key' => $key, 'label' => $label];
    }
    $ids = [];
    foreach ((array)($in['custom'] ?? []) as $q) {
        $label = $t($q['label'] ?? '', 300);
        if ($label === '') continue;
        $type = array_key_exists($q['type'] ?? '', NABOR_FORM_CUSTOM_TYPES) ? $q['type'] : 'text';
        $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($q['id'] ?? ''))) ?: 'q' . bin2hex(random_bytes(4));
        if (isset($ids[$id])) $id = 'q' . bin2hex(random_bytes(4));
        $ids[$id] = true;
        $opts = $type === 'select' ? array_values(array_filter(array_map(fn($o) => $t($o, 120), (array)($q['options'] ?? [])))) : [];
        if ($type === 'select' && !$opts) continue;
        $out['custom'][] = ['id' => $id, 'step' => max(1, min(4, (int)($q['step'] ?? 2))), 'type' => $type, 'label' => $label,
                            'help' => $t($q['help'] ?? '', 500), 'required' => !empty($q['required']), 'options' => $opts];
    }
    org_setting_set('ti_nabor_form', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/** Walidacja odpowiedzi na pytania dodatkowe. Zwraca [odpowiedzi, błędy]. */
function naborFormValidateExtra(array $post): array {
    $ans = []; $err = [];
    foreach (naborForm()['custom'] as $q) {
        $v = $post['extra'][$q['id']] ?? '';
        if ($q['type'] === 'checkbox') $v = !empty($v) ? 'tak' : '';
        else $v = mb_substr(trim((string)$v), 0, $q['type'] === 'textarea' ? 3000 : 300);
        if ($q['type'] === 'select' && $v !== '' && !in_array($v, $q['options'], true)) $v = '';
        if ($q['required'] && $v === '') $err[] = 'Uzupełnij: „' . $q['label'] . '”.';
        if ($v !== '') $ans[$q['id']] = $v;
    }
    return [$ans, $err];
}

/** Odpowiedzi dodatkowe zgłoszenia do wyświetlenia: [[etykieta, odpowiedź], …] (także dla usuniętych już pytań). */
function naborExtraRows(?string $json): array {
    $ans = json_decode($json ?: '{}', true) ?: [];
    if (!$ans) return [];
    $labels = [];
    foreach (naborForm()['custom'] as $q) $labels[$q['id']] = $q['label'];
    $rows = [];
    foreach ($ans as $id => $v) $rows[] = [$labels[$id] ?? ('Pytanie ' . $id . ' (usunięte z formularza)'), (string)$v];
    return $rows;
}

/** Token podglądu formularza (ważny w dniu wygenerowania) — pozwala kierownikowi zobaczyć zamknięty formularz. */
function naborPreviewToken(?string $day = null): string {
    $key = org_setting('ti_nabor_preview_key');
    if ($key === '') { $key = bin2hex(random_bytes(16)); org_setting_set('ti_nabor_preview_key', $key); }
    return substr(hash_hmac('sha256', 'nabor-preview|' . ($day ?? date('Y-m-d')), $key), 0, 24);
}

function naborPreviewValid(string $token): bool {
    return $token !== '' && hash_equals(naborPreviewToken(), $token);
}
