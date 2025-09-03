<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use Chrome\Chrome;
use Chrome\ChromeOptions;
use Chrome\ChromeProcess;

class BOAService
{
    private string $baseUrl = 'https://cs.bankofabyssinia.com/slip/';

    /**
     * Verify BOA payment transaction
     */
    public function verifyPayment(string $transactionId, string $senderAccountLast5Digits): array
    {
        $extractedData = [
            'transaction_id' => $transactionId,
            'sender_name' => null,
            'sender_bank_name' => 'Bank of Abyssinia',
            'receiver_name' => null,
            'receiver_bank_name' => null,
            'status' => 'UNKNOWN',
            'date' => null,
            'amount' => 0.0,
            'debug_info' => ''
        ];

        try {
            if (strlen($senderAccountLast5Digits) < 5) {
                throw new Exception('Sender account last 5 digits must have at least 5 digits.');
            }

            $fullTrxParam = $transactionId . $senderAccountLast5Digits;
            $receiptUrl = $this->baseUrl . '?trx=' . $fullTrxParam;
            
            // Try alternative URL formats
            $alternativeUrls = [
                $this->baseUrl . '?trx=' . $fullTrxParam,
                $this->baseUrl . '?transaction=' . $fullTrxParam,
                $this->baseUrl . '?ref=' . $fullTrxParam,
                $this->baseUrl . '?id=' . $fullTrxParam,
                $this->baseUrl . 'api/receipt?trx=' . $fullTrxParam,
                $this->baseUrl . 'api/transaction?trx=' . $fullTrxParam,
            ];

            Log::info('Fetching BOA receipt using direct API from: ' . $receiptUrl);

            // Use direct API call instead of web scraping
            $apiData = $this->fetchFromBOAApi($fullTrxParam);
            
            if (!$apiData) {
                throw new Exception('Failed to fetch BOA receipt from API.');
            }

            Log::info('Successfully fetched BOA receipt from API: ' . json_encode($apiData));

            // Parse API response
            $parsedData = $this->parseApiResponse($apiData, $transactionId);

            // Merge extracted data
            $extractedData = array_merge($extractedData, $parsedData);

            // Parse date if present
            if (isset($extractedData['date'])) {
                $extractedData['date'] = $this->parseDate($extractedData['date']);
            }

            // Ensure amount is numeric
            if (isset($extractedData['amount'])) {
                $extractedData['amount'] = floatval($extractedData['amount']);
            }

            Log::info('Successfully processed BOA transaction: ' . $transactionId);

            return $extractedData;

        } catch (Exception $e) {
            Log::error('BOA verification error: ' . $e->getMessage());
            $extractedData['debug_info'] = 'Error: ' . $e->getMessage();
            $extractedData['status'] = 'Failed';
            return $extractedData;
        }
    }

    /**
     * Parse HTML content to extract transaction details
     */
    private function parseHtmlContent(string $htmlContent, string $transactionId): array
    {
        $crawler = new Crawler($htmlContent);

        // Check if we have the receipt page - try multiple selectors
        $receiptHeader = null;
        $selectors = ['h1.text-center', 'h1', '.receipt-header', '.page-title'];
        
        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $receiptHeader = $element->first()->text();
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!$receiptHeader || strpos(strtolower($receiptHeader), 'receipt') === false) {
            $availableHeaders = $crawler->filter('h1, h2, h3')->each(function($node) { return $node->text(); });
            Log::warning('Receipt header not found or invalid. Available headers: ' . implode(', ', $availableHeaders));
            // Don't throw exception, continue with parsing
        }

        // Find the main table - try multiple selectors
        $mainTable = null;
        $tableSelectors = ['table.my-5', 'table', '.receipt-table', '.transaction-details'];
        
