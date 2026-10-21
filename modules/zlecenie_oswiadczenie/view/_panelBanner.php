<?php
/**
 * modules/zlecenie_oswiadczenie/view/_panelBanner.php — baner w panelu zleceniobiorcy (panel/index.php):
 * oświadczenia do aktywnej umowy zlecenia nie są jeszcze wypełnione albo czekają na skan / zostały odrzucone.
 * Wymaga w scope: $contracts (panel_contracts()).
 */
$_oswTodo = [];
try {
    require_once dirname(__DIR__) . '/logic/OswService.php';
    foreach ($contracts ?? [] as $_oc) {
        if (($_oc['contract_type'] ?? '') !== 'zlecenie' || !empty($_oc['_is_guardian'])) continue;
        if (!empty($_oc['data_zakonczenia']) && $_oc['data_zakonczenia'] < date('Y-m-d')) continue;
        if (in_array(contractStatusGroup($_oc['status'] ?? ''), ['closed', 'cancelled'], true)) continue;
        $_oo = OswService::forContract((int)$_oc['id']);
        $_od = $_oo ? OswService::docs((int)$_oo['id']) : [];
        $_need = !$_oo || $_oo['status'] === 'roboczy'
              || array_filter($_od, fn($d) => in_array($d['status'], ['wypelnione', 'wydrukowane', 'odrzucone'], true));
        if ($_need) $_oswTodo[] = ['umowa' => $_oc, 'osw' => $_oo, 'docs' => $_od];
    }
} catch (\Throwable $e) { error_log('[osw panel] ' . $e->getMessage()); }
if (!$_oswTodo) return;
$_ot = $_oswTodo[0];
$_oFilled = $_ot['osw'] && $_ot['osw']['status'] !== 'roboczy';
$_oRejected = (bool)array_filter($_ot['docs'], fn($d) => $d['status'] === 'odrzucone');
?>
<div class="alert d-flex flex-wrap align-items-center gap-3 mb-3" role="region" aria-label="Oświadczenia do umowy"
     style="background:#f0f9ff;border:1px solid #7dd3fc;border-left:5px solid #0284c7">
  <div class="flex-shrink-0 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;border-radius:14px;background:#0369a1;color:#fff;font-size:1.6rem" aria-hidden="true">
    <i class="bi bi-file-earmark-check"></i>
  </div>
  <div class="flex-grow-1" style="min-width:230px">
    <div class="fw-bold" style="font-size:1.15rem">
      <?= $_oRejected ? 'Prześlij ponownie skan oświadczenia' : ($_oFilled ? 'Prześlij skany podpisanych oświadczeń' : 'Wypełnij oświadczenia do umowy zlecenia') ?>
    </div>
    <div class="small text-secondary">
      Umowa <?= h($_ot['umowa']['numer_umowy'] ?? '') ?>: oświadczenie dla celów podatkowych i ubezpieczenia ZUS oraz oświadczenie o numerze rachunku bankowego.
      <?= $_oFilled ? 'Wydrukuj je, podpisz i prześlij skany przez system.' : 'Wypełnisz je online w kilka minut, potem wydrukujesz, podpiszesz i prześlesz skan.' ?>
    </div>
  </div>
  <a href="<?= h(OswService::url('/umowa/' . (int)$_ot['umowa']['id'])) ?>"
     class="btn btn-primary btn-lg flex-shrink-0"><?= $_oFilled ? 'Otwórz oświadczenia' : 'Wypełnij oświadczenia' ?></a>
</div>
