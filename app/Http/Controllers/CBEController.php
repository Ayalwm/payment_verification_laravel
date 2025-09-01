<?php

namespace App\Http\Controllers;

use App\Models\CBEVerification;
use App\Services\CBEService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CBEController extends Controller
{
    private CBEService $cbeService;

    public function __construct(CBEService $cbeService)
    {
        $this->cbeService = $cbeService;
    }

    /**
     * Verify CBE payment transaction
     */
    public function verifyPayment(Request $request): JsonResponse
    {
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

        try {
            $transactionId = $request->input('transaction_id');
            $accountNumber = $request->input('account_number');

            // Call the CBE service to process PDF fresh every time
            $result = $this->cbeService->verifyPayment($transactionId, $accountNumber);

            // Store verification result
            $verification = CBEVerification::create([
                'transaction_id' => $transactionId,
                'account_number' => $accountNumber,
                'sender_name' => $result['sender_name'],
                'sender_bank_name' => $result['sender_bank_name'],
                'receiver_name' => $result['receiver_name'],
                'receiver_bank_name' => $result['receiver_bank_name'],
                'status' => $result['status'],
                'date' => $result['date'],
                'amount' => $result['amount'],
                'message' => $result['message'] ?? null,
                'debug_info' => $result['debug_info'] ?? null,
                'verified_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'data' => $verification->toArray(),
                'message' => 'CBE transaction verification completed'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction verification failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get CBE service status
     */
    public function status(): JsonResponse
    {
        $totalVerifications = CBEVerification::count();
        $recentVerifications = CBEVerification::latest()->take(5)->get();

        return response()->json([
            'success' => true,
            'service' => 'CBE Transaction Verification',
            'status' => 'active',
            'total_verifications' => $totalVerifications,
            'recent_verifications' => $recentVerifications,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Get verification history
     */
    public function history(Request $request): JsonResponse
    {
        $query = CBEVerification::query();

        // Apply filters
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('transaction_id')) {
            $query->byTransactionId($request->transaction_id);
        }

        if ($request->has('account_number')) {
            $query->byAccountNumber($request->account_number);
        }

        // Paginate results
        $verifications = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $verifications,
            'message' => 'Verification history retrieved successfully'
        ]);
    }

    /**
     * Get specific verification by ID
     */
    public function show(int $id): JsonResponse
    {
        $verification = CBEVerification::find($id);

        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'Verification not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $verification,
            'message' => 'Verification details retrieved successfully'
        ]);
    }
}
