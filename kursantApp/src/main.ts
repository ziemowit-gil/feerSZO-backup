import { registerLocaleData } from '@angular/common';
import localePl from '@angular/common/locales/pl';
import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { AppComponent } from './app/app.component';

// Bez tego DatePipe rzuca "NG0701: Missing locale data for the locale 'pl'"
// dla KAŻDEGO {{ x | date:'...':'':'pl' }} w aplikacji (a to niemal każdy
// widok listy — komunikaty, oceny, lekcje, dashboard...). Błąd jest rzucany
// synchronicznie w trakcie update-passu Angulara, więc przerywa też
// renderowanie WSZYSTKICH kolejnych elementów tej samej pętli @for — objawiało
// się to jako puste karty po pierwszej pozycji na liście.
registerLocaleData(localePl);

bootstrapApplication(AppComponent, appConfig).catch(err => console.error(err));