        foreach ($tableSelectors as $selector) {
            try {
                $table = $crawler->filter($selector);
                if ($table->count() > 0) {
                    $mainTable = $table->first();
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!$mainTable) {
            // Log available tables for debugging
            $availableTables = $crawler->filter('table')->each(function($node) { 
                return [
                    'class' => $node->attr('class'),
                    'rows' => $node->filter('tr')->count(),
                    'sample_text' => substr($node->text(), 0, 100)
                ];
            });
            Log::warning('No suitable table found. Available tables: ' . json_encode($availableTables));
            throw new Exception('Receipt table not found');
        }

        $extractedData = [
            'sender_name' => null,
            'sender_account' => null,
            'receiver_name' => null,
            'receiver_account' => null,
            'amount' => 0.0,
            'date' => null,
            'status' => 'Completed'
        ];

        // Extract data from table rows
        $mainTable->filter('tr')->each(function (Crawler $row) use (&$extractedData) {
            $cells = $row->filter('td');
            if ($cells->count() >= 2) {
                $label = trim($cells->eq(0)->text());
                $value = trim($cells->eq(1)->text());

                // Normalize label for case-insensitive matching
                $normalizedLabel = strtolower(trim($label));
                
                switch ($normalizedLabel) {
                    case 'source account name':
                    case 'sender name':
                    case 'from':
                        $extractedData['sender_name'] = $value;
                        break;
                    case 'source account number':
                    case 'source account':
                    case 'sender account':
                    case 'from account':
                        $extractedData['sender_account'] = $value;
                        break;
                    case 'receiver\'s name':
                    case 'receiver name':
                    case 'beneficiary name':
                    case 'to':
                        $extractedData['receiver_name'] = $value;
                        break;
                    case 'receiver account number':
                    case 'receiver account':
                    case 'beneficiary account':
                    case 'to account':
                        $extractedData['receiver_account'] = $value;
                        break;
                    case 'transferred amount':
                    case 'amount':
                    case 'transaction amount':
                        $extractedData['amount'] = $this->extractAmount($value);
                        break;
                    case 'transaction date':
                    case 'date':
                    case 'transfer date':
                        $extractedData['date'] = $value;
                        break;
                    case 'transaction reference':
                    case 'reference':
                    case 'transaction id':
                        if (!empty($value)) {
                            $extractedData['transaction_id'] = $value;
                        }
                        break;
                }
                
                // Log what we found for debugging
                Log::info("BOA parsing - Label: '$label' -> Value: '$value'");
            }
        });

        return $extractedData;
    }

    /**
     * Extract amount from string
     */
    private function extractAmount(string $amountStr): float
    {
        // Remove non-numeric characters except decimal point
        $cleanedAmount = preg_replace('/[^\d.]/', '', $amountStr);
        
        try {
            return floatval($cleanedAmount);
        } catch (Exception $e) {
            return 0.0;
        }
    }

    /**
     * Parse date string to ISO format
     */
    private function parseDate(?string $dateStr): ?string
    {
        if (!$dateStr) return null;

        try {
            // Expected format: "DD/MM/YY HH:MM" (e.g., "01/09/25 14:30")
            if (preg_match('/(\d{2})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/', $dateStr, $matches)) {
                $day = $matches[1];
                $month = $matches[2];
                $yearShort = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];

                $fullYear = '20' . $yearShort;
                
                $dateTime = new \DateTime("$fullYear-$month-$day $hour:$minute:00");
                return $dateTime->format('Y-m-d H:i:s');
            }

            // If no format matches, return original string
            return $dateStr;
        } catch (Exception $e) {
            return $dateStr;
        }
    }

    /**
     * Fetch data from BOA API
     */
    private function fetchFromBOAApi(string $transactionParam): ?array
    {
        try {
            $apiUrl = 'https://cs.bankofabyssinia.com/api/onlineSlip/getDetails/?id=' . $transactionParam;
            
            Log::info('Calling BOA API: ' . $apiUrl);
            
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Referer' => 'https://cs.bankofabyssinia.com/slip/',
                    'Origin' => 'https://cs.bankofabyssinia.com'
                ])
                ->get($apiUrl);

            if (!$response->successful()) {
                throw new Exception('BOA API request failed: ' . $response->status());
            }

            $data = $response->json();
            
            if (!$data) {
                throw new Exception('Invalid JSON response from BOA API');
            }

            Log::info('BOA API response received: ' . json_encode($data));
            
