<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lipa Na M-Pesa</title>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style>
  body { font-family: 'IBM Plex Mono', monospace; }
  .display { font-family: 'Manrope', sans-serif; }

  @keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.75); }
  }
  .animate-pulse-dot { animation: pulse-dot 1.3s ease-in-out infinite; }

  @media (prefers-reduced-motion: reduce) {
    .animate-pulse-dot { animation: none; }
  }
</style>
</head>
<body class="min-h-screen m-0 flex items-center justify-center p-6" style="background-color:#EEF2F6;">

<div x-data="mpesaPay()" class="w-full max-w-[420px]">

  <div class="text-center mb-7">
    <div class="display text-[13px] font-semibold tracking-[0.14em] uppercase" style="color:#12A454;">M-Pesa</div>
    <h1 class="display text-[24px] font-bold mt-1" style="color:#101827;">Send a payment</h1>
  </div>

  <div class="bg-white rounded-2xl border border-[#E2E8F0] shadow-[0_20px_50px_-20px_rgba(16,24,39,0.15)] p-8">

    <div x-show="status !== 'success'">

      {{-- Hero amount --}}
      <div class="text-center mb-8">
        <label for="amount" class="block text-[11px] font-semibold tracking-[0.12em] uppercase mb-2" style="color:#64748B;">Amount</label>
        <div class="flex items-center justify-center gap-2">
          <span class="display text-[22px] font-semibold" style="color:#64748B;">KES</span>
          <input
            type="number"
            id="amount"
            x-model="amount"
            :disabled="status === 'pending'"
            min="1"
            placeholder="0"
            class="display w-[180px] text-center text-[44px] font-bold bg-transparent border-0 focus:outline-none disabled:text-[#94A3B8]"
            style="color:#101827;"
          >
        </div>
        <div class="w-16 h-[3px] mx-auto mt-3 rounded-full" style="background-color:#12A454;"></div>
      </div>

      {{-- Recipient --}}
      <div class="mb-7">
        <label for="phone" class="block text-[11px] font-semibold tracking-[0.12em] uppercase mb-2" style="color:#64748B;">Recipient number</label>
        <input
          type="text"
          id="phone"
          x-model="phone"
          :disabled="status === 'pending'"
          placeholder="0712 345 678"
          autocomplete="tel"
          class="w-full text-[16px] font-medium rounded-xl border px-4 py-3.5 transition-colors
                 focus:outline-none focus:ring-4 disabled:bg-[#F8FAFC] disabled:text-[#94A3B8]"
          style="border-color:#E2E8F0; color:#101827;"
          onfocus="this.style.borderColor='#12A454'; this.style.boxShadow='0 0 0 4px rgba(18,164,84,0.12)'"
          onblur="this.style.borderColor='#E2E8F0'; this.style.boxShadow='none'"
        >
      </div>

      {{-- Stage tracker --}}
      <div class="mb-8" x-show="status !== 'idle'" x-transition>
        <div class="flex items-center">
          <template x-for="(stage, i) in stages" :key="i">
            <div class="flex items-center flex-1 last:flex-none">
              <div class="flex flex-col items-center gap-2 shrink-0">
                <div
                  class="w-2.5 h-2.5 rounded-full transition-colors duration-300"
                  :class="i === currentStage ? 'animate-pulse-dot' : ''"
                  :style="i <= currentStage ? 'background-color:#12A454' : 'background-color:#E2E8F0'"
                ></div>
                <span
                  class="text-[9.5px] font-medium tracking-wide text-center w-[70px]"
                  :style="i <= currentStage ? 'color:#101827' : 'color:#94A3B8'"
                  x-text="stage"
                ></span>
              </div>
              <div
                x-show="i < stages.length - 1"
                class="h-[2px] flex-1 mx-1 -mt-4 transition-colors duration-500"
                :style="i < currentStage ? 'background-color:#12A454' : 'background-color:#E2E8F0'"
              ></div>
            </div>
          </template>
        </div>
      </div>

      <button
        @click="startPayment"
        :disabled="status === 'pending'"
        class="display w-full py-4 rounded-xl text-[15.5px] font-bold text-white transition-all active:scale-[0.99] flex items-center justify-center gap-2.5
               disabled:cursor-not-allowed"
        :style="status === 'pending' ? 'background-color:#94A3B8' : 'background-color:#12A454'"
        onmouseover="if(!this.disabled) this.style.backgroundColor='#0B6B39'"
        onmouseout="if(this.disabled) return; this.style.backgroundColor='#12A454'"
      >
        <template x-if="status === 'pending'">
          <span class="h-4 w-4 rounded-full border-2 border-white border-t-transparent animate-spin"></span>
        </template>
        <span x-text="status === 'pending' ? 'Awaiting confirmation…' : 'Send STK push'"></span>
      </button>

      <template x-if="status === 'error'">
        <p class="text-[12.5px] mt-4 text-center font-medium" style="color:#E1483B;" x-text="statusMessage"></p>
      </template>

      <template x-if="status === 'pending'">
        <p class="text-[12.5px] mt-4 text-center" style="color:#64748B;">Enter your M-Pesa PIN on your phone to complete this payment.</p>
      </template>
    </div>

    {{-- Success state --}}
    <template x-if="status === 'success'">
      <div class="text-center py-2" x-transition>
        <div class="w-14 h-14 rounded-full mx-auto flex items-center justify-center" style="background-color:#E7F7EE;">
          <svg class="w-6 h-6" style="color:#12A454;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 class="display text-[20px] font-bold mt-4" style="color:#101827;">Payment confirmed</h2>
        <p class="display text-[32px] font-extrabold mt-1" style="color:#101827;" x-text="'KES ' + Number(amount || 0).toLocaleString('en-KE')"></p>
        <p class="text-[12.5px] mt-2" style="color:#64748B;">
          Receipt <span class="font-semibold" style="color:#101827;" x-text="receipt"></span>
        </p>

        <button
          @click="resetForm"
          class="display w-full mt-7 py-3.5 rounded-xl text-[14px] font-semibold border transition-colors"
          style="border-color:#E2E8F0; color:#101827;"
          onmouseover="this.style.backgroundColor='#F8FAFC'"
          onmouseout="this.style.backgroundColor='transparent'"
        >
          Send another payment
        </button>
      </div>
    </template>
  </div>

  <p class="text-center text-[11px] mt-5" style="color:#94A3B8;">Secured by Safaricom · Powered by Daraja</p>
</div>

<script>
function mpesaPay() {
  return {
    phone: '',
    amount: '',
    status: 'idle', // idle | pending | success | error
    statusMessage: '',
    receipt: null,
    checkoutRequestId: null,
    pollInterval: null,
    stages: ['Push sent', 'Awaiting PIN', 'Confirmed'],
    currentStage: 0,

    async startPayment() {
      if (!this.phone || !this.amount) {
        this.status = 'error';
        this.statusMessage = 'Enter an amount and recipient number to continue.';
        return;
      }

      this.status = 'pending';
      this.currentStage = 0;

      try {
        const response = await axios.post('{{ route('mpesa.initiate') }}', {
          phone: this.phone,
          amount: this.amount,
        });
        this.checkoutRequestId = response.data.checkout_request_id;
        this.currentStage = 1;
        this.pollInterval = setInterval(() => this.checkPaymentStatus(), 2000);
      } catch (error) {
        this.status = 'error';
        this.statusMessage = error.response?.data?.message || 'Could not start the payment. Try again.';
      }
    },

    async checkPaymentStatus() {
      try {
        const response = await axios.get(`/api/mpesa/status/${this.checkoutRequestId}`);
        const status = response.data.status;

        if (status === 'SUCCESS') {
          clearInterval(this.pollInterval);
          this.currentStage = 2;
          this.status = 'success';
          this.receipt = response.data.receipt;
        } else if (status === 'FAILED') {
          clearInterval(this.pollInterval);
          this.status = 'error';
          this.statusMessage = response.data.reason || 'Payment was not completed.';
        }
      } catch (error) {
        clearInterval(this.pollInterval);
        this.status = 'error';
        this.statusMessage = 'Could not verify payment status.';
      }
    },

    resetForm() {
      this.status = 'idle';
      this.statusMessage = '';
      this.receipt = null;
      this.checkoutRequestId = null;
      this.currentStage = 0;
      this.phone = '';
      this.amount = '';
    },
  };
}
</script>
</body>
</html>