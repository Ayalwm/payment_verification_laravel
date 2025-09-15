<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

class ImageProcessor
{
    private string $geminiApiKey;

    public function __construct()
    {
        $this->geminiApiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
        
        if (!$this->geminiApiKey) {
            throw new Exception('Gemini API key not configured');
        }
    }

    /**
     * Extract transaction ID from image using Gemini AI OCR
     */
    public function extractTransactionIdFromImage(string $imageBase64): ?array
    {
        try {
            $prompt = "Analyze this bank transaction receipt image.
            Your task is to extract only the **Transaction ID**. Look for labels like \"Invoice No.\", \"Reference No.\", \"Transaction Ref\", \"Receipt No.\", \"VAT Receipt No.\". This is typically an alphanumeric string, often 10-15 characters long. If it's part of a URL, extract only the ID part.

            Output the Transaction ID clearly labeled.
            If the Transaction ID is not found, state \"Transaction ID: Not Found\".

            Example Output:
            Transaction ID: FT25188TN19J";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->geminiApiKey
            ])->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent', [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => $imageBase64
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.1
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Gemini OCR API request failed: ' . $response->status() . ' - ' . $response->body());
                return null;
            }

            $responseData = $response->json();
            
            if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                Log::error('Invalid Gemini OCR API response format');
                return null;
            }

            $extractedText = $responseData['candidates'][0]['content']['parts'][0]['text'];
            
            // Extract transaction ID using regex
            if (preg_match('/Transaction ID:\s*([A-Z0-9]+)/i', $extractedText, $matches)) {
                $transactionId = strtoupper(trim($matches[1]));
                Log::info('Successfully extracted transaction ID from image: ' . $transactionId);
                return [
                    'transaction_id' => $transactionId
                ];
            }

            Log::warning('No transaction ID found in Gemini OCR response: ' . $extractedText);
            return null;

        } catch (Exception $e) {
            Log::error('Gemini OCR extraction error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract QR code data from image
     */
    public function extractQrCodeData(string $imageBase64): ?string
    {
        try {
            // Decode base64 to image file
            $imageData = base64_decode($imageBase64);
            $tmpFile = tempnam(sys_get_temp_dir(), 'qr_');
            file_put_contents($tmpFile, $imageData);

            // Use khanamiryan/qrcode-detector-decoder
            $qrcode = new \Zxing\QrReader($tmpFile);
            $text = $qrcode->text();

            // Clean up temp file
            @unlink($tmpFile);

            if ($text) {
                Log::info('QR code extracted: ' . $text);
                return $text;
            } else {
                Log::info('No QR code found in image.');
                return null;
            }
        } catch (Exception $e) {
            Log::error('QR code extraction error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Process uploaded file and convert to base64
     */
    public function processUploadedFile(UploadedFile $file): ?string
    {
        try {
            if (!$file->isValid()) {
                throw new Exception('Invalid file upload');
            }

            // Check file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            if (!in_array($file->getMimeType(), $allowedTypes)) {
                throw new Exception('Invalid file type. Only JPEG, PNG, and GIF images are allowed.');
            }

            // Check file size (max 10MB)
            if ($file->getSize() > 10 * 1024 * 1024) {
                throw new Exception('File too large. Maximum size is 10MB.');
            }

            $imageContent = file_get_contents($file->getPathname());
            $imageBase64 = base64_encode($imageContent);

            Log::info('Successfully processed uploaded image, size: ' . strlen($imageBase64) . ' characters');
            return $imageBase64;

        } catch (Exception $e) {
            Log::error('File processing error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract CBE transaction ID and account number from QR code
     */
    public function extractCbeDataFromQr(string $qrData): ?array
    {
        try {
            // Pattern 1: QR contains both transaction ID and account number (id=TRANSACTION_ID + 8_DIGIT_ACCOUNT)
            if (preg_match('/id=([A-Z0-9]+)(\d{8})/i', $qrData, $matches)) {
                $transactionId = strtoupper(trim($matches[1]));
                $accountNumber = trim($matches[2]);
                
                Log::info('Successfully extracted CBE data from QR (with account): ' . $transactionId . ' / ' . $accountNumber);
                return [
                    'transaction_id' => $transactionId,
                    'account_number' => $accountNumber
                ];
            }

            // Pattern 2: QR contains only transaction ID (id=TRANSACTION_ID or just TRANSACTION_ID)
            if (preg_match('/id=([A-Z0-9]+)/i', $qrData, $matches)) {
                $transactionId = strtoupper(trim($matches[1]));
                
                Log::info('Successfully extracted CBE transaction ID from QR (no account): ' . $transactionId);
                return [
                    'transaction_id' => $transactionId,
                    'account_number' => null
                ];
            }

            // Pattern 3: QR contains just the transaction ID without "id=" prefix
            if (preg_match('/^([A-Z0-9]{10,15})$/i', trim($qrData), $matches)) {
                $transactionId = strtoupper(trim($matches[1]));
                
                Log::info('Successfully extracted CBE transaction ID from QR (direct): ' . $transactionId);
                return [
                    'transaction_id' => $transactionId,
                    'account_number' => null
                ];
            }

            Log::warning('No CBE pattern found in QR data: ' . $qrData);
            return null;

        } catch (Exception $e) {
            Log::error('CBE QR data extraction error: ' . $e->getMessage());
            return null;
        }
    }
}
