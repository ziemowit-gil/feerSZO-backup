<?php
/** Test zgodności: stopkaRenderHtml() (PHP) == stopkaGen().html() (JS, node). Użycie: php cli/stopkaParity.php */
require_once dirname(__DIR__) . '/modules/stopka/logic/stopka.php';
function db_one() { return null; }
$design = stopkaDesignSanitize(['font' => 'Calibri', 'size' => 14, 'nameAccent' => true, 'sepStyle' => 'dashed', 'logoUrl' => 'https://x.pl/l.png?a=1&b=2', 'accent' => '#aa0000']);
$org = ['org_name' => "Fundacja \"Ą&B'", 'address' => 'ul. Długa 1', 'nip' => '123', 'krs' => '', 'website' => 'feer.org.pl', 'promo_html' => '<b>Hej</b> <a href="https://a.pl">l</a>', 'design' => $design];
$me = ['first_name' => 'Zię', 'last_name' => 'Gil<', 'job_title' => 'Dyr', 'phone' => '+48 1', 'email' => 'a@b.pl'];
$e = fn($v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$php = stopkaRenderHtml($org, ['name' => $e(trim($me['first_name'] . ' ' . $me['last_name'])), 'title' => $e($me['job_title']), 'phone' => $e($me['phone']), 'email' => $e($me['email'])]);
$src = file_get_contents(dirname(__DIR__) . '/modules/stopka/index.php');
preg_match('/function stopkaGen\(d\) \{.*?\n\}\n/s', $src, $m);
$js = $m[0] . "\nconst g = stopkaGen(" . json_encode(['org' => $org, 'me' => $me, 'design' => $design, 'defaults' => [], 'fonts' => []], JSON_UNESCAPED_UNICODE)
    . ");\ng.safePromo = h => h; // allowlistę DOM sprawdza przeglądarka; tu porównujemy sam układ\nprocess.stdout.write(g.html());";
$f = tempnam(sys_get_temp_dir(), 'par') . '.js'; file_put_contents($f, $js);
$out = shell_exec('node ' . escapeshellarg($f));
unlink($f);
if ($out === $php) { echo "OK: zgodne (" . strlen($php) . " znaków)\n"; exit(0); }
echo "RÓŻNICA\nPHP: $php\nJS : $out\n"; exit(1);
