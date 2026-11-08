import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import '../css/app.css'
createApp(App).use(createPinia()).mount('#app')

if (import.meta.env.PROD && 'serviceWorker' in navigator) { window.addEventListener('load', () => { navigator.serviceWorker.register('/sw.js').catch(() => { console.warn('Offline application shell could not be installed.') }) }) }
