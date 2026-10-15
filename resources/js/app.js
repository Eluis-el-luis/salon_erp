import Alpine from 'alpinejs';
import dashboard from './dashboard';

window.Alpine = Alpine;

// Componente del Dashboard Gerencial (Centro de Mando SPA)
Alpine.data('dashboard', dashboard);

Alpine.start();