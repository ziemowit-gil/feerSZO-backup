<?php
/**
 * includes/invoice_pdf.php — generowanie PDF faktury (mPDF).
 *
 * Jeden szablon dla wszystkich źródeł — oferty CRM, rozliczenia TI i faktur
 * ręcznych. Dokument wystawiony w Fakturowni ma tam własny PDF; ten generator
 * służy fakturom wystawianym samodzielnie oraz podglądowi szkicu.
 *
 * Układ zgodny z wymogami art. 106e ustawy o VAT: strony transakcji z NIP-ami,
 * daty wystawienia i sprzedaży, pozycje z ceną netto i stawką, zestawienie VAT
 * w rozbiciu na stawki, kwota do zapłaty słownie oraz sposób i termin zapłaty.
 *
 * Wymaga: db.php, functions.php, invoices.php, vendor/autoload.php (mPDF)
 */

declare(strict_types=1);

require_once __DIR__ . '/invoices.php';

// ── Kwota słownie ────────────────────────────────────────────────────────────

/**
 * Liczba całkowita słownie (po polsku). Do 999 999 999 — wystarczy dla faktur.
 */
function invoice_words_int(int $n): string
{
    if ($n === 0) return 'zero';

    $ones  = ['', 'jeden', 'dwa', 'trzy', 'cztery', 'pięć', 'sześć', 'siedem', 'osiem', 'dziewięć'];
    $teens = ['dziesięć', 'jedenaście', 'dwanaście', 'trzynaście', 'czternaście',
              'piętnaście', 'szesnaście', 'siedemnaście', 'osiemnaście', 'dziewiętnaście'];
    $tens  = ['', '', 'dwadzieścia', 'trzydzieści', 'czterdzieści', 'pięćdziesiąt',
              'sześćdziesiąt', 'siedemdziesiąt', 'osiemdziesiąt', 'dziewięćdziesiąt'];
    $huns  = ['', 'sto', 'dwieście', 'trzysta', 'czterysta', 'pięćset',
              'sześćset', 'siedemset', 'osiemset', 'dziewięćset'];

    // Formy odmiany dla tysięcy i milionów: [1, 2–4, 5+]
    $groups = [
        ['', '', ''],
        ['tysiąc', 'tysiące', 'tysięcy'],
        ['milion', 'miliony', 'milionów'],
    ];

    /** Grupa trzycyfrowa słownie. */
    $triple = function (int $v) use ($ones, $teens, $tens, $huns): string {
        $out = [];
        $h = intdiv($v, 100);
        $t = intdiv($v % 100, 10);
        $u = $v % 10;
        if ($h) $out[] = $huns[$h];
        if ($t === 1) {
            $out[] = $teens[$u];
        } else {
            if ($t) $out[] = $tens[$t];
            if ($u) $out[] = $ones[$u];
        }
        return implode(' ', $out);
    };

    /** Właściwa forma nazwy grupy dla liczebnika. */
    $form = function (int $v, array $f): string {
        if ($f[0] === '') return '';
        if ($v === 1) return $f[0];
        $t = $v % 100;
        $u = $v % 10;
        if ($u >= 2 && $u <= 4 && !($t >= 12 && $t <= 14)) return $f[1];
        return $f[2];
    };

    $parts = [];
    for ($g = 2; $g >= 0; $g--) {
        $v = intdiv($n, (int)(10 ** ($g * 3))) % 1000;
        if ($v === 0) continue;
        // „jeden tysiąc" brzmi źle — mówimy po prostu „tysiąc".
        $num = ($v === 1 && $g > 0) ? '' : $triple($v);
        $parts[] = trim($num . ' ' . $form($v, $groups[$g]));
    }
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

/** Kwota słownie w formacie „sto dwadzieścia trzy złote 45/100". */
function invoice_amount_words(float $amount, string $currency = 'PLN'): string
{
    $amount = round($amount, 2);
    $int    = (int)floor($amount);
    $cents  = (int)round(($amount - $int) * 100);

    $unit = 'złotych';
    if ($currency === 'PLN') {
        $t = $int % 100; $u = $int % 10;
        if ($int === 1) $unit = 'złoty';
        elseif ($u >= 2 && $u <= 4 && !($t >= 12 && $t <= 14)) $unit = 'złote';
    } else {
        $unit = $currency;
    }

    return invoice_words_int($int) . ' ' . $unit . ' ' . str_pad((string)$cents, 2, '0', STR_PAD_LEFT) . '/100';
}

// ── Dane sprzedawcy ──────────────────────────────────────────────────────────

/** Dane wystawcy z ustawień organizacji + pierwszy rachunek bankowy. */
function invoice_seller(string $currency = 'PLN'): array
{
    // Rachunek dobieramy po PRZEZNACZENIU, nie „pierwszy z listy": faktura dotyczy
    // działalności odpłatnej (szkolenia, zajęcia), więc rachunek opisany jako
    // szkoleniowy/odpłatny musi wygrać z rachunkiem dotacyjnym. Ten sam helper
    // co oferty CRM, żeby na fakturze i na ofercie był ten sam numer.
    $accounts = [];
    try {
        require_once __DIR__ . '/crm_offers.php';
        if (function_exists('crm_offer_bank_accounts')) {
            foreach (crm_offer_bank_accounts('odplatna', $currency) as $a) {
                $accounts[] = ['nrb' => (string)$a['nrb'], 'bank' => (string)($a['bank'] ?? ''), 'opis' => (string)($a['opis'] ?? '')];
            }
        }
    } catch (\Throwable $e) { $accounts = []; }

    // Zapas: surowa lista z ustawień, gdy helper niedostępny albo nic nie dopasował.
    if (!$accounts) {
        $raw = org_setting('org_rachunki_bankowe');
        foreach (($raw ? (json_decode($raw, true) ?: []) : []) as $a) {
            if (!is_array($a) || empty($a['nrb'])) continue;
            $accounts[] = [
                'nrb'  => (string)$a['nrb'],
                'bank' => (string)($a['bank'] ?? ''),
                'opis' => (string)($a['opis'] ?? ''),
            ];
        }
    }

    // Konto oznaczone „dla TI" (ustawienia organizacji → Rachunki) idzie na
    // pierwsze miejsce — faktury wystawiamy z modułu TI i na dokumencie ma być
    // ten sam numer, który kursant widzi w danych do wpłat i w portfelu.
    try {
        $ti_raw = org_setting('org_rachunki_bankowe');
        foreach (($ti_raw ? (json_decode($ti_raw, true) ?: []) : []) as $a) {
            if (!is_array($a) || empty($a['nrb']) || empty($a['dla_ti'])) continue;
            usort($accounts, fn($x, $y) => (int)($y['nrb'] === $a['nrb']) <=> (int)($x['nrb'] === $a['nrb']));
            break;
        }
    } catch (\Throwable $e) {}

    $logo_f = trim((string)org_setting('org_logo'));
    $logo   = $logo_f !== '' ? dirname(__DIR__) . '/assets/logo/' . $logo_f : '';
    if ($logo !== '' && !is_file($logo)) $logo = '';

    return [
        'name'   => org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''),
        'adres'  => org_setting('org_adres'),
        'nip'    => org_setting('org_nip'),
        'regon'  => org_setting('org_regon'),
        'krs'    => org_setting('org_krs'),
        'miasto' => org_setting('org_miejscowosc'),
        'email'  => org_setting('org_email'),
        'tel'    => org_setting('org_telefon'),
        'www'    => org_setting('org_www'),
        'logo'   => $logo,
        'accounts' => $accounts,
    ];
}

