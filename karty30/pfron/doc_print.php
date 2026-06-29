<?php
/**
 * karty30/pfron/doc_print.php — Generowanie dokumentów PFRON jako PDF (mPDF).
 *
 * ?type=umowa  → PDF: Umowa uczestnictwa + Regulamin (jako załącznik)
 * ?type=regulamin → PDF: sam Regulamin
 * ?preview=1   → strona HTML z przyciskiem podpisu (wywoływana przez docs.php)
 *
 * Dane pobierane z $_SESSION['k30_pfron_doc_draft'] (ustawionej przez docs.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

k30_require_access();
karty30_migrate();

$type    = in_array($_GET['type'] ?? '', ['umowa', 'regulamin'], true) ? $_GET['type'] : 'umowa';
$preview = !empty($_GET['preview']);   // tryb podpisu (strona HTML, nie PDF)
$d       = $_SESSION['k30_pfron_doc_draft'] ?? null;

if (!$d) {
    echo '<p style="font-family:sans-serif;padding:2rem">Brak danych dokumentu. <a href="docs.php">Wróć do formularza</a>.</p>';
    exit;
}

function fmt_date(string $s): string {
    if (!$s) return '................................';
    try { return (new DateTime($s))->format('d.m.Y'); } catch (\Throwable $e) { return $s; }
}
// Frazy oznaczające świadomy brak danych
const BLANK_PHRASES = ['nie podano', 'nie posiada', 'brak', '-', '—', 'bd', 'b/d', 'n/d', 'nd'];

function blank_pdf(string $v, string $ph = '................................'): string {
    $v = trim($v);
    if ($v === '') return '<span style="color:#999;font-style:italic">' . $ph . '</span>';
    // Jeśli użytkownik wpisał frazę "nie podano / nie posiada" — renderuj kursywą
    if (in_array(mb_strtolower($v), BLANK_PHRASES, true)) {
        return '<em style="color:#555">' . htmlspecialchars($v, ENT_QUOTES) . '</em>';
    }
    return htmlspecialchars($v, ENT_QUOTES);
}

$pfron_id    = (int)($d['pfron_id']          ?? 0);
$name        = $d['client_name']         ?? '';
$pesel       = $d['pesel']               ?? '';
$address     = $d['address']             ?? '';
$phone       = $d['phone']               ?? '';
$email       = $d['email']               ?? '';
$c_date      = fmt_date($d['contract_date']       ?? '');
$pfron_no    = $d['pfron_contract_no']   ?? '';
$mc_date     = fmt_date($d['main_contract_date']  ?? '');
$mc_sign     = $d['main_contract_sign']  ?? '';
$h_total     = (int)($d['hours_total']     ?? 30);
$h_training  = (int)($d['hours_training']  ?? 25);
$h_trial     = 3;
$penalty_amt = $d['penalty_amount']      ?? '100,00';
$penalty_wrd = $d['penalty_words']       ?? 'sto';

$existing_doc_number = '';
$existing_signed_at  = '';
if ($pfron_id) {
    $pfrow = db_one("SELECT doc_number, signed_at FROM k30_pfron_contracts WHERE id=?", [$pfron_id]);
    $existing_doc_number = $pfrow['doc_number'] ?? '';
    $existing_signed_at  = $pfrow['signed_at']  ?? '';
}

// ═══════════════════════════════════════════════════════════════════════════
// TRYB PODGLĄDU: strona HTML z przyciskiem podpisu
// ═══════════════════════════════════════════════════════════════════════════
if ($preview) {
    include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
    ?>
    <nav aria-label="breadcrumb" class="mb-3">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="docs.php<?= $pfron_id ? '?pfron_id='.$pfron_id : '' ?>">Dokumenty PFRON</a></li>
        <li class="breadcrumb-item active">Podgląd i podpis</li>
      </ol>
    </nav>
    <?= flash_html() ?>

    <?php if ($pfron_id && !$existing_doc_number): ?>
    <!-- Panel podpisu -->
    <div class="card mb-4 border-warning shadow-sm" style="max-width:640px">
      <div class="card-header fw-bold"><i class="bi bi-pen me-1" aria-hidden="true"></i>Podpis uczestnika</div>
      <div class="card-body">
        <p class="small text-body-secondary mb-2">
          Uczestnik składa podpis odręczny poniżej lub wgraj skan podpisanej umowy.
          Po zatwierdzeniu umowie zostanie automatycznie nadany numer <strong>PFRON-AS/xx/<?= date('Y') ?></strong>.
        </p>

        <!-- Zakładki: Odręczny / Skan -->
        <ul class="nav nav-tabs nav-sm mb-3" id="sig-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-draw-btn" data-bs-toggle="tab" data-bs-target="#tab-draw"
                    type="button" role="tab" aria-controls="tab-draw" aria-selected="true">
              <i class="bi bi-pen me-1" aria-hidden="true"></i>Odręczny
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-scan-btn" data-bs-toggle="tab" data-bs-target="#tab-scan"
                    type="button" role="tab" aria-controls="tab-scan" aria-selected="false">
              <i class="bi bi-image me-1" aria-hidden="true"></i>Skan / zdjęcie
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <!-- Podpis odręczny -->
          <div class="tab-pane fade show active" id="tab-draw" role="tabpanel" aria-labelledby="tab-draw-btn">
            <canvas id="sig-canvas" style="width:100%;height:160px;border:1px solid #ccc;border-radius:4px;cursor:crosshair;touch-action:none;background:#fafafa;display:block"
                    role="img" aria-label="Pole podpisu odręcznego"></canvas>
            <div class="d-flex gap-2 mt-2 align-items-center flex-wrap">
              <button class="btn btn-outline-secondary btn-sm" id="sig-clear">Wyczyść</button>
              <span class="text-body-secondary small">Mysz, rysik lub palec</span>
            </div>
          </div>

          <!-- Skan / zdjęcie -->
          <div class="tab-pane fade" id="tab-scan" role="tabpanel" aria-labelledby="tab-scan-btn">
            <div class="border rounded p-3 bg-body-secondary text-center">
              <label for="sig-file" class="d-block mb-2 text-body-secondary small">
                <i class="bi bi-upload fs-4 d-block mb-1" aria-hidden="true"></i>
                Wgraj skan lub zdjęcie podpisanej umowy (JPG, PNG, max 4 MB)
              </label>
              <input type="file" class="form-control" id="sig-file" accept="image/jpeg,image/png,image/webp">
            </div>
            <div id="sig-scan-preview" class="mt-2" style="display:none">
              <img id="sig-scan-img" style="max-height:140px;max-width:100%;border:1px solid #ccc;border-radius:4px" alt="Podgląd skanu podpisu">
              <button class="btn btn-outline-secondary btn-sm d-block mt-1" id="sig-scan-clear">Usuń</button>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3 flex-wrap align-items-center">
          <button class="btn btn-danger" id="sig-submit" disabled>
            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Zatwierdź podpis i pobierz PDF
          </button>
          <span id="sig-status" class="text-body-secondary small"></span>
        </div>
        <div id="sign-result" class="mt-3" style="display:none"></div>
      </div>
    </div>
    <?php elseif ($existing_doc_number): ?>
    <div class="alert alert-success d-flex gap-2 align-items-center mb-4">
      <i class="bi bi-patch-check-fill fs-4" aria-hidden="true"></i>
      <div>
        Umowa zarejestrowana: <strong class="font-monospace"><?= h($existing_doc_number) ?></strong>
        <span class="text-body-secondary small ms-2"><?= $existing_signed_at ? date('d.m.Y H:i', strtotime($existing_signed_at)) : '' ?></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Przyciski pobierania PDF -->
    <div class="d-flex gap-2 mb-4 flex-wrap">
      <a href="doc_print.php?type=umowa" class="btn btn-danger" id="btn-pdf-umowa"
         <?= ($pfron_id && !$existing_doc_number) ? 'style="display:none"' : '' ?>>
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz PDF — Umowa + Regulamin
      </a>
      <a href="doc_print.php?type=regulamin" class="btn btn-outline-secondary">
        <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Pobierz PDF — Sam regulamin
      </a>
    </div>

    <script>
    (function() {
      const submit = document.getElementById('sig-submit');
      const status = document.getElementById('sig-status');
      const result = document.getElementById('sign-result');
      if (!submit) return;

      let activeSigData = null;   // aktualne dane podpisu (canvas lub skan)
      let activeMode    = 'draw'; // 'draw' | 'scan'

      // ── Zakładki ──────────────────────────────────────────────────────────
      document.getElementById('tab-draw-btn')?.addEventListener('shown.bs.tab', () => { activeMode = 'draw'; checkReady(); });
      document.getElementById('tab-scan-btn')?.addEventListener('shown.bs.tab', () => { activeMode = 'scan';  checkReady(); });

      function checkReady() {
        submit.disabled = !activeSigData;
      }

      // ── Podpis odręczny ───────────────────────────────────────────────────
      const canvas = document.getElementById('sig-canvas');
      const ctx    = canvas.getContext('2d');

      function resizeCanvas() {
        const r = canvas.getBoundingClientRect();
        const dpr = devicePixelRatio;
        canvas.width  = r.width  * dpr;
        canvas.height = r.height * dpr;
        ctx.scale(dpr, dpr);
        ctx.strokeStyle = '#111'; ctx.lineWidth = 2;
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      }
      resizeCanvas();
      window.addEventListener('resize', resizeCanvas);

      let drawing = false;
      function pos(e) {
        const r = canvas.getBoundingClientRect();
        const s = e.touches ? e.touches[0] : e;
        return { x: s.clientX - r.left, y: s.clientY - r.top };
      }
      canvas.addEventListener('mousedown',  e => { e.preventDefault(); drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
      canvas.addEventListener('mousemove',  e => { if(!drawing)return; e.preventDefault(); const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); activeSigData='canvas'; checkReady(); });
      canvas.addEventListener('mouseup',    () => drawing=false);
      canvas.addEventListener('mouseleave', () => drawing=false);
      canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); }, {passive:false});
      canvas.addEventListener('touchmove',  e => { if(!drawing)return; e.preventDefault(); const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); activeSigData='canvas'; checkReady(); }, {passive:false});
      canvas.addEventListener('touchend',   () => drawing=false);

      document.getElementById('sig-clear')?.addEventListener('click', () => {
        ctx.clearRect(0, 0, canvas.width/devicePixelRatio, canvas.height/devicePixelRatio);
        activeSigData = null; checkReady(); result.style.display='none';
      });

      // ── Upload skanu ──────────────────────────────────────────────────────
      const fileInput   = document.getElementById('sig-file');
      const scanPreview = document.getElementById('sig-scan-preview');
      const scanImg     = document.getElementById('sig-scan-img');
      let   scanDataUrl = null;

      fileInput?.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 4 * 1024 * 1024) {
          alert('Plik jest za duży (max 4 MB).'); this.value=''; return;
        }
        const reader = new FileReader();
        reader.onload = e => {
          scanDataUrl = e.target.result;
          scanImg.src = scanDataUrl;
          scanPreview.style.display = 'block';
          activeSigData = 'scan'; checkReady();
        };
        reader.readAsDataURL(file);
      });

      document.getElementById('sig-scan-clear')?.addEventListener('click', () => {
        fileInput.value = ''; scanDataUrl = null;
        scanPreview.style.display = 'none';
        activeSigData = null; checkReady();
      });

      // ── Wysyłanie ─────────────────────────────────────────────────────────
      submit.addEventListener('click', async function() {
        if (!activeSigData) return;
        submit.disabled = true; status.textContent = 'Zapisywanie…';

        const sigData = activeMode === 'scan'
          ? scanDataUrl
          : canvas.toDataURL('image/png');

        const csrf = <?= json_encode(csrf_token()) ?>;
        try {
          const res  = await fetch('sign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pfron_id: <?= $pfron_id ?>, signature_data: sigData, _csrf: csrf }),
          });
          const data = await res.json();
          if (data.ok) {
            result.className = 'alert alert-success';
            result.innerHTML = 'Numer dokumentu: <strong class="font-monospace">' + data.doc_number + '</strong>';
            result.style.display = 'block';
            status.textContent = '';
            const btnPdf = document.getElementById('btn-pdf-umowa');
            if (btnPdf) btnPdf.style.display = '';
            document.querySelector('#sign-panel p')?.remove();
          } else {
            result.className = 'alert alert-danger';
            result.textContent = 'Błąd: ' + (data.error || 'nieznany');
            result.style.display = 'block';
            submit.disabled = false; status.textContent = '';
          }
        } catch(e) {
          result.className = 'alert alert-danger';
          result.textContent = 'Błąd sieci: ' + e.message;
          result.style.display = 'block';
          submit.disabled = false; status.textContent = '';
        }
      });
    })();
    </script>
    <?php
    include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php';
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// GENEROWANIE PDF przez mPDF
// ═══════════════════════════════════════════════════════════════════════════

// Zbierz podpis jeśli istnieje
$sig_img_html = '';
if ($pfron_id && $existing_doc_number) {
    $pfrow2 = db_one("SELECT signature_data FROM k30_pfron_contracts WHERE id=?", [$pfron_id]);
    $sig_b64 = $pfrow2['signature_data'] ?? '';
    if ($sig_b64) {
        $sig_img_html = '<img src="' . htmlspecialchars($sig_b64, ENT_QUOTES) . '" style="max-width:180px;max-height:60px;display:block">';
    }
}

$doc_number_line = $existing_doc_number
    ? '<p style="text-align:right;font-size:10pt;margin:0 0 4pt">Nr dokumentu: <strong>' . htmlspecialchars($existing_doc_number, ENT_QUOTES) . '</strong></p>'
    : '';

// ── HTML umowy ──────────────────────────────────────────────────────────────
function html_umowa(array $v): string {
    extract($v);
    ob_start(); ?>
<h1 style="font-size:13pt;font-weight:bold;text-align:center;text-transform:uppercase;margin:0 0 2pt;line-height:1.4">Umowa uczestnictwa w szkoleniu indywidualnym<br>finansowanym ze środków PFRON</h1>
<?= $doc_number_line ?>
<p style="text-align:center;margin:0 0 14pt">zawarta w dniu <strong><?= $c_date ?></strong> w Nowym Sączu pomiędzy:</p>

<p style="margin:0 0 6pt"><strong>Fundacją Edukacji Empatii Rozwoju FEER</strong> z siedzibą w Nowym Sączu (adres: ul. Barbackiego 28/18, 33-300 Nowy Sącz), wpisaną do rejestru stowarzyszeń KRS pod numerem 000779281, NIP: 7343570539, reprezentowaną przez: Ziemowita Gila – Prezesa Zarządu; zwaną dalej <strong>„Fundacją"</strong>,</p>
<p style="text-align:center;font-weight:bold;margin:6pt 0">a</p>
<p style="margin:0 0 14pt">Panem/Panią <strong><?= blank_pdf($name) ?></strong><br>PESEL: <?= blank_pdf($pesel) ?><br>adres <?= blank_pdf($address) ?><br>telefon <?= blank_pdf($phone) ?><br>e-mail <?= blank_pdf($email) ?><br>zwanym/-ą dalej <strong>„Uczestnikiem"</strong>.</p>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 1. Przedmiot umowy</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Przedmiotem niniejszej umowy jest określenie zasad uczestnictwa Uczestnika w indywidualnym szkoleniu finansowanym ze środków Państwowego Funduszu Rehabilitacji Osób Niepełnosprawnych (PFRON), realizowanym za pośrednictwem właściwego MOPS lub innej jednostki uprawnionej – umowa główna [skierowanie] z dnia <?= blank_pdf($mc_date, '..................') ?> - znak sprawy: <?= blank_pdf($mc_sign, '..................') ?></li>
  <li>Szkolenie obejmuje łącznie <strong><?= $h_total ?> godzin dydaktycznych</strong>.</li>
  <li>Szkolenie realizowane będzie zgodnie z indywidualnie ustalanym harmonogramem.</li>
  <li>Integralną część niniejszej umowy stanowi Regulamin uczestnictwa w szkoleniach Fundacji.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 2. Oświadczenia Uczestnika</h3>
<p style="margin:0 0 4pt">Uczestnik oświadcza, że:</p>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>został poinformowany o zasadach finansowania szkolenia;</li>
  <li>wie, że środki publiczne przekazywane są Fundacji przed zakończeniem szkolenia;</li>
  <li>ma świadomość, że Fundacja rezerwuje dla niego czas pracy trenera kosztem innych osób oczekujących na wsparcie;</li>
  <li>rozumie, że nieuzasadnione odwoływanie zajęć może skutkować obowiązkiem zwrotu środków publicznych;</li>
  <li>zobowiązuje się współdziałać z Fundacją w sposób umożliwiający prawidłowe wykonanie szkolenia.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 3. Obowiązki Fundacji</h3>
<p style="margin:0 0 4pt">Fundacja zobowiązuje się do:</p>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>przeprowadzenia <strong><?= $h_training ?> godzin</strong> szkolenia;</li>
  <li>zapewnienia wykwalifikowanego trenera;</li>
  <li>pozostawania w gotowości do realizacji szkolenia przez okres jego trwania;</li>
  <li>ustalania terminów zajęć z uwzględnieniem możliwości Uczestnika;</li>
  <li>prowadzenia dokumentacji wymaganej przez instytucję finansującą.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 4. Obowiązki Uczestnika</h3>
<p style="margin:0 0 4pt">Uczestnik zobowiązuje się do:</p>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>uczestnictwa we wszystkich zaplanowanych zajęciach;</li>
  <li>aktywnego współdziałania z Fundacją w realizacji szkolenia;</li>
  <li>punktualnego rozpoczynania zajęć;</li>
  <li>niezwłocznego informowania o okolicznościach uniemożliwiających realizację szkolenia;</li>
  <li>ukończenia szkolenia w terminie nie dłuższym niż 3 miesiące od pierwszych zajęć, chyba że Fundacja wyrazi zgodę na przedłużenie.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 5. Okres próbny</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>W ciągu pierwszych <?= $h_trial ?> godzin szkolenia Uczestnik może zrezygnować bez podawania przyczyny i może zgłosić potrzebę zmiany trenera.</li>
  <li>Po upływie <?= $h_trial ?> godzin strony uznają, że zaakceptowały sposób realizacji i zobowiązują się do ukończenia szkolenia w całości.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 6. Zmiana terminów</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Uczestnik może dokonać zmiany terminu maksymalnie pięć razy w całym okresie szkolenia.</li>
  <li>Zmiana terminu wymaga zgłoszenia najpóźniej 48 godzin przed rozpoczęciem zajęć.</li>
  <li>Zgłoszenie dokonane po upływie tego terminu traktowane jest jako odwołanie zajęć z przyczyn leżących po stronie Uczestnika.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 7. Kara umowna</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Uczestnik przyjmuje do wiadomości, że Fundacja rezerwuje czas pracy trenera <strong>wyłącznie na rzecz danego Uczestnika</strong> – co wiąże się z kosztami transportu oraz czasem pracy trenera.</li>
  <li>Rezerwacja uniemożliwia realizację szkolenia innych beneficjentów niezależnie od tego, czy szkolenie zostanie przeprowadzone.</li>
  <li>W przypadku niewykonania lub nienależytego wykonania obowiązków z przyczyn leżących wyłącznie po stronie Uczestnika, Fundacja jest uprawniona do naliczenia kary umownej.</li>
  <li>Kara umowna wynosi <strong><?= htmlspecialchars($penalty_amt, ENT_QUOTES) ?> zł (słownie: <?= htmlspecialchars($penalty_wrd, ENT_QUOTES) ?> złotych)</strong> za każdą niezrealizowaną godzinę.</li>
  <li>Kara nie jest naliczana w przypadku nagłej choroby, hospitalizacji, wypadku, śmierci osoby najbliższej lub innych nadzwyczajnych zdarzeń losowych.</li>
  <li>Łączna wysokość kar nie może przekroczyć wartości odpowiadającej liczbie godzin niezrealizowanych z winy Uczestnika.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 8. Rozwiązanie umowy</h3>
<p style="margin:0 0 4pt">Fundacja może rozwiązać umowę ze skutkiem natychmiastowym w przypadku: dwukrotnego nieusprawiedliwionego niestawienia się, uporczywego przekładania terminów, odmowy współpracy, naruszenia Regulaminu lub zachowania uniemożliwiającego prowadzenie szkolenia.</p>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 9. Informowanie instytucji finansujących</h3>
<p style="margin:0 0 8pt">Uczestnik przyjmuje do wiadomości, że w przypadku przerwania lub niewykonania umowy Fundacja może przekazać właściwemu MOPS, PFRON lub innemu podmiotowi finansującemu informacje dotyczące przebiegu realizacji szkolenia w zakresie wymaganym przepisami prawa.</p>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 10. Przetwarzanie danych</h3>
<p style="margin:0 0 4pt">Zgodnie z art. 13 RODO Fundacja informuje, że administratorem danych osobowych jest Fundacja Edukacji Empatii Rozwoju FEER (ul. Barbackiego 28/18, 33-300 Nowy Sącz, KRS 000779281, NIP 7343570539, kontakt@feer.org.pl). Dane przetwarzane są na podstawie art. 6 ust. 1 lit. b i c RODO w celu realizacji umowy i wypełniania obowiązków prawnych. Uczestnikowi przysługują prawa dostępu, sprostowania, usunięcia i ograniczenia przetwarzania danych oraz skarga do Prezesa UODO (ul. Stawki 2, 00-193 Warszawa). Dane mogą być przekazywane podmiotom finansującym (MOPS/PFRON) oraz podmiotom współpracującym z Fundacją.</p>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 11. Postanowienia końcowe</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>W sprawach nieuregulowanych zastosowanie mają przepisy Kodeksu cywilnego.</li>
  <li>Wszelkie zmiany umowy wymagają formy pisemnej pod rygorem nieważności.</li>
  <li>Spory strony będą starały się rozwiązać polubownie, a gdy niemożliwe – właściwy sąd powszechny ze względu na miejsce wykonania umowy.</li>
  <li>Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron.</li>
</ol>

<br><br>
<table style="width:100%;border:none">
  <tr>
    <td style="width:44%;text-align:center;border:none">
      <br><br><br>
      <?= $sig_img_html ? '<div style="text-align:center">' . $sig_img_html . '</div>' : '<br>' ?>
      <div style="border-top:1px solid #000;padding-top:3pt;font-size:10pt">Fundacja</div>
    </td>
    <td style="width:12%;border:none"></td>
    <td style="width:44%;text-align:center;border:none">
      <br><br><br><br>
      <div style="border-top:1px solid #000;padding-top:3pt;font-size:10pt">Uczestnik</div>
    </td>
  </tr>
</table>

<p style="margin-top:16pt;font-size:10pt"><strong>Załącznik:</strong> Regulamin uczestnictwa w szkoleniach Fundacji</p>
    <?php
    return ob_get_clean();
}

// ── HTML regulaminu ─────────────────────────────────────────────────────────
function html_regulamin(int $h_training): string {
    ob_start(); ?>
<h1 style="font-size:13pt;font-weight:bold;text-align:center;text-transform:uppercase;margin:0 0 4pt;line-height:1.4">Regulamin uczestnictwa w indywidualnych szkoleniach<br>w Fundacji Edukacji Empatii Rozwoju „FEER"<br>finansowanych ze środków publicznych</h1>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 1. Postanowienia ogólne</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Niniejszy Regulamin określa zasady uczestnictwa w indywidualnych szkoleniach organizowanych przez Fundację Edukacji Empatii Rozwoju „FEER", finansowanych ze środków publicznych (PFRON lub innych funduszy celowych), przekazywanych za pośrednictwem właściwego organu.</li>
  <li>Regulamin stanowi integralny załącznik do Umowy uczestnictwa w indywidualnym szkoleniu finansowanym ze środków publicznych.</li>
  <li>Podpisanie Umowy oznacza potwierdzenie zapoznania się z Regulaminem i zobowiązanie do jego przestrzegania.</li>
  <li>Celem Regulaminu jest określenie praw i obowiązków stron, zapewnienie sprawnej organizacji szkoleń oraz właściwego wykorzystania środków publicznych.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 2. Definicje</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li><strong>Fundacja</strong> – Fundacja Edukacji Empatii Rozwoju „FEER".</li>
  <li><strong>Uczestnik</strong> – osoba zakwalifikowana do udziału w szkoleniu finansowanym ze środków publicznych.</li>
  <li><strong>Szkolenie</strong> – indywidualny proces edukacyjny o określonej liczbie godzin, realizowany zgodnie z zakresem zaakceptowanym przez instytucję finansującą.</li>
  <li><strong>Trener</strong> – osoba prowadząca szkolenie w imieniu Fundacji.</li>
  <li><strong>Umowa</strong> – Umowa uczestnictwa w indywidualnym szkoleniu finansowanym ze środków publicznych.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 3. Charakter szkolenia</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Szkolenia mają charakter indywidualny i są przygotowywane z uwzględnieniem potrzeb konkretnego Uczestnika. Koszt pokrywany jest przez podmiot publiczny po zaakceptowaniu przez instytucję finansującą.</li>
  <li>Z chwilą potwierdzenia realizacji Fundacja zobowiązuje się do zapewnienia wykwalifikowanego trenera, przygotowania programu, rezerwacji czasu pracy trenera, zapewnienia warunków organizacyjnych i prowadzenia dokumentacji.</li>
  <li>Każdy ustalony termin oznacza zarezerwowanie czasu pracy trenera wyłącznie dla jednego Uczestnika.</li>
  <li>Program szkolenia może być modyfikowany w trakcie realizacji przy zachowaniu celu i zakresu szkolenia.</li>
  <li>Fundacja dobiera metody z uwzględnieniem rodzaju niepełnosprawności i indywidualnych potrzeb Uczestnika.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 4. Zasada współdziałania</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Fundacja i Uczestnik wykonują swoje obowiązki w sposób lojalny, z poszanowaniem czasu i uzasadnionych interesów drugiej strony.</li>
  <li>Fundacja realizuje szkolenie z należytą starannością.</li>
  <li>Uczestnik współdziała z Fundacją: terminowo uczestniczy w zajęciach, punktualnie je rozpoczyna, pozostaje w kontakcie, informuje o przeszkodach i wykonuje zalecenia organizacyjne.</li>
  <li>Niewykonanie szkolenia z przyczyn leżących po stronie Uczestnika może skutkować koniecznością dokonania korekt rozliczeń lub zwrotu dofinansowania.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 5. Organizacja szkolenia</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Szkolenie obejmuje <strong><?= $h_training ?> godzin dydaktycznych</strong> i co do zasady powinno zostać zakończone w terminie 3 miesięcy od dnia pierwszych zajęć.</li>
  <li>Harmonogram ustalany jest indywidualnie pomiędzy Fundacją a Uczestnikiem.</li>
  <li>W trakcie szkolenia Fundacja może udostępniać sprzęt, pomoce dydaktyczne i materiały szkoleniowe.</li>
  <li>Uczestnik korzysta ze sprzętu zgodnie z jego przeznaczeniem i nie może instalować oprogramowania ani zmieniać konfiguracji bez zgody Fundacji.</li>
  <li>Materiały szkoleniowe przeznaczone są wyłącznie do użytku Uczestnika i nie mogą być udostępniane osobom trzecim bez zgody Fundacji.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 6. Znaczenie ustalonego terminu</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Ustalenie terminu oznacza rezerwację czasu pracy trenera, zabezpieczenie miejsca, przygotowanie materiałów i gotowość do wykonania szkolenia.</li>
  <li>Zarezerwowanego czasu nie można przeznaczyć dla innego Uczestnika bez odpowiednio wcześniejszej informacji.</li>
  <li>Każde nieodwołane spotkanie powoduje niewykorzystanie czasu trenera i ogranicza możliwość wsparcia innych beneficjentów.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 7. Okres adaptacyjny</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Pierwsze 3 godziny stanowią okres adaptacyjny — Uczestnik może w tym czasie zrezygnować bez podawania przyczyny lub zgłosić zastrzeżenia do trenera.</li>
  <li>Po zakończeniu okresu adaptacyjnego strony zobowiązują się do ukończenia szkolenia w całości.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 8. Zmiana terminów</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Zmiana wymaga zgłoszenia nie później niż 48 godzin przed zajęciami i potwierdzenia przez Fundację.</li>
  <li>Uczestnik może zmienić termin maksymalnie pięć razy w całym szkoleniu.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 9. Nieobecności</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Usprawiedliwiona nieobecność: nagła choroba, hospitalizacja, wypadek, zdarzenie losowe lub inna okoliczność niezależna od Uczestnika.</li>
  <li>Nieobecność powinna być zgłoszona niezwłocznie. Fundacja może żądać dokumentu potwierdzającego przyczynę.</li>
  <li>Nieobecność bez powiadomienia lub bez uzasadnienia traktowana jest jako nieusprawiedliwiona i może skutkować karą umowną.</li>
</ol>

<h3 style="font-size:11pt;font-weight:bold;text-align:center;margin:14pt 0 2pt">§ 10. Postanowienia końcowe</h3>
<ol style="margin:4pt 0;padding-left:1.6em">
  <li>Regulamin wchodzi w życie z dniem podpisania Umowy.</li>
  <li>Fundacja zastrzega prawo zmiany Regulaminu z co najmniej 7-dniowym wyprzedzeniem.</li>
  <li>W sprawach nieuregulowanych zastosowanie mają przepisy Kodeksu cywilnego.</li>
</ol>

<br><br>
<table style="width:100%;border:none">
  <tr>
    <td style="width:44%;text-align:center;border:none"><br><br><br><div style="border-top:1px solid #000;padding-top:3pt;font-size:10pt">Fundacja</div></td>
    <td style="width:12%;border:none"></td>
    <td style="width:44%;text-align:center;border:none"><br><br><br><div style="border-top:1px solid #000;padding-top:3pt;font-size:10pt">Uczestnik – potwierdzam zapoznanie się z Regulaminem</div></td>
  </tr>
</table>
    <?php
    return ob_get_clean();
}

// ── Złóż HTML i wygeneruj PDF ───────────────────────────────────────────────
$v = compact('c_date','pfron_no','mc_date','mc_sign','h_total','h_training','h_trial',
             'penalty_amt','penalty_wrd','name','pesel','address','phone','email',
             'doc_number_line','sig_img_html');

$body_html = '';
if ($type === 'umowa') {
    $body_html = html_umowa($v);
    $body_html .= '<pagebreak />';
    $body_html .= html_regulamin($h_training);
} else {
    $body_html = html_regulamin($h_training);
}

$base_css = '
body { font-family: "DejaVu Serif", serif; font-size: 11pt; line-height: 1.55; color: #000; }
ol   { margin: 4pt 0; padding-left: 1.6em; }
ol li{ margin-bottom: 2pt; }
table { border-collapse: collapse; }
td   { vertical-align: top; }
';

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 25,
        'margin_right'  => 20,
        'margin_top'    => 20,
        'margin_bottom' => 18,
        'margin_header' => 0,
        'margin_footer' => 0,
        'default_font'  => 'dejavuserif',
    ]);
    $mpdf->SetTitle($type === 'umowa' ? 'Umowa PFRON' : 'Regulamin PFRON');
    $mpdf->SetAuthor('FEER');
    $mpdf->SetCreator('FEER SZO');
    $mpdf->WriteHTML($base_css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($body_html, \Mpdf\HTMLParserMode::HTML_BODY);

    $filename = $type === 'umowa'
        ? 'PFRON-umowa' . ($existing_doc_number ? '-' . str_replace('/', '_', $existing_doc_number) : '') . '.pdf'
        : 'PFRON-regulamin.pdf';

    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:red;padding:2rem">Błąd generowania PDF: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
