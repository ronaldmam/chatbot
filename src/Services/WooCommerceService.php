<?php
// src/Services/WooCommerceService.php

namespace App\Services;

class WooCommerceService
{
    private string $storeUrl;
    private string $consumerKey;
    private string $consumerSecret;

    public function __construct()
    {
        $this->storeUrl = defined('STORE_URL') ? STORE_URL : 'https://naldike.com';
        
        // Credentials can be defined in config.php for production or filled through dashboard setup
        $this->consumerKey = defined('WC_CONSUMER_KEY') ? WC_CONSUMER_KEY : '';
        $this->consumerSecret = defined('WC_CONSUMER_SECRET') ? WC_CONSUMER_SECRET : '';
    }

    /**
     * Look up inventory details or query store products
     * 
     * Uses WooCommerce API if credentials are present, else falls back to local HTML crawler scraper.
     * 
     * @param string $keyword Search term
     * @return string Structured plain text of product context for RAG model injection
     */
    public function searchProducts(string $keyword): string
    {
        if (!empty($this->consumerKey) && !empty($this->consumerSecret)) {
            return $this->searchProductsViaApi($keyword);
        } else {
            return $this->searchProductsViaScraper($keyword);
        }
    }

    /**
     * Query WooCommerce REST API endpoints for product stock and price
     */
    private function searchProductsViaApi(string $keyword): string
    {
        $endpoint = rtrim($this->storeUrl, '/') . '/wp-json/wc/v3/products';
        $url = $endpoint . '?search=' . urlencode($keyword) . '&per_page=5';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->consumerKey . ":" . $this->consumerSecret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return "No se pudo conectar a la base de datos de productos.";
        }

        $products = json_decode($response, true);
        if (empty($products) || isset($products['code'])) {
            return "No se encontraron productos coincidentes en el inventario.";
        }

        $contextString = "Productos e inventario en tiempo real (vía WooCommerce API):\n";
        foreach ($products as $p) {
            $stock = ($p['manage_stock'] ?? false) 
                ? ($p['stock_quantity'] ?? 0) . " unidades"
                : (($p['stock_status'] ?? '') === 'instock' ? 'Disponible' : 'Agotado');
                
            $price = isset($p['price']) && $p['price'] !== '' ? 'S/. ' . $p['price'] : 'Consultar';
            $image = !empty($p['images']) ? $p['images'][0]['src'] : '';
            $link = $p['permalink'] ?? '';

            $contextString .= "- Nombre: {$p['name']}\n";
            $contextString .= "  Precio: {$price}\n";
            $contextString .= "  Stock: {$stock}\n";
            if ($image) $contextString .= "  Imagen: {$image}\n";
            if ($link) $contextString .= "  Link: {$link}\n\n";
        }

        return $contextString;
    }

    /**
     * WooCommerce Theme-Compatible Scraper Fallback
     */
    private function searchProductsViaScraper(string $keyword): string
    {
        $url = rtrim($this->storeUrl, '/') . '/?s=' . urlencode($keyword) . '&post_type=product';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            return "No se pudo conectar al catálogo de la tienda.";
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $products = [];
        // WooCommerce default loop elements
        $nodes = $xpath->query("//li[contains(@class, 'product')] | //div[contains(@class, 'product-type-simple')]");

        $count = 0;
        foreach ($nodes as $node) {
            if ($count >= 5) {
                break;
            }

            $titleNode = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $node)->item(0);
            $priceNode = $xpath->query(".//span[contains(@class, 'price')]", $node)->item(0);
            $imageNode = $xpath->query(".//img", $node)->item(0);
            $linkNode = $xpath->query(".//a[contains(@class, 'woocommerce-LoopProduct-link')]", $node)->item(0);

            $name = $titleNode ? trim($titleNode->textContent) : 'Producto sin nombre';
            $price = $priceNode ? trim($priceNode->textContent) : 'Precio no disponible';
            $image = $imageNode ? $imageNode->getAttribute('src') : '';
            $link = $linkNode ? $linkNode->getAttribute('href') : '';
            $stockStatus = "Disponible";

            if (strpos($node->getAttribute('class'), 'outofstock') !== false) {
                $stockStatus = "Agotado";
            }

            $products[] = [
                'name' => $name,
                'price' => $price,
                'stock' => $stockStatus,
                'image' => $image,
                'link' => $link
            ];
            $count++;
        }

        if (empty($products)) {
            return "No encontré productos relacionados con tu búsqueda en la tienda.";
        }

        $contextString = "Productos en inventario (vía Scraping Web):\n";
        foreach ($products as $p) {
            $contextString .= "- Nombre: {$p['name']}\n";
            $contextString .= "  Precio: {$p['price']}\n";
            $contextString .= "  Estado: {$p['stock']}\n";
            if (!empty($p['image'])) $contextString .= "  Imagen: {$p['image']}\n";
            if (!empty($p['link'])) $contextString .= "  Link: {$p['link']}\n\n";
        }

        return $contextString;
    }
}
