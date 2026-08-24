<?php
/**
 * misc/domeny/_generuj.php — generator stron informacyjnych domen technicznych.
 *
 * Fundacja trzyma kilka domen, które nie są jej stroną: jedne tylko przekierowują
 * na feer.org.pl, inne obsługują wyłącznie pocztę projektów siostrzanych. Wpisanie
 * takiego adresu kończyło się pustą stroną albo błędem serwera — czyli wyglądało
 * na awarię, a nie na zamierzony stan.
 *
 * Strony są STATYCZNE i samodzielne: żadnego PHP, CDN-u ani zewnętrznych plików,
 * bo lądują na hostingach, które mają tylko serwować plik. Tło bierzemy z tego
 * samego generatora co reszta systemu (includes/app_bg.php) — kafel SVG wpisany
 * w data-URI, więc strona zostaje jednym plikiem.
 *
 * Użycie:  php misc/domeny/_generuj.php
 * Wynik:   misc/domeny/<domena>.html  (do wgrania jako index.html na serwerze domeny)
 */

if (php_sapi_name() !== 'cli') {
    die("Generator uruchamia się z konsoli: php misc/domeny/_generuj.php\n");
}

require_once dirname(dirname(__DIR__)) . '/includes/app_bg.php';

const CEL         = 'https://feer.org.pl';
const ORGANIZACJA = 'Fundacja Edukacji Empatii Rozwoju FEER';

/**
 * Domeny do opisania.
 *
 * `redirect` = liczba sekund do przekierowania; 0 oznacza „ta domena nigdzie
 * nie prowadzi" i wtedy strona nie odlicza ani nie przenosi.
 */
$DOMENY = [
    'edukacja.cloud' => [
        'redirect' => 6,
        'lead'     => 'To domena techniczna Fundacji.',
        'opis'     => 'Adres <strong>edukacja.cloud</strong> należy do Fundacji i służy do celów '
                    . 'technicznych. Nie prowadzimy pod nim serwisu — za chwilę przeniesiemy Cię '
                    . 'na naszą stronę główną.',
        'ikona'    => 'chmura',
    ],
    'feer.me' => [
        'redirect' => 6,
        'lead'     => 'To domena techniczna Fundacji.',
        'opis'     => 'Adres <strong>feer.me</strong> należy do Fundacji i służy do celów '
                    . 'technicznych — głównie do krótkich odnośników. Za chwilę przeniesiemy Cię '
                    . 'na naszą stronę główną.',
        'ikona'    => 'link',
    ],
    'equi.org.pl' => [
        'redirect' => 0,
        'lead'     => 'To domena techniczna Fundacji — bez przekierowania.',
        'opis'     => 'Adres <strong>equi.org.pl</strong> należy do Fundacji i obsługuje '
                    . '<strong>pocztę projektów siostrzanych</strong>. Nie prowadzimy pod nim '
                    . 'serwisu i celowo nie przekierowujemy — adresy e-mail w tej domenie działają '
                    . 'niezależnie od strony.',
        'ikona'    => 'koperta',
    ],
];

/** Ikony jako inline SVG — plik ma być samodzielny, więc żadnych fontów ikon. */
function ikona(string $key): string
{
    $paths = [
        'chmura'  => '<path d="M6.5 20a5.5 5.5 0 0 1-.5-10.98 7 7 0 0 1 13.4 1.63A4.5 4.5 0 0 1 18.5 20z"/>',
        'link'    => '<path d="M10 13a5 5 0 0 0 7.07 0l3-3A5 5 0 0 0 13 3l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3A5 5 0 0 0 11 21l1.5-1.5"/>',
        'koperta' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$key] ?? $paths['link']) . '</svg>';
}

/** Kafel tła — ten sam kształt co w app_bg_tile(), tu w wersji na jasną kanwę. */
$tile = app_bg_tile('#000', '.035');

$szablon = static function (string $domena, array $d) use ($tile): string {
    $sek      = (int)$d['redirect'];
    $przekier = $sek > 0;
    $tytul    = $domena . ' — domena techniczna ' . ORGANIZACJA;
    $cel_js   = CEL;   // heredoc nie rozwija stałych, więc adres przez zmienną

    // Odliczanie ma dać się ZATRZYMAĆ (WCAG 2.2.1 Timing Adjustable). Automatyczne
    // przeniesienie bez możliwości przerwania zabiera stronę komuś, kto właśnie
    // czyta, dlaczego tu trafił.
    $skrypt = $przekier ? <<<JS

<script>
(function () {
  var sek  = {$sek};
  var out  = document.getElementById('licznik');
  var stop = document.getElementById('stop');
  var info = document.getElementById('stan');
  var t = null;

  function tik() {
    sek -= 1;
    if (out) out.textContent = sek;
    if (sek <= 0) { window.location.replace('{$cel_js}'); return; }
    t = setTimeout(tik, 1000);
  }

  if (stop) {
    stop.addEventListener('click', function () {
      clearTimeout(t);
      stop.hidden = true;
      if (info) info.textContent = 'Przekierowanie zatrzymane. Możesz przejść dalej przyciskiem powyżej.';
    });
  }
  t = setTimeout(tik, 1000);
})();
</script>
JS : '';

    $blok_przekierowania = $przekier ? <<<HTML

    <p class="odlicz" id="stan" role="status" aria-live="polite">
      Przekierowanie za <strong id="licznik">{$sek}</strong> s.
    </p>
    <button type="button" class="stop" id="stop">Zatrzymaj przekierowanie</button>
HTML : '';

    $noscript = $przekier
        ? '<noscript><p class="odlicz">Automatyczne przekierowanie wymaga JavaScriptu — '
        . 'skorzystaj z przycisku powyżej.</p></noscript>'
        : '';

    $opis  = $d['opis'];
    $lead  = $d['lead'];
    $ikona = ikona($d['ikona']);
    $cel   = CEL;
    $org   = ORGANIZACJA;
    $rok   = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$tytul}</title>
