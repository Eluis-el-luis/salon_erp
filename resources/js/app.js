import Alpine from 'alpinejs';
import dashboard from './dashboard';
import pinAutorizacion from './pin-modal';
import notificationBell from './notification-bell';

window.Alpine = Alpine;

// Componente del Dashboard Gerencial (Centro de Mando SPA)
Alpine.data('dashboard', dashboard);

// Modal global de Doble Confirmación por PIN (Supervisor Override)
Alpine.data('pinAutorizacion', pinAutorizacion);

// Campanita de Notificaciones en Navbar
Alpine.data('notificationBell', notificationBell);

Alpine.start();
