import { inject } from '@angular/core';
import { Router, CanActivateFn } from '@angular/router';

export const authGuard: CanActivateFn = (route, state) => {
  const router = inject(Router);
  const token = localStorage.getItem('token');

  if (token) {
    // Quick validation check: Check if token has expired
    try {
      const payload = JSON.parse(atob(token.split('.')[1]));
      const exp = payload.exp;
      if (exp && Date.now() >= exp * 1000) {
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        router.navigate(['/login']);
        return false;
      }
      return true;
    } catch (e) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
    }
  }

  router.navigate(['/login']);
  return false;
};
