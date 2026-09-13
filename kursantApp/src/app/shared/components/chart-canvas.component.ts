import {
  Component, Input, ElementRef, ViewChild, AfterViewInit, OnChanges, OnDestroy, SimpleChanges,
} from '@angular/core';
import { Chart, ChartConfiguration, registerables } from 'chart.js';

Chart.register(...registerables);

/**
 * Cienki wrapper na Chart.js — jedno miejsce tworzenia/niszczenia instancji
 * wykresu na <canvas>, żeby komponenty z wykresami (Pulpit — frekwencja) nie
 * musiały same zarządzać cyklem życia Chart.js. `config` to zwykły
 * ChartConfiguration Chart.js (type/data/options) — przebudowuje wykres przy
 * każdej zmianie referencji.
 */
@Component({
  selector: 'app-chart-canvas',
  standalone: true,
  template: `<canvas #canvas [attr.aria-label]="ariaLabel" role="img"></canvas>`,
  styles: [`:host { display: block; position: relative; }`],
})
export class ChartCanvasComponent implements AfterViewInit, OnChanges, OnDestroy {
  @Input() config!: ChartConfiguration;
  @Input() ariaLabel = 'Wykres';
  @ViewChild('canvas', { static: true }) canvasRef!: ElementRef<HTMLCanvasElement>;

  private chart: Chart | null = null;

  ngAfterViewInit(): void {
    this.render();
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['config'] && this.canvasRef) this.render();
  }

  ngOnDestroy(): void {
    this.chart?.destroy();
  }

  private render(): void {
    if (!this.config) return;
    this.chart?.destroy();
    this.chart = new Chart(this.canvasRef.nativeElement, this.config);
  }
}
