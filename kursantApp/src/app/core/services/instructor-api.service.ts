import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { ApiResponse, InstructorDashboard } from '../models/kursant.models';

const API = '/api/v1/dydaktyk_instructor.php';

@Injectable({ providedIn: 'root' })
export class InstructorApiService {
  private http = inject(HttpClient);

  private get<T>(action: string, params: Record<string, string> = {}): Observable<ApiResponse<T>> {
    let p = new HttpParams().set('action', action);
    for (const [k, v] of Object.entries(params)) p = p.set(k, v);
    return this.http.get<ApiResponse<T>>(API, { params: p });
  }

  getDashboard() { return this.get<InstructorDashboard>('dashboard'); }
}