            return $data;
            
        } catch (Exception $e) {
            Log::error('BOA API error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse API response to extract transaction details
     */
    private function parseApiResponse(array $apiData, string $transactionId): array
    {
        $extractedData = [
            'sender_name' => null,
            'sender_account' => null,
            'sender_phone' => null,
            'sender_address' => null,
            'receiver_name' => null,
            'receiver_account' => null,
            'amount' => 0.0,
            'amount_in_words' => null,
            'date' => null,
            'transaction_type' => null,
            'narrative' => null,
            'vat_amount' => 0.0,
            'service_charge' => 0.0,
            'total_amount' => 0.0,
            'status' => 'Completed'
        ];

        // Check if API response is successful
        if (!isset($apiData['header']['status']) || $apiData['header']['status'] !== 'success') {
            throw new Exception('BOA API returned unsuccessful status');
        }

        // Check if we have body data
        if (!isset($apiData['body']) || !is_array($apiData['body']) || empty($apiData['body'])) {
            throw new Exception('No transaction data found in BOA API response');
        }

        $transactionData = $apiData['body'][0]; // Get first transaction

        // Map API fields to our data structure
        foreach ($transactionData as $key => $value) {
            $normalizedKey = strtolower(trim($key));
            
            switch ($normalizedKey) {
                case 'payer\'s name':
                case 'payer name':
                case 'sender name':
                case 'source account name':
                case 'from':
                    $extractedData['sender_name'] = $value;
                    break;
                case 'payer\'s account':
                case 'payer account':
                case 'sender account':
                case 'source account':
                case 'from account':
                    $extractedData['sender_account'] = $value;
                    break;
                case 'tel.':
                case 'phone':
                case 'telephone':
                    $extractedData['sender_phone'] = $value;
                    break;
                case 'address':
                    $extractedData['sender_address'] = $value;
                    break;
                case 'beneficiary name':
                case 'receiver name':
                case 'receiver\'s name':
                case 'to':
                    $extractedData['receiver_name'] = $value;
                    break;
                case 'beneficiary account':
                case 'receiver account':
                case 'receiver\'s account':
                case 'to account':
                    $extractedData['receiver_account'] = $value;
                    break;
                case 'amount':
                case 'transaction amount':
                case 'transferred amount':
                    $extractedData['amount'] = $this->extractAmount($value);
                    break;
                case 'transferred amount in word':
                case 'amount in words':
                    $extractedData['amount_in_words'] = $value;
                    break;
                case 'transaction date':
                case 'date':
                case 'transfer date':
                    $extractedData['date'] = $value;
                    break;
                case 'transaction type':
                case 'type':
                    $extractedData['transaction_type'] = $value;
                    break;
                case 'narrative':
                case 'description':
                    $extractedData['narrative'] = $value;
                    break;
                case 'vat (15%)':
                case 'vat':
                case 'vat amount':
                    $extractedData['vat_amount'] = $this->extractAmount($value);
                    break;
                case 'service charge':
                case 'fee':
                    $extractedData['service_charge'] = $this->extractAmount($value);
                    break;
                case 'total amount including vat':
                case 'total amount':
                    $extractedData['total_amount'] = $this->extractAmount($value);
                    break;
                case 'transaction reference':
                case 'reference':
                case 'transaction id':
                    if (!empty($value)) {
                        $extractedData['transaction_id'] = $value;
                    }
                    break;
            }
            
            // Log what we found for debugging
            Log::info("BOA API parsing - Key: '$key' -> Value: '$value'");
        }

        return $extractedData;
    }

    /**
     * Fetch HTML content using Chrome headless browser
     */
    private function fetchWithChrome(string $url): ?string
    {
        try {
            Log::info('Starting Chrome headless browser for: ' . $url);
            
            // Create Chrome options
            $options = new ChromeOptions();
            $options->addArguments([
                '--headless',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--window-size=1920,1080',
                '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]);

            // Create Chrome instance
            $chrome = new Chrome($options);
            
            // Navigate to the URL
            $page = $chrome->createPage();
            $page->navigate($url);
            
            // Wait for the page to load and JavaScript to execute
            $page->waitForLoad();
            
            // Wait additional time for dynamic content to load
            sleep(5);
            
            // Get the HTML content after JavaScript execution
            $htmlContent = $page->getHtml();
            
            // Close the page and browser
            $page->close();
            $chrome->quit();
            
            Log::info('Chrome headless browser completed successfully');
            
            return $htmlContent;
            
        } catch (Exception $e) {
            Log::error('Chrome headless browser error: ' . $e->getMessage());
            
            // Try to clean up Chrome if it's still running
            try {
                if (isset($chrome)) {
                    $chrome->quit();
                }
            } catch (Exception $cleanupError) {
                Log::warning('Chrome cleanup error: ' . $cleanupError->getMessage());
            }
            
            return null;
        }
    }
}
