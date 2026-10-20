<?php
/**
 * modules/it_portal/logic/itPortalBlocks.php — edytor blokowy podstron Portalu Usług IT.
 *
 * Treść artykułu / wstępu usługi = lista bloków (JSON w itp_articles.blocks, itp_categories.intro_blocks):
 *   text    {html}
 *   wizard  {title, intro, steps:[{title, html, images:[{file, alt, caption}]}]}   — „Kreator krok po kroku”
 *   image   {file, alt, caption}                                                   — pojedynczy zrzut ekranu
 *   callout {kind: info|warn|tip, html}
 * Zrzuty ekranu: uploads/it_portal/{hex}.{png|jpg|gif|webp}, publicznie przez /uslugi-it/img/{plik}.
 * Kolumny body_html / intro_html trzymają sam tekst z bloków (zgodność wsteczna, wyszukiwarka);
 * artykuł bez bloków renderuje się jak dawniej z body_html.
 */
require_once dirname(__DIR__, 3) . '/includes/functions.php';

const ITP_IMG_RE = '/^[a-f0-9]{20}\.(png|jpg|gif|webp)$/';
const ITP_CALLOUTS = ['info' => 'Informacja', 'tip' => 'Wskazówka', 'warn' => 'Uwaga'];

function itPortalImageDir(): string {
    return rtrim(UPLOAD_DIR, '/') . '/it_portal/';
}

/** Zapisuje przesłany obraz; zwraca nazwę pliku. @throws InvalidArgumentException */
function itPortalImageStore(array $f): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) throw new InvalidArgumentException('Nie udało się odebrać pliku.');
    if ($f['size'] > 8 * 1024 * 1024) throw new InvalidArgumentException('Obraz jest większy niż 8 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? '';
    if ($ext === '') throw new InvalidArgumentException('Dozwolone formaty: PNG, JPG, GIF, WEBP.');
    $dir = itPortalImageDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new InvalidArgumentException('Nie można utworzyć katalogu na obrazy.');
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) throw new InvalidArgumentException('Nie udało się zapisać obrazu.');
    return $name;
}

function itPortalImageUrl(string $file): string {
    return itPortalUrl('img/' . $file);
}

function itPortalBlockImage(mixed $im): ?array {
    if (!is_array($im)) return null;
    $file = (string)($im['file'] ?? '');
    if (!preg_match(ITP_IMG_RE, $file) || !is_file(itPortalImageDir() . $file)) return null;
    return ['file' => $file, 'alt' => mb_substr(trim((string)($im['alt'] ?? '')), 0, 300), 'caption' => mb_substr(trim((string)($im['caption'] ?? '')), 0, 300)];
}

/** Czyści listę bloków z wejścia (JSON lub tablica) do bezpiecznej postaci. */
function itPortalBlocksClean(mixed $raw): array {
    $in = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($in)) return [];
    $out = [];
    foreach ($in as $b) {
        if (!is_array($b)) continue;
        switch ($b['type'] ?? '') {
            case 'text':
                $html = itPortalSanitizeHtml((string)($b['html'] ?? ''));
                if ($html !== '') $out[] = ['type' => 'text', 'html' => $html];
                break;
            case 'callout':
                $html = itPortalSanitizeHtml((string)($b['html'] ?? ''));
                $kind = isset(ITP_CALLOUTS[$b['kind'] ?? '']) ? $b['kind'] : 'info';
                if ($html !== '') $out[] = ['type' => 'callout', 'kind' => $kind, 'html' => $html];
                break;
            case 'image':
                if ($im = itPortalBlockImage($b)) $out[] = ['type' => 'image'] + $im;
                break;
            case 'wizard':
                $steps = [];
                foreach (array_slice((array)($b['steps'] ?? []), 0, 60) as $s) {
                    if (!is_array($s)) continue;
                    $imgs = array_values(array_filter(array_map('itPortalBlockImage', array_slice((array)($s['images'] ?? []), 0, 8))));
                    $st = ['title' => mb_substr(trim((string)($s['title'] ?? '')), 0, 200), 'html' => itPortalSanitizeHtml((string)($s['html'] ?? '')), 'images' => $imgs];
                    if ($st['title'] !== '' || $st['html'] !== '' || $imgs) $steps[] = $st;
                }
                if ($steps) $out[] = ['type' => 'wizard', 'title' => mb_substr(trim((string)($b['title'] ?? '')), 0, 200), 'intro' => itPortalSanitizeHtml((string)($b['intro'] ?? '')), 'steps' => $steps];
                break;
        }
    }
    return $out;
}

