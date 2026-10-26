<?php
/**
 * modules/ti_training_contract/logic/trainingContractFees.php — „Promocje i opłaty” umowy szkoleniowej SZK:
 * model naliczania opłat (ryczałt / godzinowy / raty / miesięcznie), promocja z reguł rabatowych TI (modules/ti_pricing,
 * tabela discount_rules — te same reguły co w cennikach), harmonogram płatności i ustawienia umów SZK (settings szk_*).
 *
 * Wynik trafia do umowy: dane['oplaty'] (model, kwota bazowa, promocja, harmonogram) oraz pola wynagrodzenie /
 * kwota_umowy (kwota po promocji — to ona idzie do DOCX). Podpisanej umowy nie zmieniamy.
 */
require_once __DIR__ . '/trainingContractStore.php';
require_once dirname(__DIR__, 2) . '/ti_pricing/logic/pricingEngine.php';

final class TiSzkFees
{
    public const MODELS = [
        'ryczalt'    => 'Ryczałt (jedna kwota)',
        'godzinowy'  => 'Godzinowy (stawka × liczba godzin)',
        'raty'       => 'Raty (równe, miesięczne)',
        'miesieczny' => 'Miesięcznie (stała kwota przez N miesięcy)',
    ];

    /** Ustawienia umów SZK (Ustawienia umów): domyślny model, liczba rat, termin płatności (dni), wzorzec nazwy pliku DOCX. */
    public static function settings(): array
    {
        $model = org_setting('szk_default_model');
        return [
            'model'    => isset(self::MODELS[$model]) ? $model : 'ryczalt',
            'raty'     => max(1, min(60, (int)(org_setting('szk_default_raty') ?: 3))),
            'dni'      => max(0, min(365, (int)(org_setting('szk_payment_days') ?: 14))),
            'filename' => org_setting('szk_filename_pattern') ?: 'umowa_szkoleniowa_{nr}_{nazwisko}',
            'auto'     => org_setting('szk_auto_charges') !== '0',
        ];
    }

    public static function saveSettings(array $in): void
    {
        $model = (string)($in['model'] ?? '');
        org_setting_set('szk_default_model', isset(self::MODELS[$model]) ? $model : 'ryczalt');
        org_setting_set('szk_default_raty', (string)max(1, min(60, (int)($in['raty'] ?? 3))));
        org_setting_set('szk_payment_days', (string)max(0, min(365, (int)($in['dni'] ?? 14))));
        org_setting_set('szk_auto_charges', !empty($in['auto']) ? '1' : '0');
        $pat = trim((string)($in['filename'] ?? ''));
        org_setting_set('szk_filename_pattern', preg_match('/^[A-Za-z0-9_{}\-]{3,80}$/', $pat) ? $pat : 'umowa_szkoleniowa_{nr}_{nazwisko}');
    }

    /** Kwota z formularza („1 234,50”) → grosze albo null. */
    public static function gr(string $raw): ?int
    {
        $v = ti_pricing_num($raw);
        return $v === null ? null : ti_pricing_gr($v);
    }

    public static function fmt(int $gr): string
    {
        return number_format($gr / 100, 2, ',', '');
    }

    /** Aktywne reguły rabatowe (promocje) obowiązujące dziś. */
    public static function promotions(bool $onlyCurrent = true): array
    {
        ti_pricing_migrate();
        $rows = db_all("SELECT * FROM discount_rules WHERE is_active = 1 ORDER BY priority, id");
        if (!$onlyCurrent) return $rows;
        $today = date('Y-m-d');
        return array_values(array_filter($rows, fn($r) => (empty($r['date_from']) || substr((string)$r['date_from'], 0, 10) <= $today)
                                                      && (empty($r['date_to']) || substr((string)$r['date_to'], 0, 10) >= $today)));
    }

    public static function promotionLabel(array $r): string
    {
        $v = (float)$r['discount_value'];
        return $r['name'] . ' (−' . ($r['discount_type'] === 'percentage' ? rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . '%' : number_format($v, 2, ',', '') . ' zł') . ')';
    }

    /** Kwota po promocji (grosze); nie schodzi poniżej 0. @return array{przed:int,po:int,rabat:int} */
    public static function applyPromotion(int $baseGr, ?array $rule): array
    {
        if (!$rule) return ['przed' => $baseGr, 'po' => $baseGr, 'rabat' => 0];
        $disc = $rule['discount_type'] === 'percentage' ? (int)round($baseGr * ((float)$rule['discount_value']) / 100) : ti_pricing_gr((float)$rule['discount_value']);
        $disc = max(0, min($baseGr, $disc));
        return ['przed' => $baseGr, 'po' => $baseGr - $disc, 'rabat' => $disc];
    }

