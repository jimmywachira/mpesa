<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\MpesaTransaction; // adjust to your actual model
use Safaricom\Mpesa\Mpesa;

class MpesaPayment extends Component
{
    public string $phone = '';
    public string $amount = '1';

    public ?string $checkoutRequestId = null;
    public string $status = 'idle'; // idle | pending | success | failed
    public ?string $statusMessage = null;
    public ?string $receiptNumber = null;

    protected $rules = [
        'phone' => ['required', 'regex:/^(?:254|0)7\d{8}$/'],
        'amount' => ['required', 'numeric', 'min:1'],
    ];

    public function startPayment(): void
    {
        $this->validate();

        $this->status = 'pending';
        $this->statusMessage = 'Initiating STK Push... Check your phone.';

        $normalizedPhone = $this->normalizePhone($this->phone);

        // Idempotency: reuse an in-flight request instead of double-firing
        // an STK push if the user double-clicks or a retry lands here.
        $idempotencyKey = 'mpesa_push_' . md5($normalizedPhone . $this->amount . now()->format('Y-m-d_H:i'));

        try {
            $mpesa = new Mpesa();

            $invoiceNo = 'PAY-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));

            $response = $mpesa->STKPushSimulation(
                config('mpesa.shortcode'),
                config('mpesa.shortcode'),
                config('mpesa.passkey'),
                (int) ceil((float) $this->amount),
                $normalizedPhone,
                config('mpesa.shortcode'),
                $normalizedPhone,
                config('mpesa.stk_callback_url'),
                $invoiceNo,
                "Payment {$invoiceNo}",
                'Payment'
            );

            $result = is_string($response) ? json_decode($response, true) : (array) $response;

            if (empty($result['CheckoutRequestID'])) {
                throw new \RuntimeException($result['errorMessage'] ?? 'STK push was not accepted by Safaricom.');
            }

            $this->checkoutRequestId = $result['CheckoutRequestID'];

            // Persist so the callback route (hit by Safaricom, not this component)
            // has somewhere to write the final result.
            MpesaTransaction::create([
                'checkout_request_id' => $this->checkoutRequestId,
                'merchant_request_id' => $result['MerchantRequestID'] ?? null,
                'phone' => $normalizedPhone,
                'amount' => $this->amount,
                'invoice_no' => $invoiceNo,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (\Throwable $e) {
            Log::warning('M-Pesa STK push failed', ['error' => $e->getMessage()]);
            $this->status = 'failed';
            $this->statusMessage = 'Failed to trigger payment. Please try again.';
        }
    }

    // Called by wire:poll while status = pending.
    // This is the STK Query fallback — don't rely on the callback alone,
    // since Safaricom callbacks can be delayed or dropped.
    public function checkStatus(): void
    {
        if ($this->status !== 'pending' || ! $this->checkoutRequestId) {
            return;
        }

        $transaction = MpesaTransaction::where('checkout_request_id', $this->checkoutRequestId)->first();

        if (! $transaction) {
            return;
        }

        // Callback already resolved it — trust the DB first.
        if ($transaction->status === 'success') {
            $this->status = 'success';
            $this->statusMessage = 'Payment successful.';
            $this->receiptNumber = $transaction->receipt_number;
            return;
        }

        if ($transaction->status === 'failed') {
            $this->status = 'failed';
            $this->statusMessage = $transaction->failure_reason ?? 'Payment failed.';
            return;
        }

        // Still pending after ~30s of polling — actively query Safaricom
        // rather than waiting indefinitely for a callback.
        if ($transaction->created_at->diffInSeconds(now()) > 30) {
            try {
                $mpesa = new Mpesa();
                $query = $mpesa->STKPushQuery(
                    config('mpesa.shortcode'),
                    config('mpesa.shortcode'),
                    config('mpesa.passkey'),
                    $this->checkoutRequestId
                );

                $result = is_string($query) ? json_decode($query, true) : (array) $query;
                $resultCode = $result['ResultCode'] ?? null;

                if ($resultCode === '0') {
                    $transaction->update(['status' => 'success']);
                    $this->status = 'success';
                    $this->statusMessage = 'Payment successful.';
                } elseif ($resultCode !== null) {
                    $transaction->update(['status' => 'failed', 'failure_reason' => $result['ResultDesc'] ?? null]);
                    $this->status = 'failed';
                    $this->statusMessage = $result['ResultDesc'] ?? 'Payment failed.';
                }
                // else: genuinely still pending on Safaricom's side — keep polling
            } catch (\Throwable $e) {
                Log::warning('STK Query fallback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public function reset_(): void
    {
        $this->reset(['checkoutRequestId', 'status', 'statusMessage', 'receiptNumber']);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (Str::startsWith($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        return $phone;
    }

    public function render()
    {
        return view('livewire.mpesa-payment');
    }
}
?>

<div class="flex min-h-screen items-center justify-center bg-[#f4f6f9] px-4" x-data>
    <div class="w-full max-w-md rounded-xl border border-gray-100 bg-white p-8 shadow-lg shadow-black/5">

        <div class="mb-6 text-center text-2xl font-bold text-[#44b649]">
            Lipa Na M-Pesa
        </div>

        <form wire:submit.prevent="startPayment" class="space-y-4">
            <div>
                <label for="phone" class="mb-1 block text-sm font-semibold text-gray-700">Phone Number</label>
                <input
                    type="text"
                    id="phone"
                    wire:model="phone"
                    placeholder="e.g., 0712345678 or 254712345678"
                    :disabled="$wire.status === 'pending'"
                    class="w-full rounded-md border border-gray-300 px-3 py-2.5 text-base focus:border-[#44b649] focus:outline-none focus:ring-1 focus:ring-[#44b649] disabled:bg-gray-100"
                >
                @error('phone') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="amount" class="mb-1 block text-sm font-semibold text-gray-700">Amount (KES)</label>
                <input
                    type="number"
                    id="amount"
                    wire:model="amount"
                    min="1"
                    :disabled="$wire.status === 'pending'"
                    class="w-full rounded-md border border-gray-300 px-3 py-2.5 text-base focus:border-[#44b649] focus:outline-none focus:ring-1 focus:ring-[#44b649] disabled:bg-gray-100"
                >
                @error('amount') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
            </div>

            <button
                type="submit"
                :disabled="$wire.status === 'pending'"
                class="w-full rounded-md bg-[#44b649] py-3 text-base font-bold text-white transition-colors hover:bg-[#359439] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
                <span wire:loading.remove wire:target="startPayment">
                    {{ $status === 'pending' ? 'Waiting for confirmation...' : 'Pay Now' }}
                </span>
                <span wire:loading wire:target="startPayment">Sending request...</span>
            </button>
        </form>

        {{-- Poll every 2s while a payment is in flight. Livewire calls
             checkStatus() on the server on each tick — no client JS needed. --}}
        @if ($status === 'pending')
            <div wire:poll.2s="checkStatus"></div>
        @endif

        @if ($statusMessage)
            <div
                class="mt-4 rounded-md p-3 text-center text-sm font-bold
                    {{ match($status) {
                        'pending' => 'bg-blue-50 text-blue-900',
                        'success' => 'bg-green-50 text-green-900',
                        'failed' => 'bg-red-50 text-red-900',
                        default => '',
                    } }}"
            >
                {{ $statusMessage }}
                @if ($status === 'success' && $receiptNumber)
                    <div class="mt-1 font-mono text-xs font-normal">Receipt: {{ $receiptNumber }}</div>
                @endif
            </div>
        @endif

        @if (in_array($status, ['success', 'failed']))
            <button
                wire:click="reset_"
                class="mt-3 w-full rounded-md border border-gray-300 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50"
            >
                Make another payment
            </button>
        @endif
    </div>
</div>