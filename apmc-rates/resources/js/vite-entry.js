import '../css/app.css';
import './app.js';
import Alpine from '@alpinejs/csp';
window.Alpine = Alpine;

if (typeof window.marketApp === 'function') {
  Alpine.data('marketApp', window.marketApp);
}

Alpine.start();