    /** Harmonogram: lista [lp, termin Y-m-d, kwota gr]. Raty/miesięcznie: pierwsza w terminie, kolejne co miesiąc; ostatnia domyka grosze. */
    public static function schedule(string $model, int $totalGr, int $n, string $firstDate): array
    {
        $n = in_array($model, ['raty', 'miesieczny'], true) ? max(1, min(60, $n)) : 1;
        $base = (int)floor($totalGr / $n);
        $out = [];
        $t0 = strtotime($firstDate !== '' ? $firstDate : date('Y-m-d')) ?: time();
        for ($i = 0; $i < $n; $i++) {
            $amount = $i === $n - 1 ? $totalGr - $base * ($n - 1) : $base;
            $out[] = ['lp' => $i + 1, 'termin' => date('Y-m-d', strtotime("+{$i} month", $t0)), 'kwota' => $amount];
        }
        return $out;
    }

    /**
     * Zapis „Promocje i opłaty” w umowie. Zwraca '' albo komunikat błędu.
     * Wejście: model, kwota_bazowa | godziny + stawka, raty, termin, promocja_id.
     */
    public static function save(int $id, array $in, int $uid): string
    {
        $c = TiTrainingContractStore::get($id);
        if (!$c) return 'Nie ma takiej umowy.';
        if (TiTrainingContractStore::isSigned($c['_row']['status'])) return 'Umowa jest już podpisana — opłat nie zmieniamy (zmień status na „projekt” albo „do podpisu”).';
        $model = (string)($in['model'] ?? '');
        if (!isset(self::MODELS[$model])) return 'Wybierz model naliczania opłat.';
        $hours = null; $rate = null;
        if ($model === 'godzinowy') {
            $hours = ti_pricing_num((string)($in['godziny'] ?? ''), 10000);
            $rateGr = self::gr((string)($in['stawka'] ?? ''));
            if ($hours === null || $hours <= 0) return 'Podaj liczbę godzin (większą od 0).';
            if ($rateGr === null) return 'Podaj stawkę za godzinę (liczba ≥ 0).';
            $baseGr = (int)round($hours * $rateGr);
            $rate = $rateGr;
        } else {
            $baseGr = self::gr((string)($in['kwota_bazowa'] ?? ''));
            if ($baseGr === null) return 'Podaj kwotę bazową (liczba ≥ 0, maks. 2 miejsca po przecinku).';
        }
        $n = (int)($in['raty'] ?? 1);
        if (in_array($model, ['raty', 'miesieczny'], true) && ($n < 1 || $n > 60)) return 'Liczba rat: 1–60.';
        $first = trim((string)($in['termin'] ?? ''));
        if ($first !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $first)) return 'Nieprawidłowa data pierwszej płatności.';
        $pid = (int)($in['promocja_id'] ?? 0);
        $rule = null;
        if ($pid > 0) {
            ti_pricing_migrate();
            $rule = db_one("SELECT * FROM discount_rules WHERE id = ? AND is_active = 1", [$pid]);
            if (!$rule) return 'Wybrana promocja nie istnieje albo jest nieaktywna.';
        }
        $calc = self::applyPromotion($baseGr, $rule);
        $d = $c;
        unset($d['_row'], $d['nr']);
        $d['oplaty'] = [
            'model' => $model, 'kwota_bazowa' => $calc['przed'], 'godziny' => $hours, 'stawka' => $rate, 'raty' => $n, 'termin' => $first,
            'promocja' => $rule ? ['id' => (int)$rule['id'], 'nazwa' => $rule['name'], 'typ' => $rule['discount_type'], 'wartosc' => (float)$rule['discount_value'], 'rabat' => $calc['rabat']] : null,
            'kwota_po' => $calc['po'], 'harmonogram' => self::schedule($model, $calc['po'], $n, $first),
        ];
        $d['wynagrodzenie'] = self::fmt($calc['po']);
        if (($d['finansowanie'] ?? '') === 'wlasne') $d['kwota_kursant'] = $d['wynagrodzenie'];
        db_update(TiTrainingContractStore::TABLE, [
            'dane' => json_encode($d, JSON_UNESCAPED_UNICODE), 'kwota_umowy' => $d['wynagrodzenie'], 'model_naliczania' => $model,
            'promocja_id' => $pid > 0 ? $pid : null, 'updated_at' => date('Y-m-d H:i:s'),
        ], $id);
        if (function_exists('log_contract_action')) {
            log_contract_action('szk', $id, $uid, 'update', 'Opłaty: ' . self::MODELS[$model] . ', ' . self::fmt($calc['po']) . ' zł' . ($rule ? ', promocja „' . $rule['name'] . '” (−' . self::fmt($calc['rabat']) . ' zł)' : ''));
        }
        return '';
    }
}
