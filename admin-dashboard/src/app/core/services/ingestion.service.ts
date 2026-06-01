import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface IngestedItem {
  id: number;
  type: 'pdf' | 'url' | 'woocommerce';
  title: string;
  source_url: string;
  created_at: string;
}

@Injectable({
  providedIn: 'root'
})
export class IngestionService {
  private http = inject(HttpClient);
  
  private apiBase = location.hostname === 'localhost' || location.hostname === '127.0.0.1' 
    ? 'http://localhost:8000' 
    : '';

  /**
   * Fetch list of all ingested knowledge items in the RAG model
   */
  public getIngestedItems(): Observable<IngestedItem[]> {
    return this.http.get<IngestedItem[]>(`${this.apiBase}/api/ingest`);
  }

  /**
   * Ingest page text contents from web scraper crawler
   */
  public ingestUrl(url: string): Observable<any> {
    return this.http.post<any>(`${this.apiBase}/api/ingest/url`, { url });
  }

  /**
   * Upload binary PDF manual and extract pure plain text content
   */
  public ingestPdf(formData: FormData): Observable<any> {
    return this.http.post<any>(`${this.apiBase}/api/ingest/pdf`, formData);
  }

  /**
   * Force pull active stocks & pricing from WooCommerce REST API sync
   */
  public syncWooCommerce(): Observable<any> {
    return this.http.post<any>(`${this.apiBase}/api/ingest/woocommerce`, {});
  }

  /**
   * Remove a knowledge base item by its ID
   */
  public deleteItem(id: number): Observable<any> {
    return this.http.delete<any>(`${this.apiBase}/api/ingest/${id}`);
  }

  /**
   * Fetch Options Bot greeting and responses settings templates
   */
  public getBotSettings(): Observable<any> {
    return this.http.get<any>(`${this.apiBase}/api/settings/bot`);
  }

  /**
   * Save updated Options Bot greetings and responses templates
   */
  public saveBotSettings(settings: { welcome_message: string, option_1_response: string, option_2_response: string, global_ai_instructions?: string }): Observable<any> {
    return this.http.post<any>(`${this.apiBase}/api/settings/bot`, settings);
  }
}
