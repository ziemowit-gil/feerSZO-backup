<?php /** account.php — dane konta administratora i zmiana hasła. Zmienne: $account */ ?>
<div class="grid gap-6 lg:grid-cols-3">
  <form method="post" class="space-y-6 lg:col-span-2">
    <?= csrf_field() ?>

    <section class="<?= ui('card') ?> space-y-5">
      <h2 class="<?= ui('section_h') ?>">Dane logowania</h2>
      <div class="grid gap-5 sm:grid-cols-2">
        <label class="block">
          <span class="<?= ui('label') ?>">E-mail (login)</span>
          <input type="email" name="email" required value="<?= e($account['email']) ?>" class="<?= ui('input') ?>">
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Imię i nazwisko</span>
          <input name="name" value="<?= e($account['name']) ?>" class="<?= ui('input') ?>">
        </label>
      </div>
    </section>

    <section class="<?= ui('card') ?> space-y-5">
      <h2 class="<?= ui('section_h') ?>">Zmiana hasła</h2>
      <label class="block max-w-md">
        <span class="<?= ui('label') ?>">Aktualne hasło <span class="text-rose-400">*</span></span>
        <input type="password" name="current" required autocomplete="current-password" class="<?= ui('input') ?>">
        <span class="<?= ui('hint') ?>">Wymagane do zatwierdzenia każdej zmiany na tej stronie.</span>
      </label>
      <div class="grid gap-5 sm:grid-cols-2">
        <label class="block">
          <span class="<?= ui('label') ?>">Nowe hasło</span>
          <input type="password" name="password" autocomplete="new-password" class="<?= ui('input') ?>">
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Powtórz nowe hasło</span>
          <input type="password" name="password2" autocomplete="new-password" class="<?= ui('input') ?>">
        </label>
      </div>
      <p class="text-xs text-zinc-500">Min. 10 znaków, wielka i mała litera oraz cyfra. Puste pola = hasło bez zmian.</p>
    </section>

    <div class="flex items-center gap-3 border-t border-zinc-800 pt-5">
      <button type="submit" class="<?= ui('btn_primary') ?>"><i class="bi bi-check-lg"></i> Zapisz</button>
    </div>
  </form>

  <section class="<?= ui('card') ?> h-fit space-y-3 text-sm text-zinc-400">
    <h2 class="<?= ui('section_h') ?>">Informacje</h2>
    <p>Rola: <span class="text-zinc-200"><?= e($account['role']) ?></span></p>
    <p>Konto utworzone: <span class="text-zinc-200"><?= e((string)$account['created_at']) ?></span></p>
    <p>Ostatnie logowanie:
      <span class="text-zinc-200"><?= e((string)($account['last_login_at'] ?? '—')) ?></span>
      <?php if (!empty($account['last_login_ip'])): ?>
        <span class="block text-xs text-zinc-500">z IP <?= e((string)$account['last_login_ip']) ?></span>
      <?php endif; ?>
    </p>
    <p class="border-t border-zinc-800 pt-3 text-xs">
      Hasła są przechowywane jako skróty <code>password_hash()</code> (bcrypt).
      Po 5 nieudanych próbach logowanie jest blokowane na 5 minut.
    </p>
  </section>
</div>
