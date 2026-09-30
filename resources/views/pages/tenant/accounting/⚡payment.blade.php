<?php

use App\Models\Party;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $partySearch = '';
    public string $tableSearch = '';
    public string $paymentDate = '';
    public string $paymentMethod = 'cash';
    public string $amount = '';
    public string $notes = '';
    public string $voucherNumber = '';

    public ?int $paymentId = null;
    public ?int $payableId = null;

    public bool $showForm = false;
    public bool $showDeleteModal = false;
    public bool $showPrintModal = false;

    public ?int $deleteId = null;
    public ?Payment $printVoucher = null;

    public function mount(): void
    {
        $this->paymentDate = now()->format('Y-m-d');
    }

    protected function getTenantId(): ?int
    {
        $user = Auth::user();

        return session('active_tenant_id')
            ?: $user?->tenant_id
            ?: $user?->tenants?->first()?->id;
    }

    #[Computed]
    public function items()
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return Payment::query()
                ->whereRaw('1 = 0')
                ->paginate(15);
        }

        return Payment::query()
            ->with(['payable'])
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->when(trim($this->tableSearch) !== '', function ($query) {
                $search = trim($this->tableSearch);

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'voucher_number',
                        'like',
                        "%{$search}%"
                    )->orWhereHas('payable', function ($partyQuery) use ($search) {
                        $partyQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('tax_number', 'like', "%{$search}%");
                    });
                });
            })
            ->latest('payment_date')
            ->latest('id')
            ->paginate(15);
    }

    #[Computed]
    public function voucherCount(): int
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return 0;
        }

        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->count();
    }

    #[Computed]
    public function todayTotal(): float
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return 0;
        }

        return (float) Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->whereDate('payment_date', today())
            ->sum('amount');
    }

    #[Computed]
    public function partyResults()
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId || !$this->showForm || $this->payableId) {
            return collect();
        }

        return Party::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('type', ['supplier', 'both'])
            ->when(trim($this->partySearch) !== '', function ($query) {
                $search = trim($this->partySearch);

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('tax_number', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function selectedParty(): ?Party
    {
        if (!$this->payableId) {
            return null;
        }

        return Party::query()
            ->where('tenant_id', $this->getTenantId())
            ->find($this->payableId);
    }

    public function updatedTableSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPartySearch(): void
    {
        // Livewire updates the computed partyResults automatically.
    }

    public function openCreate(): void
    {
        $this->resetForm();

        $this->voucherNumber = $this->generateVoucherNumber();

        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $tenantId = $this->getTenantId();

        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->findOrFail($id);

        $this->paymentId = $payment->id;
        $this->payableId = $payment->payable_id;
        $this->voucherNumber = $payment->voucher_number;

        $this->paymentDate =
            optional($payment->payment_date)->format('Y-m-d')
            ?? now()->format('Y-m-d');

        $this->paymentMethod = $payment->payment_method ?? 'cash';
        $this->amount = (string) $payment->amount;
        $this->notes = $payment->notes ?? '';
        $this->partySearch = '';

        $this->showForm = true;
    }

    public function clearParty(): void
    {
        $this->payableId = null;
        $this->partySearch = '';
    }

    public function selectParty(int $partyId): void
    {
        $tenantId = $this->getTenantId();

        $party = Party::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('type', ['supplier', 'both'])
            ->findOrFail($partyId);

        $this->payableId = $party->id;
        $this->partySearch = '';
    }

    public function savePayment(): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->dispatch(
                'toast',
                type: 'error',
                message: 'لا يوجد مستأجر نشط.'
            );

            return;
        }

        $validated = $this->validate([
            'payableId' => ['required', 'integer'],
            'voucherNumber' => ['required', 'string', 'max:50'],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['required', 'in:cash,bank,card,check'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($tenantId, $validated) {

            $newParty = Party::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('type', ['supplier', 'both'])
                ->lockForUpdate()
                ->findOrFail($validated['payableId']);

            $newAmount = (float) $validated['amount'];

            /*
             * تعديل سند دفع موجود
             */
            if ($this->paymentId) {

                $payment = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('type', 'payment')
                    ->lockForUpdate()
                    ->findOrFail($this->paymentId);

                $oldPartyId = $payment->payable_id;
                $oldAmount = (float) $payment->amount;

                /*
                 * نفس المورد
                 */
                if ($oldPartyId === $newParty->id) {

                    $newParty->current_balance =
                        (float) $newParty->current_balance
                        + $oldAmount
                        - $newAmount;

                    $newParty->save();

                /*
                 * تغيير المورد
                 */
                } else {

                    $oldParty = Party::query()
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->findOrFail($oldPartyId);

                    /*
                     * إعادة تأثير سند الدفع القديم للمورد القديم
                     */
                    $oldParty->current_balance =
                        (float) $oldParty->current_balance
                        + $oldAmount;

                    $oldParty->save();

                    /*
                     * تطبيق سند الدفع الجديد على المورد الجديد
                     */
                    $newParty->current_balance =
                        (float) $newParty->current_balance
                        - $newAmount;

                    $newParty->save();
                }

                $payment->update([
                    'payable_id' => $newParty->id,
                    'voucher_number' => $validated['voucherNumber'],
                    'payment_date' => $validated['paymentDate'],
                    'payment_method' => $validated['paymentMethod'],
                    'amount' => $newAmount,
                    'notes' => $validated['notes'] ?? null,
                ]);

            /*
             * إنشاء سند دفع جديد
             */
            } else {

                Payment::create([
                    'tenant_id' => $tenantId,
                    'payable_id' => $newParty->id,
                    'type' => 'payment',
                    'voucher_number' => $validated['voucherNumber'],
                    'payment_date' => $validated['paymentDate'],
                    'payment_method' => $validated['paymentMethod'],
                    'amount' => $newAmount,
                    'notes' => $validated['notes'] ?? null,
                ]);

                /*
                 * سند الدفع يقلل رصيد المورد
                 */
                $newParty->current_balance =
                    (float) $newParty->current_balance
                    - $newAmount;

                $newParty->save();
            }
        });

        $message = $this->paymentId
            ? 'تم تحديث سند الدفع بنجاح.'
            : 'تم إنشاء سند الدفع بنجاح.';

        $this->showForm = false;

        $this->dispatch(
            'toast',
            type: 'success',
            message: $message
        );

        $this->resetForm();
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $tenantId = $this->getTenantId();

        Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->findOrFail($id);

        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        $tenantId = $this->getTenantId();

        if (!$this->deleteId || !$tenantId) {
            return;
        }

        DB::transaction(function () use ($tenantId) {

            $payment = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('type', 'payment')
                ->lockForUpdate()
                ->findOrFail($this->deleteId);

            $party = Party::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($payment->payable_id);

            /*
             * إعادة قيمة سند الدفع إلى رصيد المورد
             */
            $party->current_balance =
                (float) $party->current_balance
                + (float) $payment->amount;

            $party->save();

            $payment->delete();
        });

        $this->showDeleteModal = false;
        $this->deleteId = null;

        $this->dispatch(
            'toast',
            type: 'success',
            message: 'تم حذف سند الدفع وتحديث الرصيد.'
        );

        $this->resetPage();
    }

    public function print(int $id): void
    {
        $tenantId = $this->getTenantId();

        $this->printVoucher = Payment::query()
            ->with(['payable'])
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->findOrFail($id);

        $this->showPrintModal = true;
    }

    public function closePrint(): void
    {
        $this->showPrintModal = false;
        $this->printVoucher = null;
    }

    public function sendWhatsapp(int $id): void
    {
        $tenantId = $this->getTenantId();

        $payment = Payment::query()
            ->with('payable')
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->findOrFail($id);

        /*
         * تنظيف رقم الهاتف
         */
        $phone = preg_replace(
            '/\D+/',
            '',
            (string) $payment->payable?->phone
        );

        if (!$phone) {
            $this->dispatch(
                'toast',
                type: 'error',
                message: 'لا يوجد رقم هاتف لهذا المورد.'
            );

            return;
        }

        $message = implode("\n", [
            'سند دفع',
            'رقم السند: ' . $payment->voucher_number,
            'المورد: ' . ($payment->payable?->name ?? '-'),
            'المبلغ: ' . number_format((float) $payment->amount, 2),
            'التاريخ: ' . (
                optional($payment->payment_date)->format('Y-m-d')
                ?? '-'
            ),
        ]);

        $url =
            'https://wa.me/'
            . $phone
            . '?text='
            . rawurlencode($message);

        $this->dispatch(
            'open-url',
            url: $url
        );
    }

    protected function generateVoucherNumber(): string
    {
        $tenantId = $this->getTenantId();

        $lastId = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'payment')
            ->max('id');

        return 'PAY-' . str_pad(
            (string) (($lastId ?? 0) + 1),
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    protected function resetForm(): void
    {
        $this->reset([
            'partySearch',
            'tableSearch',
            'amount',
            'notes',
            'voucherNumber',
            'paymentId',
            'payableId',
        ]);

        $this->paymentDate = now()->format('Y-m-d');
        $this->paymentMethod = 'cash';
        $this->showForm = false;
    }

    public function render()
    {
        return $this->view([
            'payments' => $this->items,
        ])->layout('layouts::tenant');
    }
};

?>

<flux:main class="space-y-6">

    <div>

        <div class="mx-auto w-full max-w-[1600px] p-4 sm:p-6 lg:p-8">

            @include('pages.tenant.accounting.partials.voucher-table', [
                'mode' => 'payment',
                'title' => 'سندات الدفع',
                'subtitle' => 'إدارة دفعات الموردين وتحديث أرصدتهم.',
                'items' => $payments,
                'todayTotal' => $this->todayTotal,
                'voucherCount' => $this->voucherCount,
                'createLabel' => 'سند دفع',
            ])

        </div>

        @include('pages.tenant.accounting.partials.voucher-form', [
            'mode' => 'payment',
            'showForm' => $showForm,
            'selectedParty' => $this->selectedParty,
            'partyResults' => $this->partyResults,
            'partyLabel' => 'المورد',
            'saveMethod' => 'savePayment',
            'paymentId' => $paymentId,
            'amount' => $amount,
        ])

        @if($showDeleteModal)

            <div class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4">

                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">

                    <h3 class="text-lg font-black text-slate-900">
                        حذف سند الدفع
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        هل أنت متأكد من حذف هذا السند؟
                        سيتم إعادة قيمة السند إلى رصيد المورد.
                    </p>

                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

                        <button
                            type="button"
                            wire:click="$set('showDeleteModal', false)"
                            class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            إلغاء
                        </button>

                        <button
                            type="button"
                            wire:click="delete"
                            wire:loading.attr="disabled"
                            class="rounded-xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-60"
                        >
                            حذف السند
                        </button>

                    </div>

                </div>

            </div>

        @endif

        @if($showPrintModal && $printVoucher)

            @include('pages.tenant.accounting.partials.voucher-print', [
                'mode' => 'payment',
                'voucher' => $printVoucher,
            ])

        @endif

    </div>

</flux:main>
