<?php
/**
 * modules/zlecenie_oswiadczenie/view/_panelZlecenieSekcje.php — sekcje pivotu „umowa zlecenie” i „rachunki” w panelu
 * współpracownika (szata Metro panelu: mt-h2/mt-group/mt-kv/mt-btn). Wymaga: $_pvz z _panelZlecenie.php, $_fmt_d.
 */
if (empty($_pvz)) return;
$_pvzSt = ['wypelnione' => 'warn', 'wydrukowane' => 'info', 'skan' => 'info', 'zweryfikowane' => 'ok', 'odrzucone' => 'warn'];
?>
  <!-- ═══════════ UMOWA ZLECENIE ═══════════ -->
  <section id="zlecenie" data-pvm-panel="zlecenie" role="tabpanel" aria-labelledby="pvm-tab-zlecenie" tabindex="-1" hidden>
    <h2 class="mt-h2">Umowa <b>zlecenie</b></h2>
    <?php foreach ($_pvz as $_z): $_r = $_z['row']; $_o = $_z['osw']; ?>
    <h3 class="mt-h3"><?= h($_r['numer_umowy']) ?> <span class="mt-badge"><?= h($_r['status']) ?></span></h3>
    <div class="mt-group">
      <dl class="mt-kv">
        <div><dt>Okres</dt><dd><?= h($_fmt_d($_r['data_rozpoczecia'] ?: $_r['data_zawarcia'])) ?: '—' ?> → <?= h($_fmt_d($_r['data_zakonczenia'])) ?: (!empty($_r['bezterminowa']) ? 'bezterminowo' : '—') ?></dd></div>
        <?php if (!empty($_r['przedmiot_zlecenia'])): ?><div><dt>Przedmiot</dt><dd><?= h(mb_strimwidth((string)$_r['przedmiot_zlecenia'], 0, 160, '…')) ?></dd></div><?php endif; ?>
        <?php if (!empty($_r['stawka_kwota'])): ?><div><dt>Stawka</dt><dd><?= h(number_format((float)$_r['stawka_kwota'], 2, ',', ' ')) ?> zł<?= ($_r['typ_stawki'] ?? '') === 'godzinowo' ? ' / godz.' : '' ?></dd></div><?php endif; ?>
        <?php if (!empty($_r['opiekun'])): ?><div><dt>Opiekun</dt><dd><?= h($_r['opiekun']) ?></dd></div><?php endif; ?>
      </dl>
    </div>
    <h3 class="mt-h3">Oświadczenia ZUS/PIT i rachunek bankowy</h3>
    <div class="mt-group">
      <?php if (!$_o || $_o['status'] === 'roboczy'): ?>
      <p class="mb-2">Do umowy potrzebne są dwa oświadczenia: dla celów podatkowych i ubezpieczenia ZUS oraz o numerze rachunku bankowego.
        <?= $_o && (int)$_o['step'] > 1 ? 'Masz rozpoczęte wypełnianie (krok ' . (int)$_o['step'] . ' z 6).' : 'Wypełnisz je online w kilka minut.' ?></p>
      <?php else: ?>
      <ul class="list-unstyled mb-2">
        <?php foreach (OswService::KINDS as $_k => [$_n]): $_d = $_z['docs'][$_k] ?? null; if (!$_d) continue; ?>
        <li class="mb-1"><span class="mt-badge mt-badge--<?= h($_pvzSt[$_d['status']] ?? 'info') ?>"><?= h(OswService::DOC_STATUS[$_d['status']][0]) ?></span> <?= h($_n) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <a class="mt-btn" href="<?= h(OswService::url('/umowa/' . (int)$_r['id'])) ?>"><i class="bi bi-file-earmark-check" aria-hidden="true"></i><?= !$_o || $_o['status'] === 'roboczy' ? 'Wypełnij oświadczenia' : 'Otwórz oświadczenia' ?><span class="visually-hidden"> — umowa <?= h($_r['numer_umowy']) ?></span></a>
      <a class="mt-btn mt-btn--ghost" href="<?= h(APP_URL . '/panel/rozliczenia_zlecenie.php?id=' . (int)$_r['id']) ?>"><i class="bi bi-calculator" aria-hidden="true"></i>Godziny i rozliczenia<span class="visually-hidden"> — umowa <?= h($_r['numer_umowy']) ?></span></a>
    </div>
    <?php endforeach; ?>
  </section>

  <!-- ═══════════ RACHUNKI ═══════════ -->
  <section id="rachunki" data-pvm-panel="rachunki" role="tabpanel" aria-labelledby="pvm-tab-rachunki" tabindex="-1" hidden>
    <h2 class="mt-h2">Rachunki <b>do umowy zlecenie</b></h2>
    <?php foreach ($_pvz as $_z): $_r = $_z['row']; ?>
    <h3 class="mt-h3"><?= h($_r['numer_umowy']) ?></h3>
    <?php if ($_z['rachTodo']): ?>
    <div class="mt-group" style="border-left:6px solid var(--m-orange,#c25100)">
      <p class="mb-2"><strong>Brak rachunku za <?= h(rachunek_month_label()) ?>.</strong> Podpisany rachunek prześlij na <strong><?= h(rachunek_skan_email()) ?></strong>,
        a oryginał dostarcz w ciągu <?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni<?= rachunek_org_address() ? ' na adres: ' . h(rachunek_org_address()) : '' ?>.</p>
      <a class="mt-btn" href="mailto:<?= h(rachunek_skan_email()) ?>?subject=<?= rawurlencode('Rachunek — ' . $_r['numer_umowy'] . ' — ' . rachunek_month_label()) ?>"><i class="bi bi-envelope" aria-hidden="true"></i>Wyślij rachunek</a>
    </div>
    <?php endif; ?>
    <?php if ($_z['rach']): ?>
    <div class="mt-group p-0">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Rachunki do umowy <?= h($_r['numer_umowy']) ?></caption>
        <thead><tr><th scope="col">Rachunek</th><th scope="col">Okres</th><th scope="col" class="text-end">Brutto</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
        <tbody>
        <?php foreach ($_z['rach'] as $_rc): $_signed = rachunek_is_signed($_rc);
              $_okres = !empty($_rc['okres_od']) ? rachunek_okres_label((string)$_rc['okres_od'], (string)($_rc['okres_do'] ?? '')) : (string)($_rc['okres'] ?? ''); ?>
          <tr>
            <td><?= h($_rc['numer'] ?: 'rachunek #' . (int)$_rc['id']) ?><?= rachunek_is_test($_rc) ? ' <span class="mt-badge mt-badge--warn">test</span>' : '' ?></td>
            <td><?= h($_okres ?: '—') ?></td>
            <td class="text-end text-nowrap"><?= $_rc['kwota_brutto'] !== null && $_rc['kwota_brutto'] !== '' ? h(number_format((float)$_rc['kwota_brutto'], 2, ',', ' ')) . ' zł' : '—' ?></td>
            <td><span class="mt-badge mt-badge--<?= $_signed ? 'ok' : 'info' ?>"><?= h(rachunek_status_label((string)$_rc['status'])) ?></span></td>
            <td class="text-end"><?php if (!empty($_rc['notify_token'])): ?><a class="mt-btn <?= $_signed ? 'mt-btn--ghost' : '' ?>" href="<?= h(APP_URL . '/contracts/zlecenie/rachunek_pobierz.php?token=' . urlencode((string)$_rc['notify_token'])) ?>"><?= $_signed ? 'Otwórz' : 'Pobierz i podpisz' ?><span class="visually-hidden"> — <?= h($_rc['numer'] ?: 'rachunek #' . (int)$_rc['id']) ?></span></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php elseif (!$_z['rachTodo']): ?>
    <div class="mt-empty"><p class="mb-0">Nie ma jeszcze rachunków do tej umowy.</p></div>
    <?php endif; ?>
    <?php endforeach; ?>
  </section>
