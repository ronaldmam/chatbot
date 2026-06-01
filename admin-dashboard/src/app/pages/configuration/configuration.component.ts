import { Component, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IngestionService, IngestedItem } from '../../core/services/ingestion.service';

@Component({
  selector: 'app-configuration',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './configuration.component.html',
  styleUrls: ['./configuration.component.css']
})
export class ConfigurationComponent implements OnInit {
  ingestionService = inject(IngestionService);

  ingestedItems: IngestedItem[] = [];
  urlToIngest = '';
  selectedFile: File | null = null;
  
  isIngestingUrl = false;
  isIngestingPdf = false;
  isSyncingWc = false;
  isLoadingItems = true;

  welcomeMessage = '';
  option1Response = '';
  option2Response = '';
  globalAiInstructions = '';
  isSavingBot = false;

  successMessage = '';
  errorMessage = '';

  ngOnInit(): void {
    this.loadIngestedItems();
    this.loadBotSettings();
  }

  /**
   * Fetch ingested RAG index items with dynamic local mock fallback
   */
  loadIngestedItems(): void {
    this.ingestionService.getIngestedItems().subscribe({
      next: (data) => {
        this.ingestedItems = data;
        this.isLoadingItems = false;
      },
      error: () => {
        this.mockItems();
        this.isLoadingItems = false;
      }
    });
  }

  /**
   * Crawl a target URL and index text contents
   */
  ingestUrl(): void {
    if (!this.urlToIngest) return;

    this.isIngestingUrl = true;
    this.clearMessages();

    this.ingestionService.ingestUrl(this.urlToIngest).subscribe({
      next: () => {
        this.isIngestingUrl = false;
        this.urlToIngest = '';
        this.showSuccess('Sitio web rastreado e indexado con éxito.');
        this.loadIngestedItems();
      },
      error: (err) => {
        this.isIngestingUrl = false;
        this.showError(err.error?.message || 'Error al procesar el rastreo de la URL.');
      }
    });
  }

  /**
   * Handle file selector selections
   */
  onFileSelected(event: any): void {
    const file = event.target.files[0];
    if (file) {
      this.selectedFile = file;
    }
  }

  /**
   * Upload binary PDF and parse details to database
   */
  ingestPdf(): void {
    if (!this.selectedFile) return;

    this.isIngestingPdf = true;
    this.clearMessages();

    const formData = new FormData();
    formData.append('pdf', this.selectedFile);

    this.ingestionService.ingestPdf(formData).subscribe({
      next: () => {
        this.isIngestingPdf = false;
        this.selectedFile = null;
        this.showSuccess('Manual PDF procesado e ingerido en la Base de Conocimiento.');
        this.loadIngestedItems();
      },
      error: (err) => {
        this.isIngestingPdf = false;
        this.showError(err.error?.message || 'Error al subir o analizar el archivo PDF.');
      }
    });
  }

  /**
   * Pull active stocks and pricing from WooCommerce API
   */
  syncWooCommerce(): void {
    this.isSyncingWc = true;
    this.clearMessages();

    this.ingestionService.syncWooCommerce().subscribe({
      next: () => {
        this.isSyncingWc = false;
        this.showSuccess('Inventario y stock sincronizados de manera exitosa.');
        this.loadIngestedItems();
      },
      error: (err) => {
        this.isSyncingWc = false;
        this.showError(err.error?.message || 'Fallo de autenticación o timeout con la WooCommerce REST API.');
      }
    });
  }

  /**
   * Delete an item from the knowledge index
   */
  deleteItem(id: number): void {
    if (!confirm('¿Estás seguro de que deseas eliminar este recurso? La IA de Naldike Store ya no lo utilizará como contexto para las respuestas.')) return;

    this.ingestionService.deleteItem(id).subscribe({
      next: () => {
        this.showSuccess('Recurso de conocimiento eliminado.');
        this.loadIngestedItems();
      },
      error: () => {
        // Mock fallback delete
        this.ingestedItems = this.ingestedItems.filter(item => item.id !== id);
        this.showSuccess('Recurso de conocimiento eliminado (Simulado).');
      }
    });
  }

  private clearMessages(): void {
    this.successMessage = '';
    this.errorMessage = '';
  }

  private showSuccess(msg: string): void {
    this.successMessage = msg;
    setTimeout(() => this.successMessage = '', 5000);
  }

  private showError(msg: string): void {
    this.errorMessage = msg;
    setTimeout(() => this.errorMessage = '', 5000);
  }

  /**
   * Mock items for local testing
   */
  mockItems(): void {
    if (this.ingestedItems.length > 0) return;
    this.ingestedItems = [
      { id: 1, type: 'pdf', title: 'Manual de Garantías Naldike.pdf', source_url: '/uploads/manual_garantias.pdf', created_at: '2026-05-22' },
      { id: 2, type: 'url', title: 'Políticas de Envío y Delivery', source_url: 'https://naldike.com/politicas-envio', created_at: '2026-05-22' },
      { id: 3, type: 'woocommerce', title: 'Sincronización WooCommerce REST API', source_url: 'https://naldike.com/wp-json', created_at: '2026-05-22' }
    ];
  }

  /**
   * Load active welcome and option reply settings from backend settings API
   */
  loadBotSettings(): void {
    this.ingestionService.getBotSettings().subscribe({
      next: (data) => {
        this.welcomeMessage = data.welcome_message;
        this.option1Response = data.option_1_response;
        this.option2Response = data.option_2_response;
        this.globalAiInstructions = data.global_ai_instructions || '';
      },
      error: (err) => {
        console.error('Failed to load bot settings:', err);
      }
    });
  }

  /**
   * Submit and persist modified welcome and option template configurations
   */
  saveBotSettings(): void {
    if (!this.welcomeMessage.trim() || !this.option1Response.trim() || !this.option2Response.trim()) {
      this.showError('Por favor, completa todos los campos del bot.');
      return;
    }

    this.isSavingBot = true;
    this.clearMessages();

    const payload = {
      welcome_message: this.welcomeMessage,
      option_1_response: this.option1Response,
      option_2_response: this.option2Response,
      global_ai_instructions: this.globalAiInstructions
    };

    this.ingestionService.saveBotSettings(payload).subscribe({
      next: () => {
        this.isSavingBot = false;
        this.showSuccess('Configuración de respuestas del bot actualizada con éxito.');
      },
      error: (err) => {
        this.isSavingBot = false;
        this.showError(err.error?.message || 'Error al guardar la configuración del bot.');
      }
    });
  }
}
