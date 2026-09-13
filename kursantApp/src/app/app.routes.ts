import { Routes } from '@angular/router';
import { authGuard } from './core/auth/auth.guard';
import { instructorGuard } from './core/auth/instructor.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/login/login.component').then(m => m.LoginComponent),
    title: 'Logowanie — Panel Kursanta',
  },
  {
    path: 'logowanie-prowadzacy',
    loadComponent: () =>
      import('./features/instructor/login/instructor-login.component').then(m => m.InstructorLoginComponent),
    title: 'Logowanie — Panel prowadzącego',
  },
  {
    path: 'prowadzacy',
    loadComponent: () =>
      import('./features/instructor/shell/instructor-shell.component').then(m => m.InstructorShellComponent),
    canActivate: [instructorGuard],
    children: [
      { path: '', redirectTo: 'pulpit', pathMatch: 'full' },
      {
        path: 'pulpit',
        loadComponent: () =>
          import('./features/instructor/pulpit/pulpit.component').then(m => m.InstructorPulpitComponent),
        title: 'Pulpit — Panel prowadzącego',
      },
      {
        path: 'lekcje',
        loadComponent: () =>
          import('./features/instructor/lekcje/lekcje.component').then(m => m.InstructorLekcjeComponent),
        title: 'Lekcje — Panel prowadzącego',
      },
      {
        path: 'zadania',
        loadComponent: () =>
          import('./features/instructor/zadania/zadania.component').then(m => m.InstructorZadaniaComponent),
        title: 'Zadania — Panel prowadzącego',
      },
      {
        path: 'materialy',
        loadComponent: () =>
          import('./features/instructor/materialy/materialy.component').then(m => m.InstructorMaterialyComponent),
        title: 'Materiały — Panel prowadzącego',
      },
      {
        path: 'wiadomosci',
        loadComponent: () =>
          import('./features/instructor/wiadomosci/wiadomosci.component').then(m => m.InstructorWiadomosciComponent),
        title: 'Wiadomości — Panel prowadzącego',
      },
      {
        path: 'formalnosci',
        loadComponent: () =>
          import('./features/instructor/formalnosci/formalnosci.component').then(m => m.InstructorFormalnosciComponent),
        title: 'Formalności — Panel prowadzącego',
      },
      {
        path: 'wydruki',
        loadComponent: () =>
          import('./features/instructor/wydruki/wydruki.component').then(m => m.InstructorWydrukiComponent),
        title: 'Wydruki — Panel prowadzącego',
      },
    ],
  },
  {
    path: 'prowadzacy-impersonate',
    loadComponent: () =>
      import('./features/instructor/impersonate/instructor-impersonate.component').then(m => m.InstructorImpersonateComponent),
    title: 'Logowanie Microsoft 365 — Panel prowadzącego',
  },
  {
    path: 'impersonate',
    loadComponent: () =>
      import('./features/impersonate/impersonate.component').then(m => m.ImpersonateComponent),
    title: 'Logowanie administratora — Panel Kursanta',
  },
  {
    path: '',
    loadComponent: () =>
      import('./features/shell/shell.component').then(m => m.ShellComponent),
    canActivate: [authGuard],
    children: [
      { path: '', redirectTo: 'dane', pathMatch: 'full' },
      {
        path: 'dane',
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then(m => m.DashboardComponent),
        title: 'Dane kursanta',
      },
      {
        path: 'lekcje',
        loadComponent: () =>
          import('./features/lekcje/lekcje.component').then(m => m.LekcjeComponent),
        title: 'Moje lekcje',
      },
      {
        path: 'zadania',
        loadComponent: () =>
          import('./features/zadania/zadania.component').then(m => m.ZadaniaComponent),
        title: 'Materiały i zadania',
      },
      {
        path: 'oceny',
        loadComponent: () =>
          import('./features/oceny/oceny.component').then(m => m.OcenyComponent),
        title: 'Oceny',
      },
      {
        path: 'plan',
        loadComponent: () =>
          import('./features/plan/plan.component').then(m => m.PlanComponent),
        title: 'Plan nauczania',
      },
      {
        path: 'testy',
        loadComponent: () =>
          import('./features/testy/testy.component').then(m => m.TestyComponent),
        title: 'Testy i quizy',
      },
      {
        path: 'komunikaty',
        loadComponent: () =>
          import('./features/komunikaty/komunikaty.component').then(m => m.KomunikatyComponent),
        title: 'Komunikaty',
      },
      {
        path: 'wiadomosci',
        loadComponent: () =>
          import('./features/wiadomosci/wiadomosci.component').then(m => m.WiadomosciComponent),
        title: 'Wiadomości',
      },
      {
        path: 'rozliczenia',
        loadComponent: () =>
          import('./features/rozliczenia/rozliczenia.component').then(m => m.RozliczeniaComponent),
        title: 'Rozliczenia',
      },
      {
        path: 'portfel',
        loadComponent: () =>
          import('./features/portfel/portfel.component').then(m => m.PortfelComponent),
        title: 'Portfel',
      },
      {
        path: 'online',
        loadComponent: () =>
          import('./features/online/online.component').then(m => m.OnlineComponent),
        title: 'Szkolenia online',
      },
      {
        path: 'vlab',
        loadComponent: () =>
          import('./features/vlab/vlab.component').then(m => m.VlabComponent),
        title: 'VLab',
      },
      {
        path: 'dysk',
        loadComponent: () =>
          import('./features/dysk/dysk.component').then(m => m.DyskComponent),
        title: 'Mój dysk',
      },
      {
        path: 'licencje',
        loadComponent: () =>
          import('./features/licencje/licencje.component').then(m => m.LicencjeComponent),
        title: 'Licencje',
      },
      {
        path: 'pfron',
        loadComponent: () =>
          import('./features/pfron/pfron.component').then(m => m.PfronComponent),
        title: 'PFRON',
      },
      {
        path: 'problem',
        loadComponent: () =>
          import('./features/problem/problem.component').then(m => m.ProblemComponent),
        title: 'Pomoc / Zgłoszenie',
      },
      {
        path: 'aktywnosc',
        loadComponent: () =>
          import('./features/aktywnosc/aktywnosc.component').then(m => m.AktywnoscComponent),
        title: 'Aktywność konta',
      },
      {
        path: 'ustawienia',
        loadComponent: () =>
          import('./features/ustawienia/ustawienia.component').then(m => m.UstawieniaComponent),
        title: 'Ustawienia',
      },
      {
        path: 'regulaminy',
        loadComponent: () =>
          import('./features/regulaminy/regulaminy.component').then(m => m.RegulaminyComponent),
        title: 'Regulaminy',
      },
      {
        path: 'upowaznieni',
        loadComponent: () =>
          import('./features/upowaznieni/upowaznieni.component').then(m => m.UpowaznienComponent),
        title: 'Upoważnieni',
      },
      {
        path: 'dostep',
        loadComponent: () =>
          import('./features/dostep/dostep.component').then(m => m.DostepComponent),
        title: 'Dostęp opiekuna',
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
