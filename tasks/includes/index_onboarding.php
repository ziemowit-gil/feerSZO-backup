<?php
/**
 * tasks/includes/index_onboarding.php
 * Ekran powitalny modułu Zadania — renderowany gdy użytkownik nie należy
 * jeszcze do żadnego obszaru roboczego. Wydzielone z index.php.
 * Oczekuje $is_admin.
 */
?>
<!-- ══ ONBOARDING — brak obszarów ══════════════════════════════════════════ -->
<div class="tw-max-w-[680px] tw-mx-auto tw-my-8">

  <!-- Hero -->
  <div class="tw-rounded-2xl tw-text-white tw-mb-5 tw-relative tw-overflow-hidden tw-py-8 tw-px-10"
       style="background:linear-gradient(135deg,#1e40af,#3b82f6)">
    <div class="tw-absolute tw-w-[220px] tw-h-[220px] tw-rounded-full tw-bg-white/[.06] tw-right-[-60px] tw-top-[-60px]"></div>
    <div class="tw-text-3xl tw-mb-3">📋</div>
    <h1 class="tw-text-[1.4rem] tw-font-extrabold tw-m-0 tw-mb-[.4rem] tw-tracking-[-.02em]">Witaj w module Zadania!</h1>
    <p class="tw-text-[.88rem] tw-opacity-85 tw-m-0 tw-leading-[1.6]">
      Tu zarządzasz zadaniami organizacji — przypisujesz je do wolontariuszy,
      śledzisz postęp i widzisz kto nad czym pracuje.
    </p>
  </div>

  <?php if ($is_admin): ?>
  <!-- Kroki dla admina -->
  <div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-2xl tw-p-6 tw-mb-4">
    <div class="tw-text-[.78rem] tw-font-bold tw-uppercase tw-tracking-[.08em] tw-text-slate-400 tw-mb-4">Jak zacząć — 3 kroki</div>

    <div class="tw-flex tw-flex-col tw-gap-[.85rem]">
      <div class="tw-flex tw-items-start tw-gap-4">
        <div class="tw-w-8 tw-h-8 tw-rounded-full tw-bg-blue-50 tw-text-blue-600 tw-flex tw-items-center tw-justify-center tw-font-extrabold tw-text-[.85rem] tw-shrink-0">1</div>
        <div>
          <div class="tw-font-semibold tw-text-[.9rem] tw-text-slate-900">Utwórz obszar roboczy</div>
          <div class="tw-text-[.8rem] tw-text-slate-500 tw-mt-[.15rem]">Obszar to odpowiednik projektu lub działu — np. „Wolontariat 2026", „Komunikacja"</div>
          <a href="<?= APP_URL ?>/admin/tasks_workspaces.php" class="tw-inline-flex tw-items-center tw-gap-[.35rem] tw-mt-2 tw-bg-blue-600 tw-text-white tw-py-[.35rem] tw-px-[.85rem] tw-rounded-lg tw-no-underline tw-text-[.8rem] tw-font-semibold">
            <i class="bi bi-plus-lg"></i>Utwórz obszar
          </a>
        </div>
      </div>

      <div class="tw-flex tw-items-start tw-gap-4 tw-opacity-50">
        <div class="tw-w-8 tw-h-8 tw-rounded-full tw-bg-slate-50 tw-text-slate-500 tw-border-[1.5px] tw-border-slate-200 tw-flex tw-items-center tw-justify-center tw-font-extrabold tw-text-[.85rem] tw-shrink-0">2</div>
        <div>
          <div class="tw-font-semibold tw-text-[.9rem] tw-text-slate-900">Dodaj listy i zadania</div>
          <div class="tw-text-[.8rem] tw-text-slate-500 tw-mt-[.15rem]">W obszarze tworzysz listy (np. „Do zrobienia", „W trakcie", „Gotowe") i zadania w każdej z nich</div>
        </div>
      </div>

      <div class="tw-flex tw-items-start tw-gap-4 tw-opacity-50">
        <div class="tw-w-8 tw-h-8 tw-rounded-full tw-bg-slate-50 tw-text-slate-500 tw-border-[1.5px] tw-border-slate-200 tw-flex tw-items-center tw-justify-center tw-font-extrabold tw-text-[.85rem] tw-shrink-0">3</div>
        <div>
          <div class="tw-font-semibold tw-text-[.9rem] tw-text-slate-900">Przypisz wolontariuszy</div>
          <div class="tw-text-[.8rem] tw-text-slate-500 tw-mt-[.15rem]">Przypisuj zadania do konkretnych osób — wolontariusze widzą swoje zadania po zalogowaniu do panelu</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tip -->
  <div class="tw-bg-green-50 tw-border-[1.5px] tw-border-green-200 tw-rounded-[10px] tw-py-4 tw-px-5 tw-flex tw-gap-3 tw-items-start">
    <i class="bi bi-lightbulb-fill tw-text-green-600 tw-shrink-0 tw-mt-[.1rem]"></i>
    <div class="tw-text-[.82rem] tw-text-green-700 tw-leading-[1.5]">
      <strong>Szybki start:</strong> Możesz też zaimportować tablice bezpośrednio z Trello —
      przejdź do <a href="<?= APP_URL ?>/admin/trello_import.php" class="tw-text-green-700 tw-font-semibold">Import z Trello</a> w panelu admina.
    </div>
  </div>

  <?php else: ?>
  <!-- Dla zwykłego użytkownika -->
  <div class="tw-bg-white tw-border tw-border-slate-200 tw-rounded-2xl tw-p-7 tw-text-center">
    <i class="bi bi-person-plus tw-text-3xl tw-text-slate-400 tw-block tw-mb-3"></i>
    <div class="tw-font-bold tw-text-[.95rem] tw-text-slate-900 tw-mb-[.3rem]">Nie masz jeszcze przypisanego obszaru</div>
    <p class="tw-text-[.83rem] tw-text-slate-500 tw-leading-[1.6] tw-m-0">
      Poproś administratora lub koordynatora, aby dodał Cię do obszaru roboczego.
      Po przypisaniu zobaczysz tutaj swoje zadania.
    </p>
  </div>
  <?php endif; ?>

</div>
