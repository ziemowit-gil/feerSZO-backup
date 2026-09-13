import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { InstructorAuthService } from './instructor-auth.service';

export const instructorGuard: CanActivateFn = () => {
  const auth   = inject(InstructorAuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) return true;

  return router.createUrlTree(['/logowanie-prowadzacy']);
};
