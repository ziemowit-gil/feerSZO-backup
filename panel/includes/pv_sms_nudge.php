<?php
/**
 * panel/includes/pv_sms_nudge.php — Baner „Zachęta do logowania SMS".
 *
 * Czysty Bootstrap + formularz POST. Przycisk „Nie pokazuj ponownie" wysyła
 * `_dismiss_sms_nudge` (handler w panel/index.php zapisuje wybór trwale
 * w users.sms_nudge_dismissed i przeładowuje stronę). Bez JS.
 *
 * Wymaga w zasięgu: $_nudge_has_phone (bool), csrf_field(), APP_URL, h().
 */
$_nudge_has_phone = $_nudge_has_phone ?? false;
?>
<style>
.pvp-sms-nudge{
  border-radius:12px;
  background:linear-gradient(135deg,#065f46 0%,#059669 100%);
  overflow:hidden;
  box-shadow:0 4px 16px rgba(5,150,105,.25);
  animation:pvDirSlide .35s ease;
}
@keyframes pvDirSlide{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){.pvp-sms-nudge{animation:none}}
.pvp-sms-nudge-inner  {display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;flex-wrap:wrap}
.pvp-sms-nudge-icon   {width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1.35rem;color:#fff;flex-shrink:0}
.pvp-sms-nudge-content{flex:1;min-width:180px}
.pvp-sms-nudge-title  {font-weight:800;font-size:.94rem;color:#fff;margin-bottom:.15rem}
.pvp-sms-nudge-sub    {font-size:.8rem;color:rgba(255,255,255,.82);line-height:1.45}
.pvp-sms-nudge-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.pvp-sms-nudge-btn    {display:inline-flex;align-items:center;gap:.35rem;padding:.45rem 1rem;border-radius:7px;background:rgba(255,255,255,.95);color:#059669;font-size:.83rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s;border:2px solid transparent}
.pvp-sms-nudge-btn:hover,.pvp-sms-nudge-btn:focus-visible{background:#fff;color:#059669;outline-offset:2px}
.pvp-sms-nudge-dismiss{background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);border-radius:7px;color:rgba(255,255,255,.85);padding:.4rem .5rem;cursor:pointer;font-size:.9rem;line-height:1;transition:background .12s}
.pvp-sms-nudge-dismiss:hover,.pvp-sms-nudge-dismiss:focus-visible{background:rgba(255,255,255,.28);color:#fff;outline-offset:2px}
@media(max-width:500px){.pvp-sms-nudge-inner{gap:.75rem}.pvp-sms-nudge-btn{font-size:.8rem;padding:.4rem .8rem}}
</style>

<div class="pvp-sms-nudge mb-3" role="complementary" aria-label="Zachęta do logowania SMS">
  <div class="pvp-sms-nudge-inner">
    <div class="pvp-sms-nudge-icon" aria-hidden="true">
      <i class="bi bi-<?= $_nudge_has_phone ? 'phone-fill' : 'phone' ?>"></i>
    </div>
    <div class="pvp-sms-nudge-content">
      <?php if ($_nudge_has_phone): ?>
      <div class="pvp-sms-nudge-title">Zaloguj się szybciej kodem SMS 📱</div>
      <div class="pvp-sms-nudge-sub">
        Zamiast hasła wpisz numer telefonu i zaloguj się jednorazowym kodem SMS — szybciej i bez zapamiętywania haseł.
      </div>
      <?php else: ?>
      <div class="pvp-sms-nudge-title">Uprość logowanie — dodaj numer telefonu 📱</div>
      <div class="pvp-sms-nudge-sub">
        Podaj swój numer w profilu, a będziesz mógł logować się kodem SMS zamiast hasłem.
      </div>
      <?php endif; ?>
    </div>
    <div class="pvp-sms-nudge-actions">
      <?php if ($_nudge_has_phone): ?>
      <a href="<?= APP_URL ?>/auth/login.php?tab=sms"
         class="pvp-sms-nudge-btn"
         aria-label="Wypróbuj logowanie kodem SMS przy następnym logowaniu">
        <i class="bi bi-phone me-1" aria-hidden="true"></i>Spróbuj
      </a>
      <?php else: ?>
      <a href="<?= APP_URL ?>/directory/profile_edit.php"
         class="pvp-sms-nudge-btn"
         aria-label="Dodaj numer telefonu w profilu">
        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj numer
      </a>
      <?php endif; ?>
      <form method="post" style="display:inline;margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="_dismiss_sms_nudge" value="1">
        <button type="submit"
                class="pvp-sms-nudge-dismiss"
                aria-label="Nie pokazuj ponownie">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </form>
    </div>
  </div>
</div>
