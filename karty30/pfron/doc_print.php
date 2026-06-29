<?php
/**
 * karty30/pfron/doc_print.php — Strona druku dokumentów PFRON (umowa / regulamin).
 *
 * Dane pobierane z sesji (ustawionej przez docs.php).
 * Otwierana przez window.print() lub ręcznie z przeglądarki jako PDF.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();

// 'umowa' = umowa + regulamin (jako załącznik); 'regulamin' = sam regulamin
$type = in_array($_GET['type'] ?? '', ['umowa', 'regulamin'], true) ? $_GET['type'] : 'umowa';
$d    = $_SESSION['k30_pfron_doc_draft'] ?? null;

if (!$d) {
    echo '<p style="font-family:sans-serif;padding:2rem">Brak danych dokumentu. <a href="docs.php">Wróć do formularza</a>.</p>';
    exit;
}

// Pomocnicze: formatowanie daty na czytelną polską
function fmt_date(string $s): string {
    if (!$s) return '................................';
    try {
        $dt = new DateTime($s);
        return $dt->format('d.m.Y');
    } catch (\Throwable $e) { return h($s); }
}

function blank(string $v, string $placeholder = '................................'): string {
    $v = trim($v);
    return $v !== '' ? h($v) : '<span class="blank">' . h($placeholder) . '</span>';
}

$name        = $d['client_name']        ?? '';
$pesel       = $d['pesel']              ?? '';
$address     = $d['address']            ?? '';
$phone       = $d['phone']              ?? '';
$email       = $d['email']              ?? '';
$c_date      = fmt_date($d['contract_date'] ?? '');
$pfron_no    = $d['pfron_contract_no']  ?? '';
$mc_date     = fmt_date($d['main_contract_date'] ?? '');
$mc_sign     = $d['main_contract_sign'] ?? '';
$h_total     = (int)($d['hours_total']    ?? 30);
$h_training  = (int)($d['hours_training'] ?? 25);
$h_trial     = 3;
$penalty_amt = $d['penalty_amount']     ?? '100,00';
$penalty_wrd = $d['penalty_words']      ?? 'sto';
$org         = defined('ORG_NAME') ? ORG_NAME : 'Fundacja Edukacji Empatii Rozwoju FEER';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $type === 'umowa' ? 'Umowa uczestnictwa w szkoleniu PFRON' : 'Regulamin uczestnictwa w szkoleniach FEER' ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  :root { --font: 'Times New Roman', Times, serif; --font-sz: 11pt; }
  html { font-size: var(--font-sz); }
  body {
    font-family: var(--font);
    font-size: var(--font-sz);
    line-height: 1.55;
    color: #000;
    background: #fff;
    margin: 0;
    padding: 0;
  }
  .page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 25mm 25mm 20mm 25mm;
  }
  h1.doc-title {
    font-size: 13pt;
    font-weight: bold;
    text-align: center;
    text-transform: uppercase;
    margin: 0 0 2pt 0;
    line-height: 1.4;
  }
  h2.doc-subtitle {
    font-size: 11pt;
    font-weight: normal;
    text-align: center;
    margin: 0 0 6pt 0;
  }
  .doc-city {
    text-align: center;
    margin-bottom: 14pt;
    font-size: 11pt;
  }
  .parties { margin-bottom: 14pt; }
  .parties p { margin: 2pt 0; }
  .bold { font-weight: bold; }
  .par { margin: 0 0 8pt 0; }
  h3.par-heading {
    font-size: 11pt;
    font-weight: bold;
    text-align: center;
    margin: 14pt 0 2pt 0;
  }
  h3.par-heading .par-no {
    display: block;
  }
  ol { margin: 4pt 0 4pt 0; padding-left: 1.6em; }
  ol li { margin-bottom: 2pt; }
  ul { margin: 4pt 0 4pt 0; padding-left: 1.6em; }
  ul li { margin-bottom: 2pt; }
  .sigs { margin-top: 28pt; display: flex; justify-content: space-between; }
  .sigs .sig-col { width: 44%; text-align: center; }
  .sig-line { border-top: 1px solid #000; padding-top: 3pt; margin-top: 36pt; font-size: 10pt; }
  .blank { color: #555; font-style: italic; }
  .inline-blank { display: inline-block; min-width: 8em; border-bottom: 1px solid #555; }
  .attachment-note { margin-top: 16pt; font-size: 10pt; }
  .page-break { page-break-after: always; }
  /* Strona druku */
  @media screen {
    body { background: #f5f5f5; }
    .page { background: #fff; box-shadow: 0 0 12px rgba(0,0,0,.15); margin: 16px auto; }
    .print-bar {
      position: fixed; top: 0; left: 0; right: 0;
      background: #1e3a5f; color: #fff;
      padding: 8px 20px;
      display: flex; align-items: center; gap: 12px;
      z-index: 100; font-family: sans-serif; font-size: 13px;
    }
    .print-bar button {
      background: #e53935; color: #fff; border: none;
      padding: 6px 18px; border-radius: 4px; cursor: pointer; font-size: 13px;
    }
    .print-bar a { color: #aad4f5; font-size: 12px; }
    body { padding-top: 44px; }
  }
  @media print {
    .print-bar { display: none !important; }
    body { padding: 0; background: #fff; }
    .page { margin: 0; box-shadow: none; padding: 20mm 20mm 15mm 25mm; width: 100%; }
    .page-break { page-break-after: always; }
  }
</style>
</head>
<body>

<div class="print-bar" id="pbar">
  <button onclick="window.print()">&#128438; Drukuj / Zapisz PDF</button>
  <a href="docs.php">&larr; Wróć do formularza</a>
  <?php if ($type === 'umowa'): ?>
  <a href="doc_print.php?type=regulamin" target="_blank" style="margin-left:auto">Otwórz regulamin &rarr;</a>
  <?php endif; ?>
  <span style="margin-left:auto;opacity:.7"><?= $type === 'umowa' ? 'Umowa uczestnictwa' : 'Regulamin' ?></span>
</div>

<?php if ($type === 'umowa'): ?>
<!-- ═══════════════════════════════════════ UMOWA ═══════════════════════════════════════ -->
<div class="page">

  <h1 class="doc-title">Umowa uczestnictwa w szkoleniu indywidualnym<br>finansowanym ze środków PFRON</h1>
  <p class="doc-city">zawarta w dniu <strong><?= $c_date ?></strong> w Nowym Sączu pomiędzy:</p>

  <div class="parties">
    <p><strong>Fundacją Edukacji Empatii Rozwoju FEER</strong> z siedzibą w Nowym Sączu
      (adres: ul. Barbackiego 28/18, 33-300 Nowy Sącz), wpisaną do rejestru stowarzyszeń
      Krajowego Rejestru Sądowego, którego akta przechowuje Sąd Rejonowy dla Krakowa Śródmieścia
      w Krakowie Wydział XII Gospodarczy KRS pod numerem 000779281, posiadającą NIP: 7343570539,
      reprezentowaną przez: Ziemowita Gila – Prezesa Zarządu; zwaną dalej <strong>„Fundacją"</strong>,</p>
    <p style="text-align:center;font-weight:bold;margin:8pt 0">a</p>
    <p>
      Panem/Panią <strong><?= blank($name) ?></strong><br>
      PESEL: <?= blank($pesel) ?><br>
      adres <?= blank($address) ?><br>
      telefon <?= blank($phone) ?><br>
      e-mail <?= blank($email) ?><br>
      zwanym/-ą dalej <strong>„Uczestnikiem"</strong>.
    </p>
  </div>

  <h3 class="par-heading"><span class="par-no">§ 1.</span>Przedmiot umowy</h3>
  <ol>
    <li>Przedmiotem niniejszej umowy jest określenie zasad uczestnictwa Uczestnika w indywidualnym
      szkoleniu finansowanym ze środków Państwowego Funduszu Rehabilitacji Osób Niepełnosprawnych (PFRON),
      realizowanym za pośrednictwem właściwego Miejskiego Ośrodka Pomocy Społecznej lub innej jednostki
      uprawnionej do finansowania szkolenia – umowa główna [skierowanie] z dnia <?= blank($mc_date, '..................') ?>
      - znak sprawy: <?= blank($mc_sign, '..................') ?></li>
    <li>Szkolenie obejmuje łącznie <strong><?= $h_total ?> godzin dydaktycznych</strong>.</li>
    <li>Szkolenie realizowane będzie zgodnie z indywidualnie ustalanym harmonogramem.</li>
    <li>Integralną część niniejszej umowy stanowi Regulamin uczestnictwa w szkoleniach Fundacji.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 2.</span>Oświadczenia Uczestnika</h3>
  <p class="par">Uczestnik oświadcza, że:</p>
  <ol>
    <li>został poinformowany o zasadach finansowania szkolenia;</li>
    <li>wie, że środki publiczne przekazywane są Fundacji przed zakończeniem szkolenia;</li>
    <li>ma świadomość, że Fundacja rezerwuje dla niego czas pracy trenera, zasoby organizacyjne
      oraz możliwość udziału w szkoleniu kosztem innych osób oczekujących na wsparcie;</li>
    <li>rozumie, że nieuzasadnione odwoływanie zajęć powoduje rzeczywiste koszty organizacyjne
      oraz może skutkować obowiązkiem zwrotu części środków publicznych i składania wyjaśnień
      wobec instytucji finansujących;</li>
    <li>zobowiązuje się współdziałać z Fundacją w sposób umożliwiający prawidłowe wykonanie szkolenia.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 3.</span>Obowiązki Fundacji</h3>
  <p class="par">Fundacja zobowiązuje się do:</p>
  <ol>
    <li>przeprowadzenia <strong><?= $h_training ?> godzin</strong> szkolenia;</li>
    <li>zapewnienia wykwalifikowanego trenera;</li>
    <li>pozostawania w gotowości do realizacji szkolenia przez okres jego trwania;</li>
    <li>ustalania terminów zajęć z uwzględnieniem możliwości Uczestnika;</li>
    <li>prowadzenia dokumentacji wymaganej przez instytucję finansującą.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 4.</span>Obowiązki Uczestnika</h3>
  <p class="par">Uczestnik zobowiązuje się do:</p>
  <ol>
    <li>uczestnictwa we wszystkich zaplanowanych zajęciach;</li>
    <li>aktywnego współdziałania z Fundacją w realizacji szkolenia;</li>
    <li>punktualnego rozpoczynania zajęć;</li>
    <li>niezwłocznego informowania o okolicznościach uniemożliwiających realizację szkolenia;</li>
    <li>ukończenia szkolenia w terminie nie dłuższym niż 3 miesiące od pierwszych zajęć,
      chyba że Fundacja wyrazi zgodę na jego przedłużenie.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 5.</span>Okres próbny</h3>
  <ol>
    <li>W ciągu pierwszych <?= $h_trial ?> godzin szkolenia Uczestnik może zrezygnować z udziału bez
      podawania przyczyny. W tym okresie Uczestnik może zgłosić potrzebę zmiany trenera.</li>
    <li>Po upływie <?= $h_trial ?> godzin strony uznają, że zaakceptowały sposób realizacji szkolenia
      oraz zobowiązują się do jego ukończenia w całości.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 6.</span>Zmiana terminów</h3>
  <ol>
    <li>Terminy zajęć ustalane są wspólnie przez Strony. Uczestnik, z zastrzeżeniem warunku,
      o którym mowa w ust. 2 poniżej, może dokonać zmiany terminu maksymalnie pięć razy w całym
      okresie szkolenia.</li>
    <li>Zmiana terminu wymaga zgłoszenia najpóźniej 48 godzin przed rozpoczęciem zajęć.</li>
    <li>Zmiana wymaga akceptacji Fundacji. Zgłoszenie dokonane po upływie terminu wskazanego
      w ust. 2 traktowane jest jako odwołanie zajęć z przyczyn leżących po stronie Uczestnika.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 7.</span>Kara umowna</h3>
  <ol>
    <li>Uczestnik przyjmuje do wiadomości, że przed każdym szkoleniem Fundacja dokonuje rezerwacji
      czasu pracy trenera, przygotowuje harmonogram zajęć oraz pozostaje w gotowości do wykonania
      szkolenia <strong>wyłącznie na rzecz danego Uczestnika</strong> – co dodatkowo wiąże się z kosztami
      transportu oraz czasem pracy trenera.</li>
    <li>Rezerwacja terminu uniemożliwia wykorzystanie tego czasu na realizację szkolenia innych
      beneficjentów oraz powoduje ponoszenie przez Fundację kosztów organizacyjnych niezależnie od tego,
      czy szkolenie zostanie przeprowadzone.</li>
    <li>Strony zgodnie postanawiają, że prawidłowa realizacja niniejszej umowy wymaga współdziałania
      Uczestnika z Fundacją.</li>
    <li>W przypadku niewykonania lub nienależytego wykonania obowiązków wynikających z niniejszej umowy
      z przyczyn leżących wyłącznie po stronie Uczestnika, Fundacja jest uprawniona do naliczenia
      kary umownej.</li>
    <li>Kara umowna wynosi <strong><?= h($penalty_amt) ?> zł (słownie: <?= h($penalty_wrd) ?> złotych)</strong>
      za każdą godzinę szkolenia, która nie została zrealizowana wskutek:
      <ol type="a">
        <li>niestawienia się na umówione zajęcia;</li>
        <li>odwołania zajęć z naruszeniem terminu 48 godzin;</li>
        <li>przekroczenia dopuszczalnego limitu zmian terminów;</li>
        <li>przerwania szkolenia po zakończeniu okresu próbnego;</li>
        <li>odmowy kontynuowania szkolenia bez uzasadnionej przyczyny;</li>
        <li>innych zawinionych działań lub zaniechań Uczestnika uniemożliwiających wykonanie szkolenia.</li>
      </ol>
    </li>
    <li>Kara umowna nie jest naliczana w przypadku:
      <ol type="a">
        <li>nagłej choroby,</li>
        <li>hospitalizacji,</li>
        <li>wypadku,</li>
        <li>śmierci osoby najbliższej,</li>
        <li>innych zdarzeń losowych o charakterze nadzwyczajnym, których Uczestnik nie mógł przewidzieć
          ani im zapobiec.</li>
      </ol>
    </li>
    <li>Fundacja może zażądać przedstawienia dokumentów potwierdzających okoliczności wskazane w ust. 6.</li>
    <li>Łączna wysokość naliczonych kar umownych nie może przekroczyć wartości odpowiadającej liczbie
      godzin szkolenia niezrealizowanych z przyczyn leżących po stronie Uczestnika.</li>
    <li>Zapłata kary umownej nie wyłącza prawa Fundacji do dochodzenia odszkodowania przewyższającego
      jej wysokość, jeżeli poniesiona szkoda jest wyższa.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 8.</span>Rozwiązanie umowy</h3>
  <p class="par">Fundacja może rozwiązać niniejszą umowę ze skutkiem natychmiastowym w przypadku:</p>
  <ol>
    <li>dwukrotnego nieusprawiedliwionego niestawienia się Uczestnika;</li>
    <li>uporczywego przekładania terminów;</li>
    <li>przekroczenia limitu zmian terminów;</li>
    <li>odmowy współpracy;</li>
    <li>naruszenia Regulaminu;</li>
    <li>zachowania uniemożliwiającego prowadzenie szkolenia.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 9.</span>Informowanie instytucji finansujących</h3>
  <p class="par">Uczestnik przyjmuje do wiadomości, że w przypadku przerwania szkolenia lub niewykonania
    niniejszej umowy Fundacja może przekazać właściwemu MOPS, PFRON lub innemu podmiotowi finansującemu
    informacje dotyczące przebiegu realizacji szkolenia oraz przyczyn jego zakończenia w zakresie
    wymaganym przepisami prawa i zasadami rozliczania środków publicznych.</p>

  <h3 class="par-heading"><span class="par-no">§ 10.</span>Przetwarzanie danych</h3>
  <p class="par">Zgodnie z art. 13 ust. 1 i 2 Rozporządzenia Parlamentu Europejskiego i Rady (UE)
    2016/679 z dnia 27 kwietnia 2016 r. w sprawie ochrony osób fizycznych w związku z przetwarzaniem
    danych osobowych (RODO) Fundacja informuje, że:</p>
  <ol>
    <li>Administratorem danych osobowych Uczestnika jest Fundacja Edukacji Empatii Rozwoju FEER
      z siedzibą w Nowym Sączu (adres: ul. Barbackiego 28/18, 33-300 Nowy Sącz), wpisana do rejestru
      stowarzyszeń KRS pod numerem 000779281, NIP: 7343570539, kontakt e-mail: kontakt@feer.org.pl;</li>
    <li>dane osobowe Uczestnika przetwarzane są przez Fundację w związku z zawartą umową na wykonanie
      szkolenia (art. 6 ust. 1 lit b RODO) oraz w celu wypełniania obowiązków prawnych związanych z
      finansowaniem szkolenia ze środków publicznych (art. 6 ust. 1 lit c RODO);</li>
    <li>Uczestnikowi przysługuje prawo żądania dostępu do danych osobowych, ich sprostowania, usunięcia,
      ograniczenia przetwarzania oraz prawo żądania przeniesienia danych;</li>
    <li>na działania Fundacji w zakresie przetwarzania danych osobowych przysługuje Uczestnikowi skarga
      do Prezesa Urzędu Ochrony Danych Osobowych, ul. Stawki 2, 00-193 Warszawa;</li>
    <li>dane osobowe Uczestnika nie podlegają zautomatyzowanemu podejmowaniu decyzji ani profilowaniu,
      jak i nie są przekazywane do państw trzecich;</li>
    <li>dane osobowe Uczestnika mogą być przekazywane podmiotom finansującym szkolenie (MOPS/PFRON)
      oraz podmiotom współpracującym z Fundacją w zakresie niezbędnym do realizacji celów umowy.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 11.</span>Postanowienia końcowe</h3>
  <ol>
    <li>W sprawach nieuregulowanych zastosowanie mają przepisy Kodeksu cywilnego.</li>
    <li>Wszelkie zmiany niniejszej umowy wymagają formy pisemnej pod rygorem nieważności.</li>
    <li>Spory wynikłe z wykonania umowy strony będą starały się rozwiązać polubownie, a gdy to okaże się
      niemożliwe – spór rozstrzygać będzie właściwy miejscowo sąd powszechny, ustalony ze względu na
      miejsce wykonania umowy szkoleniowej.</li>
    <li>Umowę wraz z załącznikiem sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla
      każdej ze Stron.</li>
  </ol>

  <div class="sigs">
    <div class="sig-col">
      <div class="sig-line">Fundacja</div>
    </div>
    <div class="sig-col">
      <div class="sig-line">Uczestnik</div>
    </div>
  </div>

  <p class="attachment-note"><strong>Załącznik:</strong> Regulamin uczestnictwa w szkoleniach Fundacji</p>

</div><!-- /page umowa -->

<div class="page-break"></div>

<?php endif; /* umowa */ ?>
<?php if ($type === 'umowa' || $type === 'regulamin'): ?>
<!-- ═══════ REGULAMIN — drukowany jako załącznik do umowy lub samodzielnie ═══════ -->
<div class="page">

  <h1 class="doc-title">Regulamin uczestnictwa w indywidualnych szkoleniach<br>
    w Fundacji Edukacji Empatii Rozwoju „FEER"<br>finansowanych ze środków publicznych</h1>

  <h3 class="par-heading"><span class="par-no">§ 1.</span>Postanowienia ogólne</h3>
  <ol>
    <li>Niniejszy Regulamin określa zasady uczestnictwa w indywidualnych szkoleniach organizowanych przez
      Fundację Edukacji Empatii Rozwoju „FEER", zwaną dalej „Fundacją", finansowanych ze środków
      publicznych (np. Państwowego Funduszu Rehabilitacji Osób Niepełnosprawnych (PFRON) lub innych
      funduszy celowych), przekazywanych za pośrednictwem właściwego organu.</li>
    <li>Regulamin stanowi integralny załącznik do Umowy uczestnictwa w indywidualnym szkoleniu
      finansowanym ze środków publicznych.</li>
    <li>Podpisanie Umowy oznacza potwierdzenie zapoznania się z treścią Regulaminu oraz zobowiązanie
      do przestrzegania wszystkich jego postanowień.</li>
    <li>Celem Regulaminu jest określenie praw i obowiązków stron, zapewnienie sprawnej organizacji
      szkoleń oraz właściwego wykorzystania środków publicznych przeznaczonych na wsparcie osób
      z niepełnosprawnościami.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 2.</span>Definicje</h3>
  <p class="par">Ilekroć w Regulaminie jest mowa o:</p>
  <ol>
    <li><strong>Fundacji</strong> – należy przez to rozumieć Fundację Edukacji Empatii Rozwoju „FEER".</li>
    <li><strong>Uczestniku</strong> – należy przez to rozumieć osobę zakwalifikowaną do udziału w szkoleniu
      finansowanym ze środków publicznych.</li>
    <li><strong>Szkoleniu</strong> – należy przez to rozumieć indywidualny proces edukacyjny obejmujący
      określoną ilość godzin dydaktycznych, realizowany zgodnie z zakresem zaakceptowanym przez
      instytucję finansującą.</li>
    <li><strong>Trenerze</strong> – osobę prowadzącą szkolenie w imieniu Fundacji.</li>
    <li><strong>Umowie</strong> – Umowę uczestnictwa w indywidualnym szkoleniu finansowanym ze środków
      publicznych.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 3.</span>Charakter szkolenia</h3>
  <ol>
    <li>Szkolenia realizowane przez Fundację mają charakter indywidualny i są przygotowywane z
      uwzględnieniem potrzeb konkretnego Uczestnika. Co do zasady koszt szkolenia pokrywany jest przez
      podmiot publiczny (np. MOPS, PFRON lub inne). Środki finansowe przeznaczane są na realizację
      szkolenia dla oznaczonego beneficjenta po uprzednim zaakceptowaniu kosztów przez instytucję
      finansującą.</li>
    <li>Z chwilą potwierdzenia realizacji szkolenia Fundacja zobowiązuje się do:
      <ol type="a">
        <li>zapewnienia wykwalifikowanego trenera,</li>
        <li>przygotowania programu szkolenia,</li>
        <li>rezerwacji czasu pracy trenera,</li>
        <li>zapewnienia odpowiednich warunków organizacyjnych,</li>
        <li>prowadzenia dokumentacji wymaganej przez instytucje finansujące.</li>
      </ol>
    </li>
    <li>Każdy ustalony termin zajęć oznacza zarezerwowanie czasu pracy trenera wyłącznie dla jednego
      i z góry określonego Uczestnika.</li>
    <li>Fundacja organizuje szkolenia zgodnie z zasadą racjonalnego gospodarowania środkami publicznymi
      oraz z poszanowaniem prawa innych beneficjentów do uzyskania wsparcia.</li>
    <li>Szkolenia są dostosowywane do potrzeb, możliwości oraz poziomu wiedzy i umiejętności Uczestnika.
      Program szkolenia może zostać zmodyfikowany w trakcie jego realizacji, jeżeli jest to uzasadnione
      postępami Uczestnika lub zaleceniami instytucji finansującej, przy zachowaniu celu i zakresu
      szkolenia.</li>
    <li>Fundacja dobiera metody dydaktyczne, tempo pracy oraz wykorzystywane narzędzia z uwzględnieniem
      rodzaju niepełnosprawności, możliwości psychofizycznych oraz indywidualnych potrzeb Uczestnika.</li>
    <li>Uczestnik zobowiązuje się do aktywnego informowania trenera o okolicznościach mogących mieć
      wpływ na sposób prowadzenia szkolenia.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 4.</span>Zasada współdziałania</h3>
  <ol>
    <li>Szkolenie finansowane jest ze środków publicznych przeznaczonych na wsparcie konkretnego
      beneficjenta. Fundacja i Uczestnik zobowiązują się wykonywać swoje obowiązki w sposób lojalny,
      z poszanowaniem czasu, pracy oraz uzasadnionych interesów drugiej strony.</li>
    <li>Strony zobowiązują się do współpracy przez cały okres realizacji szkolenia.</li>
    <li>Fundacja zobowiązuje się do wykonania szkolenia z należytą starannością.</li>
    <li>Uczestnik zobowiązuje się współdziałać z Fundacją w sposób umożliwiający wykonanie szkolenia
      zgodnie z jego celem, zakresem oraz warunkami finansowania.</li>
    <li>Obowiązek współdziałania obejmuje w szczególności:
      <ol type="a">
        <li>terminowe uczestnictwo w zajęciach,</li>
        <li>punktualne rozpoczynanie spotkań,</li>
        <li>pozostawanie w kontakcie z Fundacją,</li>
        <li>informowanie o przeszkodach mogących mieć wpływ na realizację szkolenia,</li>
        <li>wykonywanie zaleceń organizacyjnych dotyczących przebiegu szkolenia.</li>
      </ol>
    </li>
    <li>Uczestnik przyjmuje do wiadomości, że szkolenie finansowane jest ze środków publicznych, których
      wykorzystanie podlega szczegółowym zasadom rozliczania. Niewykonanie lub przerwanie szkolenia z
      przyczyn leżących po stronie Uczestnika może skutkować koniecznością dokonania korekt rozliczeń
      lub zwrotu całości albo części otrzymanego dofinansowania.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 5.</span>Organizacja szkolenia i korzystanie ze sprzętu/materiałów</h3>
  <ol>
    <li>Szkolenie obejmuje <strong><?= $h_training ?> godzin dydaktycznych</strong>.
      Co do zasady powinno zostać zakończone w terminie 3 miesięcy od dnia przeprowadzenia pierwszych zajęć.</li>
    <li>Harmonogram ustalany jest indywidualnie pomiędzy Fundacją a Uczestnikiem.</li>
    <li>Fundacja dokłada wszelkich starań, aby terminy były dostosowane do możliwości Uczestnika, jednak
      ostateczna decyzja należy do Organizatora.</li>
    <li>W trakcie szkolenia Fundacja może udostępniać Uczestnikowi sprzęt komputerowy, urządzenia
      specjalistyczne, pomoce dydaktyczne oraz materiały szkoleniowe.</li>
    <li>Uczestnik zobowiązuje się korzystać z udostępnionego sprzętu zgodnie z jego przeznaczeniem.</li>
    <li>W przypadku zauważenia nieprawidłowości w działaniu sprzętu Uczestnik zobowiązany jest
      niezwłocznie poinformować o tym trenera.</li>
    <li>Zabrania się instalowania oprogramowania, zmiany konfiguracji sprzętu lub podejmowania innych
      działań mogących wpłynąć na jego prawidłowe funkcjonowanie bez zgody Fundacji.</li>
    <li>Materiały szkoleniowe przekazywane Uczestnikowi przeznaczone są wyłącznie do wykorzystania na
      potrzeby realizowanego szkolenia. Ich rozpowszechnianie bez zgody Fundacji jest niedopuszczalne.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 6.</span>Znaczenie ustalonego terminu szkolenia</h3>
  <ol>
    <li>Ustalenie terminu zajęć oznacza, że Fundacja:
      <ol type="a">
        <li>rezerwuje czas pracy trenera,</li>
        <li>zabezpiecza miejsce prowadzenia szkolenia i/lub właściwe środki techniczne,</li>
        <li>przygotowuje materiały dydaktyczne,</li>
        <li>pozostaje w gotowości do wykonania szkolenia.</li>
      </ol>
    </li>
    <li>Z uwagi na indywidualny charakter szkolenia zarezerwowanego czasu nie można przeznaczyć dla
      innego Uczestnika bez odpowiednio wcześniejszej informacji o zmianie terminu.</li>
    <li>Każde nieodwołane spotkanie powoduje niewykorzystanie czasu pracy trenera oraz ogranicza
      możliwość udzielenia wsparcia innym beneficjentom oczekującym na szkolenie.</li>
    <li>Uczestnik przyjmuje do wiadomości, że Fundacja zobowiązana jest do rozliczenia środków
      publicznych zgodnie z obowiązującymi przepisami oraz warunkami określonymi przez instytucję
      finansującą.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 7.</span>Okres adaptacyjny</h3>
  <ol>
    <li>W trosce o komfort współpracy pierwsze 3 godziny szkolenia stanowią okres adaptacyjny.
      W tym czasie Uczestnik może zrezygnować z udziału w dalszym szkoleniu bez obowiązku podawania
      przyczyny.</li>
    <li>W okresie adaptacyjnym Uczestnik może zgłosić zastrzeżenia dotyczące sposobu prowadzenia
      szkolenia lub współpracy z trenerem.</li>
    <li>Fundacja, w miarę możliwości organizacyjnych, może zaproponować zmianę trenera.</li>
    <li>Po zakończeniu okresu adaptacyjnego przyjmuje się, że strony akceptują sposób realizacji
      szkolenia i zobowiązują się do jego ukończenia w całości.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 8.</span>Zmiana terminów</h3>
  <ol>
    <li>Zmiana ustalonego terminu wymaga zgłoszenia Fundacji nie później niż 48 godzin przed planowanym
      rozpoczęciem zajęć.</li>
    <li>Zmiana terminu wymaga potwierdzenia przez Fundację.</li>
    <li>Uczestnik może zmienić termin szkolenia maksymalnie pięć razy podczas całego procesu
      szkoleniowego.</li>
    <li>Każda kolejna zmiana może zostać nieuwzględniona przez Fundację.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 9.</span>Nieobecności</h3>
  <ol>
    <li>Za usprawiedliwioną nieobecność uważa się nieobecność spowodowaną takimi zdarzeniami jak:
      nagła choroba, hospitalizacja, wypadek, zdarzenie losowe, inna okoliczność niezależna od
      Uczestnika.</li>
    <li>Nieobecność usprawiedliwiona powinna zostać zgłoszona niezwłocznie, możliwie przed planowanym
      terminem zajęć lub bezpośrednio po zdarzeniu.</li>
    <li>Fundacja może zażądać dokumentu potwierdzającego przyczynę nieobecności.</li>
    <li>Nieobecność bez uprzedniego powiadomienia lub bez uzasadnionej przyczyny traktowana jest jako
      nieusprawiedliwiona i może skutkować naliczeniem kary umownej.</li>
  </ol>

  <h3 class="par-heading"><span class="par-no">§ 10.</span>Postanowienia końcowe</h3>
  <ol>
    <li>Regulamin wchodzi w życie z dniem podpisania Umowy.</li>
    <li>Fundacja zastrzega sobie prawo do zmiany Regulaminu. Zmiana wymaga poinformowania Uczestnika z
      co najmniej 7-dniowym wyprzedzeniem.</li>
    <li>W sprawach nieuregulowanych Regulaminem zastosowanie mają przepisy Kodeksu cywilnego.</li>
  </ol>

  <div class="sigs">
    <div class="sig-col">
      <div class="sig-line">Fundacja</div>
    </div>
    <div class="sig-col">
      <div class="sig-line">Uczestnik – potwierdzam zapoznanie się z Regulaminem</div>
    </div>
  </div>

</div><!-- /page regulamin -->
<?php endif; /* regulamin */  ?>

<script>
window.addEventListener('load', function() {
  // Automatycznie otwórz dialog druku po załadowaniu
  setTimeout(function() { window.print(); }, 400);
});
</script>
</body>
</html>
