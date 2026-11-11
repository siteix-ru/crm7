import { mount } from 'svelte';
import App from './App.svelte';
import './app.css';

const target = document.getElementById('app');

if (!target) {
  throw new Error('Root element #app not found');
}

const app = mount(App, { target });

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./sw.js').catch(() => {
      // офлайн-режим недоступен — не критично
    });
  });

  const hadController = Boolean(navigator.serviceWorker.controller);
  let interacted = false;
  let reloading = false;

  for (const type of ['pointerdown', 'keydown', 'input'] as const) {
    window.addEventListener(type, () => (interacted = true), { once: true, capture: true });
  }

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController || interacted || reloading) {
      return;
    }

    reloading = true;
    window.location.reload();
  });
}

export default app;
