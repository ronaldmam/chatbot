import { inject } from '@angular/core';
import { Router, CanActivateFn } from '@angular/router';

export const roleGuard: CanActivateFn = (route, state) => {
  const router = inject(Router);
  const token = localStorage.getItem('token');

  if (token) {
    try {
      // Decode JWT payload without third-party libraries (FTP-friendly)
      const payloadBase64 = token.split('.')[1];
      const payload = JSON.parse(atob(payloadBase64));
      
      const role = payload.data?.role;

      // Allow access only to 'admin' users
      if (role === 'admin') {
        return true;
      }
    } catch (e) {
      console.error('Failed to parse JWT payload for role verification:', e);
    }
  }

  // Redirect agents/unauthorized users to dashboard home
  router.navigate(['/dashboard']);
  return false;
};
