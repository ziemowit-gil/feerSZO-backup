<?php
/**
 * includes/donation_pdf.php — dokumenty darowizn (mPDF).
 *
 * Dwa dokumenty, bo prawo wymaga dwóch różnych rzeczy (art. 26 ust. 7 ustawy
 * o PIT — szczegóły w includes/donations.php):
 *
 *   donation_pdf_annual()     — POTWIERDZENIE przekazanych darowizn pieniężnych
 *                               za rok. Dokument pomocniczy: podstawą odliczenia
 *                               jest dowód wpłaty na rachunek, i tak to na nim
 *                               napisano. Wystawiamy, bo darczyńcy o to proszą
 *                               i bo zestawienie roczne realnie im pomaga.
 *
 *   donation_pdf_acceptance() — OŚWIADCZENIE O PRZYJĘCIU darowizny rzeczowej.
 *                               To jest dokument wymagany przez ustawę, więc
 *                               zawiera dane darczyńcy, wartość i wyraźne
 *                               oświadczenie obdarowanego o przyjęciu.
 *
 * Szablon i pomocniki (dane wystawcy, kwota słownie, format NRB) wzięte z
 * generatora faktur — jeden wygląd dokumentów wychodzących z systemu.
 */

declare(strict_types=1);

require_once __DIR__ . '/donations.php';
require_once __DIR__ . '/invoice_pdf.php';

/**
 * Dane obdarowanego z rachunkiem właściwym DLA DAROWIZN.
 *
 * invoice_seller() dobiera rachunek działalności odpłatnej (szkolenia, zajęcia) —
 * na dokumencie darowizny byłby to zły numer. crm_offer_bank_accounts() z celem
 * innym niż odpłatny preferuje rachunki opisane jako dotacyjne/darowiznowe.
 */
function _donation_seller(string $currency = 'PLN'): array
{
    $s = invoice_seller($currency);
    try {
        require_once __DIR__ . '/crm_offers.php';
        if (function_exists('crm_offer_bank_accounts')) {
            $acc = crm_offer_bank_accounts('statutowa', $currency);
            if ($acc) $s['accounts'] = $acc;
        }
    } catch (\Throwable $e) { /* zostaje rachunek z invoice_seller() */ }
    return $s;
}

