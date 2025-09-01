<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CBEVerification extends Model
{
    use HasFactory;

    protected $table = 'cbe_verifications';

    protected $fillable = [
        'transaction_id',
        'account_number',
        'sender_name',
        'sender_bank_name',
        'receiver_name',
        'receiver_bank_name',
        'status',
        'date',
        'amount',
        'message',
        'debug_info',
        'verified_at'
    ];

    protected $casts = [
        'amount' => 'float',
        'date' => 'datetime',
        'verified_at' => 'datetime'
    ];

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by transaction ID
     */
    public function scopeByTransactionId($query, $transactionId)
    {
        return $query->where('transaction_id', $transactionId);
    }

    /**
     * Scope to filter by account number
     */
    public function scopeByAccountNumber($query, $accountNumber)
    {
        return $query->where('account_number', $accountNumber);
    }
}
