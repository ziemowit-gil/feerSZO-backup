import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { MatTableModule } from '@angular/material/table';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialog, MatDialogModule, MatDialogRef, MAT_DIALOG_DATA } from '@angular/material/dialog';
import { MatCardModule } from '@angular/material/card';
import { Inject } from '@angular/core';
import { PlannerService } from '../../core/services/planner.service';
import { TokenWallet, TokenTransaction, TokenPrice } from '../../core/models/planner.models';

@Component({
  selector: 'app-grant-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatButtonModule],
  template: `
    <h2 mat-dialog-title>Przyznaj żetony</h2>
    <mat-dialog-content>
      <form [formGroup]="form" style="display:flex;flex-direction:column;gap:8px;min-width:360px;margin-top:8px">
        <mat-form-field appearance="outline">
          <mat-label>ID klienta</mat-label>
          <input matInput type="number" formControlName="client_id"/>
        </mat-form-field>
        <mat-form-field appearance="outline">
          <mat-label>Liczba żetonów</mat-label>
          <input matInput type="number" formControlName="amount"/>
        </mat-form-field>
        <mat-form-field appearance="outline">
          <mat-label>Powód</mat-label>
          <input matInput formControlName="note" placeholder="np. opłata za kurs jesień 2026"/>
        </mat-form-field>
      </form>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" (click)="save()" [disabled]="form.invalid || saving()">
        @if (saving()) { Wysyłam… } @else { Przyznaj }
      </button>
    </mat-dialog-actions>
  `,
})
export class GrantDialogComponent {
  form: FormGroup;
  saving = signal(false);
  constructor(
    @Inject(MAT_DIALOG_DATA) public data: null,
    private ref: MatDialogRef<GrantDialogComponent>,
    private fb: FormBuilder,
    private planner: PlannerService,
  ) {
    this.form = this.fb.group({
      client_id: [null, [Validators.required, Validators.min(1)]],
      amount:    [null, [Validators.required, Validators.min(1)]],
      note:      [''],
    });
  }
  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    this.planner.grantTokens(this.form.value).subscribe({
      next: w => { this.saving.set(false); this.ref.close(w); },
      error: () => this.saving.set(false),
    });
  }
}

@Component({
  selector: 'app-price-dialog',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatButtonModule],
  template: `
    <h2 mat-dialog-title>{{ data ? 'Edytuj cenę' : 'Nowa cena żetonów' }}</h2>
    <mat-dialog-content>
      <form [formGroup]="form" style="display:flex;flex-direction:column;gap:8px;min-width:340px;margin-top:8px">
        <mat-form-field appearance="outline"><mat-label>ID kursu (opcj.)</mat-label><input matInput type="number" formControlName="course_id"/></mat-form-field>
        <mat-form-field appearance="outline"><mat-label>ID mentora (opcj.)</mat-label><input matInput type="number" formControlName="mentor_id"/></mat-form-field>
        <mat-form-field appearance="outline"><mat-label>ID ścieżki (opcj.)</mat-label><input matInput type="number" formControlName="tech_path_id"/></mat-form-field>
        <mat-form-field appearance="outline"><mat-label>Poziom (opcj.)</mat-label><input matInput formControlName="level" placeholder="beginner/intermediate/advanced"/></mat-form-field>
        <mat-form-field appearance="outline"><mat-label>Cena (żetonów/sesję)</mat-label><input matInput type="number" formControlName="price" min="1"/></mat-form-field>
      </form>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button mat-dialog-close>Anuluj</button>
      <button mat-flat-button color="primary" (click)="save()" [disabled]="form.invalid || saving()">
        @if (saving()) { Zapisywanie… } @else { Zapisz }
      </button>
    </mat-dialog-actions>
  `,
})
export class PriceDialogComponent {
  form: FormGroup;
  saving = signal(false);
  constructor(
    @Inject(MAT_DIALOG_DATA) public data: TokenPrice | null,
    private ref: MatDialogRef<PriceDialogComponent>,
    private fb: FormBuilder,
    private planner: PlannerService,
  ) {
    this.form = this.fb.group({
      course_id:    [data?.course_id ?? null],
      mentor_id:    [data?.mentor_id ?? null],
      tech_path_id: [data?.tech_path_id ?? null],
      level:        [data?.level ?? ''],
      price:        [data?.price ?? 1, [Validators.required, Validators.min(1)]],
    });
  }
  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    const obs = this.data ? this.planner.updatePrice(this.data.id, this.form.value) : this.planner.createPrice(this.form.value);
    obs.subscribe({ next: p => { this.saving.set(false); this.ref.close(p); }, error: () => this.saving.set(false) });
  }
}

