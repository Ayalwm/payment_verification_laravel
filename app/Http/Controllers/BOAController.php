<?php

namespace App\Http\Controllers;

use App\Services\BOAService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class BOAController extends Controller
{
    private BOAService $boaService;

    public function __construct(BOAService $boaService)
    {
        $this->boaService = $boaService;
    }

    /**
     * Verify BOA payment transaction
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'transaction_id' => 'required|string|min:10',
                'sender_account_last_5_digits' => 'required|string|min:5|max:5'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $transactionId = $request->input('transaction_id');
            $senderAccountLast5Digits = $request->input('sender_account_last_5_digits');

            // Call the BOA service to process web scraping fresh every time
            $result = $this->boaService->verifyPayment($transactionId, $senderAccountLast5Digits);

            // Return verification result directly without storing in database
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'BOA transaction verification completed'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'BOA verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get BOA service status (no database required)
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'service' => 'BOA Transaction Verification',
                'status' => 'active',
                'description' => 'Service is running and ready to verify transactions'
            ],
            'message' => 'BOA service status retrieved successfully'
        ]);
    }
}
