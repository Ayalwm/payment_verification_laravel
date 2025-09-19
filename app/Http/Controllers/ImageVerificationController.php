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
                    'message' => 'Unable to extract transaction ID from image. Please enter it manually.',
                    'data' => [
                        'transaction_id' => null,
                        'account_number' => $extractedAccountNumber,
                        'status' => 'Manual Entry Required',
                        'debug_info' => 'Could not extract transaction ID from image using QR code or OCR. Please enter transaction ID manually.',
                        'suggested_action' => 'manual_entry',
                        'error_type' => 'manual_entry_required',
                        'ui_action' => 'switch_to_manual_tab'
                    ]
                ], 400);
            }

            // Check if transaction ID is valid for CBE (exactly 12 characters)
            $isValidTransactionId = $transactionId && 
                strlen($transactionId) === 12 && 
                !in_array(strtoupper($transactionId), ['NOT', 'NO', 'NONE', 'NULL', 'ERROR', 'FAIL', 'FAILED']) &&
                preg_match('/^[A-Za-z0-9]+$/', $transactionId);

            if (!$isValidTransactionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to extract valid transaction ID from image. Please enter it manually.',
                    'data' => [
                        'transaction_id' => null,
                        'account_number' => $extractedAccountNumber,
                        'status' => 'Manual Entry Required',
                        'debug_info' => 'Extracted ID is not 12 alphanumeric characters: ' . $transactionId,
                        'suggested_action' => 'manual_entry',
                        'error_type' => 'manual_entry_required',
                        'ui_action' => 'switch_to_manual_tab'
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

            // Check if transaction ID is valid for BOA (exactly 12 characters)
            $isValidTransactionId = $transactionId && 
                strlen($transactionId) === 12 && 
                !in_array(strtoupper($transactionId), ['NOT', 'NO', 'NONE', 'NULL', 'ERROR', 'FAIL', 'FAILED']) &&
                preg_match('/^[A-Za-z0-9]+$/', $transactionId);

            if (!$isValidTransactionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to extract valid transaction ID from image. Please enter it manually.',
                    'data' => [
                        'transaction_id' => null,
                        'sender_account' => $senderAccount,
                        'status' => 'Manual Entry Required',
                        'debug_info' => 'Extracted ID is not 12 alphanumeric characters: ' . $transactionId,
                        'suggested_action' => 'manual_entry'
                    ]
                ], 400);
            }

            if (!$transactionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to extract transaction ID from image. Please enter it manually.',
                    'data' => [
                        'transaction_id' => null,
                        'sender_account' => $senderAccount,
                        'status' => 'Manual Entry Required',
                        'debug_info' => 'Could not extract transaction ID from image using OCR. Please enter transaction ID manually.',
                        'suggested_action' => 'manual_entry'
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

            // Debug logging
            \Log::info('Telebirr Image Processing Debug:', [
                'extracted_data' => $extractedData,
                'transaction_id' => $transactionId,
                'is_null' => is_null($transactionId),
                'is_empty' => empty($transactionId)
            ]);

            // Check if transaction ID is valid for Telebirr (typically 10 characters)
            $isValidTransactionId = $transactionId && 
                strlen($transactionId) === 10 && 
                !in_array(strtoupper($transactionId), ['NOT', 'NO', 'NONE', 'NULL', 'ERROR', 'FAIL', 'FAILED']) &&
                preg_match('/^[A-Za-z0-9]+$/', $transactionId);

            // Debug validation
            \Log::info('Telebirr Validation Debug:', [
                'transaction_id' => $transactionId,
                'length' => $transactionId ? strlen($transactionId) : 'null',
                'is_10_chars' => $transactionId ? strlen($transactionId) === 10 : false,
                'is_error_word' => $transactionId ? in_array(strtoupper($transactionId), ['NOT', 'NO', 'NONE', 'NULL', 'ERROR', 'FAIL', 'FAILED']) : false,
                'is_alphanumeric' => $transactionId ? preg_match('/^[A-Za-z0-9]+$/', $transactionId) : false,
                'is_valid' => $isValidTransactionId
            ]);

            if (!$isValidTransactionId) {
                // If image processing fails or extracts invalid text, always ask for manual entry
                \Log::info('Telebirr: Returning Manual Entry Required due to invalid transaction ID');
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to extract transaction ID from image. Please enter it manually.',
                    'data' => [
                        'transaction_id' => null,
                        'status' => 'Manual Entry Required',
                        'debug_info' => 'Could not extract valid transaction ID from image using OCR. Please enter transaction ID manually.',
                        'suggested_action' => 'manual_entry'
                    ]
                ], 400);
            }

            // Try verification with the extracted ID
            \Log::info('Telebirr: Proceeding to call Telebirr service with transaction ID: ' . $transactionId);
            $result = $this->telebirrService->verifyPayment($transactionId);
            
            // If verification succeeds, return success
            if (isset($result['status']) && strtoupper($result['status']) === 'SUCCESS') {
                return response()->json([
                    'success' => true,
                    'data' => $result,
                    'message' => 'Telebirr image verification completed'
                ]);
            }

            // Check if the transaction ID contains 0 or O characters
            $hasZeroOrO = preg_match('/[0O]/', $transactionId);
            
            if ($hasZeroOrO) {
                // If verification fails and ID has 0/O, ask user to verify manually
                return response()->json([
                    'success' => false,
                    'message' => 'Telebirr verification failed. Please verify the transaction ID manually.',
                    'data' => [
                        'transaction_id' => $transactionId,
                        'status' => 'Manual Entry Required',
                        'debug_info' => 'Transaction ID contains 0/O characters and verification failed. Please check the ID and try manual entry.',
                        'suggested_action' => 'manual_entry'
                    ]
                ], 400);
            } else {
                // If verification fails but no 0/O, return normal failure
                return response()->json([
                    'success' => false,
                    'data' => $result,
                    'message' => 'Telebirr verification failed'
                ], 400);
            }

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Telebirr image verification failed: ' . $e->getMessage()
            ], 500);
        }
    }


}
