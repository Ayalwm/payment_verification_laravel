<?php

namespace App\Http\Controllers;

use App\Services\TelebirrService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Exception;

class TelebirrController extends Controller
{
    private TelebirrService $telebirrService;

    public function __construct(TelebirrService $telebirrService)
    {
        $this->telebirrService = $telebirrService;
    }

    /**
     * Verify Telebirr payment transaction
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'transaction_id' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $transactionId = $request->input('transaction_id');

            // Call Telebirr service
            $verificationResult = $this->telebirrService->verifyPayment($transactionId);

            // Return verification result directly without storing in database
            return response()->json([
                'success' => true,
                'data' => $verificationResult,
                'message' => 'Telebirr transaction verification completed'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Telebirr verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service status (no database required)
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'service' => 'Telebirr Payment Verification',
                'status' => 'active',
                'description' => 'Service is running and ready to verify transactions'
            ],
            'message' => 'Telebirr service status retrieved successfully'
        ]);
    }
}