@Component({
  selector: 'app-tokens',
  standalone: true,
  imports: [
    CommonModule, MatTableModule, MatButtonModule, MatIconModule,
    MatFormFieldModule, MatInputModule, MatTabsModule,
    MatProgressSpinnerModule, MatSnackBarModule, MatDialogModule, MatCardModule,
  ],
  template: `
    <div class="page-header">
      <div><h2 class="page-title">System żetonowy</h2><p class="page-sub">Portfele, transakcje i cennik</p></div>
      <button mat-flat-button color="primary" (click)="openGrant()"><mat-icon>toll</mat-icon> Przyznaj żetony</button>
    </div>

    <mat-tab-group>
      <!-- TAB 1: Portfele -->
      <mat-tab label="Portfele">
        <div style="padding:16px 0">
          <div class="lookup-row">
            <span style="font-size:13px;color:var(--t2)">Wyszukaj portfel po ID klienta:</span>
            <input #cid type="number" placeholder="np. 42" class="id-input"/>
            <button mat-stroked-button (click)="loadWallet(+cid.value)">Szukaj</button>
          </div>
          @if (wallet()) {
            <mat-card class="wallet-card">
              <mat-card-content>
                <div class="wallet-grid">
                  <div class="wallet-stat"><div class="stat-label">Dostępne</div><div class="stat-val green">{{ wallet()!.balance }}</div></div>
                  <div class="wallet-stat"><div class="stat-label">Zarezerwowane</div><div class="stat-val amber">{{ wallet()!.balance_hold }}</div></div>
                  <div class="wallet-stat"><div class="stat-label">Wydane łącznie</div><div class="stat-val">{{ wallet()!.total_spent }}</div></div>
                </div>
                <button mat-stroked-button (click)="loadTx(wallet()!.id)" style="margin-top:12px">
                  <mat-icon>history</mat-icon> Historia transakcji
                </button>
              </mat-card-content>
            </mat-card>
          }
          @if (txLoading()) { <mat-spinner [diameter]="28" style="margin:16px auto"/> }
          @if (transactions().length) {
            <div class="table-wrap" style="margin-top:16px">
              <table mat-table [dataSource]="transactions()">
                <ng-container matColumnDef="type">
                  <th mat-header-cell *matHeaderCellDef>Typ</th>
                  <td mat-cell *matCellDef="let t"><span [class]="txClass(t.type)">{{ t.type }}</span></td>
                </ng-container>
                <ng-container matColumnDef="amount">
                  <th mat-header-cell *matHeaderCellDef>Kwota</th>
                  <td mat-cell *matCellDef="let t" [class]="t.amount > 0 ? 'pos' : 'neg'">{{ t.amount > 0 ? '+' : '' }}{{ t.amount }}</td>
                </ng-container>
                <ng-container matColumnDef="note">
                  <th mat-header-cell *matHeaderCellDef>Opis</th>
                  <td mat-cell *matCellDef="let t">{{ t.note || '—' }}</td>
                </ng-container>
                <ng-container matColumnDef="date">
                  <th mat-header-cell *matHeaderCellDef>Data</th>
                  <td mat-cell *matCellDef="let t">{{ t.created_at | date:'dd.MM.yy HH:mm' }}</td>
                </ng-container>
                <tr mat-header-row *matHeaderRowDef="txCols"></tr>
                <tr mat-row *matRowDef="let row; columns: txCols"></tr>
              </table>
            </div>
          }
        </div>
      </mat-tab>

      <!-- TAB 2: Cennik -->
      <mat-tab label="Cennik">
        <div style="padding:16px 0">
          <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
            <button mat-stroked-button (click)="openPriceDialog()"><mat-icon>add</mat-icon> Nowa reguła cenowa</button>
          </div>
          @if (priceLoading()) { <mat-spinner [diameter]="28" style="margin:16px auto"/> }
          @else {
            <div class="table-wrap">
              <table mat-table [dataSource]="prices()">
                <ng-container matColumnDef="course"><th mat-header-cell *matHeaderCellDef>Kurs</th><td mat-cell *matCellDef="let p">{{ p.course_id ?? '—' }}</td></ng-container>
                <ng-container matColumnDef="mentor"><th mat-header-cell *matHeaderCellDef>Mentor</th><td mat-cell *matCellDef="let p">{{ p.mentor_id ?? '—' }}</td></ng-container>
                <ng-container matColumnDef="path"><th mat-header-cell *matHeaderCellDef>Ścieżka</th><td mat-cell *matCellDef="let p">{{ p.tech_path_id ?? '—' }}</td></ng-container>
                <ng-container matColumnDef="level"><th mat-header-cell *matHeaderCellDef>Poziom</th><td mat-cell *matCellDef="let p">{{ p.level || '—' }}</td></ng-container>
                <ng-container matColumnDef="price">
                  <th mat-header-cell *matHeaderCellDef>Cena</th>
                  <td mat-cell *matCellDef="let p"><strong>{{ p.price }} żet.</strong></td>
                </ng-container>
                <ng-container matColumnDef="actions">
                  <th mat-header-cell *matHeaderCellDef></th>
                  <td mat-cell *matCellDef="let p"><button mat-icon-button (click)="openPriceDialog(p)"><mat-icon>edit</mat-icon></button></td>
                </ng-container>
                <tr mat-header-row *matHeaderRowDef="priceCols"></tr>
                <tr mat-row *matRowDef="let row; columns: priceCols"></tr>
              </table>
              @if (!prices().length) { <div class="empty-state"><mat-icon>sell</mat-icon><p>Brak reguł cenowych.</p></div> }
            </div>
          }
        </div>
      </mat-tab>
    </mat-tab-group>
  `,
  styles: [`
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; }
    .page-title { font-size: 1.4rem; font-weight: 800; margin: 0; }
    .page-sub { color: var(--t2); margin: 4px 0 0; font-size: 13px; }
    .lookup-row { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
    .id-input { border: 1px solid var(--bor); border-radius: 4px; padding: 8px 12px; background: var(--sur); color: var(--t1); width: 120px; font-size: 14px; }
    .wallet-card { border: 1px solid var(--bor); background: var(--sur); }
    .wallet-grid { display: flex; gap: 32px; }
    .wallet-stat { display: flex; flex-direction: column; align-items: center; }
    .stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--t3); }
    .stat-val { font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .stat-val.green { color: #2DD58A; }
    .stat-val.amber { color: #FBBF24; }
    .table-wrap { overflow-x: auto; border: 1px solid var(--bor); border-radius: 6px; }
    .pos { color: #2DD58A; font-weight: 600; }
    .neg { color: #F87171; font-weight: 600; }
    .tx-grant { padding: 2px 6px; border-radius: 3px; background: rgba(45,213,138,.12); color: #2DD58A; font-size: 11px; }
    .tx-hold  { padding: 2px 6px; border-radius: 3px; background: rgba(251,191,36,.12); color: #FBBF24; font-size: 11px; }
    .tx-spend { padding: 2px 6px; border-radius: 3px; background: rgba(248,113,113,.12); color: #F87171; font-size: 11px; }
    .tx-refund { padding: 2px 6px; border-radius: 3px; background: rgba(59,130,246,.12); color: #60A5FA; font-size: 11px; }
    .empty-state { display: flex; flex-direction: column; align-items: center; padding: 40px; color: var(--t3); gap: 8px; }
    .empty-state mat-icon { font-size: 36px; width: 36px; height: 36px; }
  `],
})
export class TokensComponent implements OnInit {
  wallet       = signal<TokenWallet | null>(null);
  transactions = signal<TokenTransaction[]>([]);
  prices       = signal<TokenPrice[]>([]);
  txLoading    = signal(false);
  priceLoading = signal(false);