/** @return array{0:string,1:string} [JSON bloków, tekst do kolumny *_html]; bez pola bloków — dawny textarea. */
function itPortalBlocksFromInput(array $in, string $blocksKey, string $htmlKey): array {
    if (!isset($in[$blocksKey])) return ['', itPortalSanitizeHtml((string)($in[$htmlKey] ?? ''))];
    $blocks = itPortalBlocksClean((string)$in[$blocksKey]);
    $text = '';
    foreach ($blocks as $b) if (in_array($b['type'], ['text', 'callout'], true)) $text .= $b['html'] . "\n";
    return [$blocks ? json_encode($blocks, JSON_UNESCAPED_UNICODE) : '', trim($text)];
}

function itPortalBlocksPlain(string $blocksJson, string $fallbackHtml): string {
    $parts = [itPortalPlain($fallbackHtml)];
    foreach (itPortalBlocksClean($blocksJson) as $b) {
        if ($b['type'] === 'wizard') {
            $parts[] = $b['title'] . ' ' . itPortalPlain($b['intro']);
            foreach ($b['steps'] as $s) $parts[] = $s['title'] . ' ' . itPortalPlain($s['html']);
        } elseif ($b['type'] === 'image') {
            $parts[] = $b['alt'] . ' ' . $b['caption'];
        }
    }
    return trim(implode("\n", array_filter($parts, fn($p) => trim($p) !== '')));
}

/** Bloki do edytora: zapisane albo — dla starego artykułu — jeden blok tekstu z kolumny HTML. */
function itPortalBlocksInit(array $row, string $blocksKey, string $htmlKey): array {
    $blocks = itPortalBlocksClean((string)($row[$blocksKey] ?? ''));
    if (!$blocks && trim((string)($row[$htmlKey] ?? '')) !== '') $blocks = [['type' => 'text', 'html' => (string)$row[$htmlKey]]];
    return $blocks;
}

function itPortalBlocksHas(array $row, string $blocksKey, string $htmlKey): bool {
    return trim((string)($row[$blocksKey] ?? '')) !== '' || trim((string)($row[$htmlKey] ?? '')) !== '';
}

function itPortalRenderImage(array $im): string {
    $alt = $im['alt'] !== '' ? $im['alt'] : ($im['caption'] !== '' ? $im['caption'] : 'Zrzut ekranu');
    $u = h(itPortalImageUrl($im['file']));
    return '<figure class="itp-shot"><a href="' . $u . '" target="_blank" rel="noopener" title="Otwórz w pełnym rozmiarze">'
         . '<img src="' . $u . '" alt="' . h($alt) . '" loading="lazy"></a>'
         . ($im['caption'] !== '' ? '<figcaption>' . h($im['caption']) . '</figcaption>' : '') . '</figure>';
}

