import { Component, signal, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatSelectModule } from '@angular/material/select';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatChipsModule } from '@angular/material/chips';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatDialog } from '@angular/material/dialog';
import { MatSnackBar } from '@angular/material/snack-bar';
import { PageHeaderComponent } from '../../../../shared/components/page-header/page-header.component';
import { DydaktykaService } from '../../services/dydaktyka.service';
import { Grade, Course, GradeValue } from '../../models/dydaktyka.model';

@Component({
  selector: 'app-grade-list',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule,
    MatTableModule, MatFormFieldModule, MatSelectModule,
    MatButtonModule, MatIconModule, MatChipsModule,
    MatProgressBarModule, MatTooltipModule,
    PageHeaderComponent,
  ],
  templateUrl: './grade-list.component.html',
  styleUrl: './grade-list.component.scss',
})
export class GradeListComponent implements OnInit {
  private svc    = inject(DydaktykaService);
  private fb     = inject(FormBuilder);

  courses  = signal<Course[]>([]);
  grades   = signal<Grade[]>([]);
  loading  = signal(false);

  filterForm = this.fb.group({ course_id: [null as number | null] });

  readonly displayedColumns = ['client_name', 'category', 'value_text', 'weight', 'description', 'graded_at'];

  readonly gradeColors: Record<string, string> = {
    '1': '#fee2e2', '2': '#fef3c7', '3': '#fef9c3',
    '4': '#d1fae5', '5': '#bbf7d0', '6': '#86efac',
    'nzal': '#fee2e2', 'zal': '#d1fae5', 'nb': '#e5e7eb',
  };

  ngOnInit(): void {
    this.svc.getCourses().subscribe(c => { this.courses.set(c); });
    this.filterForm.get('course_id')!.valueChanges.subscribe(id => {
      if (id) { this.loading.set(true); this.svc.getGrades(id).subscribe(g => { this.grades.set(g); this.loading.set(false); }); }
      else this.grades.set([]);
    });
  }
}
