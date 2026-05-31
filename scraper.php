<?php
// scraper.php
function extractKeywords($text) {
    if (empty($text)) return '';
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text); // Remove punctuation
    
    $stopwords = ['hola', 'que', 'tal', 'tienes', 'tienen', 'busco', 'necesito', 'quiero', 'precio', 'de', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'para', 'con', 'en', 'por', 'favor', 'como', 'cual', 'cuantos', 'cuanto', 'cuesta', 'hay', 'alguna', 'algun', 'algún'];
    
    $words = explode(' ', $text);
    $keywords = array_filter($words, function($word) use ($stopwords) {
        $word = trim($word);
        return !empty($word) && !in_array($word, $stopwords);
    });
    
    return implode(' ', $keywords);
}

function scrapeProducts($query)
{
    // Simple scraping logic. 
    // In a real scenario, we might search specifically based on the query.
    // For now, we'll scrape the main page or a search result page.

    // Extract keywords to improve search results
    $cleanQuery = extractKeywords($query);

    // Construct search URL if query is provided
    $url = 'https://naldike.com/';
    if (!empty($cleanQuery)) {
        $url = 'https://naldike.com/?s=' . urlencode($cleanQuery) . '&post_type=product';
    } else if (!empty($query)) {
        // Fallback in case everything was filtered out
        $url = 'https://naldike.com/?s=' . urlencode($query) . '&post_type=product';
    }

    // Fetch HTML
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) {
        return "No se pudo acceder a la tienda en este momento.";
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Select products (WooCommerce structure usually has li.product or div.product)
    // Adjust selectors based on typical WooCommerce themes or Naldike's specific structure
    $products = [];
    $nodes = $xpath->query("//li[contains(@class, 'product')] | //div[contains(@class, 'product-type-simple')]");

    $count = 0;
    foreach ($nodes as $node) {
        if ($count >= 5)
            break; // Limit to 5 products to avoid context overflow

        $titleNode = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $node)->item(0);
        $priceNode = $xpath->query(".//span[contains(@class, 'price')]", $node)->item(0);
        $imageNode = $xpath->query(".//img", $node)->item(0);
        $linkNode = $xpath->query(".//a[contains(@class, 'woocommerce-LoopProduct-link')]", $node)->item(0);

        $name = $titleNode ? trim($titleNode->textContent) : 'Producto sin nombre';
        $price = $priceNode ? trim($priceNode->textContent) : 'Precio no disponible';
        $image = $imageNode ? $imageNode->getAttribute('src') : '';
        $link = $linkNode ? $linkNode->getAttribute('href') : '';

        // Check stock (sometimes indicated by class or text, hard to get accurate stock without API, but we can check for 'Out of stock' badges)
        $outOfStock = $xpath->query(".//span[contains(@class, 'onsale')]", $node); // Just an example, often 'out-of-stock' class on li
        $stockStatus = "Disponible"; // Default assumption

        // Refined stock check
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

    // Format for AI context
    $contextString = "Información de productos encontrados en Naldike.com:\n";
    foreach ($products as $p) {
        $contextString .= "- Nombre: {$p['name']}\n";
        $contextString .= "  Precio: {$p['price']}\n";
        $contextString .= "  Estado: {$p['stock']}\n";
        $contextString .= "  Imagen: {$p['image']}\n"; // AI can use this to provide [IMAGE: url]
        $contextString .= "  Link: {$p['link']}\n\n";
    }

    return $contextString;
}