  txCols    = ['type', 'amount', 'note', 'date'];
  priceCols = ['course', 'mentor', 'path', 'level', 'price', 'actions'];

  constructor(private planner: PlannerService, private dialog: MatDialog, private snack: MatSnackBar) {}

  ngOnInit(): void { this.loadPrices(); }

  loadWallet(clientId: number): void {
    if (!clientId) return;
    this.planner.getWallet(clientId).subscribe({ next: w => this.wallet.set(w), error: () => this.snack.open('Portfel nie znaleziony.', '', { duration: 2000 }) });
  }

  loadTx(walletId: number): void {
    this.txLoading.set(true);
    this.planner.getTransactions(walletId).subscribe({ next: t => { this.transactions.set(t); this.txLoading.set(false); }, error: () => this.txLoading.set(false) });
  }

  loadPrices(): void {
    this.priceLoading.set(true);
    this.planner.getPrices().subscribe({ next: p => { this.prices.set(p); this.priceLoading.set(false); }, error: () => this.priceLoading.set(false) });
  }

  openGrant(): void {
    this.dialog.open(GrantDialogComponent, { width: '420px', data: null, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((w: TokenWallet | null) => { if (w) this.snack.open(`Przyznano żetony. Saldo: ${w.balance}`, '', { duration: 3000 }); });
  }

  openPriceDialog(p?: TokenPrice): void {
    this.dialog.open(PriceDialogComponent, { width: '400px', data: p ?? null, panelClass: 'planner-dialog' })
      .afterClosed().subscribe((np: TokenPrice | null) => {
        if (!np) return;
        this.prices.update(all => { const i = all.findIndex(x => x.id === np.id); return i >= 0 ? all.map(x => x.id === np.id ? np : x) : [...all, np]; });
        this.snack.open('Reguła cenowa zapisana.', '', { duration: 2000 });
      });
  }

  txClass(type: string): string { return `tx-${type}`; }
}
