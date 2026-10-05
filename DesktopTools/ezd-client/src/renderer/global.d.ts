/// <reference types="vite/client" />
import type { Api } from '@shared/contracts';
declare global { interface Window { api: Api } }
export {};
