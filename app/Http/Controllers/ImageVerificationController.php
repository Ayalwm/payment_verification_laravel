<?php

namespace App\Http\Controllers;

use App\Services\ImageProcessor;
use App\Services\CBEService;
use App\Services\BOAService;
use App\Services\TelebirrService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Exception;

class ImageVerificationController extends Controller
{
    private ImageProcessor $imageProcessor;
    private CBEService $cbeService;
    private BOAService $boaService;
    private TelebirrService $telebirrService;

    public function __construct(
        ImageProcessor $imageProcessor,
        CBEService $cbeService,
        BOAService $boaService,
        TelebirrService $telebirrService
    ) {
        $this->imageProcessor = $imageProcessor;
        $this->cbeService = $cbeService;
        $this->boaService = $boaService;
        $this->telebirrService = $telebirrService;
    }

    /**
     * Verify CBE payment from image
     */
    public function verifyCbeFromImage(Request $request): JsonResponse
    {
        try {
            // Validate image upload
            $validator = Validator::make($request->all(), [
                'image' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
                'account_number' => 'required|string|min:8|max:20'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $imageFile = $request->file('image');
            $accountNumber = $request->input('account_number');

            // Process the uploaded image
            $imageBase64 = $this->imageProcessor->processUploadedFile($imageFile);
            if (!$imageBase64) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process uploaded image'
                ], 400);
            }

            $transactionId = null;
            $extractedAccountNumber = $accountNumber;

            // Try QR code extraction first
            $qrData = $this->imageProcessor->extractQrCodeData($imageBase64);
            if ($qrData) {
                $cbeData = $this->imageProcessor->extractCbeDataFromQr($qrData);
                if ($cbeData) {
                    $transactionId = $cbeData['transaction_id'];
                    // If QR contains account number, use it; otherwise use provided account number
                    $extractedAccountNumber = $cbeData['account_number'] ?: $accountNumber;
                }
            }

            // If no QR data, try OCR
            if (!$transactionId) {
                $extractedData = $this->imageProcessor->extractTransactionIdFromImage($imageBase64);
                if ($extractedData && isset($extractedData['transaction_id'])) {
                    $transactionId = $extractedData['transaction_id'];
                }
            }

            if (!$transactionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No CBE transaction ID found in image',
                    'data' => [
                        'transaction_id' => null,
                        'account_number' => $extractedAccountNumber,
                        'status' => 'No Transaction ID Found',
                        'debug_info' => 'Could not extract transaction ID from image using QR code or OCR'
                    ]
                ], 400);
            }

            // Validate that we have both transaction ID and account number
            if (!$extractedAccountNumber || strlen($extractedAccountNumber) < 8) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account number is required and must be at least 8 digits for CBE verification',
                    'data' => [
                        'transaction_id' => $transactionId,
                        'account_number' => $extractedAccountNumber,
                        'status' => 'Account Number Required',
                        'debug_info' => 'Transaction ID found but valid account number is required for CBE verification'
                    ]
                ], 400);
            }

            // Verify with CBE service
            $result = $this->cbeService->verifyPayment($transactionId, $extractedAccountNumber);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'CBE image verification completed'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'CBE image verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify BOA payment from image
     */
    public function verifyBoaFromImage(Request $request): JsonResponse
    {
        try {
            // Validate image upload
            $validator = Validator::make($request->all(), [
                'image' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
                'sender_account' => 'nullable|string|min:5|max:20'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $imageFile = $request->file('image');
            $senderAccount = $request->input('sender_account');

            // Process the uploaded image
            $imageBase64 = $this->imageProcessor->processUploadedFile($imageFile);
            if (!$imageBase64) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process uploaded image'
                ], 400);
            }

            // Extract transaction ID using OCR
            $extractedData = $this->imageProcessor->extractTransactionIdFromImage($imageBase64);
            $transactionId = $extractedData['transaction_id'] ?? null;

            if (!$transactionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No BOA transaction ID found in image',
                    'data' => [
                        'transaction_id' => null,
                        'sender_account' => $senderAccount,
                        'status' => 'No Transaction ID Found',
                        'debug_info' => 'Could not extract transaction ID from image using OCR'
                    ]
                ], 400);
            }

            if (!$senderAccount || strlen($senderAccount) < 5) {
                return response()->json([
                    'success' => true,
                    'message' => 'BOA transaction ID extracted. Sender account required.',
                    'data' => [
                        'transaction_id' => $transactionId,
                        'sender_account' => $senderAccount,
                        'status' => 'Account Number Required',
                        'debug_info' => 'Transaction ID found but sender account number is required for verification'
                    ]
                ]);
            }

            // Extract last 5 digits
            $senderAccountLast5 = substr($senderAccount, -5);

            // Verify with BOA service
            $result = $this->boaService->verifyPayment($transactionId, $senderAccountLast5);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'BOA image verification completed'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'BOA image verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify Telebirr payment from image
     */
    public function verifyTelebirrFromImage(Request $request): JsonResponse
    {
        try {
            // Validate image upload
            $validator = Validator::make($request->all(), [
                'image' => 'required|file|image|mimes:jpeg,png,jpg,gif|max:10240' // 10MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $imageFile = $request->file('image');

            // Process the uploaded image
            $imageBase64 = $this->imageProcessor->processUploadedFile($imageFile);
            if (!$imageBase64) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process uploaded image'
                ], 400);
            }

            // Extract transaction ID using OCR
            $extractedData = $this->imageProcessor->extractTransactionIdFromImage($imageBase64);
            $transactionId = $extractedData['transaction_id'] ?? null;

            if (!$transactionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Telebirr transaction ID found in image',
                    'data' => [
                        'transaction_id' => null,
                        'status' => 'No Transaction ID Found',
                        'debug_info' => 'Could not extract transaction ID from image using OCR'
                    ]
                ], 400);
            }

            // First try with the original extracted ID
            $result = $this->telebirrService->verifyPayment($transactionId);
            
            // If original ID succeeds, return it
            if (isset($result['status']) && strtoupper($result['status']) === 'SUCCESS') {
                return response()->json([
                    'success' => true,
                    'data' => $result,
                    'message' => 'Telebirr image verification completed'
                ]);
            }

            // Check if this ID has 0/O characters that need swapping
            $hasZeroOrO = preg_match('/[0O]/', $transactionId);
            
            if (!$hasZeroOrO) {
                // No 0/O characters, return the failed result directly
                return response()->json([
                    'success' => false,
                    'data' => $result,
                    'message' => 'Telebirr verification failed'
                ]);
            }

            // Has 0/O characters, try swap combinations
            $triedIds = [$transactionId]; // Track original ID
            $success = false;
            $finalResult = null;

            foreach ($this->generateZeroOLetterCombinations($transactionId) as $candidateId) {
                if (in_array($candidateId, $triedIds)) continue;
                $triedIds[] = $candidateId;
                
                $candidateResult = $this->telebirrService->verifyPayment($candidateId);
                
                if (isset($candidateResult['status']) && strtoupper($candidateResult['status']) === 'SUCCESS') {
                    $success = true;
                    $finalResult = $candidateResult;
                    break;
                }
            }

            // Return successful result if found, otherwise return failed result
            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $finalResult,
                    'message' => 'Telebirr image verification completed (with 0/O swap)'
                ]);
            } else {
                // All attempts failed, return failed result
                return response()->json([
                    'success' => false,
                    'data' => $result,
                    'message' => 'Telebirr verification failed (tried 0/O swaps)'
                ]);
            }

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Telebirr image verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate all 0/O swap combinations for a string (only '0' and 'O', uppercase)
     */
    private function generateZeroOLetterCombinations($input)
    {
        $positions = [];
        $chars = str_split($input);
        foreach ($chars as $i => $c) {
            if ($c === '0' || $c === 'O') {
                $positions[] = $i;
            }
        }
        $combos = [];
        $total = count($positions);
        $max = pow(2, $total);
        for ($i = 0; $i < $max; $i++) {
            $newChars = $chars;
            for ($j = 0; $j < $total; $j++) {
                $pos = $positions[$j];
                // If bit is set, swap
                if ($i & (1 << $j)) {
                    $newChars[$pos] = ($chars[$pos] === '0') ? 'O' : '0';
                } else {
                    $newChars[$pos] = $chars[$pos];
                }
            }
            $combos[] = implode('', $newChars);
        }
        return array_unique($combos);
    }

}
