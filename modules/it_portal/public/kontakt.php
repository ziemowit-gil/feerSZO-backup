<?php
/**
 * modules/it_portal/public/kontakt.php — Portal Usług IT: kontakt.
 * Adres: <APP_URL>/uslugi-it/kontakt. Wstęp (ustawienie „contact_intro”, np. linki do Helpdesku),
 * pod nim tabela: jednostka · kontakt · zespół (wiersze „Imię Nazwisko | e-mail”).
 */
require_once __DIR__ . '/_layout.php';

$intro    = itPortalSanitizeHtml(itPortalSetting('contact_intro'));
$contacts = itPortalContacts();

itPortalPageStart('Kontakt', 'kontakt', 'Kontakt z obsługą informatyczną ' . ORG_NAME . '.');
?>
<h1 class="itp-sr">Kontakt</h1>
<?php if ($intro !== ''): ?>
  <div class="itp-body itp-contact-intro"><?= $intro ?></div>
<?php endif; ?>
<?php if ($contacts): ?>
  <table class="itp-table">
    <caption class="itp-sr">Kontakt do obsługi informatycznej jednostek</caption>
    <thead><tr><th scope="col">Jednostka</th><th scope="col">Kontakt</th><th scope="col">Zespół</th></tr></thead>
    <tbody>
      <?php foreach ($contacts as $c): $team = itPortalLinkLines($c['team']); ?>
        <tr>
          <td><?= h($c['unit']) ?></td>
          <td><div class="itp-body"><?= itPortalSanitizeHtml($c['contact_html']) ?></div></td>
          <td><?php if ($team): ?><ul>
            <?php foreach ($team as $t): ?><li><?= $t['href'] ? '<a href="' . h($t['href']) . '">' . h($t['label']) . '</a>' : h($t['label']) ?></li><?php endforeach; ?>
          </ul><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php elseif ($intro === ''): ?>
  <div class="itp-empty"><p>Dane kontaktowe są w przygotowaniu.</p></div>
<?php endif; ?>
<?php
itPortalPageEnd();
