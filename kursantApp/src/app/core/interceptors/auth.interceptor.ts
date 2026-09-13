import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../auth/auth.service';
import { InstructorAuthService } from '../auth/instructor-auth.service';

// Akcje logowania same zwracają 401 dla błędnych danych — to nie jest
// "token wygasł", więc nie mają wymuszać wylogowania (i tak nie ma z czego
// wylogować, a resetowałoby to np. formularz logowania w trakcie wpisywania).
const PUBLIC_LOGIN_ACTIONS = [
  'action=login', 'action=parent_login', 'action=parent_otp_send',
  'action=parent_otp_verify', 'action=parent_select_child',
  'action=authp_login', 'action=impersonate_exchange', 'action=verify_totp',
  // logout samo woła auth.logout() (patrz auth.service.ts) — 401 tutaj (np. bo
  // token już wygasł zanim zdążyliśmy się wylogować) nie może z powrotem
  // wywoływać logout(), bo to właśnie ten POST logout ponownie by odpalił —
  // nieskończona pętla POST ?action=logout, każdy kończący się 401.
  'action=logout',
];

// Panel kursanta i panel prowadzącego mają osobne tożsamości/tokeny (osobne
// API: kursant_student.php vs dydaktyk_instructor.php) — interceptor dobiera
// token i usługę wylogowania po adresie żądania, każde 401 obsługuje tylko
// swoje własne API.
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth           = inject(AuthService);
  const instructorAuth = inject(InstructorAuthService);

  const isKursantApi    = req.url.includes('/api/v1/kursant_student.php');
  const isInstructorApi = req.url.includes('/api/v1/dydaktyk_instructor.php');

  const token = isInstructorApi ? instructorAuth.token() : auth.token();
  const authReq = (token && (isKursantApi || isInstructorApi))
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authReq).pipe(
    catchError(err => {
      // 401 na chronionym zasobie = token wygasł/odrzucony po stronie serwera.
      // Bez tego każdy kolejny widok pokazywał mylące "Błąd połączenia z
      // serwerem" zamiast po prostu wrócić do ekranu logowania.
      const isPublicLogin = PUBLIC_LOGIN_ACTIONS.some(a => req.url.includes(a));
      if (err?.status === 401 && !isPublicLogin) {
        if (isKursantApi) auth.logout();
        else if (isInstructorApi) instructorAuth.logout();
      }
      return throwError(() => err);
    })
  );
};
