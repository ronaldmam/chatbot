import { Component, inject, OnInit, OnDestroy, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ChatService, Conversation, ChatMessage } from '../../core/services/chat.service';
import { interval, Subscription, startWith, switchMap, catchError, of } from 'rxjs';

@Component({
  selector: 'app-chats',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './chats.component.html',
  styleUrls: ['./chats.component.css']
})
export class ChatsComponent implements OnInit, OnDestroy {
  chatService = inject(ChatService);
  cdr = inject(ChangeDetectorRef);

  conversations: Conversation[] = [];
  selectedConversation: Conversation | null = null;
  messages: ChatMessage[] = [];
  
  isLoading = true;
  pollSub!: Subscription;
  newMessageText = '';
  isSending = false;

  ngOnInit(): void {
    // Start reactive 5-second polling using RxJS interval/switchMap
    this.pollSub = interval(5000)
      .pipe(
        startWith(0),
        switchMap(() => 
          this.chatService.getConversations().pipe(
            catchError((err) => {
              console.error('Error polling conversations:', err);
              // Gracefully return existing conversations on error to prevent interval death
              return of(this.conversations);
            })
          )
        )
      )
      .subscribe({
        next: (data) => {
          this.conversations = data;
          this.isLoading = false;
          this.cdr.detectChanges(); // Force UI update for the sidebar list
          
          // Refresh active conversation message history silently
          if (this.selectedConversation) {
            const current = this.conversations.find(c => Number(c.id) === Number(this.selectedConversation!.id));
            if (current) {
              current.unread_count = 0; // Force 0 on the active conversation to avoid race conditions
              this.selectedConversation = current;
              this.loadMessages(current.id);
            }
          }
        },
        error: (err) => {
          console.error('Fatal polling subscription error:', err);
          this.mockData();
          this.isLoading = false;
          this.cdr.detectChanges();
        }
      });
  }

  ngOnDestroy(): void {
    if (this.pollSub) {
      this.pollSub.unsubscribe();
    }
  }

  /**
   * Handle sidebar selection clicks
   */
  selectConversation(conv: Conversation): void {
    console.log('selectConversation clicked:', conv);
    this.selectedConversation = conv;
    conv.unread_count = 0; // Instantly clear in the UI for smooth agent UX
    this.messages = []; // Clear current messages first for smooth transition
    this.cdr.detectChanges(); // Force immediate rendering of active client and clear messages viewport
    this.loadMessages(conv.id);
  }

  /**
   * Fetch thread messages for active view
   */
  loadMessages(id: number): void {
    console.log('loadMessages called for ID:', id);
    this.chatService.getMessages(id).subscribe({
      next: (data) => {
        console.log('loadMessages API success. Count:', data.length, 'Messages:', data);
        const hasNewMessages = data.length > this.messages.length;
        this.messages = data;
        this.cdr.detectChanges(); // Force rendering of new messages immediately
        if (hasNewMessages) {
          setTimeout(() => {
            this.scrollToBottom();
            this.cdr.detectChanges(); // Force scroll adjustment render
          }, 50);
        }
      },
      error: (err) => {
        console.error('loadMessages API error:', err);
        if (this.selectedConversation) {
          this.messages = this.selectedConversation.messages || [];
          this.cdr.detectChanges(); // Force rendering fallback
        }
      }
    });
  }

  /**
   * Force update conversation flow state from dropdown
   */
  changeFlowState(state: 'bot' | 'ia' | 'human'): void {
    if (!this.selectedConversation) return;

    this.chatService.updateFlowState(this.selectedConversation.id, state).subscribe({
      next: () => {
        this.selectedConversation!.flow_state = state;
      },
      error: () => {
        this.selectedConversation!.flow_state = state;
      }
    });
  }

  /**
   * Save custom instructions/context for the active conversation
   */
  saveInstructions(instructions: string): void {
    if (!this.selectedConversation) return;

    this.chatService.updateInstructions(this.selectedConversation.id, instructions).subscribe({
      next: (res) => {
        this.selectedConversation!.custom_instructions = instructions;
        alert('Contexto e instrucciones guardadas exitosamente.');
      },
      error: (err) => {
        alert('Error al guardar instrucciones: ' + (err.error?.message || 'Error de red.'));
      }
    });
  }

