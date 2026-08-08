import { Pipe, PipeTransform } from '@angular/core';
import { LessonStatus } from '../../core/models/kursant.models';

const LESSON_LABELS: Record<string, string> = {
  held:            'Odbyła się',
  planned:         'Zaplanowana',
  cancelled:       'Odwołana',
  excused:         'Usprawiedliwiona',
  absence:         'Nieobecność',
  remote_material: 'Praca własna',
};

const HOMEWORK_LABELS: Record<string, string> = {
  pending:   'Oczekuje',
  submitted: 'Wysłane',
  graded:    'Ocenione',
};

const TEST_LABELS: Record<string, string> = {
  available: 'Dostępny',
  completed: 'Ukończony',
  expired:   'Wygasł',
  locked:    'Zablokowany',
};

@Pipe({ name: 'statusLabel', standalone: true })
export class StatusLabelPipe implements PipeTransform {
  transform(value: string): string {
    return LESSON_LABELS[value] ?? HOMEWORK_LABELS[value] ?? TEST_LABELS[value] ?? value;
  }
}