/** HTML treści podstrony: z bloków albo (artykuł starego typu) z kolumny HTML. */
function itPortalRenderBlocks(array $row, string $blocksKey, string $htmlKey, string $empty = ''): string {
    $blocks = itPortalBlocksClean((string)($row[$blocksKey] ?? ''));
    if (!$blocks) return itPortalSanitizeHtml((string)($row[$htmlKey] ?? '')) ?: $empty;
    $out = ''; $n = 0;
    foreach ($blocks as $b) {
        switch ($b['type']) {
            case 'text': $out .= $b['html']; break;
            case 'image': $out .= itPortalRenderImage($b); break;
            case 'callout':
                $out .= '<aside class="itp-callout itp-callout--' . $b['kind'] . '"><strong class="itp-callout__t">' . h(ITP_CALLOUTS[$b['kind']]) . '</strong><div>' . $b['html'] . '</div></aside>';
                break;
            case 'wizard':
                $n++; $total = count($b['steps']);
                $out .= '<section class="itp-wizard" aria-label="' . h($b['title'] !== '' ? $b['title'] : 'Instrukcja krok po kroku') . '">';
                if ($b['title'] !== '') $out .= '<h2 class="itp-wizard__h">' . h($b['title']) . '</h2>';
                if ($b['intro'] !== '') $out .= '<div class="itp-wizard__intro itp-body">' . $b['intro'] . '</div>';
                $out .= '<p class="itp-wizard__meta">Liczba kroków: ' . $total . '</p><ol class="itp-steps">';
                foreach ($b['steps'] as $i => $s) {
                    $out .= '<li class="itp-step"><div class="itp-step__head"><span class="itp-step__no" aria-hidden="true">' . ($i + 1) . '</span>'
                          . '<h3 class="itp-step__t"><span class="itp-sr">Krok ' . ($i + 1) . ' z ' . $total . ': </span>' . h($s['title'] !== '' ? $s['title'] : 'Krok ' . ($i + 1)) . '</h3></div>'
                          . '<div class="itp-step__body">' . ($s['html'] !== '' ? '<div class="itp-body">' . $s['html'] . '</div>' : '');
                    $imgs = '';
                    foreach ($s['images'] as $im) $imgs .= itPortalRenderImage($im);
                    $out .= $imgs . '</div></li>';
                }
                $out .= '</ol></section>';
                break;
        }
    }
    return $out . ($n ? itPortalWizardScript() : '');
}

/** Tryb „po kolei” kreatora (progresywne ulepszenie — bez JS lista kroków jest w pełni czytelna). */
function itPortalWizardScript(): string {
    static $sent = false;
    if ($sent) return '';
    $sent = true;
    return <<<'HTML'
<script>
(function () {
  document.querySelectorAll('.itp-wizard').forEach(function (w) {
    var steps = w.querySelectorAll('.itp-step'), cur = 0, one = false;
    if (steps.length < 2) return;
    var bar = document.createElement('div');
    bar.className = 'itp-wizard__bar';
    bar.innerHTML = '<button type="button" class="itp-btn" data-a="mode" aria-pressed="false">Pokaż po jednym kroku</button>'
      + '<span class="itp-wizard__nav" hidden><button type="button" class="itp-btn" data-a="prev">Wstecz</button>'
      + '<span class="itp-wizard__pos" role="status" aria-live="polite"></span>'
      + '<button type="button" class="itp-btn itp-btn--primary" data-a="next">Dalej</button></span>';
    w.querySelector('.itp-wizard__meta').after(bar);
    var nav = bar.querySelector('.itp-wizard__nav'), pos = bar.querySelector('.itp-wizard__pos'), mode = bar.querySelector('[data-a=mode]');
    function show() {
      steps.forEach(function (s, i) { s.hidden = one && i !== cur; });
      nav.hidden = !one;
      pos.textContent = 'Krok ' + (cur + 1) + ' z ' + steps.length;
      bar.querySelector('[data-a=prev]').disabled = cur === 0;
      bar.querySelector('[data-a=next]').disabled = cur === steps.length - 1;
      mode.textContent = one ? 'Pokaż wszystkie kroki' : 'Pokaż po jednym kroku';
      mode.setAttribute('aria-pressed', one ? 'true' : 'false');
    }
    bar.addEventListener('click', function (e) {
      var a = e.target.getAttribute && e.target.getAttribute('data-a');
      if (a === 'mode') one = !one; else if (a === 'prev' && cur > 0) cur--; else if (a === 'next' && cur < steps.length - 1) cur++; else return;
      show();
      if (one && a !== 'mode') steps[cur].scrollIntoView({block: 'nearest'});
    });
    show();
  });
})();
</script>
HTML;
}
