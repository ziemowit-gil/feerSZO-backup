import { Component, signal, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { KursantApiService } from '../../core/services/kursant-api.service';

@Component({
  selector: 'app-problem',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule,
  ],
  template: `
    <div class="page-header">
      <h1>Pomoc / Zgłoszenie problemu</h1>
      <p class="subtitle">Wyślij zgłoszenie do zespołu wsparcia</p>
    </div>

    @if (submitted()) {
      <div class="k-alert success" role="status" aria-live="polite">
        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
        <div>
          <strong>Zgłoszenie wysłane!</strong>
          <p class="mb-0 text-sm" style="margin:.25rem 0 0">
            Twoje zgłoszenie (nr KUR-…) zostało przyjęte. Odpowiemy na podany adres e-mail.
          </p>
        </div>
      </div>
      <button mat-stroked-button (click)="reset()" style="margin-top:1rem">
        Wyślij kolejne zgłoszenie
      </button>
    } @else {
      <section class="k-card" aria-labelledby="problem-form-heading">
        <h2 class="k-card-title" id="problem-form-heading">
          <span class="material-symbols-outlined" aria-hidden="true">support_agent</span>
          Formularz zgłoszeniowy
        </h2>

        @if (error()) {
          <div class="k-alert danger" role="alert">{{ error() }}</div>
        }

        <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
          <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
            <mat-label>Kategoria problemu</mat-label>
            <mat-select formControlName="category" id="problem-category" required>
              <mat-option value="logowanie">Problem z logowaniem</mat-option>
              <mat-option value="lekcje">Lekcje / harmonogram</mat-option>
              <mat-option value="materialy">Materiały dydaktyczne</mat-option>
              <mat-option value="rozliczenia">Rozliczenia / płatności</mat-option>
              <mat-option value="techniczny">Problem techniczny</mat-option>
              <mat-option value="inne">Inne</mat-option>
            </mat-select>
          </mat-form-field>

          <mat-form-field appearance="fill" style="width:100%;margin-bottom:.75rem">
            <mat-label>Temat zgłoszenia</mat-label>
            <input matInput
                   formControlName="subject"
                   id="problem-subject"
                   maxlength="200"
                   [attr.aria-required]="true">
            @if (form.controls.subject.invalid && form.controls.subject.touched) {
              <mat-error>Temat jest wymagany</mat-error>
            }
          </mat-form-field>

          <mat-form-field appearance="fill" style="width:100%;margin-bottom:1rem">
            <mat-label>Opis problemu</mat-label>
            <textarea matInput
                      formControlName="body"
                      id="problem-body"
                      rows="6"
                      maxlength="3000"
                      [attr.aria-required]="true"
                      placeholder="Opisz dokładnie, co się dzieje, kiedy problem wystąpił i jakie kroki podjąłeś..."></textarea>
            <mat-hint align="end">{{ form.value.body?.length ?? 0 }}/3000</mat-hint>
            @if (form.controls.body.invalid && form.controls.body.touched) {
              <mat-error>Opis problemu jest wymagany</mat-error>
            }
          </mat-form-field>

          <button mat-flat-button
                  type="submit"
                  [disabled]="sending()"
                  [attr.aria-busy]="sending()">
            <span class="material-symbols-outlined" aria-hidden="true">send</span>
            Wyślij zgłoszenie
          </button>
        </form>
      </section>
    }
  `,
  styles: [`
    .mb-0 { margin-bottom: 0 !important; }
  `],
})
export class ProblemComponent {
  private fb  = inject(FormBuilder);
  private api = inject(KursantApiService);

  form = this.fb.nonNullable.group({
    category: ['inne'],
    subject:  ['', [Validators.required, Validators.minLength(3)]],
    body:     ['', [Validators.required, Validators.minLength(10)]],
  });

  sending   = signal(false);
  submitted = signal(false);
  error     = signal<string | null>(null);

  submit(): void {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.sending()) return;

    const { category, subject, body } = this.form.getRawValue();
    this.sending.set(true);
    this.error.set(null);

    this.api.reportIssue(`[${category}] ${subject}`, body).subscribe({
      next: res => {
        this.sending.set(false);
        if (res.success) {
          this.submitted.set(true);
        } else {
          this.error.set(res.error ?? 'Błąd wysyłania zgłoszenia.');
        }
      },
      error: () => {
        this.sending.set(false);
        this.error.set('Błąd połączenia z serwerem.');
      },
    });
  }

  reset(): void {
    this.form.reset({ category: 'inne', subject: '', body: '' });
    this.submitted.set(false);
    this.error.set(null);
  }
}
