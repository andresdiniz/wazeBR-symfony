/**
 * wazeBR - Application Configuration
 */

export const config = {
  appName: 'wazeBR',
  appVersion: '1.0.0',
  debug: false,
  
  api: {
    baseUrl: '/api',
    timeout: 30000,
    retryAttempts: 3,
    retryDelay: 1000,
  },
  
  map: {
    defaultLat: -19.9167,
    defaultLng: -43.9345,
    defaultZoom: 12,
    minZoom: 8,
    maxZoom: 18,
    tileProvider: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: 'wazeBR',
  },
  
  chart: {
    colors: {
      primary: '#2563eb',
      success: '#22c55e',
      warning: '#f59e0b',
      danger: '#ef4444',
      info: '#06b6d4',
    },
    fontFamily: "'Inter', sans-serif",
    fontSize: 12,
    animation: true,
    responsive: true,
  },
  
  pagination: {
    defaultLimit: 20,
    limitOptions: [10, 20, 50, 100],
  },
  
  timeouts: {
    notification: 5000,
    debounce: 300,
    throttle: 1000,
  },
  
  storage: {
    token: 'wazebr_token',
    user: 'wazebr_user',
    preferences: 'wazebr_preferences',
    sidebar: 'wazebr_sidebar_collapsed',
  },
  
  features: {
    notifications: true,
    darkMode: true,
    mapClustering: true,
    realtimeUpdates: false,
  },
  
  traffic: {
    free: { max: 5, color: 'success' },
    moderate: { max: 15, color: 'warning' },
    heavy: { max: 30, color: 'danger' },
    jam: { max: Infinity, color: 'danger' },
  },
  
  alertTypes: {
    accident: { icon: '🚗', color: 'danger' },
    hazard: { icon: '⚠️', color: 'warning' },
    police: { icon: '👮', color: 'info' },
    traffic: { icon: '🚦', color: 'warning' },
    weather: { icon: '🌧️', color: 'info' },
    flood: { icon: '🌊', color: 'danger' },
  },
  
  formats: {
    date: 'DD/MM/YYYY',
    time: 'HH:mm',
    datetime: 'DD/MM/YYYY HH:mm',
    display: 'ddd, DD MMM YYYY HH:mm',
  },
  
  locale: 'pt-BR',
  timezone: 'America/Sao_Paulo',
};

export default config;
