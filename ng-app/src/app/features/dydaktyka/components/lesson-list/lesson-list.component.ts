import { Component, OnInit, inject, signal, computed } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatDialog } from '@angular/material/dialog';
import { MatSnackBar } from '@angular/material/snack-bar';
import { CommonModule } from '@angular/common';
import { MatTableModule } from '@angular/material/table';
import { MatSortModule, Sort } from '@angular/material/sort';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatChipsModule } from '@angular/material/chips';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatMenuModule } from '@angular/material/menu';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatNativeDateModule } from '@angular/material/core';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { debounceTime, distinctUntilChanged } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { DydaktykaService, SessionFilter } from '../../services/dydaktyka.service';
import { Session, SessionStatus, Course } from '../../models/dydaktyka.model';
import { LessonDialogComponent } from '../lesson-dialog/lesson-dialog.component';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';

@Component({
  selector: 'app-lesson-list',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule,
    MatTableModule, MatSortModule, MatPaginatorModule,
    MatFormFieldModule, MatInputModule, MatSelectModule,
    MatButtonModule, MatIconModule, MatChipsModule,
    MatTooltipModule, MatMenuModule, MatDatepickerModule,
    MatNativeDateModule, MatProgressBarModule,
    PageHeaderComponent,
  ],
  templateUrl: './lesson-list.component.html',
  styleUrl: './lesson-list.component.scss',
})
export class LessonListComponent implements OnInit {
  private svc    = inject(DydaktykaService);
  private dialog = inject(MatDialog);
  private snack  = inject(MatSnackBar);
  private fb     = inject(FormBuilder);

  sessions  = signal<Session[]>([]);
  total     = signal(0);
  loading   = signal(false);
  courses   = signal<Course[]>([]);

  page     = 0;
  pageSize = 20;

  readonly displayedColumns = [
    'lesson_date', 'time_from', 'course_name', 'topic',
    'status', 'duration_min', 'has_homework', 'actions'
  ];

  readonly statusLabels: Record<string, string> = {
    scheduled:       'Zaplanowana',
    completed:       'Odbyta',
    cancelled:       'Odwołana',
    remote_material: 'Praca własna',
  };

  readonly statusColors: Record<string, string> = {
    scheduled:       'status-pending',
    completed:       'status-active',
    cancelled:       'status-cancelled',
    remote_material: 'status-draft',
  };

  filterForm = this.fb.group({
    q:         [''],
    course_id: [null as number | null],
    status:    ['' as SessionStatus | ''],
    date_from: [null as Date | null],
    date_to:   [null as Date | null],
  });

  constructor() {
    this.filterForm.get('q')!.valueChanges.pipe(
      debounceTime(380),
      distinctUntilChanged(),
      takeUntilDestroyed(),
    ).subscribe(() => this.load(true));

    this.filterForm.get('course_id')!.valueChanges.pipe(takeUntilDestroyed())
      .subscribe(() => this.load(true));
    this.filterForm.get('status')!.valueChanges.pipe(takeUntilDestroyed())
      .subscribe(() => this.load(true));
  }

  ngOnInit(): void {
    this.svc.getCourses().subscribe(c => this.courses.set(c));
    this.load();
  }

  load(resetPage = false): void {
    if (resetPage) this.page = 0;
    const v = this.filterForm.value;
    const filter: SessionFilter = {
      q:         v.q || undefined,
      course_id: v.course_id || undefined,
      status:    v.status    || undefined,
      date_from: v.date_from ? this.toIso(v.date_from) : undefined,
      date_to:   v.date_to   ? this.toIso(v.date_to)   : undefined,
      page:      this.page,
      per_page:  this.pageSize,
    };
    this.loading.set(true);
    this.svc.getSessions(filter).subscribe({
      next: r => { this.sessions.set(r.rows); this.total.set(r.total); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  onPage(e: PageEvent): void {
    this.page = e.pageIndex;
    this.pageSize = e.pageSize;
    this.load();
  }

  clearFilters(): void {
    this.filterForm.reset({ q: '', course_id: null, status: '', date_from: null, date_to: null });
    this.load(true);
  }

  openAdd(): void {
    const ref = this.dialog.open(LessonDialogComponent, {
      data: { courses: this.courses() },
      width: '580px',
      maxWidth: '96vw',
      ariaLabel: 'Dodaj lekcję',
    });
    ref.afterClosed().subscribe(result => {
      if (!result) return;
      this.svc.createSession(result).subscribe(() => {
        this.snack.open('Lekcja dodana', 'OK', { duration: 3000 });
        this.load();
      });
    });
  }

  openEdit(session: Session): void {
    const ref = this.dialog.open(LessonDialogComponent, {
      data: { session, courses: this.courses() },
      width: '580px',
      maxWidth: '96vw',
      ariaLabel: 'Edytuj lekcję',
    });
    ref.afterClosed().subscribe(result => {
      if (!result) return;
      this.svc.updateSession(session.id, result).subscribe(() => {
        this.snack.open('Lekcja zaktualizowana', 'OK', { duration: 3000 });
        this.load();
      });
    });
  }

  deleteSession(session: Session): void {
    if (!confirm(`Usunąć lekcję z ${session.lesson_date}?`)) return;
    this.svc.deleteSession(session.id).subscribe(() => {
      this.snack.open('Lekcja usunięta', 'OK', { duration: 3000 });
      this.load();
    });
  }

  private toIso(d: Date): string {
    return d.toISOString().slice(0, 10);
  }
}
