<?php
// src/Controllers/IngestionController.php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\KnowledgeBase;
use App\Repositories\KnowledgeBaseRepository;
use App\Services\WebScraperService;
use App\Services\WooCommerceService;
use Smalot\PdfParser\Parser as PdfParser;

class IngestionController
{
    private KnowledgeBaseRepository $kbRepository;
    private WebScraperService $scraperService;
    private WooCommerceService $wooService;

    public function __construct()
    {
        $this->kbRepository = new KnowledgeBaseRepository();
        $this->scraperService = new WebScraperService();
        $this->wooService = new WooCommerceService();
    }

    /**
     * Get all ingested knowledge base resources.
     * Route: GET /api/ingest
     */
    public function getAll(): void
    {
        try {
            $items = $this->kbRepository->findAll();
            $serializedItems = [];
            foreach ($items as $item) {
                $serializedItems[] = [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'source_url' => $item->sourceUrl ?? '',
                    'created_at' => $item->createdAt
                ];
            }
            Response::json($serializedItems);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Scrape a URL and ingest its content as knowledge base.
     * Route: POST /api/ingest/url
     */
    public function ingestUrl(): void
    {
        try {
            $body = Request::getBody();
            $url = $body['url'] ?? '';

            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'A valid URL is required for ingestion.'
                ], 400);
                return;
            }

            // Scrape the content
            $content = $this->scraperService->scrapeUrl($url);

            if (empty($content)) {
                Response::json([
                    'error' => 'Unprocessable Entity',
                    'message' => 'Failed to scrape or extract content from the specified URL.'
                ], 422);
                return;
            }

            // Extract page title or fallback
            $title = parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH);
            $title = rtrim($title, '/');

            $kb = new KnowledgeBase(
                null,
                'url',
                $title,
                $content,
                $url
            );

            $this->kbRepository->save($kb);

            Response::json([
                'message' => 'Webpage successfully scraped and ingested.',
                'item' => [
                    'id' => $kb->id,
                    'type' => $kb->type,
                    'title' => $kb->title,
                    'source_url' => $kb->sourceUrl,
                    'created_at' => $kb->createdAt
                ]
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a PDF, parse its contents, and ingest as knowledge base.
     * Route: POST /api/ingest/pdf
     */
    public function ingestPdf(): void
    {
        try {
            if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'Please upload a valid PDF file.'
                ], 400);
                return;
            }

            $file = $_FILES['pdf'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($fileExtension !== 'pdf') {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'Only PDF documents are supported.'
                ], 400);
                return;
            }

            // Use Smalot PdfParser to extract text contents
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file['tmp_name']);
            $content = $pdf->getText();

            // Clean multiple spaces and whitespace
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);

            if (empty($content)) {
                Response::json([
                    'error' => 'Unprocessable Entity',
                    'message' => 'The uploaded PDF document does not contain any indexable plain text.'
                ], 422);
                return;
            }

            $title = basename($file['name']);
            $kb = new KnowledgeBase(
                null,
                'pdf',
                $title,
                $content,
                '/uploads/' . uniqid() . '_' . $title
            );

            $this->kbRepository->save($kb);

            Response::json([
                'message' => 'PDF document successfully parsed and ingested.',
                'item' => [
                    'id' => $kb->id,
                    'type' => $kb->type,
                    'title' => $kb->title,
                    'source_url' => $kb->sourceUrl,
                    'created_at' => $kb->createdAt
                ]
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force synchronization with WooCommerce catalog.
     * Route: POST /api/ingest/woocommerce
     */
    public function syncWooCommerce(): void
    {
        try {
            // Search all items or common keywords to index catalog
            // Let's index general keywords, e.g., using searchProducts fallback
            $wooService = new WooCommerceService();
            $content = $wooService->searchProducts(''); // Get general product list

            if (empty($content) || strpos($content, 'No se pudo conectar') === 0) {
                Response::json([
                    'error' => 'Service Unavailable',
                    'message' => 'Could not connect to the WooCommerce API or crawler catalog.'
                ], 503);
                return;
            }

            // Delete old WooCommerce ingested sync item if exists
            $existing = $this->kbRepository->findWooCommerceConfig();
            if ($existing) {
                $this->kbRepository->delete($existing->id);
            }

            $kb = new KnowledgeBase(
                null,
                'woocommerce',
                'Sincronización WooCommerce REST API',
                $content,
                defined('STORE_URL') ? STORE_URL : 'https://naldike.com'
            );

            $this->kbRepository->save($kb);

            Response::json([
                'message' => 'WooCommerce product inventory successfully synced and indexed.',
                'item' => [
                    'id' => $kb->id,
                    'type' => $kb->type,
                    'title' => $kb->title,
                    'source_url' => $kb->sourceUrl,
                    'created_at' => $kb->createdAt
                ]
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an ingested resource.
     * Route: DELETE /api/ingest/{id}
     */
    public function deleteItem($id): void
    {
        try {
            $itemId = (int)$id;
            $item = $this->kbRepository->find($itemId);

            if (!$item) {
                Response::json([
                    'error' => 'Not Found',
                    'message' => "Resource with ID $itemId not found."
                ], 404);
                return;
            }

            $this->kbRepository->delete($itemId);

            Response::json([
                'message' => 'Knowledge base resource deleted successfully.'
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
