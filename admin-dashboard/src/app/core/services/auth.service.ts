import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, map } from 'rxjs';

export interface User {
  id: number;
  username: string;
  email: string;
  role: 'admin' | 'agent';
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private http = inject(HttpClient);
  
  // Dynamically resolve target API in development (XAMPP running at :8000) vs production (relative paths)
  private apiBase = location.hostname === 'localhost' || location.hostname === '127.0.0.1' 
    ? 'http://localhost:8000' 
    : '';

  private currentUserSubject = new BehaviorSubject<User | null>(null);
  public currentUser$ = this.currentUserSubject.asObservable();

  constructor() {
    const cachedUser = localStorage.getItem('user');
    if (cachedUser) {
      try {
        this.currentUserSubject.next(JSON.parse(cachedUser));
      } catch (e) {
        localStorage.removeItem('user');
      }
    }
  }

  public get currentUserValue(): User | null {
    return this.currentUserSubject.value;
  }

  /**
   * Log in user and cache JWT token statically in storage
   */
  public login(username: string, password: string): Observable<any> {
    return this.http.post<any>(`${this.apiBase}/api/auth/login`, { username, password }).pipe(
      map(response => {
        if (response.token && response.user) {
          localStorage.setItem('token', response.token);
          localStorage.setItem('user', JSON.stringify(response.user));
          this.currentUserSubject.next(response.user);
        }
        return response;
      })
    );
  }

  /**
   * Clear storage credentials on logout
   */
  public logout(): void {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    this.currentUserSubject.next(null);
  }

  public isLoggedIn(): boolean {
    return !!localStorage.getItem('token');
  }

  public isAdmin(): boolean {
    return this.currentUserValue?.role === 'admin';
  }
}
