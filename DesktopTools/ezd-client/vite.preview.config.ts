// Podgląd samego renderera w zwykłej przeglądarce (z atrapą API) — do przeglądu UI i testów a11y: npm run preview
import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  root: 'src/renderer',
  plugins: [react()],
  resolve: { alias: { '@shared': resolve(__dirname, 'src/shared') } },
  server: { port: 5199, strictPort: true, fs: { allow: [resolve(__dirname)] } }
});
