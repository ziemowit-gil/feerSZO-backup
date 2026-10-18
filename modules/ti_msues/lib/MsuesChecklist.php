<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesChecklist.php — checklista MSUES dla kadry: kryteria i ich ocena dla prowadzącego w usłudze.
 *
 * Kryteria pochodzą ze Słownika Centralnego (słownik systemowy `msues_kryteria_kadry`, zasiany migracją jednorazową
 * 2026-10_msues_dictionary) — etykiety, opisy, kolejność i atrybut `required` można zmieniać w panelu słowników,
 * a kody (cv, dyplomy, umowa, oswiadczenie) są używane tutaj. Bez słownika — stała MSUES_CRITERIA.
 */
require_once dirname(__DIR__, 2) . '/dictionary/lib/dictionaryApi.php';

const MSUES_DICT = 'msues_kryteria_kadry';
const MSUES_CRITERIA = [
    'cv'           => ['label' => 'CV (życiorys zawodowy)', 'description' => 'Aktualne CV prowadzącego z opisem doświadczenia zgodnego z zakresem usługi.', 'required' => true],
    'dyplomy'      => ['label' => 'Dyplomy, certyfikaty, zaświadczenia', 'description' => 'Dokumenty potwierdzające wykształcenie, przygotowanie pedagogiczne/merytoryczne i uprawnienia (ważne w dniu sprawdzenia).', 'required' => true],
    'umowa'        => ['label' => 'Umowa współpracy', 'description' => 'Aktywna umowa z Rejestru Umów (zlecenie, wolontariat, o dzieło, o pracę) albo wgrana umowa spoza rejestru.', 'required' => true],
    'oswiadczenie' => ['label' => 'Formalne oświadczenie o kwalifikacjach (OKK-1)', 'description' => 'Oświadczenie zatwierdzone pod audyt, z wgranym skanem podpisu — dla tej usługi albo ogólne.', 'required' => true],
];

final class MsuesChecklist
{
    public const DOC_KINDS = [
        'cv'         => 'CV',
        'dyplom'     => 'Dyplom / świadectwo',
        'certyfikat' => 'Certyfikat / zaświadczenie',
        'umowa'      => 'Umowa (spoza Rejestru Umów)',
        'inne'       => 'Inny dokument',
    ];

    public static function documentKinds(): array
    {
        return self::DOC_KINDS;
    }

    /** @return array<string, array{label:string, description:string, required:bool}> */
    public static function criteria(): array
    {
        $items = dictItems(MSUES_DICT, []);
        if (!$items) return MSUES_CRITERIA;
        $out = [];
        foreach ($items as $code => $it) {
            if (!isset(MSUES_CRITERIA[$code])) continue;   // kod bez logiki oceny — pomijamy
            $req = $it['attributes']['required'] ?? MSUES_CRITERIA[$code]['required'];
            $out[$code] = ['label' => (string)$it['label'], 'description' => (string)($it['description'] ?? MSUES_CRITERIA[$code]['description']), 'required' => (bool)$req];
        }
        return $out ?: MSUES_CRITERIA;
    }

    /** Seed słownika (format importu Słownika Centralnego) — używany przez migrację jednorazową. */
    public static function dictionarySeed(): string
    {
        $entries = [];
        $i = 0;
        foreach (MSUES_CRITERIA as $code => $c) {
            $entries[] = ['code' => $code, 'label' => $c['label'], 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true,
                          'description' => $c['description'], 'attributes' => ['required' => $c['required']]];
        }
        return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [[
            'code' => MSUES_DICT, 'name' => 'MSUES — kryteria checklisty kadry', 'kind' => 'system', 'parent_code' => null,
            'is_protected' => false, 'sort_order' => 50,
            'description' => 'Kryteria gotowości prowadzącego do audytu MSUES 2.0 (moduł panelu dydaktyka „Certyfikacja MSUES”). Kody są używane w kodzie — zmieniaj etykiety, opisy, kolejność i atrybut required (true/false).',
            'entries' => $entries,
        ]]], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Ocena kryteriów dla prowadzącego w usłudze.
     * @return list<array{code:string,label:string,description:string,required:bool,ok:bool,detail:string,evidence:array}>
     */
    public static function evaluate(int $trainerId, ?int $courseId, DeclarationRepository $repo, MsuesStaffDocuments $docs): array
    {
        $all = $docs->list($trainerId);
        $valid = array_values(array_filter($all, fn($d) => MsuesStaffDocuments::isValid($d)));
        $byKind = fn(array $kinds) => array_values(array_filter($valid, fn($d) => in_array($d['kind'], $kinds, true)));
        $expired = array_values(array_filter($all, fn($d) => !MsuesStaffDocuments::isValid($d)));
        $out = [];
        foreach (self::criteria() as $code => $c) {
            $ok = false; $detail = ''; $ev = [];
            switch ($code) {
                case 'cv':
                    $ev = $byKind(['cv']);
                    $ok = (bool)$ev;
                    $detail = $ok ? 'CV z ' . substr((string)$ev[0]['created_at'], 0, 10) : 'Brak CV w dokumentach kadry.';
                    break;
                case 'dyplomy':
                    $ev = $byKind(['dyplom', 'certyfikat']);
                    $ok = (bool)$ev;
                    $exp = array_filter($expired, fn($d) => in_array($d['kind'], ['dyplom', 'certyfikat'], true));
                    $detail = $ok ? count($ev) . ' dok.' . ($exp ? ' (+' . count($exp) . ' po terminie ważności)' : '') : ($exp ? 'Wszystkie dyplomy/certyfikaty są po terminie ważności.' : 'Brak dyplomów i certyfikatów.');
                    break;
                case 'umowa':
                    $contracts = array_values(array_filter(MsuesSzoData::contracts($trainerId), fn($x) => $x['active']));
                    $ext = $byKind(['umowa']);
                    $ok = $contracts || $ext;
                    $ev = array_merge(array_map(fn($x) => ['kind' => 'rejestr', 'title' => $x['label'] . ' ' . $x['numer_umowy'] . ' (' . $x['status'] . ')'] + $x, $contracts), $ext);
                    $detail = $contracts ? $contracts[0]['label'] . ' ' . $contracts[0]['numer_umowy'] . ' — ' . $contracts[0]['status']
                            : ($ext ? 'Umowa wgrana do dokumentów kadry.' : 'Brak aktywnej umowy w Rejestrze Umów.');
                    break;
                case 'oswiadczenie':
                    $a = $repo->approvedFor($trainerId, $courseId);
                    $ok = (bool)$a;
                    if ($a) {
                        $ev = [$a];
                        $detail = 'Wersja ' . $a['version'] . ($a['course_id'] ? '' : ' (ogólne)') . ', zatwierdził(a) ' . $a['decided_name'] . ' ' . substr((string)$a['decided_at'], 0, 10);
                    } else {
                        $l = $repo->latestFor($trainerId, $courseId);
                        $detail = $l ? 'Oświadczenie w statusie „' . $l['statusEnum']->label() . '”.' : 'Brak oświadczenia.';
                        if ($l) $ev = [$l];
                    }
                    break;
            }
            $out[] = ['code' => $code, 'label' => $c['label'], 'description' => $c['description'], 'required' => $c['required'], 'ok' => $ok, 'detail' => $detail, 'evidence' => $ev];
        }
        return $out;
    }
}
