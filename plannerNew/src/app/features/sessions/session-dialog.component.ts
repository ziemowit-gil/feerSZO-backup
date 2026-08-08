import { Component, Inject, OnInit, signal } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogRef, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatChipsModule } from '@angular/material/chips';
import { CommonModule } from '@angular/common';
import { PlannerService } from '../../core/services/planner.service';
import { Session, Room, Draft, ConflictResult } from '../../core/models/planner.models';

export interface SessionDialogData {
  session: Session | null;
  prefill: Partial<Session>;
  rooms: Room[];
  drafts: Draft[];
}

@Component({
  selector: 'app-session-dialog',
  standalone: true,
  imports: [
    CommonModule, ReactiveFormsModule, MatDialogModule,
    MatFormFieldModule, MatInputModule, MatSelectModule,
    MatButtonModule, MatIconModule, MatProgressSpinnerModule, MatChipsModule,
  ],
  template: `
    <h2 mat-dialog-title>{{ isEdit ? 'Edytuj sesję' : 'Nowa sesja' }}</h2>
    <mat-dialog-content>
      <form [formGroup]="form" class="dialog-form">
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Data</mat-label>
            <input matInput type="date" formControlName="lesson_date"/>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Kurs ID</mat-label>
            <input matInput type="number" formControlName="course_id" placeholder="np. 42"/>
          </mat-form-field>
        </div>
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Od</mat-label>
            <input matInput type="time" formControlName="time_from"/>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Do</mat-label>
            <input matInput type="time" formControlName="time_to"/>
          </mat-form-field>
        </div>
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Typ bloku</mat-label>
            <mat-select formControlName="block_type">
              <mat-option value="theory">Teoria</mat-option>
              <mat-option value="workshop">Warsztat</mat-option>
              <mat-option value="lab">Lab</mat-option>
              <mat-option value="code_review">Code Review</mat-option>
              <mat-option value="project">Projekt</mat-option>
            </mat-select>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Tryb</mat-label>
            <mat-select formControlName="mode">
              <mat-option value="onsite">Stacjonarne</mat-option>
              <mat-option value="remote">Zdalne</mat-option>
              <mat-option value="hybrid">Hybrydowe</mat-option>
            </mat-select>
          </mat-form-field>
        </div>
        <div class="row2">
          <mat-form-field appearance="outline">
            <mat-label>Sala</mat-label>
            <mat-select formControlName="room_id">
              <mat-option [value]="null">— brak —</mat-option>
              @for (r of data.rooms; track r.id) {
                <mat-option [value]="r.id">{{ r.name }} ({{ r.capacity }}os.)</mat-option>
              }
            </mat-select>
          </mat-form-field>
          <mat-form-field appearance="outline">
            <mat-label>Draft</mat-label>
            <mat-select formControlName="draft_id">
              <mat-option [value]="null">Opublikowany</mat-option>
              @for (d of data.drafts; track d.id) {
                <mat-option [value]="d.id">[{{ d.status }}] {{ d.title }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        </div>
        <mat-form-field appearance="outline" class="full-width">
          <mat-label>Temat</mat-label>
          <input matInput formControlName="topic"/>
        </mat-form-field>
        <mat-form-field appearance="outline" class="full-width">
          <mat-label>Notatki</mat-label>
          <textarea matInput formControlName="notes" rows="2"></textarea>
        </mat-form-field>
      </form>

      <!-- Konflikt checker -->
      @if (checking()) { <mat-spinner [diameter]="20" class="inline-spin"/> Sprawdzam konflikty… }
      @if (conflicts()) {
        @if (conflicts()!.hard.length) {
          <div class="conflict-box hard">
            <mat-icon>error</mat-icon>
            @for (c of conflicts()!.hard; track c.code) { <div>{{ c.msg }}</div> }
          </div>
        }
        @if (conflicts()!.soft.length) {
          <div class="conflict-box soft">
            <mat-icon>warning</mat-icon>
            @for (c of conflicts()!.soft; track c.code) { <div>{{ c.msg }}</div> }
          </div>
        }
        @if (!conflicts()!.hard.length && !conflicts()!.soft.length) {
          <div class="conflict-box ok"><mat-icon>check_circle</mat-icon> Brak konfliktów</div>
        }
      }
    </mat-dialog-content>

    <mat-dialog-actions align="end">
      <button mat-button (click)="checkOnly()">Sprawdź konflikty</button>
      <button mat-button mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" (click)="save()"
              [disabled]="form.invalid || saving() || (conflicts()?.hard?.length ?? 0) > 0">
        @if (saving()) { Zapisywanie… } @else { {{ isEdit ? 'Zapisz' : 'Utwórz' }} }
      </button>
    </mat-dialog-actions>
  `,
  styles: [`
    .dialog-form { display: flex; flex-direction: column; gap: 4px; min-width: 480px; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .full-width { width: 100%; }
    .inline-spin { display: inline-block; margin: 8px 0; }
    .conflict-box { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 6px; font-size: 12.5px; margin-top: 8px; }
    .conflict-box.hard { background: rgba(248,113,113,.12); color: #F87171; }
    .conflict-box.soft { background: rgba(251,191,36,.10); color: #FBBF24; }
    .conflict-box.ok   { background: rgba(45,213,138,.10); color: #2DD58A; }
  `],
})
export class SessionDialogComponent implements OnInit {
  form: FormGroup;
  saving   = signal(false);
  checking = signal(false);
  conflicts = signal<ConflictResult | null>(null);

  get isEdit(): boolean { return !!this.data.session; }

  constructor(
    @Inject(MAT_DIALOG_DATA) public data: SessionDialogData,
    private dialogRef: MatDialogRef<SessionDialogComponent>,
    private fb: FormBuilder,
    private planner: PlannerService,
  ) {
    const s = data.session ?? data.prefill as Session;
    this.form = this.fb.group({
      course_id:   [s?.course_id ?? null, Validators.required],
      lesson_date: [s?.lesson_date ?? '', Validators.required],
      time_from:   [s?.time_from ?? '09:00', Validators.required],
      time_to:     [s?.time_to ?? '10:00',   Validators.required],
      block_type:  [s?.block_type ?? 'theory'],
      mode:        [s?.mode ?? 'onsite'],
      room_id:     [s?.room_id ?? null],
      draft_id:    [s?.draft_id ?? null],
      topic:       [s?.topic ?? ''],
      notes:       [s?.notes ?? ''],
    });
  }

  ngOnInit(): void {}

  checkOnly(): void {
    this.checking.set(true);
    const v = this.form.value;
    this.planner.checkConflicts({ ...v, skip_id: this.data.session?.id }).subscribe({
      next: cr => { this.conflicts.set(cr); this.checking.set(false); },
      error: () => this.checking.set(false),
    });
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const v = this.form.value;
    const obs = this.isEdit
      ? this.planner.updateSession(this.data.session!.id, v)
      : this.planner.createSession(v);

    obs.subscribe({
      next: s => { this.saving.set(false); this.dialogRef.close(s); },
      error: e => {
        this.saving.set(false);
        if (e.raw?.error) {
          try { this.conflicts.set(JSON.parse(e.raw.error)); } catch { this.conflicts.set({ hard: [{ code: 'ERR', msg: e.message }], soft: [] }); }
        }
      },
    });
  }
}
