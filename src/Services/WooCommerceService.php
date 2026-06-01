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
     * Clean and normalize Marketplace reference titles by stripping common Facebook prefixes,
     * brand tags, item codes, and prices, while preserving secondary specifications (like pipe separator).
     *
     * @param string $ref Raw reference title
     * @return string Cleaned product query string
     */
    public static function cleanMarketplaceTitle(string $ref): string
    {
        if (empty($ref)) {
            return '';
        }

        $keyword = $ref;

        // 1. Remove emojis and non-printable characters
        $keyword = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F1E6}-\x{1F1FF}]/u', '', $keyword);

        // 2. Remove common Facebook Messenger / Marketplace banner prefixes
        $keyword = preg_replace('/Artículo:\s*/iu', '', $keyword);
        $keyword = preg_replace('/Conversación con el título\s+/iu', '', $keyword);
        $keyword = preg_replace('/Conversación sobre el artículo en venta\s+/iu', '', $keyword);
        $keyword = preg_replace('/Conversación sobre\s+/iu', '', $keyword);
        $keyword = preg_replace('/Artículo en venta:?\s+/iu', '', $keyword);
        $keyword = preg_replace('/Conversación con\s+/iu', '', $keyword);

        // 3. Remove brand name prefixes if present
        $keyword = preg_replace('/^Naldike\s*·?\s*/iu', '', $keyword);
        $keyword = preg_replace('/^Naldike\s*-?\s*/iu', '', $keyword);

        // 4. Remove prices and their trailing hyphens/spaces (e.g. S/ 25 -, PEN25 -)
        $keyword = preg_replace('/S\/\.?\s*\d+[\.,]?\d*\s*[-–—]?\s*/i', '', $keyword);
        $keyword = preg_replace('/PEN\s*\d+[\.,]?\d*\s*[-–—]?\s*/i', '', $keyword);
        $keyword = preg_replace('/\$\s*\d+[\.,]?\d*\s*[-–—]?\s*/i', '', $keyword);

        // 5. Remove item codes (e.g. AA1234, BB-5678)
        $keyword = preg_replace('/\b[A-Z]{2,4}-?\d{2,}\b/i', '', $keyword);

        // 6. Clean up whitespace and trim
        $keyword = trim(preg_replace('/\s+/', ' ', $keyword));

        return $keyword;
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
        if (empty(trim($keyword))) {
            return "No se encontraron productos coincidentes en el inventario.";
        }

        $res = '';
        if (!empty($this->consumerKey) && !empty($this->consumerSecret)) {
            $res = $this->searchProductsViaApi($keyword);
        } else {
            $res = $this->searchProductsViaScraper($keyword);
        }

        // If the primary search yields no products, and there is a separator (like '|' or ' - '),
        // do a fallback search with the portion before the separator!
        if ((stripos($res, 'No se encontraron') !== false || stripos($res, 'No se pudo') !== false || empty(trim($res))) 
            && (strpos($keyword, '|') !== false || strpos($keyword, ' - ') !== false)) {
            
            $fallbackKeyword = $keyword;
            if (strpos($keyword, '|') !== false) {
                $parts = explode('|', $keyword);
                $fallbackKeyword = trim($parts[0]);
            } else if (strpos($keyword, ' - ') !== false) {
                $parts = explode(' - ', $keyword);
                $fallbackKeyword = trim($parts[0]);
            }

            if (!empty($fallbackKeyword) && $fallbackKeyword !== $keyword) {
                if (!empty($this->consumerKey) && !empty($this->consumerSecret)) {
                    $res = $this->searchProductsViaApi($fallbackKeyword);
                } else {
                    $res = $this->searchProductsViaScraper($fallbackKeyword);
                }
            }
        }

        return $res;
    }

    /**
     * Find the best matching product and return its structured data (name, link, price).
     * Used by the follow-up timer to send the real product link to the customer.
     *
     * @param string $keyword Search term extracted from the Marketplace reference
     * @return array|null ['name'=>string, 'link'=>string, 'price'=>string] or null if not found
     */
    public function getProductLink(string $keyword): ?array
    {
        if (empty(trim($keyword))) return null;

        $product = $this->executeGetProductLink($keyword);

        // Fallback if not found and contains pipe or hyphen
        if (empty($product) && (strpos($keyword, '|') !== false || strpos($keyword, ' - ') !== false)) {
            $fallbackKeyword = $keyword;
            if (strpos($keyword, '|') !== false) {
                $parts = explode('|', $keyword);
                $fallbackKeyword = trim($parts[0]);
            } else if (strpos($keyword, ' - ') !== false) {
                $parts = explode(' - ', $keyword);
                $fallbackKeyword = trim($parts[0]);
            }

            if (!empty($fallbackKeyword) && $fallbackKeyword !== $keyword) {
                $product = $this->executeGetProductLink($fallbackKeyword);
            }
        }

        return $product;
    }

    /**
     * Internal helper to execute the WooCommerce API or Scraper getProductLink search
     */
    private function executeGetProductLink(string $keyword): ?array
    {
        if (!empty($this->consumerKey) && !empty($this->consumerSecret)) {
            // WooCommerce REST API path
            $endpoint = rtrim($this->storeUrl, '/') . '/wp-json/wc/v3/products';
            $url = $endpoint . '?search=' . urlencode($keyword) . '&per_page=1&status=publish';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->consumerKey . ':' . $this->consumerSecret);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);

            if ($response) {
                $products = json_decode($response, true);
                if (!empty($products) && is_array($products) && !isset($products['code'])) {
                    $p = $products[0];
                    return [
                        'name'  => $p['name'] ?? '',
                        'link'  => $p['permalink'] ?? '',
                        'price' => isset($p['price']) && $p['price'] !== '' ? 'S/. ' . $p['price'] : 'Consultar'
                    ];
                }
            }
        } else {
            // Scraper fallback path
            $url = rtrim($this->storeUrl, '/') . '/?s=' . urlencode($keyword) . '&post_type=product';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; NaldikeBot/1.0)');
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $html = curl_exec($ch);
            curl_close($ch);

            if ($html) {
                $dom = new \DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
                $xpath = new \DOMXPath($dom);

                $titleNode = $xpath->query("//li[contains(@class,'product')]//h2[contains(@class,'woocommerce-loop-product__title')]")->item(0);
                $priceNode = $xpath->query("//li[contains(@class,'product')]//span[contains(@class,'price')]")->item(0);
                $linkNode  = $xpath->query("//li[contains(@class,'product')]//a[contains(@class,'woocommerce-LoopProduct-link')]")->item(0);

                if ($linkNode) {
                    return [
                        'name'  => $titleNode ? trim($titleNode->textContent) : $keyword,
                        'link'  => $linkNode->getAttribute('href'),
                        'price' => $priceNode ? trim($priceNode->textContent) : 'Consultar'
                    ];
                }
            }
        }

        return null; // product not found in any source
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