  /**
   * Load mock data for local testing
   */
  mockData(): void {
    if (this.conversations.length > 0) return;

    this.conversations = [
      {
        id: 101,
        customer_id: 1,
        customer_name: 'Juan Pérez',
        platform: 'whatsapp',
        psid: '+51939021800',
        flow_state: 'ia',
        created_at: '2026-05-22 18:30:00',
        messages: [
          { id: 1, conversation_id: 101, sender: 'customer', message_text: 'Hola, buenas tardes!', created_at: '18:30' },
          { id: 2, conversation_id: 101, sender: 'bot', message_text: '¡Hola! Bienvenido a Naldike Store 🛍️. Elige una opción escribiendo el número:\n\n1️⃣ Consultar Catálogo\n2️⃣ Ver Estado de Pedido\n3️⃣ Hablar con el Asistente IA', created_at: '18:30' },
          { id: 3, conversation_id: 101, sender: 'customer', message_text: '3', created_at: '18:31' },
          { id: 4, conversation_id: 101, sender: 'bot', message_text: 'Asistente IA Activado 🤖. ¿En qué producto estás interesado hoy?', created_at: '18:31' },
          { id: 5, conversation_id: 101, sender: 'customer', message_text: '¿Tienen linternas recargables en stock?', created_at: '18:32' },
          { id: 6, conversation_id: 101, sender: 'bot', message_text: 'Sí, contamos con la Linterna Táctica Recargable LED de alta potencia. Tiene un costo de S/. 45 y está disponible para entrega inmediata. ¿Te gustaría ordenar una?', created_at: '18:32' }
        ]
      },
      {
        id: 102,
        customer_id: 2,
        customer_name: 'María Gómez',
        platform: 'messenger',
        psid: 'fb_maria_8849',
        flow_state: 'human',
        created_at: '2026-05-22 19:15:00',
        messages: [
          { id: 7, conversation_id: 102, sender: 'customer', message_text: 'Quiero hacer un reclamo por mi pago.', created_at: '19:15' },
          { id: 8, conversation_id: 102, sender: 'bot', message_text: 'Entiendo tu malestar perfectamente. He derivado este chat a un asesor de soporte humano de inmediato para resolver tu reclamo.', created_at: '19:16' }
        ]
      },
      {
        id: 103,
        customer_id: 3,
        customer_name: 'Carlos Soto',
        platform: 'tiktok',
        psid: 'tk_carlos_soto',
        flow_state: 'bot',
        created_at: '2026-05-22 20:00:00',
        messages: [
          { id: 9, conversation_id: 103, sender: 'customer', message_text: 'Hola, hacen envíos a provincia?', created_at: '20:00' }
        ]
      }
    ];
  }

  /**
   * Send a personalized message from the dashboard.
   * If automation is active, it automatically takes control (switches state to human).
   */
  sendMessage(): void {
    if (!this.selectedConversation || !this.newMessageText.trim()) return;

    const text = this.newMessageText.trim();
    this.newMessageText = '';

    if (this.selectedConversation.flow_state !== 'human') {
      this.isSending = true;
      this.chatService.updateFlowState(this.selectedConversation.id, 'human').subscribe({
        next: () => {
          this.selectedConversation!.flow_state = 'human';
          this.performSendMessage(text);
        },
        error: (err) => {
          this.isSending = false;
          alert('Error al tomar control del chat: ' + (err.error?.message || 'Servidor desconectado.'));
        }
      });
    } else {
      this.performSendMessage(text);
    }
  }

  private performSendMessage(text: string): void {
    this.isSending = true;
    this.chatService.sendMessage(this.selectedConversation!.id, text).subscribe({
      next: (msg) => {
        this.messages.push(msg);
        this.isSending = false;
        setTimeout(() => this.scrollToBottom(), 50);
      },
      error: (err) => {
        this.isSending = false;
        alert(err.error?.message || 'Error al enviar el mensaje.');
      }
    });
  }

  private scrollToBottom(): void {
    const history = document.querySelector('.messages-history');
    if (history) {
      history.scrollTop = history.scrollHeight;
    }
  }
}