<meta name="description" content="{$domena} to domena techniczna {$org}.">
<meta name="robots" content="noindex,follow">
<link rel="canonical" href="{$cel}">
<style>
*,*::before,*::after{box-sizing:border-box}
body{
  margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:1.5rem;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#1E293B;
  background-color:#E8EBF0;
  background-image:{$tile},repeating-linear-gradient(135deg,rgba(0,0,0,.028) 0 3px,transparent 3px 26px);
  background-size:420px 420px,auto;background-attachment:fixed;background-position:center top;
}
.karta{
  max-width:560px;width:100%;background:#fff;border:1px solid #E2E8F0;border-radius:18px;
  padding:2.25rem 2rem;box-shadow:0 10px 40px rgba(15,23,42,.08);text-align:center;
}
.znak{
  width:56px;height:56px;margin:0 auto 1.25rem;border-radius:14px;display:flex;
  align-items:center;justify-content:center;background:#EFF6FF;color:#1D4ED8;
}
.znak svg{width:28px;height:28px}
.domena{
  display:inline-block;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;
  color:#475569;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:20px;
  padding:.2rem .7rem;margin-bottom:1rem;
}
h1{font-size:1.5rem;font-weight:800;letter-spacing:-.02em;line-height:1.25;margin:0 0 .75rem}
p{font-size:.92rem;line-height:1.7;color:#475569;margin:0 0 1.25rem}
.cta{
  display:inline-flex;align-items:center;gap:.45rem;background:#1D4ED8;color:#fff;
  font-size:.95rem;font-weight:700;padding:.75rem 1.6rem;border-radius:11px;text-decoration:none;
  transition:background .12s,transform .12s;
}
.cta:hover,.cta:focus{background:#1E40AF;transform:translateY(-1px);color:#fff}
.odlicz{font-size:.82rem;color:#64748B;margin:1rem 0 .35rem}
.stop{
  background:none;border:1px solid #CBD5E1;color:#475569;font-size:.78rem;font-weight:600;
  padding:.3rem .8rem;border-radius:8px;cursor:pointer;
}
.stop:hover{border-color:#94A3B8;color:#1E293B}
.stopka{margin-top:1.75rem;padding-top:1.1rem;border-top:1px solid #E2E8F0;font-size:.75rem;color:#94A3B8}
.stopka a{color:#64748B}
:focus-visible{outline:3px solid #2563EB;outline-offset:3px;border-radius:6px}
@media (prefers-color-scheme:dark){
  body{background-color:#0F172A;color:#E2E8F0;
    background-image:{$tile},repeating-linear-gradient(135deg,rgba(255,255,255,.02) 0 3px,transparent 3px 26px)}
  .karta{background:#1E293B;border-color:#334155;box-shadow:0 10px 40px rgba(0,0,0,.35)}
  h1{color:#F1F5F9}
  p,.stopka a{color:#94A3B8}
  .domena{background:#0F172A;border-color:#334155;color:#94A3B8}
  .znak{background:#1E3A5F;color:#93C5FD}
  .stop{border-color:#475569;color:#CBD5E1}
}
</style>
</head>
<body>
  <main class="karta">
    <div class="znak">{$ikona}</div>
    <span class="domena">{$domena}</span>
    <h1>{$lead}</h1>
    <p>{$opis}</p>
    <a class="cta" href="{$cel}">Przejdź na feer.org.pl</a>
{$blok_przekierowania}
    {$noscript}
    <div class="stopka">
      {$org} · <a href="{$cel}">feer.org.pl</a> · &#169; {$rok}
    </div>
  </main>
{$skrypt}
</body>
</html>

HTML;
};

$dir = __DIR__;
foreach ($DOMENY as $domena => $d) {
    $plik = $dir . '/' . $domena . '.html';
    file_put_contents($plik, $szablon($domena, $d));
    printf("  %-16s → %s (%s)\n", $domena, basename($plik),
        $d['redirect'] > 0 ? 'przekierowanie po ' . $d['redirect'] . ' s' : 'bez przekierowania');
}
echo "Gotowe. Każdy plik wgrywa się na serwer swojej domeny jako index.html.\n";
