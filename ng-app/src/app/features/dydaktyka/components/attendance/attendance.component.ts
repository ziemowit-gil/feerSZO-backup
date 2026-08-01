import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatSelectModule } from '@angular/material/select';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { DydaktykaService } from '../../services/dydaktyka.service';
import { Attendance, Course, Session } from '../../models/dydaktyka.model';

@Component({
  selector: 'app-attendance',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatTableModule,
    MatFormFieldModule, MatSelectModule, MatButtonModule,
    MatIconModule, MatCheckboxModule, MatProgressBarModule,
    MatTooltipModule, PageHeaderComponent,
  ],
  templateUrl: './attendance.component.html',
  styleUrl: './attendance.component.scss',
})
export class AttendanceComponent implements OnInit {
  private svc = inject(DydaktykaService);
  private fb  = inject(FormBuilder);

  courses   = signal<Course[]>([]);
  sessions  = signal<Session[]>([]);
  rows      = signal<Attendance[]>([]);
  loading   = signal(false);

  filterForm = this.fb.group({
    course_id:  [null as number | null],
    session_id: [null as number | null],
  });

  readonly displayedColumns = ['client_name', 'attended', 'no_show', 'ind_notes'];

  readonly statusMap: Record<string, { label: string; css: string }> = {
    scheduled:       { label: 'Zaplanowana', css: 'status-pending' },
    completed:       { label: 'Odbyta',      css: 'status-active'  },
    cancelled:       { label: 'Odwołana',    css: 'status-cancelled'},
    remote_material: { label: 'Praca własna',css: 'status-draft'   },
  };

  ngOnInit(): void {
    this.svc.getCourses().subscribe(c => this.courses.set(c));

    this.filterForm.get('course_id')!.valueChanges.subscribe(id => {
      this.sessions.set([]); this.rows.set([]);
      this.filterForm.get('session_id')!.reset(null, { emitEvent: false });
      if (!id) return;
      this.loading.set(true);
      this.svc.getSessions({ course_id: id, per_page: 200 }).subscribe(r => {
        this.sessions.set(r.rows); this.loading.set(false);
      });
    });

    this.filterForm.get('session_id')!.valueChanges.subscribe(id => {
      this.rows.set([]);
      if (!id) return;
      this.loading.set(true);
      this.svc.getAttendance(id).subscribe(a => { this.rows.set(a); this.loading.set(false); });
    });
  }
}
