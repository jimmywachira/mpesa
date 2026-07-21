<?php

namespace App\Http\Controllers;

use App\Models\MpesaTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MpesaController extends Controller
{
    // Cached, lazy — not fetched on every request, only when actually needed.
    private function getAccessToken(): ?string
    {
        return Cache::remember('mpesa_access_token', now()->addMinutes(55), function () {
            try {
                $response = Http::withBasicAuth(
                    env('MPESA_CONSUMER_KEY'),
                    env('MPESA_CONSUMER_SECRET')
                )->timeout(10)->get('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');

                if (! $response->successful()) {
                    Log::warning('M-Pesa token fetch failed', ['body' => $response->body()]);
                    return null;
                }

                return $response->json('access_token');
            } catch (\Throwable $e) {
                Log::error('M-Pesa token fetch exception', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        return Str::startsWith($phone, '0') ? '254' . substr($phone, 1) : $phone;
    }

    public function initiateStkPush(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^(?:254|0)7\d{8}$/'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $amount = (int) ceil((float) $validated['amount']);

        $token = $this->getAccessToken();
        if (! $token) {
            return response()->json(['message' => 'Could not reach M-Pesa. Try again shortly.'], 502);
        }

        $timestamp = now()->format('YmdHis');
        $shortcode = config('services.mpesa.shortcode');
        $password = base64_encode($shortcode . config('services.mpesa.passkey') . $timestamp);

        try {
            $response = Http::withToken($token)->timeout(15)->post(
                'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => $amount,
                    'PartyA' => $phone,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $phone,
                    'CallBackURL' => route('mpesa.callback'),
                    'AccountReference' => 'Order_' . time(),
                    'TransactionDesc' => 'Payment for services',
                ]
            );

            $res = $response->json();
        } catch (\Throwable $e) {
            Log::error('M-Pesa STK push exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not start the payment. Try again.'], 502);
        }

        if (! isset($res['ResponseCode']) || $res['ResponseCode'] !== '0') {
            Log::warning('M-Pesa STK push rejected', ['response' => $res]);
            return response()->json(['message' => $res['errorMessage'] ?? 'Payment request was rejected.'], 422);
        }

        MpesaTransaction::create([
            'merchant_request_id' => $res['MerchantRequestID'],
            'checkout_request_id' => $res['CheckoutRequestID'],
            'phone' => $phone,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        // Normalized snake_case shape — this is what the frontend actually reads.
        return response()->json([
            'checkout_request_id' => $res['CheckoutRequestID'],
        ]);
    }

    // The endpoint your Alpine polling loop was calling but that didn't exist yet.
    public function checkStatus(string $checkoutRequestId)
    {
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        return response()->json([
            'status' => strtoupper($transaction->status), // PENDING | SUCCESS | FAILED
            'receipt' => $transaction->mpesa_receipt,
            'reason' => $transaction->result_desc,
        ]);
    }

    public function handleCallback(Request $request)
    {
        $data = $request->all();
        $stkCallback = $data['Body']['stkCallback'] ?? null;

        if (! $stkCallback) {
            Log::warning('M-Pesa callback missing stkCallback payload', ['payload' => $data]);
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
        }

        $checkoutID = $stkCallback['CheckoutRequestID'] ?? null;
        $resultCode = $stkCallback['ResultCode'] ?? null;

        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutID)->first();

        if (! $transaction) {
            Log::warning('M-Pesa callback for unknown transaction', ['checkout_id' => $checkoutID]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        if ((int) $resultCode === 0) {
            $items = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receipt = collect($items)->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

            $transaction->update([
                'status' => 'success',
                'mpesa_receipt' => $receipt,
                'result_desc' => $stkCallback['ResultDesc'] ?? null,
            ]);
        } else {
            $transaction->update([
                'status' => 'failed',
                'result_desc' => $stkCallback['ResultDesc'] ?? 'Payment was not completed.',
            ]);
        }

        // Safaricom expects exactly this shape back, regardless of outcome.
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
