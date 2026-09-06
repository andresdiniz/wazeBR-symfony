/**
 * wazeBR - Main Application Entry Point
 */

import { initApp } from './core/init.js';
import { config } from './core/config.js';

document.addEventListener('DOMContentLoaded', () => {
  initApp(config);
});

export { config };