/** NRB w czytelnym zapisie grup po 4 znaki. */
function invoice_iban_fmt(string $nrb): string
{
    $n = preg_replace('/\s+/', '', $nrb) ?? '';
    if ($n === '') return '';
    if (!str_starts_with(strtoupper($n), 'PL') && strlen($n) === 26) $n = 'PL' . $n;
    return trim(chunk_split($n, 4, ' '));
}

// ── HTML szablonu ────────────────────────────────────────────────────────────

/** Zestawienie VAT w rozbiciu na stawki. */
function invoice_vat_summary(array $items): array
{
    $sum = [];
    foreach ($items as $it) {
        $rate = (string)$it['vat_rate'];
        $sum[$rate] ??= ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
        $sum[$rate]['net']   += (float)$it['line_net'];
        $sum[$rate]['vat']   += (float)$it['line_vat'];
        $sum[$rate]['gross'] += (float)$it['line_gross'];
    }
    krsort($sum, SORT_NATURAL);
    return $sum;
}

/**
 * HTML faktury do przekazania mPDF.
 *
 * @param array $inv   Rekord z invoice_get() (z pozycjami).
 * @param array $opts  ['duplikat'=>bool, 'kopia'=>bool] — adnotacje na dokumencie.
 */
function invoice_pdf_html(array $inv, array $opts = []): string
{
    $s     = invoice_seller((string)($inv['currency'] ?: 'PLN'));
    $items = $inv['items'] ?? [];

    // Fakturę wystawia zawsze konkretny użytkownik — imiennie na dokumencie,
    // żeby było wiadomo, kto ją sporządził (created_by z chwili utworzenia).
    $issuer = '';
    if (!empty($inv['created_by'])) {
        $u = db_one("SELECT name, first_name, last_name FROM users WHERE id=?", [(int)$inv['created_by']]);
        if ($u) {
            $issuer = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            if ($issuer === '') $issuer = trim((string)($u['name'] ?? ''));
        }
    }
    $vat   = invoice_vat_summary($items);
    $cur   = (string)$inv['currency'];

    $m  = fn(float $v) => number_format($v, 2, ',', ' ');
    $d  = fn(?string $v) => $v ? date('d.m.Y', strtotime($v)) : '—';
    $kind_label = [
        'vat'      => 'Faktura VAT',
        'proforma' => 'Faktura proforma',
        'bill'     => 'Rachunek',
    ][$inv['kind']] ?? 'Faktura';

    // Szkic z TI pokazuje numer poglądowy z serii TI/nr/mm/rok — definitywny
    // nadawany jest przy wystawieniu (patrz invoice_ti_number).
    $shown_number = (string)($inv['number'] ?? '');
    $number_note  = '';
    if ($shown_number === '' && ($inv['source'] ?? '') === 'ti_billing' && function_exists('invoice_ti_number_preview')) {
        $prev = invoice_ti_number_preview($inv);
        if ($prev !== '') { $shown_number = $prev; $number_note = ' (numer poglądowy)'; }
    }
    $title = $kind_label . ' nr ' . ($shown_number ?: 'szkic/' . (int)$inv['id']);
    $is_draft = ($inv['status'] ?? '') === 'szkic' || ($inv['status'] ?? '') === 'blad';

    $annot = [];
    if (!empty($opts['duplikat'])) $annot[] = 'DUPLIKAT (' . date('d.m.Y') . ')';
    if (!empty($opts['kopia']))    $annot[] = 'KOPIA';

    ob_start(); ?>
<style>
  /* mPDF nie obsługuje flexboksa ani grida — układ opiera się na tabelach.
     Typografia nastawiona na CZYTELNOŚĆ wydruku, nie na upchanie treści:
     większy stopień pisma, więcej światła w komórkach, mniej linii. */
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #111; line-height: 1.35; }

  .hdr-title { font-size: 17pt; font-weight: bold; letter-spacing: -.01em; }
  .hdr-sub   { font-size: 9pt; color: #333; line-height: 1.45; }
  .annot     { font-size: 9pt; font-weight: bold; color: #92400e;
               border: 1px solid #d97706; background: #fef9c3; padding: 4px 7px; }

  table      { width: 100%; border-collapse: collapse; }

  .party td  { vertical-align: top; padding: 0; }
  /* Strony transakcji BEZ szarego tła — dane na białym, pełną czernią.
     Za strukturę odpowiada wyłącznie cienki pasek z boku. */
  .party-box { border-left: 2px solid #333; padding: 2px 0 2px 9px; }
  .party-lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .06em;
               color: #333; font-weight: bold; margin-bottom: 3px; }
  .party-nm  { font-weight: bold; font-size: 11.5pt; line-height: 1.25; color: #000; }
  .party-box div { font-size: 9.5pt; color: #111; }

  /* Tabela pozycji: nagłówek na ciemnym tle, wiersze rozdzielone poziomą linią,
     bez siatki pionowej — oko prowadzi wiersz, nie kratka. */
  .items th  { background: #333; color: #fff; padding: 6px 6px;
               font-size: 8.5pt; text-align: left; font-weight: bold; }
  .items td  { border-bottom: 1px solid #ddd; padding: 6px 6px; font-size: 9.5pt;
               vertical-align: top; }
  .items tbody tr:nth-child(even) td { background: #fafafa; }
  .items tfoot th, .items tfoot td { border-top: 2px solid #333; }

  .num       { text-align: right; }
  .ctr       { text-align: center; }
  /* Cyfry o równej szerokości — kolumny kwot układają się w słup. */
  .num, .money { font-variant-numeric: tabular-nums; }

  .sum th, .sum td { border-bottom: 1px solid #ddd; padding: 6px 8px; font-size: 9.5pt; }
  .sum th    { background: #f0f1f3; text-align: left; font-weight: bold; }
  .total     { font-size: 13pt; font-weight: bold; }

  .sign      { border-top: 1px solid #888; padding-top: 4px; font-size: 8.5pt;
               color: #555; text-align: center; }
  .note      { font-size: 9pt; color: #333; line-height: 1.4; }
  .draft-bar { border-left: 4px solid #b3261e; background: #fef2f2; color: #7f1d1d;
               padding: 6px 10px; margin-bottom: 10px;
               font-size: 10.5pt; font-weight: bold; letter-spacing: .02em; }
  .draft-bar-sub { display: block; font-size: 8.5pt; font-weight: normal;
                   letter-spacing: 0; margin-top: 2px; line-height: 1.35; }
</style>

<?php if ($is_draft): ?>
<!-- Dokument roboczy: musi być nie do pomylenia z fakturą wystawioną.
     Poza tym paskiem render dokłada znak wodny (invoice_pdf_render). -->
<div class="draft-bar">
  FAKTURA ROBOCZA — NIE WYSTAWIONA<br>
  <span class="draft-bar-sub">
    Dokument nie został wystawiony w KSeF ani w systemie księgowym. Nie jest dowodem
    księgowym ani podstawą do zapłaty — służy wyłącznie do podglądu wydruku.
  </span>
</div>
<?php endif; ?>

<table>
  <tr>
    <td style="width:58%">
      <?php if ($s['logo'] !== ''): ?>
      <img src="<?= h($s['logo']) ?>" alt="" style="max-height:38px;margin-bottom:5px">
      <?php endif; ?>
      <div class="hdr-title"><?= h($title) ?></div>
      <?php if ($number_note !== ''): ?>
      <div class="hdr-sub" style="color:#92400e"><?= h(trim($number_note, ' ()')) ?></div>
      <?php endif; ?>
      <div class="hdr-sub">
        Miejsce wystawienia: <?= h($s['miasto'] ?: '—') ?><br>
        Data wystawienia: <strong><?= h($d($inv['issue_date'])) ?></strong><br>
        Data sprzedaży: <strong><?= h($d($inv['sell_date'])) ?></strong>
      </div>
    </td>
    <td style="width:42%; text-align:right">
      <?php foreach ($annot as $a): ?>
      <div class="annot" style="margin-bottom:3px"><?= h($a) ?></div>
      <?php endforeach; ?>
    </td>
  </tr>
</table>

<table class="party" style="margin-top:14px">
  <tr>
    <td style="width:49%">
      <div class="party-box">
        <div class="party-lbl">Sprzedawca</div>
        <div class="party-nm"><?= h($s['name']) ?></div>
        <?php if ($s['adres']): ?><div><?= nl2br(h($s['adres'])) ?></div><?php endif; ?>
        <?php if ($s['nip']):   ?><div>NIP: <?= h($s['nip']) ?></div><?php endif; ?>
        <?php if ($s['regon']): ?><div>REGON: <?= h($s['regon']) ?></div><?php endif; ?>
        <?php if ($s['krs']):   ?><div>KRS: <?= h($s['krs']) ?></div><?php endif; ?>
        <?php if ($s['email'] || $s['tel'] || $s['www']): ?>
        <div style="margin-top:2px">
          <?= h(implode(' · ', array_filter([$s['email'], $s['tel'], $s['www']]))) ?>
        </div>
        <?php endif; ?>
      </div>
    </td>
    <td style="width:2%"></td>
    <td style="width:49%">
      <div class="party-box">
        <div class="party-lbl">Nabywca</div>
        <div class="party-nm"><?= h($inv['buyer_name']) ?></div>
        <?php if ($inv['buyer_street']): ?><div><?= h($inv['buyer_street']) ?></div><?php endif; ?>
        <?php if ($inv['buyer_post_code'] || $inv['buyer_city']): ?>
        <div><?= h(trim($inv['buyer_post_code'] . ' ' . $inv['buyer_city'])) ?></div>
        <?php endif; ?>
        <?php if ($inv['buyer_tax_no']): ?><div>NIP: <?= h($inv['buyer_tax_no']) ?></div><?php endif; ?>
      </div>
    </td>
  </tr>
</table>

<table class="items" style="margin-top:16px">
  <thead>
    <tr>
      <th style="width:4%"  class="ctr">Lp.</th>
      <th style="width:34%">Nazwa towaru / usługi</th>
      <th style="width:7%"  class="ctr">J.m.</th>
      <th style="width:8%"  class="num">Ilość</th>
      <th style="width:11%" class="num">Cena netto</th>
      <th style="width:11%" class="num">Wartość netto</th>
      <th style="width:6%"  class="ctr">VAT</th>
      <th style="width:9%"  class="num">Kwota VAT</th>
      <th style="width:10%" class="num">Wartość brutto</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $i => $it):
      $qty = rtrim(rtrim(number_format((float)$it['qty'], 3, ',', ' '), '0'), ',');
  ?>
    <tr>
      <td class="ctr"><?= $i + 1 ?></td>
      <td><?= h($it['name']) ?></td>
      <td class="ctr"><?= h($it['unit']) ?></td>
      <td class="num"><?= h($qty) ?></td>
      <td class="num"><?= $m((float)$it['unit_net']) ?></td>
      <td class="num"><?= $m((float)$it['line_net']) ?></td>
      <td class="ctr"><?= h($it['vat_rate']) ?><?= is_numeric($it['vat_rate']) ? '%' : '' ?></td>
      <td class="num"><?= $m((float)$it['line_vat']) ?></td>
      <td class="num"><?= $m((float)$it['line_gross']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<table style="margin-top:10px">
  <tr>
    <td style="width:52%; vertical-align:top">
      <!-- Zestawienie w rozbiciu na stawki — wymóg art. 106e ust. 1 pkt 14 -->
      <table class="sum">
        <tr>
          <th>Stawka</th><th class="num">Netto</th><th class="num">VAT</th><th class="num">Brutto</th>
        </tr>
        <?php foreach ($vat as $rate => $v): ?>
        <tr>
          <td><?= h($rate) ?><?= is_numeric($rate) ? '%' : '' ?></td>
          <td class="num"><?= $m($v['net']) ?></td>
          <td class="num"><?= $m($v['vat']) ?></td>
          <td class="num"><?= $m($v['gross']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
          <th>Razem</th>
          <th class="num"><?= $m((float)$inv['total_net']) ?></th>
          <th class="num"><?= $m((float)$inv['total_vat']) ?></th>
          <th class="num"><?= $m((float)$inv['total_gross']) ?></th>
        </tr>
      </table>
    </td>
    <td style="width:4%"></td>
    <td style="width:44%; vertical-align:top">
      <table class="sum">
        <tr>
          <th style="width:45%">Do zapłaty</th>
          <td class="num total"><?= $m((float)$inv['total_gross']) ?> <?= h($cur) ?></td>
        </tr>
        <tr>
          <th>Termin zapłaty</th>
          <td class="num"><?= h($d($inv['payment_to'])) ?></td>
        </tr>
        <tr>
          <th>Sposób zapłaty</th>
          <td class="num">przelew</td>
        </tr>
      </table>
      <div class="note" style="margin-top:6px">
        Słownie: <strong><?= h(invoice_amount_words((float)$inv['total_gross'], $cur)) ?></strong>
      </div>
    </td>
  </tr>
</table>

<!-- Blok płatności: rachunek + tytuł przelewu. Tytułem jest numer faktury —
     bez tego wpłaty trzeba wiązać z fakturami ręcznie. -->
<table class="sum" style="margin-top:10px">
  <tr>
    <th style="width:24%">Numer rachunku</th>
    <td>
      <?php if ($s['accounts']): $a = $s['accounts'][0]; ?>
        <strong style="font-size:9.5pt"><?= h(invoice_iban_fmt($a['nrb'])) ?></strong>
        <?= $a['bank'] ? ' <span style="color:#555">— ' . h($a['bank']) . '</span>' : '' ?>
        <?php if (trim((string)$a['opis']) !== ''): ?>
        <div style="color:#555;font-size:7.5pt"><?= h($a['opis']) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <span style="color:#555">— nie ustawiono rachunku bankowego organizacji —</span>
      <?php endif; ?>
    </td>
  </tr>
  <tr>
    <th>Tytuł przelewu</th>
    <td>
      <?php if ($inv['number']): ?>
        <strong><?= h($inv['number']) ?></strong>
      <?php elseif ($shown_number !== ''): ?>
        <strong><?= h($shown_number) ?></strong>
        <span style="color:#555">— numer poglądowy, definitywny po wystawieniu</span>
      <?php else: ?>
        <span style="color:#555">numer faktury (zostanie nadany przy wystawieniu)</span>
      <?php endif; ?>
    </td>
  </tr>
</table>

<?php
  // Faktura ze stawką zwolnioną musi wskazywać podstawę prawną zwolnienia
  // (art. 106e ust. 1 pkt 19 ustawy o VAT).
  $has_zw = false;
  foreach (array_keys($vat) as $r) if (!is_numeric($r)) { $has_zw = true; break; }
?>
<?php if ($has_zw): ?>
<div class="note" style="margin-top:8px">
  <strong>Podstawa zwolnienia z VAT:</strong> <?= h(invoices_config()['zw_basis']) ?>
</div>
<?php endif; ?>

<?php if (!empty($inv['notes'])): ?>
<div class="note" style="margin-top:8px"><strong>Uwagi:</strong> <?= nl2br(h($inv['notes'])) ?></div>
<?php endif; ?>

<table style="margin-top:34px">
  <tr>
    <td style="width:42%">
      <?php if ($issuer !== ''): ?>
      <div style="text-align:center; font-size:9pt; padding-bottom:2px"><?= h($issuer) ?></div>
      <?php endif; ?>
      <div class="sign">Wystawił<?= $issuer !== '' ? '' : ' — osoba upoważniona' ?></div>
    </td>
    <td style="width:16%"></td>
    <td style="width:42%"><div class="sign">Podpis osoby upoważnionej do odbioru</div></td>
  </tr>
</table>
<?php
    return (string)ob_get_clean();
}

// ── Załącznik: rozliczenie środków (TI) ──────────────────────────────────────

/**
 * Rozliczenie środków kursanta TI — co wpłacone, na co zaksięgowane, ile zostało.
 *
 * Faktura mówi tylko o jednym okresie; ten załącznik pokazuje pełny obraz konta:
 * wpłaty (także ze Stripe/PayU), alokację FIFO na poszczególne należności oraz
 * nadpłatę i niedopłatę — ogólną i per grupa (model kombinowany).
 * Liczby pochodzą z ti_client_allocation(), tej samej funkcji co panel — dokument
 * nie może pokazywać innego salda niż system.
 *
 * @return string HTML albo '' gdy nie ma czego pokazać.
 */
function invoice_pdf_ti_settlement(int $client_id): string
{
    if ($client_id <= 0) return '';

    $file = __DIR__ . '/ti_payments.php';
    if (!is_file($file)) return '';
    require_once $file;
    if (!function_exists('ti_client_allocation')) return '';

    try {
        $al = ti_client_allocation($client_id);
    } catch (\Throwable $e) {
        return '';
    }
    if (empty($al['rows']) && empty($al['payments'])) return '';

    // Nazwy grup i okresy należności — bez nich tabela jest listą liczb bez znaczenia.
    $courses = [];
    foreach (db_all("SELECT id, name FROM k30_ti_courses") as $c) $courses[(int)$c['id']] = (string)$c['name'];

    $periods = [];
    foreach (db_all("SELECT id, month, year FROM k30_ti_billing WHERE client_id=?", [$client_id]) as $b) {
        $periods[(int)$b['id']] = str_pad((string)(int)$b['month'], 2, '0', STR_PAD_LEFT) . '/' . (int)$b['year'];
    }

    $payments = db_all(
        "SELECT amount, paid_at, method, note, COALESCE(course_id,0) AS course_id
           FROM k30_ti_payments WHERE client_id=? ORDER BY paid_at, id",
        [$client_id]
    );

    $m       = fn(float $v) => number_format($v, 2, ',', ' ');
    $methods = ['transfer' => 'przelew', 'cash' => 'gotówka', 'stripe' => 'Stripe',
                'payu' => 'PayU', 'other' => 'inna'];

    ob_start(); ?>
<div class="party-lbl" style="margin-top:14px">Rozliczenie środków</div>
<div class="note" style="margin-bottom:6px">
  Stan konta kursanta na <?= h(date('d.m.Y')) ?> — informacyjnie.
</div>

<table class="sum">
  <tr>
    <th style="width:34%">Wpłaty ogółem</th><td class="num"><?= $m((float)$al['payments']) ?></td>
    <th style="width:34%">Należności ogółem</th><td class="num"><?= $m((float)$al['charges']) ?></td>
  </tr>
  <tr>
    <th>Zaksięgowane na należności</th><td class="num"><?= $m((float)$al['paid']) ?></td>
    <th>Niedopłata</th>
    <td class="num"<?= (float)$al['debt'] > 0 ? ' style="color:#b3261e;font-weight:bold"' : '' ?>>
      <?= $m((float)$al['debt']) ?>
    </td>
  </tr>
  <tr>
    <th>Nadpłata ogólna</th><td class="num"><?= $m((float)($al['general_credit'] ?? 0)) ?></td>
    <th>Nadpłata przypisana do grup</th><td class="num"><?= $m((float)($al['group_credit'] ?? 0)) ?></td>
  </tr>
</table>
<div class="note" style="margin-top:5px">
  Nadpłata pozostaje na koncie i jest automatycznie zaliczana na poczet kolejnych zajęć.
  Nadpłata znaczona na grupę pokrywa wyłącznie należności tej grupy.
</div>

<?php if (!empty($al['groups'])): ?>
<div class="party-lbl" style="margin-top:12px">Rozliczenie w podziale na grupy</div>
<table class="items" style="margin-top:3px">
  <thead>
    <tr>
      <th>Grupa</th>
      <th class="num" style="width:15%">Należności</th>
      <th class="num" style="width:15%">Wpłaty</th>
      <th class="num" style="width:15%">Pokryte</th>
      <th class="num" style="width:14%">Niedopłata</th>
      <th class="num" style="width:14%">Nadpłata</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($al['groups'] as $cid => $g): ?>
    <tr>
      <td><?= h($cid ? ($courses[(int)$cid] ?? ('grupa #' . (int)$cid)) : 'Bez przypisania do grupy') ?></td>
      <td class="num"><?= $m((float)$g['charges']) ?></td>
      <td class="num"><?= $m((float)$g['payments']) ?></td>
      <td class="num"><?= $m((float)$g['paid']) ?></td>
      <td class="num"><?= $m((float)$g['debt']) ?></td>
      <td class="num"><?= $m((float)$g['credit']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if (!empty($al['rows'])): ?>
<div class="party-lbl" style="margin-top:12px">Należności i ich pokrycie</div>
<table class="items" style="margin-top:3px">
  <thead>
    <tr>
      <th style="width:16%">Okres</th>
      <th>Grupa</th>
      <th class="num" style="width:16%">Do zapłaty</th>
      <th class="num" style="width:16%">Zapłacone</th>
      <th class="ctr" style="width:16%">Stan</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($al['rows'] as $r):
      $due  = (float)$r['due'];
      $paid = (float)$r['paid'];
      $stan = $paid + 0.005 >= $due ? 'opłacone' : ($paid > 0 ? 'częściowo' : 'nieopłacone');
  ?>
    <tr>
      <td><?= h($periods[(int)$r['id']] ?? ('#' . (int)$r['id'])) ?></td>
      <td><?= h((int)$r['course_id'] ? ($courses[(int)$r['course_id']] ?? ('grupa #' . (int)$r['course_id'])) : '—') ?></td>
      <td class="num"><?= $m($due) ?></td>
      <td class="num"><?= $m($paid) ?></td>
      <td class="ctr"><?= h($stan) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php if ($payments): ?>
<div class="party-lbl" style="margin-top:12px">Wpłaty</div>
<table class="items" style="margin-top:3px">
  <thead>
    <tr>
      <th style="width:16%">Data</th>
      <th class="num" style="width:16%">Kwota</th>
      <th style="width:16%">Forma</th>
      <th style="width:22%">Zaksięgowana na</th>
      <th>Uwagi</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($payments as $pmt): ?>
    <tr>
      <td><?= h($pmt['paid_at'] ? date('d.m.Y', strtotime((string)$pmt['paid_at'])) : '—') ?></td>
      <td class="num"><?= $m((float)$pmt['amount']) ?></td>
      <td><?= h($methods[(string)$pmt['method']] ?? (string)$pmt['method']) ?></td>
      <td><?= h((int)$pmt['course_id'] ? ($courses[(int)$pmt['course_id']] ?? ('grupa #' . (int)$pmt['course_id'])) : 'konto (ogólnie)') ?></td>
      <td><?= h((string)$pmt['note']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}

/**
 * Wykaz lekcji kursanta w okresie rozliczeniowym — data, grupa, czas, godziny
 * do rozliczenia i kwota za lekcję.
 *
 * Godziny liczymy DOKŁADNIE jak k30_ti_calculate_billing(): tylko lekcje ze
 * statusem odbytym ('held','individual_change','remote_material'), z obecnością
 * i nieodwołane, a czas zaokrąglany w górę do pełnych godzin PER LEKCJA.
 * Kwota per lekcja ma sens tylko w modelu godzinowym — przy modelu miesięcznym
 * czy stałym opłata nie zależy od liczby lekcji, więc pokazujemy ją osobno.
 *
 * @return string HTML albo '' gdy nie ma lekcji.
 */
function invoice_pdf_ti_lessons(int $client_id, int $month, int $year, int $course_id = 0): string
{
    if ($client_id <= 0 || $month < 1 || $month > 12) return '';

    $file = dirname(__DIR__) . '/includes/karty30.php';
    if (!function_exists('k30_ti_calculate_billing')) {
        if (!is_file($file)) return '';
        require_once $file;
    }
    if (!function_exists('k30_ti_calculate_billing')) return '';

    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));

    try {
        // Filtr grupy MUSI trafić do kalkulatora: w modelu kombinowanym rozliczenie
        // dotyczy jednej grupy, a bez filtra wykaz pokazałby lekcje ze wszystkich.
        $calc = k30_ti_calculate_billing($client_id, $month, $year, $course_id);
    } catch (\Throwable $e) {
        return '';
    }

    // Stawka i model per kurs — z rozbicia zwróconego przez kalkulator.
    $per_course = [];
    foreach (($calc['courses'] ?? []) as $c) $per_course[(int)$c['course_id']] = $c;

    $rates = [];
    foreach (db_all("SELECT course_id, hourly_rate FROM k30_ti_enrollments WHERE client_id=?", [$client_id]) as $e) {
        $rates[(int)$e['course_id']] = (float)$e['hourly_rate'];
    }

    $params = [$client_id, $from, $to];
    $course_sql = '';
    if ($course_id > 0) { $course_sql = ' AND s.course_id = ?'; $params[] = $course_id; }

    $lessons = db_all(
        "SELECT s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status,
                s.course_id, co.name AS course_name,
                COALESCE(a.attended,0) AS attended,
                COALESCE(a.no_show,0) AS no_show, COALESCE(a.no_show_billing,'') AS no_show_billing
           FROM k30_ti_attendance a
           JOIN k30_ti_sessions   s  ON s.id = a.session_id
           JOIN k30_ti_courses    co ON co.id = s.course_id
          WHERE a.client_id = ?
            AND s.status IN ('held','individual_change','remote_material')
            AND s.lesson_date BETWEEN ? AND ?
            AND COALESCE(a.cancelled,0) = 0 AND (a.attended = 1 OR COALESCE(a.no_show,0) = 1)
            {$course_sql}
       ORDER BY s.lesson_date, s.time_from",
        $params
    );
    if (!$lessons) return '';

    $m       = fn(float $v) => number_format($v, 2, ',', ' ');
    $okres   = str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '/' . $year;
    $sum_h   = 0.0;
    $sum_amt = 0.0;

    ob_start(); ?>
<div class="party-lbl" style="margin-top:12px">Wykaz lekcji — okres <?= h($okres) ?></div>
<table class="items" style="margin-top:3px">
  <thead>
    <tr>
      <th style="width:13%">Data</th>
      <th style="width:13%">Godziny</th>
      <th>Grupa</th>
      <th class="num" style="width:11%">Do rozliczenia</th>
      <th class="num" style="width:12%">Stawka</th>
      <th class="num" style="width:13%">Kwota</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($lessons as $l):
      $cid   = (int)$l['course_id'];
      $model = (int)($per_course[$cid]['model'] ?? 2);
      // No-show rozliczany wg modelu: pełna lekcja albo 1 godzina. Obecność ma
      // pierwszeństwo — wiersz obecny+no_show (dane sprzed poprawki) to zwykła lekcja.
      $l['no_show'] = !empty($l['no_show']) && empty($l['attended']);
      $h = $l['no_show'] && $l['no_show_billing'] === '1h'
          ? 1.0
          : (float)ceil((int)$l['duration_min'] / 60);
      $hourly = $model !== 1 && $model !== 3;
      $rate   = $rates[$cid] ?? 0.0;
      $amt    = $hourly ? $h * $rate : 0.0;
      $sum_h   += $h;
      $sum_amt += $amt;
      $czas = trim((string)$l['time_from']) !== ''
          ? substr((string)$l['time_from'], 0, 5) . (trim((string)$l['time_to']) !== '' ? '–' . substr((string)$l['time_to'], 0, 5) : '')
          : '—';
  ?>
    <tr>
      <td><?= h(date('d.m.Y', strtotime((string)$l['lesson_date']))) ?></td>
      <td><?= h($czas) ?></td>
      <td>
        <?= h((string)$l['course_name']) ?>
        <?php if (!empty($l['no_show'])): ?><span style="color:#b3261e"> (nieobecność płatna)</span><?php endif; ?>
        <?php if ($l['status'] === 'remote_material'): ?><span style="color:#555"> (praca własna)</span><?php endif; ?>
      </td>
      <td class="num"><?= h(rtrim(rtrim(number_format($h, 2, ',', ' '), '0'), ',')) ?> godz.</td>
      <td class="num"><?= $hourly ? $m($rate) : '—' ?></td>
      <td class="num"><?= $hourly ? $m($amt) : 'w opłacie stałej' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot class="table-light">
    <tr>
      <th colspan="3" class="num">Razem</th>
      <th class="num"><?= h(rtrim(rtrim(number_format($sum_h, 2, ',', ' '), '0'), ',')) ?> godz.</th>
      <th></th>
      <th class="num"><?= $m($sum_amt) ?></th>
    </tr>
  </tfoot>
</table>
<div class="note" style="margin-top:4px">
  Czas każdej lekcji zaokrąglany w górę do pełnych godzin — zgodnie z zasadą rozliczania zajęć.
  <?php foreach (($calc['courses'] ?? []) as $c): if ((int)$c['model'] === 1 || (int)$c['model'] === 3): ?>
  <br>Grupa <strong><?= h((string)$c['course_name']) ?></strong>: opłata stała
  <strong><?= $m((float)$c['amount']) ?> zł</strong> za okres, niezależna od liczby lekcji.
  <?php endif; endforeach; ?>
</div>
<?php
    return (string)ob_get_clean();
}

// ── Render ───────────────────────────────────────────────────────────────────

/**
 * Buduje PDF faktury. Zwraca treść pliku.
 *
 * @param array $opts ['duplikat'=>bool,'kopia'=>bool]
 */
function invoice_pdf_render(array $inv, array $opts = []): string
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $tmp = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__) . '/uploads'), '/') . '/mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 14,
        'margin_right'  => 14,
        'margin_top'    => 12,
        'margin_bottom' => 14,
        'default_font'  => 'dejavusans',
        'tempDir'       => $tmp,
    ]);

    $nr = $inv['number'] ?: ('szkic-' . (int)$inv['id']);
    $mpdf->SetTitle('Faktura ' . $nr);
    $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
    $mpdf->SetCreator('SZO');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');

    // Znak wodny na dokumencie roboczym — żeby wydruk szkicu nie zaczął krążyć
    // jako faktura. Ustawiamy przed WriteHTML, inaczej nie obejmie pierwszej strony.
    if (($inv['status'] ?? '') === 'szkic' || ($inv['status'] ?? '') === 'blad') {
        $mpdf->SetWatermarkText('FAKTURA ROBOCZA');
        $mpdf->showWatermarkText  = true;
        $mpdf->watermarkTextAlpha = 0.08;
    }

    $html = invoice_pdf_html($inv, $opts);

    // Faktura z rozliczenia TI dostaje załącznik z rozliczeniem środków —
    // faktura pokazuje jeden okres, załącznik cały stan konta kursanta.
    if (($inv['source'] ?? '') === 'ti_billing' && !empty($inv['source_id']) && empty($opts['no_settlement'])) {
        $b = db_one(
            "SELECT client_id, month, year, COALESCE(course_id,0) AS course_id
               FROM k30_ti_billing WHERE id=?",
            [(int)$inv['source_id']]
        );
        if ($b) {
            $html .= '<pagebreak />'
                   . '<div class="hdr-title" style="font-size:12pt">Załącznik do faktury</div>'
                   // Wykaz lekcji zawężony do grupy z rozliczenia; rozliczenie środków
                   // celowo obejmuje CAŁE konto kursanta — nadpłata bywa wspólna.
                   . invoice_pdf_ti_lessons((int)$b['client_id'], (int)$b['month'], (int)$b['year'], (int)$b['course_id'])
                   . invoice_pdf_ti_settlement((int)$b['client_id']);
        }
    }

    $mpdf->WriteHTML($html);
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}
