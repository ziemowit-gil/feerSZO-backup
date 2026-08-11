import { Injectable, inject, signal, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../auth/auth.service';

const API = '/api/v1/kursant_student.php';

function urlBase64ToUint8Array(b64: string): Uint8Array {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

@Injectable({ providedIn: 'root' })
export class PushService {
  private http       = inject(HttpClient);
  private auth       = inject(AuthService);
  private platformId = inject(PLATFORM_ID);

  get isSupported(): boolean {
    return isPlatformBrowser(this.platformId)
      && 'serviceWorker' in navigator
      && 'PushManager' in window
      && 'Notification' in window;
  }

  enabled    = signal(false);
  loading    = signal(false);
  permDenied = signal(false);

  async init(): Promise<void> {
    if (!this.isSupported) return;
    this.enabled.set(!!this.auth.student()?.push_enabled);
    if (Notification.permission === 'denied') this.permDenied.set(true);
    try {
      await navigator.serviceWorker.register('push-sw.js');
    } catch (e) {
      console.warn('[push] SW registration failed', e);
    }
  }

  async enable(): Promise<void> {
    if (!this.isSupported || this.loading()) return;
    this.loading.set(true);
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        this.permDenied.set(perm === 'denied');
        throw new Error('Permission not granted');
      }
      this.permDenied.set(false);

      const keyRes = await firstValueFrom(
        this.http.get<{ success: boolean; data: { vapid_public_key: string } }>(
          `${API}?action=push_vapid_key`
        )
      );
      if (!keyRes.success) throw new Error('No VAPID key');

      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(keyRes.data.vapid_public_key),
      });

      await firstValueFrom(
        this.http.post<{ success: boolean }>(`${API}?action=push_subscribe`, {
          subscription: sub.toJSON(),
        })
      );

      const s = this.auth.student();
      if (s) this.auth.updateStudent({ ...s, push_enabled: 1 });
      this.enabled.set(true);
    } finally {
      this.loading.set(false);
    }
  }

  async disable(): Promise<void> {
    if (!this.isSupported || this.loading()) return;
    this.loading.set(true);
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (sub) await sub.unsubscribe();

      await firstValueFrom(
        this.http.post<{ success: boolean }>(`${API}?action=push_unsubscribe`, {})
      );

      const s = this.auth.student();
      if (s) this.auth.updateStudent({ ...s, push_enabled: 0 });
      this.enabled.set(false);
    } finally {
      this.loading.set(false);
    }
  }
}
