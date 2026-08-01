import { Routes } from '@angular/router';

export const DYDAKTYKA_ROUTES: Routes = [
  {
    path: '',
    redirectTo: 'lekcje',
    pathMatch: 'full',
  },
  {
    path: 'lekcje',
    loadComponent: () =>
      import('./components/lesson-list/lesson-list.component').then(m => m.LessonListComponent),
    title: 'Lekcje — Dydaktyka',
  },
  {
    path: 'oceny',
    loadComponent: () =>
      import('./components/grade-list/grade-list.component').then(m => m.GradeListComponent),
    title: 'Oceny — Dydaktyka',
  },
  {
    path: 'obecnosc',
    loadComponent: () =>
      import('./components/attendance/attendance.component').then(m => m.AttendanceComponent),
    title: 'Obecność — Dydaktyka',
  },
  {
    path: 'plan',
    loadComponent: () =>
      import('./components/curriculum/curriculum.component').then(m => m.CurriculumComponent),
    title: 'Plan nauczania — Dydaktyka',
  },
];
