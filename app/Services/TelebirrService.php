<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Browser;

class TelebirrService
{
    private string $baseUrl = 'https://transactioninfo.ethiotelecom.et/receipt/';

    /**
     * Verify Telebirr payment transaction
     */
    public function verifyPayment(string $transactionId): array
    {
        $extractedData = [
            'transaction_id' => $transactionId,
            'sender_name' => null,
            'sender_bank_name' => null,
            'receiver_name' => null,
            'receiver_bank_name' => null,
            'status' => 'UNKNOWN',
            'date' => null,
            'amount' => 0.0,
            'debug_info' => ''
        ];

        try {
            if (empty($transactionId)) {
                throw new Exception('Transaction ID is required.');
            }

            $receiptUrl = $this->baseUrl . $transactionId;
            
            Log::info('Fetching Telebirr receipt using Chrome headless browser from: ' . $receiptUrl);

            // Use Chrome headless browser to handle JavaScript and dynamic content
            $htmlContent = $this->fetchWithChrome($receiptUrl);
            
            if (!$htmlContent) {
                Log::warning('Chrome headless browser failed, trying simple HTTP request as fallback');
                // Fallback to simple HTTP request
                $htmlContent = $this->fetchWithHttp($receiptUrl);
                
                if (!$htmlContent) {
                    throw new Exception('Failed to fetch Telebirr receipt using both Chrome and HTTP methods.');
                }
            }

            Log::info('Successfully downloaded Telebirr receipt using Chrome, size: ' . strlen($htmlContent) . ' bytes');
            Log::info('HTML content preview: ' . substr($htmlContent, 0, 500));

            // Parse HTML content
            $parsedData = $this->parseHtmlContent($htmlContent, $transactionId);

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

            Log::info('Successfully processed Telebirr transaction: ' . $transactionId);

            return $extractedData;

        } catch (Exception $e) {
            Log::error('Telebirr verification error: ' . $e->getMessage());
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

        // Check for "This request is not correct" message
        $notFoundMessage = $crawler->filter('div:contains("This request is not correct")');
        if ($notFoundMessage->count() > 0) {
            Log::info('Detected "This request is not correct" message for ID: ' . $transactionId);
            return [
                'sender_name' => null,
                'sender_bank_name' => null,
                'receiver_name' => null,
                'receiver_bank_name' => null,
                'status' => 'Invalid Transaction ID',
                'date' => null,
                'amount' => 0.0
            ];
        }

        // Log the HTML structure for debugging
        Log::info('HTML structure analysis for transaction: ' . $transactionId);
        Log::info('Total HTML size: ' . strlen($htmlContent) . ' bytes');
        
        // Try to find any table or structured data
        $tables = $crawler->filter('table');
        Log::info('Found ' . $tables->count() . ' tables in the HTML');
        
        // Try to find any divs with transaction-related content
        $divs = $crawler->filter('div');
        Log::info('Found ' . $divs->count() . ' divs in the HTML');
        
        // Look for any text that might contain transaction details
        $allText = $crawler->text();
        Log::info('Sample text content: ' . substr($allText, 0, 500));

        // Check if we have the main content - look for telebirr receipt title
        $mainContent = $crawler->filter('title:contains("telebirr receipt"), td:contains("telebirr"), td:contains("Transaction")');
        if ($mainContent->count() === 0) {
            Log::warning('No main content found, but proceeding with generic parsing');
        }

        $extractedData = [
            'sender_name' => null,
            'sender_bank_name' => null,
            'receiver_name' => null,
            'receiver_bank_name' => null,
            'amount' => 0.0,
            'date' => null,
            'status' => 'Completed'
        ];

        // Extract data using generic table parsing
        $extractedData = $this->extractDataFromTables($crawler, $transactionId);

        // Handle sender account type logic
        if ($extractedData['sender_bank_name'] && strpos($extractedData['sender_bank_name'], 'Organization') !== false) {
            // Sender is an organization, extract bank account holder name
            $extractedData['sender_name'] = $this->extractSenderBankAccountHolder($crawler);
        }

        // Handle receiver bank account logic
        $receiverBankAccount = $this->getValueByLabel($crawler, 'የባንክ አካውኣት ቁጥር/Bank account number');
        if ($receiverBankAccount) {
            $extractedData['receiver_bank_name'] = $extractedData['receiver_name'];
            $extractedData['receiver_name'] = $this->extractReceiverBankAccountHolder($crawler);
        }

        return $extractedData;
    }

    /**
     * Extract data from tables using generic parsing
     */
    private function extractDataFromTables(Crawler $crawler, string $transactionId): array
    {
        $extractedData = [
            'sender_name' => null,
            'sender_bank_name' => null,
            'receiver_name' => null,
            'receiver_bank_name' => null,
            'amount' => 0.0,
            'date' => null,
            'status' => 'Completed'
        ];

        try {
            // Get all tables
            $tables = $crawler->filter('table');
            
            foreach ($tables as $table) {
                $tableCrawler = new Crawler($table);
                $rows = $tableCrawler->filter('tr');
                
                foreach ($rows as $row) {
                    $rowCrawler = new Crawler($row);
                    $cells = $rowCrawler->filter('td');
                    
                    if ($cells->count() >= 2) {
                        $label = trim($cells->eq(0)->text());
                        $value = trim($cells->eq(1)->text());
                        
                        Log::info("Found label-value pair: '$label' => '$value'");
                        
                        // Debug: Check if this is a sender or receiver field
                        if (stripos($label, 'Payer Name') !== false) {
                            Log::info("MATCHED SENDER: '$label' => '$value'");
                        }
                        if (stripos($label, 'Credited Party') !== false) {
                            Log::info("MATCHED RECEIVER: '$label' => '$value'");
                        }
                        
                        // Handle case where multiple fields are in one cell
                        if (stripos($label, 'Payer Name') !== false && stripos($label, 'Credited Party') !== false) {
                            // Extract sender and receiver from the combined text
                            $this->extractFromCombinedText($label, $extractedData);
                        }
                        
                        // Try to match common patterns (including Amharic)
                        if (stripos($label, 'amount') !== false || stripos($label, 'total') !== false || 
                            stripos($label, 'ጠቅላላ') !== false || stripos($label, 'የተከፈለ') !== false) {
                            $extractedData['amount'] = $this->extractAmount($value);
                        } elseif (stripos($label, 'date') !== false || stripos($label, 'time') !== false ||
                                   stripos($label, 'ቀን') !== false) {
                            $extractedData['date'] = $this->parseDate($value);
                        } elseif (stripos($label, 'sender') !== false || stripos($label, 'from') !== false ||
                                   stripos($label, 'ከፋይ') !== false || stripos($label, 'Payer') !== false ||
                                   stripos($label, 'Payer Name') !== false) {
                            $extractedData['sender_name'] = $value;
                        } elseif (stripos($label, 'receiver') !== false || stripos($label, 'to') !== false ||
                                   stripos($label, 'ተቀባይ') !== false || stripos($label, 'Credited') !== false ||
                                   stripos($label, 'Credited Party') !== false) {
                            $extractedData['receiver_name'] = $value;
                        } elseif (stripos($label, 'status') !== false || stripos($label, 'ሁኔታ') !== false ||
                                   stripos($label, 'transaction status') !== false) {
                            $extractedData['status'] = $value;
                        }
                        
                        // Special handling for amount in words
                        if (stripos($label, 'word') !== false && stripos($value, 'birr') !== false) {
                            $extractedData['amount'] = $this->extractAmountFromWords($value);
                        }
                    }
                }
            }
            
            // If no date found in tables, try to extract from transaction ID or other sources
            if (!$extractedData['date']) {
                $extractedData['date'] = $this->extractDateFromTransactionId($transactionId);
            }
            
        } catch (Exception $e) {
            Log::warning('Error in extractDataFromTables: ' . $e->getMessage());
        }

        return $extractedData;
    }

    /**
     * Extract data from combined text where multiple fields are in one cell
     */
    private function extractFromCombinedText(string $combinedText, array &$extractedData): void
    {
        try {
            // Extract sender name
            if (preg_match('/Payer Name\s+([^የ]+)/u', $combinedText, $matches)) {
                $extractedData['sender_name'] = trim($matches[1]);
                Log::info("Extracted sender from combined text: " . $extractedData['sender_name']);
            }
            
            // Extract receiver name
            if (preg_match('/Credited Party name\s+([^የ]+)/u', $combinedText, $matches)) {
                $extractedData['receiver_name'] = trim($matches[1]);
                Log::info("Extracted receiver from combined text: " . $extractedData['receiver_name']);
            }
            
            // Extract status
            if (preg_match('/transaction status\s+([^የ]+)/u', $combinedText, $matches)) {
                $extractedData['status'] = trim($matches[1]);
                Log::info("Extracted status from combined text: " . $extractedData['status']);
            }
            
        } catch (Exception $e) {
            Log::warning('Error extracting from combined text: ' . $e->getMessage());
        }
    }

    /**
     * Extract date from transaction ID if possible
     */
    private function extractDateFromTransactionId(string $transactionId): ?string
    {
        // Try to extract date from transaction ID pattern
        // This is a fallback method
        try {
            // If transaction ID contains date-like patterns, extract them
            if (preg_match('/(\d{4})(\d{2})(\d{2})/', $transactionId, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                return "$year-$month-$day 00:00:00";
            }
        } catch (Exception $e) {
            Log::warning('Could not extract date from transaction ID: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Get value by label text using regex matching
     */
    private function getValueByLabel(Crawler $crawler, string $labelPattern): ?string
    {
        try {
            $labelTd = $crawler->filter('td')->reduce(function (Crawler $node) use ($labelPattern) {
                $text = $node->text();
                return preg_match('/' . preg_quote($labelPattern, '/') . '/i', $text);
            });

            if ($labelTd->count() > 0) {
                $nextTd = $labelTd->nextAll()->filter('td')->first();
                if ($nextTd->count() > 0) {
                    return trim($nextTd->text());
                }
            }
        } catch (Exception $e) {
            Log::warning('Error extracting value for label: ' . $labelPattern . ' - ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract sender bank account holder name from reference
     */
    private function extractSenderBankAccountHolder(Crawler $crawler): ?string
    {
        try {
            $labelTd = $crawler->filter('td')->reduce(function (Crawler $node) {
                $text = $node->text();
                return preg_match('/የከፋይ የባንክ አካውኣት ቁጥር\/Payer bank account number/i', $text);
            });

            if ($labelTd->count() > 0) {
                $nextTd = $labelTd->nextAll()->filter('td')->first();
                if ($nextTd->count() > 0) {
                    $label = $nextTd->filter('label[id*="payer_reference_number"], label[id*="reference_number"]');
                    if ($label->count() > 0) {
                        $fullText = trim($label->text());
                        $parts = explode(' ', $fullText, 2);
                        if (count($parts) > 1) {
                            return trim($parts[1]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Error extracting sender bank account holder: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract receiver bank account holder name from reference
     */
    private function extractReceiverBankAccountHolder(Crawler $crawler): ?string
    {
        try {
            $labelTd = $crawler->filter('td')->reduce(function (Crawler $node) {
                $text = $node->text();
                return preg_match('/የባንክ አካውኣት ቁጥር\/Bank account number/i', $text);
            });

            if ($labelTd->count() > 0) {
                $nextTd = $labelTd->nextAll()->filter('td')->first();
                if ($nextTd->count() > 0) {
                    $label = $nextTd->filter('label[id="paid_reference_number"]');
                    if ($label->count() > 0) {
                        $fullText = trim($label->text());
                        $parts = explode(' ', $fullText, 2);
                        if (count($parts) > 1) {
                            return trim($parts[1]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Error extracting receiver bank account holder: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract date from invoice details table
     */
    private function extractDateFromInvoiceDetails(Crawler $crawler, string $transactionId): ?string
    {
        try {
            // Find invoice details table
            $invoiceHeader = $crawler->filter('td.receipttableTd3')->reduce(function (Crawler $node) {
                $text = $node->text();
                return preg_match('/የክፍያ ዝርዝር\/ Invoice details/i', $text);
            });

            if ($invoiceHeader->count() > 0) {
                $table = $invoiceHeader->closest('table');
                if ($table->count() > 0) {
                    // Find row with transaction ID
                    $dataRow = $table->filter('tr')->reduce(function (Crawler $row) use ($transactionId) {
                        return $row->filter('td.receipttableTd2')->reduce(function (Crawler $td) use ($transactionId) {
                            return strpos($td->text(), $transactionId) !== false;
                        })->count() > 0;
                    });

                    if ($dataRow->count() > 0) {
                        $tds = $dataRow->filter('td');
                        if ($tds->count() >= 3) {
                            $dateStr = trim($tds->eq(1)->text());
                            return $this->parseDate($dateStr);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Error extracting date from invoice details: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract amount from words (e.g., "twenty-two birr and zero cent")
     */
    private function extractAmountFromWords(string $amountWords): float
    {
        try {
            // Convert words to numbers
            $amountWords = strtolower($amountWords);
            
            // Extract birr amount
            if (preg_match('/(\w+)-?(\w+)?\s*birr/', $amountWords, $matches)) {
                $birrPart = $matches[1];
                $tensPart = isset($matches[2]) ? $matches[2] : '';
                
                $amount = $this->wordToNumber($birrPart);
                if ($tensPart) {
                    $amount += $this->wordToNumber($tensPart);
                }
                
                return floatval($amount);
            }
            
            // Fallback: look for any number in the string
            if (preg_match('/(\d+\.?\d*)/', $amountWords, $matches)) {
                return floatval($matches[1]);
            }
            
        } catch (Exception $e) {
            Log::warning('Error extracting amount from words: ' . $e->getMessage());
        }
        
        return 0.0;
    }
    
    /**
     * Convert word to number
     */
    private function wordToNumber(string $word): int
    {
        $word = strtolower(trim($word));
        
        $numbers = [
            'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
            'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
            'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
            'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
            'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30,
            'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70,
            'eighty' => 80, 'ninety' => 90, 'hundred' => 100, 'thousand' => 1000
        ];
        
        return $numbers[$word] ?? 0;
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
            // Expected format: "DD-MM-YYYY HH:MM:SS" (e.g., "16-04-2025 12:24:00")
            if (preg_match('/(\d{2})-(\d{2})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})/', $dateStr, $matches)) {
                $day = $matches[1];
                $month = $matches[2];
                $year = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                
                $dateTime = new \DateTime("$year-$month-$day $hour:$minute:$second");
                return $dateTime->format('Y-m-d H:i:s');
            }

            // If no format matches, return original string
            return $dateStr;
        } catch (Exception $e) {
            return $dateStr;
        }
    }

    /**
     * Fetch HTML content using simple HTTP request (fallback)
     */
    private function fetchWithHttp(string $url): ?string
    {
        try {
            Log::info('Trying simple HTTP request for: ' . $url);
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])
                ->get($url);
            
            if ($response->successful()) {
                $htmlContent = $response->body();
                Log::info('HTTP request successful, size: ' . strlen($htmlContent) . ' bytes');
                return $htmlContent;
            } else {
                Log::warning('HTTP request failed with status: ' . $response->status());
                return null;
            }
            
        } catch (Exception $e) {
            Log::error('HTTP request error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch HTML content using Chrome headless browser
     */
    private function fetchWithChrome(string $url): ?string
    {
        try {
            Log::info('Starting Chrome headless browser for: ' . $url);
            
            // Create browser factory with correct binary path
            $browserFactory = new BrowserFactory('/usr/bin/chromium-browser');
            
            // Create browser with options
            $browser = $browserFactory->createBrowser([
                '--headless',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--window-size=1920,1080',
                '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]);
            
            // Create a page
            $page = $browser->createPage();
            
            // Navigate to the URL with longer timeout
            $page->navigate($url)->waitForNavigation(5000); // 5 second timeout
            
            // Wait additional time for dynamic content to load
            sleep(3);
            
            // Get the HTML content after JavaScript execution
            $htmlContent = $page->getHtml();
            
            // Close the browser
            $browser->close();
            
            Log::info('Chrome headless browser completed successfully');
            
            return $htmlContent;
            
        } catch (Exception $e) {
            Log::error('Chrome headless browser error: ' . $e->getMessage());
            
            // Try to clean up browser if it's still running
            try {
                if (isset($browser)) {
                    $browser->close();
                }
            } catch (Exception $cleanupError) {
                Log::warning('Chrome cleanup error: ' . $cleanupError->getMessage());
            }
            
            return null;
        }
    }
}
