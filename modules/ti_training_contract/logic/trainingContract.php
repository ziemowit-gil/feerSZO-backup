<?php
/**
 * modules/ti_training_contract/logic/trainingContract.php — „Umowa o przeprowadzenie szkolenia / zajęć dodatkowych”
 * (nr TI/UMSZ/XXX/RRRR) między Fundacją a Kursantem. Na razie TESTOWE generowanie DOCX z formularza —
 * bez zapisu w bazie i bez nadawania numeru (XXX zostaje, dopóki nie ma rejestru umów szkoleniowych).
 *
 * Treść wzoru: §1 (przedmiot umowy) — kolejne paragrafy dopisujemy w TiTrainingContract::paragraphs().
 * Formy gramatyczne (zamieszkałym/zamieszkałą, posiadającym/posiadającą, zwanym/zwaną) wg pola „plec”.
 */
class TiTrainingContract
{
    public const ORG = 'FUNDACJĄ EDUKACJI EMPATII ROZWOJU „FEER”';
    public const ORG_TAIL = ' z siedzibą w Krakowie przy ul. Zamkniętej 10/1.5 (kod pocztowy 30-554) wpisaną do rejestru stowarzyszeń '
        . 'Krajowego Rejestru Sądowego pod nr: 0000779281, posiadającą NIP 7343570539: zwaną dalej Korzystającym, '
        . 'reprezentowaną przez Ziemowita Gil – Prezesa Zarządu – dalej ';

    /** Źródła finansowania: klucz => tekst w §1. */
    public const FUNDING = [
        'wlasne'    => 'ze środków własnych Kursanta',
        'publiczne' => 'ze środków pochodzących z finansowania publicznego',
    ];

    /** Pola formularza z wartościami domyślnymi (puste = linia do ręcznego uzupełnienia na wydruku). */
    public static function defaults(): array
    {
        return ['nr' => 'TI/UMSZ/XXX/' . date('Y'), 'data' => date('Y-m-d'), 'plec' => 'K', 'imie_nazwisko' => '',
                'adres' => '', 'pesel' => '', 'temat' => '', 'finansowanie' => 'wlasne', 'zrodlo_publ' => ''];
    }

    /** Dane przykładowe do szybkiego testu. */
    public static function sample(): array
    {
        return ['plec' => 'K', 'imie_nazwisko' => 'Anna Testowa', 'adres' => 'ul. Przykładowa 1/2, 30-001 Kraków',
                'pesel' => '90010112349', 'temat' => 'Obsługa czytnika ekranu NVDA — poziom podstawowy',
                'finansowanie' => 'publiczne', 'zrodlo_publ' => 'dofinansowanie PFRON w ramach programu „Aktywny samorząd”'] + self::defaults();
    }

    /** Oczyszcza dane z formularza. Zwraca [dane, błędy]. Puste pola są dozwolone (wydruk z liniami). */
    public static function normalize(array $in): array
    {
        $d = self::defaults();
        foreach ($d as $k => $_) if (isset($in[$k])) $d[$k] = trim((string)$in[$k]);
        $d['plec'] = $d['plec'] === 'M' ? 'M' : 'K';
        if (!isset(self::FUNDING[$d['finansowanie']])) $d['finansowanie'] = 'wlasne';
        $d['pesel'] = preg_replace('/\s+/', '', $d['pesel']);
        $err = [];
        if ($d['pesel'] !== '' && !self::peselOk($d['pesel'])) $err[] = 'PESEL ma nieprawidłową sumę kontrolną albo długość.';
        if ($d['data'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['data'])) $err[] = 'Data zawarcia ma zły format.';
        if ($d['nr'] === '') $d['nr'] = self::defaults()['nr'];
        return [$d, $err];
    }

