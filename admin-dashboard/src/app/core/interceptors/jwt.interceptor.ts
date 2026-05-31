import { HttpInterceptorFn } from '@angular/common/http';

export const jwtInterceptor: HttpInterceptorFn = (req, next) => {
  // Retrieve the stored JWT token from local storage
  const token = localStorage.getItem('token');
  
  // Clone the outgoing request and append the Authorization Bearer header if a token is present
  if (token) {
    const cloned = req.clone({
      setHeaders: {
        Authorization: `Bearer ${token}`
      }
    });
    return next(cloned);
  }
  
  return next(req);
};
