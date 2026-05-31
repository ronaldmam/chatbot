<?php
// src/Services/WebScraperService.php

namespace App\Services;

class WebScraperService
{
    /**
     * Fetch HTML from URL and parse it into plain text for RAG ingestion
     * 
     * @param string $url The webpage URL to scrape
     * @return string Extracted and cleaned plain text content
     */
    public function scrapeUrl(string $url): string
    {
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
            return '';
        }

        return $this->cleanHtml($html);
    }

    /**
     * Strip boilerplate elements (scripts, styles, navigations) and extra whitespace
     */
    private function cleanHtml(string $html): string
    {
        $dom = new \DOMDocument();
        
        // Load with UTF-8 encoding support
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        $xpath = new \DOMXPath($dom);
        
        // Query elements that do not contain useful business information
        $garbage = $xpath->query('//script | //style | //noscript | //header | //footer | //nav | //iframe');
        foreach ($garbage as $node) {
            $node->parentNode->removeChild($node);
        }

        $text = $dom->textContent;
        
        // Clean multi-spaces, tabs, and newlines
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