    public static function peselOk(string $p): bool
    {
        if (!preg_match('/^\d{11}$/', $p)) return false;
        $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3]; $s = 0;
        for ($i = 0; $i < 10; $i++) $s += $w[$i] * (int)$p[$i];
        return (10 - $s % 10) % 10 === (int)$p[10];
    }

    /** Wartość albo linia do wypełnienia ręcznie. */
    private static function v(string $v, int $len = 24): string
    {
        return $v !== '' ? $v : str_repeat('_', $len);
    }

    /** Akapity treści po nagłówku stron: [['h' => '§1'] | ['p' => tekst]]. */
    public static function paragraphs(array $d): array
    {
        $fund = self::FUNDING[$d['finansowanie']];
        if ($d['finansowanie'] === 'publiczne') $fund .= ', tj. ' . self::v($d['zrodlo_publ'], 40);
        return [
            ['h' => '§1'],
            ['p' => 'Kursant zleca Fundacji, a Fundacja przyjmuje do wykonania usługę polegającą na przeprowadzeniu szkolenia '
                  . 'lub kursu o tematyce: ' . self::v($d['temat'], 30) . ', finansowanego ' . $fund . '.'],
        ];
    }

    /** Buduje DOCX i zwraca jego zawartość (bajty). */
    public static function docx(array $d): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $er = error_reporting(error_reporting() & ~E_DEPRECATED);   // PHPWord sypie E_DEPRECATED na nowszym PHP — nie może trafić przed nagłówki
        $K = $d['plec'] === 'K';
        $date = $d['data'] !== '' ? date('d.m.Y', strtotime($d['data'])) : self::v('', 12);

        $w = new \PhpOffice\PhpWord\PhpWord();
        $w->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
        $w->setDefaultFontName('Arial');
        $w->setDefaultFontSize(11);
        $just = ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::BOTH, 'spaceAfter' => 160, 'lineHeight' => 1.3];
        $ctr  = ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 120];
        $s = $w->addSection(['marginLeft' => 1300, 'marginRight' => 1300, 'marginTop' => 1200, 'marginBottom' => 1200]);

        $s->addText('Umowa o przeprowadzenie szkolenia / zajęć dodatkowych', ['bold' => true, 'size' => 12], $ctr);
        $s->addText('Nr ' . $d['nr'], ['bold' => true], $ctr);
        $s->addTextBreak(1);
        $s->addText('Zawarta dnia ' . $date, [], $just);

        $p = $s->addTextRun($just);
        $p->addText(self::ORG, ['bold' => true]);
        $p->addText(self::ORG_TAIL);
        $p->addText('„Fundacją”', ['bold' => true]);
        $s->addText('a', [], $just);

        $p = $s->addTextRun($just);
        $p->addText(($K ? 'Panią ' : 'Panem ') . self::v($d['imie_nazwisko'], 28)
            . ($K ? ' zamieszkałą ' : ' zamieszkałym ') . self::v($d['adres'], 28)
            . ($K ? ' posiadającą' : ' posiadającym') . ' PESEL ' . self::v($d['pesel'], 18)
            . ($K ? ', zwaną' : ', zwanym') . ' dalej ');
        $p->addText('Kursantem', ['bold' => true]);
        $s->addTextBreak(1);

        foreach (self::paragraphs($d) as $b) {
            if (isset($b['h'])) $s->addText($b['h'], ['bold' => true], $ctr);
            else $s->addText($b['p'], [], $just);
        }

        $s->addTextBreak(3);
        $t = $s->addTable(['alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER]);
        $t->addRow();
        foreach (['Fundacja', 'Kursant'] as $who) {
            $c = $t->addCell(4500);
            $c->addText('……………………………………', [], $ctr);
            $c->addText($who, ['size' => 9], $ctr);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'umsz');
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        error_reporting($er);
        return $bytes;
    }

    /** Nazwa pliku do pobrania. */
    public static function filename(array $d): string
    {
        $who = $d['imie_nazwisko'] !== '' ? '_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $d['imie_nazwisko']) ?: '') : '';
        return 'umowa_szkolenie' . rtrim($who, '_') . '_TEST.docx';
    }
}
