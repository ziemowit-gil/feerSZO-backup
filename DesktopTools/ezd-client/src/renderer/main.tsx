import { createRoot } from 'react-dom/client';
import { LANG } from '@shared/locale';
import { App } from './App';
import './design/themes.css';

// W trybie DEV bez Electrona (npm run preview) podstaw atrapę API — kod produkcyjny jej nie zawiera.
document.documentElement.lang = LANG;
const boot = async () => {
  if (import.meta.env.DEV && !(window as unknown as { api?: unknown }).api) await import('./devMock');
  createRoot(document.getElementById('root')!).render(<App />);
};
void boot();
