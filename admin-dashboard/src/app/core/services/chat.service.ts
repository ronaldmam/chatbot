import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface ChatMessage {
  id: number;
  conversation_id: number;
  sender: 'customer' | 'bot' | 'agent';
  message_text: string;
  created_at: string;
}

export interface Conversation {
  id: number;
  customer_id: number;
  customer_name: string;
  platform: string;
  psid: string;
  flow_state: 'bot' | 'ia' | 'human';
  unread_count?: number;
  is_marketplace?: number;
  marketplace_ref?: string;
  created_at: string;
  messages?: ChatMessage[];
}

@Injectable({
  providedIn: 'root'
})
export class ChatService {
  private http = inject(HttpClient);
  private apiBase = location.hostname === 'localhost' || location.hostname === '127.0.0.1' 
    ? 'http://localhost:8000' 
    : '';

  /**
   * Fetch list of active and archived platform conversations
   */
  public getConversations(): Observable<Conversation[]> {
    return this.http.get<Conversation[]>(`${this.apiBase}/api/chats`);
  }

  /**
   * Fetch thread messages for a specific conversation
   */
  public getMessages(conversationId: number): Observable<ChatMessage[]> {
    return this.http.get<ChatMessage[]>(`${this.apiBase}/api/chats/${conversationId}`);
  }

  /**
   * Force manual override of conversational state flow (e.g. transfer to bot/human manually)
   */
  public updateFlowState(conversationId: number, flowState: 'bot' | 'ia' | 'human'): Observable<any> {
    return this.http.put<any>(`${this.apiBase}/api/chats/${conversationId}/state`, { flow_state: flowState });
  }

  /**
   * Send a personalized agent response message
   */
  public sendMessage(conversationId: number, messageText: string): Observable<ChatMessage> {
    return this.http.post<ChatMessage>(`${this.apiBase}/api/chats/${conversationId}/message`, { message_text: messageText });
  }

  /**
   * Fetch bot metrics (total chats, containment rate, human handovers)
   */
  public getStats(): Observable<any> {
    return this.http.get<any>(`${this.apiBase}/api/chats/stats`);
  }
}