/** Wspólny CSS obu dokumentów — mPDF bez flexboksa, układ na tabelach. */
function _donation_pdf_css(): string
{
    return <<<'CSS'
<style>
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #111; line-height: 1.4; }

  .hdr-title { font-size: 16pt; font-weight: bold; letter-spacing: -.01em; }
  .hdr-sub   { font-size: 9pt; color: #333; line-height: 1.45; }

  table      { width: 100%; border-collapse: collapse; }

  .party td  { vertical-align: top; padding: 0; }
  .party-box { border-left: 2px solid #333; padding: 2px 0 2px 9px; }
  .party-lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .06em;
               color: #333; font-weight: bold; margin-bottom: 3px; }
  .party-nm  { font-weight: bold; font-size: 11.5pt; line-height: 1.25; color: #000; }
  .party-box div { font-size: 9.5pt; color: #111; }

  .items th  { background: #333; color: #fff; padding: 6px; font-size: 8.5pt;
               text-align: left; font-weight: bold; }
  .items td  { border-bottom: 1px solid #ddd; padding: 6px; font-size: 9.5pt; vertical-align: top; }
  .items tbody tr:nth-child(even) td { background: #fafafa; }
  .items tfoot th, .items tfoot td { border-top: 2px solid #333; padding: 7px 6px; font-size: 10.5pt; }

  .num   { text-align: right; font-variant-numeric: tabular-nums; }
  .total { font-size: 12.5pt; font-weight: bold; }

  .stmt  { border: 1px solid #333; padding: 9px 11px; font-size: 10pt; line-height: 1.5; }
  .legal { font-size: 8.5pt; color: #333; line-height: 1.45; }
  .warn  { border-left: 4px solid #d97706; background: #fffbeb; color: #7c2d12;
           padding: 7px 10px; font-size: 9pt; line-height: 1.45; }
  .sign  { border-top: 1px solid #888; padding-top: 4px; font-size: 8.5pt;
           color: #555; text-align: center; }
</style>
CSS;
}

/** Nagłówek: logo, tytuł, miejsce i data wystawienia. */
function _donation_pdf_header(array $s, string $title, string $sub = ''): string
{
    ob_start(); ?>
<table>
  <tr>
    <td style="width:60%">
      <?php if ($s['logo'] !== ''): ?>
      <img src="<?= h($s['logo']) ?>" alt="" style="max-height:38px;margin-bottom:5px">
      <?php endif; ?>
      <div class="hdr-title"><?= h($title) ?></div>
      <?php if ($sub !== ''): ?><div class="hdr-sub"><?= h($sub) ?></div><?php endif; ?>
    </td>
    <td style="width:40%;text-align:right" class="hdr-sub">
      <?= h($s['miasto'] ?: '') ?><?= $s['miasto'] ? ', ' : '' ?><?= h(date('d.m.Y')) ?>
    </td>
  </tr>
</table>
    <?php return (string)ob_get_clean();
}

/** Blok stron: obdarowany (my) i darczyńca. */
function _donation_pdf_parties(array $s, array $donor): string
{
    ob_start(); ?>
<table class="party" style="margin-top:12px">
  <tr>
    <td style="width:48%">
      <div class="party-box">
        <div class="party-lbl">Obdarowany</div>
        <div class="party-nm"><?= h($s['name']) ?></div>
        <?php if ($s['adres']): ?><div><?= nl2br(h($s['adres'])) ?></div><?php endif; ?>
        <?php if ($s['nip']):   ?><div>NIP: <?= h($s['nip']) ?></div><?php endif; ?>
        <?php if ($s['krs']):   ?><div>KRS: <?= h($s['krs']) ?></div><?php endif; ?>
      </div>
    </td>
    <td style="width:4%"></td>
    <td style="width:48%">
      <div class="party-box">
        <div class="party-lbl">Darczyńca</div>
        <div class="party-nm"><?= h($donor['name'] !== '' ? $donor['name'] : '—') ?></div>
        <?php if ($donor['address'] !== ''): ?><div><?= nl2br(h($donor['address'])) ?></div><?php endif; ?>
        <?php if ($donor['pesel'] !== ''):   ?><div>PESEL: <?= h($donor['pesel']) ?></div><?php endif; ?>
        <?php if ($donor['nip'] !== ''):     ?><div>NIP: <?= h($donor['nip']) ?></div><?php endif; ?>
      </div>
    </td>
  </tr>
</table>
    <?php return (string)ob_get_clean();
}

/** Miejsce na podpis osoby reprezentującej obdarowanego. */
function _donation_pdf_signature(?int $user_id = null): string
{
    $issuer = '';
    if ($user_id) {
        $u = db_one("SELECT name, first_name, last_name FROM users WHERE id=?", [$user_id]);
        if ($u) {
            $issuer = trim(((string)($u['first_name'] ?? '')) . ' ' . ((string)($u['last_name'] ?? '')));
            if ($issuer === '') $issuer = trim((string)($u['name'] ?? ''));
        }
    }
    ob_start(); ?>
<table style="margin-top:26px">
  <tr>
    <td style="width:55%"></td>
    <td style="width:45%">
      <div style="height:26px"></div>
      <div class="sign">
        <?= $issuer !== '' ? h($issuer) . '<br>' : '' ?>
        podpis osoby reprezentującej obdarowanego
      </div>
    </td>
  </tr>
</table>
    <?php return (string)ob_get_clean();
}

/** Wspólna obudowa mPDF. */
function _donation_pdf_render(string $html, string $title): string
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $tmp = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__) . '/uploads'), '/') . '/mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 15,
        'margin_right'  => 15,
        'margin_top'    => 13,
        'margin_bottom' => 14,
        'default_font'  => 'dejavusans',
        'tempDir'       => $tmp,
    ]);
    $mpdf->SetTitle($title);
    $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
    $mpdf->SetCreator('SZO');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML($html);

    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * Roczne potwierdzenie darowizn pieniężnych jednego darczyńcy.
 *
 * @return array{0:string,1:string}|null [treść PDF, nazwa pliku] albo null,
 *         gdy w danym roku nie ma czego potwierdzać.
 */
function donation_pdf_annual(int $contact_id, int $year, ?int $user_id = null): ?array
{
    $all = donations_annual_set($contact_id, $year);
    // Zestawienie dotyczy darowizn pieniężnych; rzeczowe mają własny dokument
    // (oświadczenie o przyjęciu) i mieszanie ich w jednej sumie wprowadzałoby
    // darczyńcę w błąd co do sposobu udokumentowania odliczenia.
    $rows = array_values(array_filter($all, fn($d) => ($d['kind'] ?? '') === 'pieniezna'));
    if (!$rows) return null;

    $currency = (string)($rows[0]['currency'] ?? 'PLN');
    $s        = _donation_seller($currency);
    $donor    = donation_donor($rows[0]);
    $total    = array_sum(array_map(fn($d) => (float)$d['amount'], $rows));
    $warnings = donation_doc_warnings($rows);
    $m        = fn(float $v) => number_format($v, 2, ',', ' ');

    // Waluty inne niż złoty w jednym zestawieniu nie sumują się sensownie —
    // wtedy dokument pokazuje kwoty per pozycja i pomija sumę łączną.
    $mixed = count(array_unique(array_map(fn($d) => (string)$d['currency'], $rows))) > 1;

    ob_start();
    echo _donation_pdf_css();
    echo _donation_pdf_header(
        $s,
        'Potwierdzenie przekazania darowizn',
        'za rok ' . $year
    );
    echo _donation_pdf_parties($s, $donor);
    ?>
<table class="items" style="margin-top:14px">
  <thead>
    <tr>
      <th style="width:15%">Data</th>
      <th>Cel darowizny</th>
      <th style="width:22%">Sposób wpłaty</th>
      <th style="width:18%" class="num">Kwota</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $d): ?>
    <tr>
      <td><?= h(date('d.m.Y', strtotime((string)$d['donation_date']))) ?></td>
      <td>
        <?= h(($d['purpose'] ?? '') !== '' ? (string)$d['purpose'] : 'Cele statutowe obdarowanego') ?>
        <?php if (!empty($d['bank_ref'])): ?>
        <br><span style="font-size:8.5pt;color:#555">tytuł: <?= h((string)$d['bank_ref']) ?></span>
        <?php endif; ?>
      </td>
      <td><?= h(donation_channel_label((string)$d['channel'])) ?></td>
      <td class="num"><?= h($m((float)$d['amount'])) ?> <?= h((string)$d['currency']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if (!$mixed): ?>
  <tfoot>
    <tr>
      <th colspan="3">Razem w roku <?= (int)$year ?></th>
      <td class="num total"><?= h($m($total)) ?> <?= h($currency) ?></td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>

<?php if (!$mixed): ?>
<div class="hdr-sub" style="margin-top:6px">
  Słownie: <strong><?= h(invoice_amount_words($total, $currency)) ?></strong>
</div>
<?php endif; ?>

<div class="stmt" style="margin-top:14px">
  <?= h($s['name']) ?> potwierdza otrzymanie wymienionych powyżej darowizn pieniężnych
  i przeznaczenie ich na cele statutowe, mieszczące się w sferze zadań publicznych
  określonych w art. 4 ustawy o działalności pożytku publicznego i o wolontariacie.
</div>

<?php foreach ($warnings as $w): ?>
<div class="warn" style="margin-top:10px"><?= h($w) ?></div>
<?php endforeach; ?>

<div class="legal" style="margin-top:12px">
  <strong>Charakter dokumentu.</strong> Niniejsze potwierdzenie jest dokumentem
  informacyjnym. Wysokość darowizny pieniężnej odliczanej od dochodu ustala się
  na podstawie <strong>dowodu wpłaty na rachunek płatniczy obdarowanego</strong>
  (art. 26 ust. 7 pkt 1 ustawy o podatku dochodowym od osób fizycznych) — to dowód
  przelewu, a nie to potwierdzenie, jest dokumentem wymaganym przy odliczeniu.
  Odliczenie darowizn przysługuje w granicach określonych w art. 26 ust. 1 pkt 9
  i ust. 5 tej ustawy (limit liczony od dochodu).
  <?php if ($s['accounts']): ?>
  <br>Rachunek, na który przyjmujemy darowizny:
  <?= h(invoice_iban_fmt((string)$s['accounts'][0]['nrb'])) ?>
  <?php endif; ?>
</div>

<?php
    echo _donation_pdf_signature($user_id);
    $html = (string)ob_get_clean();

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $donor['name'] !== '' ? $donor['name'] : 'darczynca') ?? '';
    $file = 'potwierdzenie-darowizn-' . $year . '-' . trim(strtolower($slug), '-') . '.pdf';

    return [_donation_pdf_render($html, 'Potwierdzenie darowizn ' . $year), $file];
}

/**
 * Oświadczenie o przyjęciu darowizny rzeczowej — dokument wymagany przez
 * art. 26 ust. 7 pkt 2 ustawy o PIT.
 *
 * @return array{0:string,1:string}|null
 */
function donation_pdf_acceptance(int $donation_id, ?int $user_id = null): ?array
{
    $d = donation_get($donation_id);
    if (!$d || ($d['kind'] ?? '') !== 'rzeczowa') return null;

    $currency = (string)($d['currency'] ?? 'PLN');
    $s        = _donation_seller($currency);
    $donor    = donation_donor($d);
    $amount   = (float)$d['amount'];
    $m        = fn(float $v) => number_format($v, 2, ',', ' ');

    ob_start();
    echo _donation_pdf_css();
    echo _donation_pdf_header(
        $s,
        'Oświadczenie o przyjęciu darowizny',
        'darowizna rzeczowa (niepieniężna)'
    );
    echo _donation_pdf_parties($s, $donor);
    ?>
<table class="items" style="margin-top:14px">
  <thead>
    <tr>
      <th style="width:18%">Data przekazania</th>
      <th>Przedmiot darowizny</th>
      <th style="width:22%" class="num">Wartość</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><?= h(date('d.m.Y', strtotime((string)$d['donation_date']))) ?></td>
      <td>
        <?= h(($d['description'] ?? '') !== '' ? (string)$d['description'] : '—') ?>
        <?php if (!empty($d['purpose'])): ?>
        <br><span style="font-size:8.5pt;color:#555">cel: <?= h((string)$d['purpose']) ?></span>
        <?php endif; ?>
      </td>
      <td class="num total"><?= h($m($amount)) ?> <?= h($currency) ?></td>
    </tr>
  </tbody>
</table>

<div class="hdr-sub" style="margin-top:6px">
  Słownie: <strong><?= h(invoice_amount_words($amount, $currency)) ?></strong>
</div>

<div class="stmt" style="margin-top:14px">
  <?= h($s['name']) ?> <strong>oświadcza, że przyjmuje opisaną powyżej darowiznę</strong>
  o wskazanej wartości i przeznacza ją na cele statutowe, mieszczące się w sferze zadań
  publicznych określonych w art. 4 ustawy o działalności pożytku publicznego
  i o wolontariacie.
  <?php if (!empty($d['accepted_at'])): ?>
  <br><span style="font-size:9pt;color:#333">Data przyjęcia:
    <?= h(date('d.m.Y', strtotime((string)$d['accepted_at']))) ?></span>
  <?php endif; ?>
</div>

<div class="legal" style="margin-top:12px">
  Dokument sporządzono na potrzeby art. 26 ust. 7 pkt 2 ustawy o podatku dochodowym
  od osób fizycznych — wysokość darowizny innej niż pieniężna ustala się na podstawie
  dowodu, z którego wynikają dane identyfikujące darczyńcę oraz wartość przekazanej
  darowizny, wraz z oświadczeniem obdarowanego o jej przyjęciu.
  Wartość darowizny wskazał darczyńca; obdarowany jej nie wycenia.
  Odliczenie przysługuje w granicach określonych w art. 26 ust. 1 pkt 9 i ust. 5
  tej ustawy.
</div>

<?php
    echo _donation_pdf_signature($user_id);
    $html = (string)ob_get_clean();

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $donor['name'] !== '' ? $donor['name'] : 'darczynca') ?? '';
    $file = 'oswiadczenie-przyjecia-darowizny-' . (int)$d['id'] . '-' . trim(strtolower($slug), '-') . '.pdf';

    return [_donation_pdf_render($html, 'Oświadczenie o przyjęciu darowizny'), $file];
}
