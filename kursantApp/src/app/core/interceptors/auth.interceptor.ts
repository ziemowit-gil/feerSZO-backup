import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../auth/auth.service';

// Akcje logowania same zwracają 401 dla błędnych danych — to nie jest
// "token wygasł", więc nie mają wymuszać wylogowania (i tak nie ma z czego
// wylogować, a resetowałoby to np. formularz logowania w trakcie wpisywania).
const PUBLIC_LOGIN_ACTIONS = [
  'action=login', 'action=parent_login', 'action=parent_otp_send',
  'action=parent_otp_verify', 'action=parent_select_child',
  'action=authp_login', 'action=impersonate_exchange',
];

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth  = inject(AuthService);
  const token = auth.token();
  const isApi = req.url.includes('/api/v1/kursant_student.php');

  const authReq = (token && isApi)
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authReq).pipe(
    catchError(err => {
      // 401 na chronionym zasobie = token wygasł/odrzucony po stronie serwera.
      // Bez tego każdy kolejny widok pokazywał mylące "Błąd połączenia z
      // serwerem" zamiast po prostu wrócić do ekranu logowania.
      const isPublicLogin = PUBLIC_LOGIN_ACTIONS.some(a => req.url.includes(a));
      if (isApi && err?.status === 401 && !isPublicLogin) {
        auth.logout();
      }
      return throwError(() => err);
    })
  );
};
