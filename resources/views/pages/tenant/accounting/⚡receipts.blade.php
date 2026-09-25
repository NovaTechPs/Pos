<?php

use App\Models\Party;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
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

    protected $queryString = [
        'tableSearch' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->paymentDate = now()->format('Y-m-d');
    }

    public function updatedTableSearch(): void
    {
        $this->resetPage();
    }

    public function getTenantId(): ?int
    {
        $user = Auth::user();

        return session('active_tenant_id')
            ?: $user?->tenant_id
            ?: $user?->tenants?->first()?->id;
    }

    public function getPartyResultsProperty()
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId || trim($this->partySearch) === '') {
            return collect();
        }

        return Party::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'both'])
            ->where(function ($query) {
                $search = trim($this->partySearch);

                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    public function getSelectedPartyProperty(): ?Party
    {
        if (!$this->payableId) {
            return null;
        }

        return Party::query()
            ->where('tenant_id', $this->getTenantId())
            ->whereKey($this->payableId)
            ->first();
    }

    public function getVoucherCountProperty(): int
    {
        return Payment::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('type', 'receipt')
            ->where('payable_type', Party::class)
            ->count();
    }

    public function getTodayTotalProperty(): float
    {
        return (float) Payment::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('type', 'receipt')
            ->where('payable_type', Party::class)
            ->whereDate('payment_date', today())
            ->sum('amount');
    }

    public function selectParty(int $partyId): void
    {
        $party = Party::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'both'])
            ->find($partyId);

        if (!$party) {
            $this->addError('payableId', 'تعذر العثور على العميل.');
            return;
        }

        $this->payableId = $party->id;
        $this->partySearch = $party->name;
        $this->resetErrorBag('payableId');
    }

    public function clearParty(): void
    {
        $this->payableId = null;
        $this->partySearch = '';
    }

    public function openCreate(): void
    {
        $this->resetForm();

        $this->voucherNumber = $this->generateVoucherNumber();
        $this->paymentDate = now()->format('Y-m-d');

        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $payment = Payment::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('type', 'receipt')
            ->where('payable_type', Party::class)
            ->with('payable')
            ->findOrFail($id);

        $this->paymentId = $payment->id;
        $this->voucherNumber = $payment->voucher_number;
        $this->payableId = $payment->payable_id;
        $this->partySearch = $payment->payable?->name ?? '';
        $this->amount = (string) $payment->amount;
        $this->paymentMethod = $payment->payment_method ?? 'cash';
        $this->paymentDate = optional($payment->payment_date)->format('Y-m-d')
            ?: now()->format('Y-m-d');
        $this->notes = $payment->notes ?? '';

        $this->resetValidation();
        $this->showForm = true;
    }

    public function saveReceipt(): void
    {
        $this->validate([
            'payableId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paymentMethod' => ['required', 'string', 'max:50'],
            'paymentDate' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'payableId.required' => 'يرجى اختيار العميل.',
            'amount.required' => 'يرجى إدخال مبلغ السند.',
            'amount.numeric' => 'المبلغ يجب أن يكون رقمًا.',
            'amount.gt' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'paymentDate.required' => 'يرجى تحديد تاريخ السند.',
        ]);

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->addError('general', 'تعذر تحديد المتجر الحالي.');
            return;
        }

        try {
            DB::transaction(function () use ($tenantId) {
                $party = Party::query()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereIn('type', ['customer', 'both'])
                    ->lockForUpdate()
                    ->find($this->payableId);

                if (!$party) {
                    throw new \RuntimeException('العميل المحدد غير موجود أو غير فعال.');
                }

                $newAmount = (float) $this->amount;

                if ($this->paymentId) {
                    $payment = Payment::query()
                        ->where('tenant_id', $tenantId)
                        ->where('type', 'receipt')
                        ->where('payable_type', Party::class)
                        ->lockForUpdate()
                        ->find($this->paymentId);

                    if (!$payment) {
                        throw new \RuntimeException('سند القبض غير موجود.');
                    }

                    $oldPartyId = $payment->payable_id;
                    $oldAmount = (float) $payment->amount;

                    if ($oldPartyId === $party->id) {
                        $party->current_balance = (float) $party->current_balance + $oldAmount - $newAmount;
                        $party->save();
                    } else {
                        $oldParty = Party::query()
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->find($oldPartyId);

                        if ($oldParty) {
                            $oldParty->current_balance = (float) $oldParty->current_balance + $oldAmount;
                            $oldParty->save();
                        }

                        $party->current_balance = (float) $party->current_balance - $newAmount;
                        $party->save();
                    }

                    $payment->update([
                        'voucher_number' => $this->voucherNumber ?: $payment->voucher_number,
                        'payable_type' => Party::class,
                        'payable_id' => $party->id,
                        'amount' => $newAmount,
                        'payment_method' => $this->paymentMethod,
                        'payment_date' => $this->paymentDate,
                        'notes' => $this->notes ?: null,
                    ]);
                } else {
                    $voucherNumber = $this->voucherNumber ?: $this->generateVoucherNumber();

                    Payment::create([
                        'tenant_id' => $tenantId,
                        'voucher_number' => $voucherNumber,
                        'type' => 'receipt',
                        'payable_type' => Party::class,
                        'payable_id' => $party->id,
                        'amount' => $newAmount,
                        'payment_method' => $this->paymentMethod,
                        'payment_date' => $this->paymentDate,
                        'notes' => $this->notes ?: null,
                        'created_by' => Auth::id(),
                    ]);

                    $party->current_balance = (float) $party->current_balance - $newAmount;
                    $party->save();
                }
            });

            $this->showForm = false;

            $this->dispatch(
                'accounting-toast',
                type: 'success',
                message: $this->paymentId
                    ? 'تم تحديث سند القبض بنجاح.'
                    : 'تم حفظ سند القبض بنجاح.'
            );

            $this->resetForm();
        } catch (\Throwable $e) {
            report($e);

            $this->addError(
                'general',
                $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'تعذر حفظ سند القبض. يرجى المحاولة مرة أخرى.'
            );
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (!$this->deleteId) {
            return;
        }

        $tenantId = $this->getTenantId();

        try {
            DB::transaction(function () use ($tenantId) {
                $payment = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('type', 'receipt')
                    ->where('payable_type', Party::class)
                    ->lockForUpdate()
                    ->find($this->deleteId);

                if (!$payment) {
                    throw new \RuntimeException('سند القبض غير موجود.');
                }

                $party = Party::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->find($payment->payable_id);

                if ($party) {
                    $party->current_balance = (float) $party->current_balance + (float) $payment->amount;
                    $party->save();
                }

                $payment->delete();
            });

            $this->showDeleteModal = false;
            $this->deleteId = null;

            $this->dispatch(
                'accounting-toast',
                type: 'success',
                message: 'تم حذف سند القبض وعكس أثره على الرصيد.'
            );
        } catch (\Throwable $e) {
            report($e);

            $this->addError(
                'general',
                $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'تعذر حذف سند القبض.'
            );
        }
    }

    public function print(int $id): void
    {
        $this->printVoucher = Payment::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('type', 'receipt')
            ->where('payable_type', Party::class)
            ->with('payable', 'user')
            ->findOrFail($id);

        $this->showPrintModal = true;
    }

    public function sendWhatsapp(int $id): void
    {
        $payment = Payment::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('type', 'receipt')
            ->where('payable_type', Party::class)
            ->with('payable')
            ->findOrFail($id);

        $phone = preg_replace('/\D+/', '', (string) $payment->payable?->phone);

        if (!$phone) {
            $this->addError('general', 'لا يوجد رقم هاتف مسجل لهذا العميل.');
            return;
        }

        $message = implode("\n", [
            'سند قبض',
            'رقم السند: ' . $payment->voucher_number,
            'العميل: ' . ($payment->payable?->name ?? '-'),
            'المبلغ: ' . number_format((float) $payment->amount, 2),
            'طريقة القبض: ' . $this->paymentMethodLabel($payment->payment_method),
            'التاريخ: ' . optional($payment->payment_date)->format('Y-m-d'),
        ]);

        $url = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);

        $this->dispatch('open-whatsapp', url: $url);
    }

    public function resetForm(): void
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

        $this->paymentMethod = 'cash';
        $this->paymentDate = now()->format('Y-m-d');

        $this->resetValidation();
    }

    private function generateVoucherNumber(): string
    {
        $lastId = Payment::query()
            ->where('tenant_id', $this->getTenantId())
            ->where('type', 'receipt')
            ->max('id');

        return 'REC-' . str_pad((string) (($lastId ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'نقدي',
            'bank' => 'تحويل بنكي',
            'card' => 'بطاقة',
            'check' => 'شيك',
            default => $method ?: '-',
        };
    }

    public function render(): mixed
    {
        $tenantId = $this->getTenantId();

        $receipts = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'receipt')
            ->where('payable_type', Party::class)
            ->with('payable')
            ->when(trim($this->tableSearch) !== '', function ($query) {
                $search = trim($this->tableSearch);

                $query->where(function ($q) use ($search) {
                    $q->where('voucher_number', 'like', "%{$search}%")
                        ->orWhereHas('payable', function ($partyQuery) use ($search) {
                            $partyQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate(15);

        return $this->view([
            'receipts' => $receipts,
        ])->layout('layouts::tenant');
    }
};
?>
<flux:main class="space-y-6">

<div dir="rtl" class="min-h-full bg-slate-50">
    @include('pages.tenant.accounting.partials.voucher-table', [
        'mode' => 'receipt',
        'title' => 'سندات القبض',
        'subtitle' => 'إدارة المقبوضات من العملاء وحركة الأرصدة',
        'createLabel' => 'سند قبض جديد',
        'items' => $receipts,
        'todayTotal' => $this->todayTotal,
        'voucherCount' => $this->voucherCount,
        'tableSearch' => $tableSearch,
    ])

    @include('pages.tenant.accounting.partials.voucher-form', [
        'mode' => 'receipt',
        'title' => $paymentId ? 'تعديل سند القبض' : 'إنشاء سند قبض',
        'partyLabel' => 'العميل',
        'partyTypes' => ['customer', 'both'],
        'saveMethod' => 'saveReceipt',
        'selectedParty' => $this->selectedParty,
        'partyResults' => $this->partyResults,
        'showForm' => $showForm,
    ])

    @include('pages.tenant.accounting.partials.voucher-print', [
        'mode' => 'receipt',
        'voucher' => $printVoucher,
        'show' => $showPrintModal,
    ])

    @if ($showDeleteModal)
        <div class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">
                <div class="p-6">
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-rose-100 text-rose-600">
                        <flux:icon name="trash" class="size-6" />
                    </div>

                    <h3 class="text-lg font-bold text-slate-900">حذف سند القبض؟</h3>

                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        سيتم حذف السند وعكس أثره على رصيد العميل.
                        <strong>الرصيد الافتتاحي لن يتغير.</strong>
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <flux:button type="button" variant="ghost" wire:click="$set('showDeleteModal', false)">
                        إلغاء
                    </flux:button>

                    <flux:button type="button" variant="danger" wire:click="delete">
                        حذف السند
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    <div
        x-data
        x-on:open-whatsapp.window="window.open($event.detail.url, '_blank')"
    ></div>
</div>
</flux:main>
