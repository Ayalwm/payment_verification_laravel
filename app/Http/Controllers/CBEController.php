<?php

namespace App\Http\Controllers;

use App\Services\CBEService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Exception;

class CBEController extends Controller
{
    private ?CBEService $cbeService = null;

    public function __construct()
    {
        // Don't inject CBEService in constructor to avoid dependency issues
    }

    private function getCbeService(): CBEService
    {
        if ($this->cbeService === null) {
            $this->cbeService = app(CBEService::class);
        }
        return $this->cbeService;
    }

    /**
     * Verify CBE payment transaction
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'transaction_id' => 'required|string|min:10',
                'account_number' => 'required|string|min:8|max:20'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $transactionId = $request->input('transaction_id');
            $accountNumber = $request->input('account_number');

            // Call the CBE service to process PDF fresh every time
            $result = $this->getCbeService()->verifyPayment($transactionId, $accountNumber);

            // Return verification result directly without storing in database
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'CBE transaction verification completed'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'CBE verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get CBE service status (no database required)
     */
    public function status(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'service' => 'CBE Transaction Verification',
                    'status' => 'active',
                    'description' => 'Service is running and ready to verify transactions'
                ],
                'message' => 'CBE service status retrieved successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'CBE service status check failed: ' . $e->getMessage()
            ], 500);
        }
    }
}