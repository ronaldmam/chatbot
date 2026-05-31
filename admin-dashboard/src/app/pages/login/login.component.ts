import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.css']
})
export class LoginComponent {
  authService = inject(AuthService);
  router = inject(Router);

  username = '';
  password = '';
  errorMessage = '';
  isLoading = false;

  constructor() {
    // Redirect instantly if already logged in
    if (this.authService.isLoggedIn()) {
      this.router.navigate(['/dashboard']);
    }
  }

  /**
   * Fire credential login check toward the backend API
   */
  login(): void {
    if (!this.username || !this.password) {
      this.errorMessage = 'Por favor, ingresa tu usuario y contraseña.';
      return;
    }

    this.isLoading = true;
    this.errorMessage = '';

    this.authService.login(this.username, this.password).subscribe({
      next: () => {
        this.router.navigate(['/dashboard']);
      },
      error: (err) => {
        this.isLoading = false;
        this.errorMessage = err.error?.message || 'Credenciales inválidas o servidor desconectado.';
      }
    });
  }
}
