<?php
/**
 * Publiczny generator stopki e-mail: /pomocne/generatorstopki (bez logowania).
 * Dla osób, którym stopki nie dodaje serwer (np. poczta dpoczta/dhosting): wpisują swoje dane,
 * widzą podgląd na żywo i kopiują gotowy blok HTML do klienta poczty. Nic nie jest zapisywane
 * po stronie serwera — dane osoby żyją tylko w przeglądarce. Dane organizacji i wygląd
 * to te same ustawienia, które admin edytuje w modules/stopka (jeden wygląd w całej firmie).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/stopka.php';

try {
    stopkaMigrate();
    $org = stopkaSettings();
} catch (Throwable $e) {
    $org = ['org_name' => defined('ORG_NAME') ? (string)ORG_NAME : '', 'address' => '', 'nip' => '', 'krs' => '',
            'website' => '', 'promo_html' => '', 'design' => stopkaDesignDefaults()];
}
$data = [
    'org' => $org,
    'me' => ['first_name' => '', 'last_name' => '', 'job_title' => '', 'phone' => '', 'email' => ''],
    'design' => $org['design'], 'defaults' => stopkaDesignDefaults(), 'fonts' => STOPKA_FONTS,
];
$orgName = (string)($org['org_name'] ?? '');
$inp = 'w-full rounded border border-solid border-slate-400 px-3 py-2 bg-white text-slate-900 focus:outline focus:outline-2 focus:outline-blue-700';
$lbl = 'block text-sm font-medium mb-1';
header('Cache-Control: no-store');
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Generator stopki e-mail<?= $orgName !== '' ? ' · ' . h($orgName) : '' ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="<?= h(APP_URL) ?>/modules/stopka/assets/stopkaGen.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/stopkaGen.js') ?>"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen">
<a href="#tresc" class="sr-only focus:not-sr-only focus:absolute focus:p-3 focus:bg-white">Przejdź do treści</a>
<main id="tresc" class="max-w-4xl mx-auto p-4 sm:p-8" x-data='stopkaGen(<?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
  <h1 class="text-2xl font-semibold mb-1">Generator stopki e-mail</h1>
  <p class="text-slate-700 mb-6">Wpisz swoje dane, sprawdź podgląd i skopiuj stopkę jednym kliknięciem. Dane zostają w Twojej przeglądarce — nic nie wysyłamy na serwer.</p>

  <div class="grid md:grid-cols-2 gap-6">
    <section class="bg-white rounded shadow p-5 space-y-3" aria-labelledby="h-dane">
      <h2 id="h-dane" class="text-lg font-semibold">Twoje dane</h2>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="<?= $lbl ?>" for="f1">Imię</label><input id="f1" x-model="me.first_name" autocomplete="given-name" class="<?= $inp ?>"></div>
        <div><label class="<?= $lbl ?>" for="f2">Nazwisko</label><input id="f2" x-model="me.last_name" autocomplete="family-name" class="<?= $inp ?>"></div>
      </div>
      <div><label class="<?= $lbl ?>" for="f3">Stanowisko</label><input id="f3" x-model="me.job_title" autocomplete="organization-title" class="<?= $inp ?>"></div>
      <div><label class="<?= $lbl ?>" for="f4">Telefon</label><input id="f4" type="tel" x-model="me.phone" autocomplete="tel" class="<?= $inp ?>" placeholder="+48 600 000 000"></div>
      <div><label class="<?= $lbl ?>" for="f5">Adres e-mail</label><input id="f5" type="email" x-model="me.email" autocomplete="email" class="<?= $inp ?>"></div>
    </section>

    <section class="bg-white rounded shadow p-5 space-y-3" aria-labelledby="h-prev">
      <h2 id="h-prev" class="text-lg font-semibold">Podgląd stopki</h2>
      <div class="border border-solid border-slate-300 rounded p-4 overflow-x-auto bg-white" x-html="html()" aria-live="polite"></div>
      <div class="flex flex-wrap gap-2">
        <button type="button" @click="copy()" class="rounded bg-blue-700 text-white px-4 py-2 hover:bg-blue-800" x-text="copied ? 'Skopiowano ✔' : 'Kopiuj stopkę do schowka'"></button>
        <button type="button" @click="copyCode()" class="rounded bg-white text-slate-900 border border-solid border-slate-500 px-4 py-2 hover:bg-slate-50" x-text="codeCopied ? 'Kod skopiowany ✔' : 'Kopiuj kod HTML'"></button>
        <button type="button" @click="download()" class="rounded bg-white text-slate-900 border border-solid border-slate-500 px-4 py-2 hover:bg-slate-50">Pobierz plik HTML</button>
      </div>
      <p class="text-sm text-slate-700" role="status" x-show="copied || codeCopied" x-cloak>Skopiowane. Wklej w ustawieniach podpisu swojej poczty.</p>
      <p class="text-sm text-slate-600">„Kopiuj stopkę” wklejasz do edytorów, które pokazują formatowanie (webmail, Outlook). „Kopiuj kod HTML” i plik są dla Thunderbirda.</p>
      <p class="text-sm text-red-800" role="alert" x-show="fail" x-cloak>Przeglądarka zablokowała schowek — zaznacz podgląd myszą i skopiuj (Ctrl+C).</p>
    </section>
  </div>

  <section class="bg-white rounded shadow p-5 mt-6" aria-labelledby="h-jak">
    <h2 id="h-jak" class="text-lg font-semibold mb-2">Jak wkleić stopkę</h2>
    <ul class="list-disc pl-5 space-y-1 text-slate-800">
      <li><strong>Poczta w przeglądarce (webmail):</strong> Ustawienia → Podpis → wklej (Ctrl+V) w edytorze podpisu.</li>
      <li><strong>Outlook (klasyczny):</strong> Plik → Opcje → Poczta → Podpisy → Nowy → wklej w polu edycji.</li>
      <li><strong>Outlook w przeglądarce:</strong> Ustawienia → Konto → Podpisy → wklej w edytorze.</li>
      <li><strong>Thunderbird:</strong> kliknij „Kopiuj kod HTML”. Prawym przyciskiem myszy kliknij nazwę skrzynki → Ustawienia → pole „Sygnaturka”, zaznacz „Używaj HTML” i wklej kod. Zamiast wklejać, możesz pobrać plik HTML i wskazać go jako sygnaturkę z pliku.</li>
    </ul>
  </section>
</main>
</body>
</html>
