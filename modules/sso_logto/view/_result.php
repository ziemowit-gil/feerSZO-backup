<?php
/**
 * modules/sso_logto/view/_result.php — wynik zakładania kont / nadawania haseł (hasła widoczne tylko raz).
 * Zmienne: $ssoRows (wiersze z SsoAccounts::provision/setPassword + name, sms), $ssoResId (id elementu).
 */
$ssoCloudTxt = function (string $c): string {
    return match (true) {
        $c === 'created' => 'założone, to samo hasło',
        $c === 'linked'  => 'istniało — dowiązane, to samo hasło',
        $c === 'synced'  => 'to samo nowe hasło',
        $c === ''        => '—',
        default          => $c,
    };
};
$ssoWithPass = array_filter($ssoRows, fn($r) => !empty($r['password']));
?>
<div class="alert alert-<?= $ssoWithPass ? 'warning' : 'info' ?> small" id="<?= h($ssoResId) ?>" role="status" tabindex="-1">
  <p class="fw-semibold mb-2">
    <?php if ($ssoWithPass): ?>
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Hasła widać tylko teraz — po odświeżeniu strony znikną. Przekaż je osobom bezpiecznie (np. SMS-em).
    <?php else: ?>
      <i class="bi bi-info-circle" aria-hidden="true"></i> Wynik:
    <?php endif; ?>
  </p>
  <div class="table-responsive">
    <table class="table table-sm mb-0 small align-middle">
      <thead><tr><th scope="col">Osoba</th><th scope="col">Login</th><th scope="col">Hasło (Logto i chmura)</th><th scope="col">Chmura</th><th scope="col">Pliki z OneDrive</th><th scope="col">SMS</th></tr></thead>
      <tbody>
      <?php foreach ($ssoRows as $r): ?>
        <tr>
          <td><?= h($r['name'] ?? '') ?><?php if (!empty($r['email'])): ?><br><span class="text-body-secondary"><?= h($r['email']) ?></span><?php endif; ?></td>
          <td><?= $r['username'] !== '' ? '<code>' . h($r['username']) . '</code>' : '—' ?></td>
          <td>
            <?php if (!empty($r['password'])): ?>
              <code class="sso-pass"><?= h($r['password']) ?></code>
              <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent);this.textContent='Skopiowano'">Kopiuj<span class="visually-hidden"> hasło <?= h($r['username']) ?></span></button>
            <?php elseif (($r['logto'] ?? '') !== ''): ?>
              <span class="text-body-secondary">ustawione przez osobę</span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= h($ssoCloudTxt((string)($r['cloud'] ?? ''))) ?></td>
          <td><?= !empty($r['job']) ? 'w kolejce (zadanie #' . (int)$r['job'] . ')' : '—' ?></td>
          <td><?= h((string)($r['sms'] ?? '') ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>(function(){var el=document.getElementById(<?= json_encode($ssoResId) ?>);if(el){el.scrollIntoView({block:'center'});el.focus({preventScroll:true});}})();</script>
