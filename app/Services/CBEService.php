<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class CBEService
{
    private string $baseUrl = 'https://apps.cbe.com.et:100/';
    private ?string $geminiApiKey;
    private Parser $pdfParser;

    public function __construct()
    {
        $this->geminiApiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
        $this->pdfParser = new Parser();
        
        // Don't throw exception for missing API key, handle gracefully
    }

    /**
     * Extract text from PDF using local PDF parser
     */
    private function extractTextFromPdf(string $pdfBytes): string
    {
        try {
            // Parse PDF content
            $pdf = $this->pdfParser->parseContent($pdfBytes);
            
            // Extract text from all pages
            $text = $pdf->getText();
            
            if (empty($text)) {
                throw new Exception('No text could be extracted from PDF');
            }
            
            Log::info('Successfully extracted text from PDF, length: ' . strlen($text));
            return $text;
            
        } catch (Exception $e) {
            Log::error('PDF parsing error: ' . $e->getMessage());
            throw new Exception('Failed to parse PDF: ' . $e->getMessage());
        }
    }

    /**
     * Extract data from text using Gemini AI
     */
    private function extractDataWithGemini(string $text): array
    {
        // If no API key is configured, try basic text extraction
        if (!$this->geminiApiKey) {
            Log::warning('Gemini API key not configured, using basic text extraction');
            return $this->extractBasicData($text);
        }

        try {
            $prompt = "Extract the following information from this CBE transaction text. Return ONLY a JSON object with these exact fields: sender_name, receiver_name, receiver_bank_name, status, date, amount. If any field is not found, use null. For date, use ISO format (YYYY-MM-DD). For amount, use numeric value only. For status, use: SUCCESS, FAILED, PENDING, or UNKNOWN.\n\nText: " . $text;

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->geminiApiKey
            ])->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                throw new Exception('Gemini API request failed: ' . $response->status() . ' - ' . $response->body());
            }

            $responseData = $response->json();
            
            if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('Invalid Gemini API response format');
            }

            $extractedText = $responseData['candidates'][0]['content']['parts'][0]['text'];
            
            // Try to parse JSON from the response
            $jsonMatch = preg_match('/\{.*\}/s', $extractedText, $matches);
            if (!$jsonMatch) {
                throw new Exception('No JSON found in Gemini response: ' . $extractedText);
            }

            $parsedData = json_decode($matches[0], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to parse JSON from Gemini response: ' . json_last_error_msg());
            }

            return $parsedData;

        } catch (Exception $e) {
            Log::error('Gemini AI extraction error: ' . $e->getMessage());
            throw new Exception('Failed to extract data with Gemini AI: ' . $e->getMessage());
        }
    }

    /**
     * Extract basic data from text without AI
     */
    private function extractBasicData(string $text): array
    {
        $data = [
            'sender_name' => null,
            'receiver_name' => null,
            'receiver_bank_name' => null,
            'status' => 'Completed',
            'date' => null,
            'amount' => 0.0
        ];

        try {
            // Extract amount (look for numbers with currency)
            if (preg_match('/(\d+\.?\d*)\s*(?:birr|ETB|Ethiopian)/i', $text, $matches)) {
                $data['amount'] = floatval($matches[1]);
            }

            // Extract date (look for common date patterns)
            if (preg_match('/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/', $text, $matches)) {
                $data['date'] = $this->parseDate($matches[1]);
            }

            // Extract sender name (look for common patterns)
            if (preg_match('/(?:from|sender|payer)[\s:]+([A-Za-z\s]+)/i', $text, $matches)) {
                $data['sender_name'] = trim($matches[1]);
            }

            // Extract receiver name (look for common patterns)
            if (preg_match('/(?:to|receiver|beneficiary)[\s:]+([A-Za-z\s]+)/i', $text, $matches)) {
                $data['receiver_name'] = trim($matches[1]);
            }

            Log::info('Basic extraction completed: ' . json_encode($data));
            return $data;

        } catch (Exception $e) {
            Log::warning('Basic extraction failed: ' . $e->getMessage());
            return $data;
        }
    }

    /**
     * Parse date string to ISO format
     */
    private function parseDate(?string $dateStr): ?string
    {
        if (!$dateStr) return null;
        
        try {
            // Try different date formats
            $formats = [
                'Y-m-d',
                'd/m/Y',
                'd-m-Y',
                'Y/m/d',
                'd.m.Y',
                'M d, Y',
                'd M Y'
            ];
            
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $dateStr);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }
            
            // If no format matches, return original string
            return $dateStr;
        } catch (Exception $e) {
            return $dateStr;
        }
    }

    /**
     * Verify CBE payment transaction
     */
    public function verifyPayment(string $transactionId, string $accountNumber): array
    {
        $extractedData = [
            'transaction_id' => $transactionId,
            'sender_name' => null,
            'sender_bank_name' => 'Commercial Bank of Ethiopia',
            'receiver_name' => null,
            'receiver_bank_name' => null,
            'status' => 'UNKNOWN',
            'date' => null,
            'amount' => 0.0,
            'debug_info' => ''
        ];

        try {
            if (strlen($accountNumber) < 8) {
                throw new Exception('Account number must have at least 8 digits to construct the PDF link.');
            }
            
            $last8DigitsOfAccount = substr($accountNumber, -8);
            $pdfUrl = $this->baseUrl . '?id=' . $transactionId . $last8DigitsOfAccount;
            
            $response = Http::timeout(120)
                ->withoutVerifying()
                ->get($pdfUrl);
            
            if (!$response->successful()) {
                throw new Exception('Failed to fetch PDF: ' . $response->status());
            }
            
            $contentType = $response->header('Content-Type');
            if (strpos($contentType, 'application/pdf') === false) {
                throw new Exception('Response is not a PDF. Content-Type: ' . $contentType);
            }
            
            $pdfBytes = $response->body();
            if (empty($pdfBytes)) {
                throw new Exception('PDF content is empty');
            }
            
            Log::info('Successfully downloaded PDF from CBE, size: ' . strlen($pdfBytes) . ' bytes');
            
            // Extract text from PDF using local parser
            $pdfText = $this->extractTextFromPdf($pdfBytes);
            
            // Extract structured data using Gemini AI
            $structuredData = $this->extractDataWithGemini($pdfText);
            
            // Merge extracted data
            $extractedData = array_merge($extractedData, $structuredData);
            
            // Parse date if present
            if (isset($extractedData['date'])) {
                $extractedData['date'] = $this->parseDate($extractedData['date']);
            }
            
            // Ensure amount is numeric
            if (isset($extractedData['amount'])) {
                $extractedData['amount'] = floatval($extractedData['amount']);
            }
            
            Log::info('Successfully processed CBE transaction: ' . $transactionId);
            
            return $extractedData;

        } catch (Exception $e) {
            Log::error('CBE verification error: ' . $e->getMessage());
            
            // Return a proper error response instead of test data
            return [
                'transaction_id' => $transactionId,
                'sender_name' => null,
                'sender_bank_name' => 'Commercial Bank of Ethiopia',
                'receiver_name' => null,
                'receiver_bank_name' => null,
                'status' => 'Service Unavailable',
                'date' => null,
                'amount' => 0.0,
                'debug_info' => 'CBE verification service is temporarily unavailable: ' . $e->getMessage()
            ];
        }
    }
}