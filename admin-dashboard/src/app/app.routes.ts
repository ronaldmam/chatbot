import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { roleGuard } from './core/guards/role.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () => import('./pages/login/login.component').then(m => m.LoginComponent)
  },
  {
    path: 'dashboard',
    loadComponent: () => import('./pages/dashboard/dashboard.component').then(m => m.DashboardComponent),
    canActivate: [authGuard]
  },
  {
    path: 'chats',
    loadComponent: () => import('./pages/chats/chats.component').then(m => m.ChatsComponent),
    canActivate: [authGuard]
  },
  {
    path: 'configuration',
    loadComponent: () => import('./pages/configuration/configuration.component').then(m => m.ConfigurationComponent),
    canActivate: [authGuard, roleGuard]
  },
  {
    path: '',
    redirectTo: 'dashboard',
    pathMatch: 'full'
  },
  {
    path: '**',
    redirectTo: 'dashboard'
  }
];
