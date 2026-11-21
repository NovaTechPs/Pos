<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    /*
    |--------------------------------------------------------------------------
    | List State
    |--------------------------------------------------------------------------
    */

    public string $search = '';

    public int $perPage = 10;
    public string $typeFilter = '';
    public string $statusFilter = 'active';
    public string $balanceFilter = '';
    public string $sort = 'latest';


    /*
    |--------------------------------------------------------------------------
    | Create / Edit State
    |--------------------------------------------------------------------------
    */

    public bool $showModal = false;

    public ?int $partyId = null;

    public string $name = '';

    public ?string $phone = null;

    public ?string $email = null;

    public ?string $address = null;

    public ?string $tax_number = null;

    public string $type = 'customer';

    public float $opening_balance = 0.0;


    /*
    |--------------------------------------------------------------------------
    | Statement State
    |--------------------------------------------------------------------------
    */

    public bool $showStatementModal = false;

    public ?Party $selectedPartyForStatement = null;


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'address' => [
                'nullable',
                'string',
                'max:255',
            ],

            'tax_number' => [
                'nullable',
                'string',
                'max:50',
            ],

            'type' => [
                'required',
                'in:customer,supplier,both',
            ],

            'opening_balance' => [
                'nullable',
                'numeric',
            ],
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    /**
     * الحصول على الـ Tenant النشط.
     *
     * الأولوية:
     * 1. active_tenant_id من Session
     * 2. tenant_id من المستخدم
     * 3. أول Tenant مرتبط بالمستخدم
     */
    private function getTenantId(): ?int
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $activeTenantId = session('active_tenant_id');

        if ($activeTenantId) {
            return (int) $activeTenantId;
        }

        if (!empty($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        if (method_exists($user, 'tenants')) {
            return $user->tenants()->first()?->id;
        }

        return null;
    }


    /**
     * الحصول على اسم المتجر الحالي.
     */
    private function getTenantName(): string
    {
        $user = auth()->user();

        if (!$user) {
            return '';
        }

        return $user->tenant?->name
            ?? $user->tenants?->first()?->name
            ?? '';
    }


    /*
    |--------------------------------------------------------------------------
    | Lifecycle / Pagination
    |--------------------------------------------------------------------------
    */

    public function updated(string $property): void
    {
        if (in_array($property, ['typeFilter', 'statusFilter', 'balanceFilter', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'typeFilter', 'statusFilter', 'balanceFilter', 'sort', 'perPage');
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }


    public function updatingPerPage(): void
    {
        $this->resetPage();
    }


    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    /**
     * فتح نموذج إنشاء طرف جديد.
     */
    public function openCreateModal(): void
    {
        $this->resetValidation();

        $this->resetForm();

        $this->showModal = true;
    }


    /**
     * فتح نموذج تعديل الطرف.
     */
    public function editParty(int $id): void
    {
        $this->resetValidation();

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'تعذر تحديد المتجر الحالي.'
            );

            return;
        }

        $party = Party::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->partyId = $party->id;

        $this->name = $party->name;

        $this->phone = $party->phone;

        $this->email = $party->email;

        $this->address = $party->address;

        $this->tax_number = $party->tax_number;

        $this->type = $party->type;

        /*
         * نعرض الرصيد الافتتاحي فقط.
         *
         * لا يتم استخدامه لتعديل current_balance.
         */
        $this->opening_balance = (float) $party->opening_balance;

        $this->showModal = true;
    }


    /**
     * إغلاق نموذج الإضافة / التعديل.
     */
    public function closeModal(): void
    {
        $this->showModal = false;

        $this->resetForm();
    }


    /**
     * إعادة النموذج للوضع الافتراضي.
     */
    public function resetForm(): void
    {
        $this->reset([
            'partyId',
            'name',
            'phone',
            'email',
            'address',
            'tax_number',
            'type',
            'opening_balance',
        ]);

        $this->type = 'customer';

        $this->opening_balance = 0.0;
    }


    /**
     * حفظ الطرف.
     */
    public function save(): void
    {
        $this->authorize($this->partyId ? 'customers.edit' : 'customers.create');
        $validated = $this->validate();

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'تعذر تحديد المتجر الحالي.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */

        if ($this->partyId) {
            /*
             * تعديل الرصيد الافتتاحي يحرّك الرصيد الحالي بنفس الفرق،
             * حتى يبقى كشف الحساب مطابقاً للرصيد المسجّل.
             * current_balance لا يُعدَّل مباشرة من هنا.
             */
            unset($validated['current_balance']);

            DB::transaction(function () use ($validated, $tenantId): void {
                $party = Party::query()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($this->partyId);

                $newOpeningBalance = round((float) ($validated['opening_balance'] ?? 0), 2);
                $difference = round($newOpeningBalance - (float) $party->opening_balance, 2);
                $validated['opening_balance'] = $newOpeningBalance;

                if (abs($difference) >= 0.005) {
                    $validated['current_balance'] = round((float) $party->current_balance + $difference, 2);
                }

                $party->update($validated);
            });

            session()->flash(
                'message',
                'تم تعديل بيانات الطرف بنجاح.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        else {
            $openingBalance = (float) (
                $validated['opening_balance'] ?? 0
            );

            DB::transaction(function () use (
                $validated,
                $tenantId,
                $openingBalance
            ) {
                $validated['tenant_id'] = $tenantId;

                /*
                 * عند إنشاء الطرف:
                 *
                 * opening_balance = الرصيد الافتتاحي
                 * current_balance = الرصيد الافتتاحي
                 */
                $validated['current_balance'] = $openingBalance;

                Party::create($validated);
            });

            session()->flash(
                'message',
                'تمت إضافة الطرف بنجاح.'
            );
        }

        $this->closeModal();
    }


    /*
    |--------------------------------------------------------------------------
    | Delete / Deactivate
    |--------------------------------------------------------------------------
    */

    /**
     * Customers and suppliers are never deleted, so their statements and history stay complete;
     * a party that is no longer used is deactivated instead.
     */
    public function deleteParty(int $id): void
    {
        session()->flash('error', 'لا يمكن حذف الزبون أو المورد. يمكنك تعطيله بدلاً من الحذف.');
    }

    /**
     * تعطيل الطرف بدل حذفه.
     */
    public function deactivateParty(int $id): void
    {
        $this->authorize('customers.delete');
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'تعذر تحديد المتجر الحالي.'
            );

            return;
        }

        $party = Party::query()
            ->where('tenant_id', $tenantId)
            ->find($id);

        if (!$party) {
            return;
        }

        $party->update([
            'is_active' => false,
        ]);

        session()->flash(
            'message',
            'تم تعطيل الطرف بنجاح.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Statement
    |--------------------------------------------------------------------------
    */

    /**
     * تحميل Party مع البيانات اللازمة لكشف الحساب.
     */
    private function getPartyForStatement(int $partyId): Party
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            abort(403);
        }

        return Party::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'orders' => function ($query) use ($tenantId) {
                    $query->withoutGlobalScope('tenant_id')->where('tenant_id', $tenantId)->where('status', 'completed');
                    $query->select(
                        'id',
                        'customer_id',
                        'type',
                        'total',
                        'paid_amount',
                        'delivery_fee',
                        'invoice_date',
                        'invoice_number',
                        'notes',
                        'created_at'
                    );
                },

                'payments' => function ($query) use ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                    $query->select(
                        'id',
                        'party_id',
                        'type',
                        'voucher_number',
                        'amount',
                        'payment_method',
                        'order_id',
                        'purchase_id',
                        'purchase_return_id',
                        'created_at',
                        'payment_date',
                        'notes'
                    );
                },
                'purchases' => function ($query) use ($tenantId) {
                    $query->withoutGlobalScope('tenant_id')->where('tenant_id', $tenantId);
                    $query->select(
                        'id',
                        'tenant_id',
                        'supplier_id',
                        'reference_number',
                        'total',
                        'paid_amount',
                        'invoice_date',
                        'notes',
                        'created_at'
                    );
                },
                'purchaseReturns' => function ($query) use ($tenantId) {
                    $query->where('tenant_id', $tenantId)->select(
                        'id',
                        'tenant_id',
                        'supplier_id',
                        'reference_number',
                        'total',
                        'notes',
                        'created_at'
                    );
                },
            ])
            ->findOrFail($partyId);
    }


    /**
     * بناء جميع حركات كشف الحساب.
     *
     * هذه الدالة هي المصدر الموحد:
     *
     * - شاشة كشف الحساب
     * - WhatsApp
     * - الطباعة الحرارية
     */
    private function buildStatementTransactions(
        Party $party
    ): Collection {
        /*
        | Every document is first signed as "owed to us" (positive) or "owed by us" (negative),
        | the same way as the statement report. It is then shown in the party's own direction:
        | a customer's debit is what they owe us; a supplier's debit is what we owe them, which is
        | also how their recorded balance is kept.
        */
        $supplierView = $party->type === 'supplier';
        $transactions = collect();
        $push = function (string $id, string $sourceType, int $sourceId, mixed $date, string $description, ?string $notes, float $owedToUs) use ($transactions, $supplierView): void {
            if (abs($owedToUs) < 0.005) {
                return;
            }
            $amount = $supplierView ? -$owedToUs : $owedToUs;
            $transactions->push([
                'id' => $id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'date' => $date,
                'description' => $description,
                'notes' => $notes,
                'debit' => $amount > 0 ? round($amount, 2) : 0.00,
                'credit' => $amount < 0 ? round(-$amount, 2) : 0.00,
            ]);
        };

        /*
        |--------------------------------------------------------------------------
        | Sales invoices and returns
        |--------------------------------------------------------------------------
        */

        $linkedReceipts = \App\Models\Payment::query()->where('tenant_id', $party->tenant_id)->where('type', 'receipt')
            ->whereIn('order_id', $party->orders->modelKeys())->selectRaw('order_id, SUM(amount) as received')
            ->groupBy('order_id')->pluck('received', 'order_id');

        foreach ($party->orders as $order) {
            $isReturn = $order->type === 'return';
            $number = $order->invoice_number ?: $order->id;
            $date = $order->invoice_date ?? $order->created_at;
            $amount = max(0, abs((float) $order->total) - (float) $order->delivery_fee);

            $push('order-'.$order->id, 'order', $order->id, $date,
                ($isReturn ? 'مردود مبيعات #' : 'فاتورة مبيعات #').$number, $order->notes, $isReturn ? -$amount : $amount);

            if (! $isReturn) {
                // Paid on the invoice itself without a receipt voucher: shown as its own line. Linked vouchers are listed on their own.
                $paidOnInvoice = max(0, (float) $order->paid_amount - (float) $order->delivery_fee - (float) ($linkedReceipts[$order->id] ?? 0));
                $push('order-paid-'.$order->id, 'order', $order->id, $date, 'سند قبض — فاتورة المبيعات #'.$number, null, -min($amount, $paidOnInvoice));
            }
        }

        // Delivery fees the store owes a delivery company.
        if (in_array($party->type, ['supplier', 'both'], true)) {
            Order::query()
                ->where('tenant_id', $party->tenant_id)
                ->where('delivery_party_id', $party->id)
                ->where('status', 'completed')
                ->where('delivery_fee', '>', 0)
                ->get()
                ->each(fn (Order $order) => $push('delivery-'.$order->id, 'delivery', $order->id, $order->invoice_date ?? $order->created_at,
                    'رسوم توصيل فاتورة #'.($order->invoice_number ?: $order->id), $order->notes, -(float) $order->delivery_fee));
        }

        /*
        |--------------------------------------------------------------------------
        | Purchases and purchase returns
        |--------------------------------------------------------------------------
        */

        $linkedPurchasePayments = \App\Models\Payment::query()->where('tenant_id', $party->tenant_id)
            ->whereIn('purchase_id', $party->purchases->modelKeys())
            ->selectRaw("purchase_id, SUM(CASE WHEN type = 'payment' THEN amount ELSE -amount END) as paid")
            ->groupBy('purchase_id')->pluck('paid', 'purchase_id');

        foreach ($party->purchases as $purchase) {
            $number = $purchase->reference_number ?: $purchase->id;
            $date = $purchase->invoice_date ?? $purchase->created_at;
            $total = abs((float) $purchase->total);
            $push('purchase-'.$purchase->id, 'purchase', $purchase->id, $date, 'فاتورة مشتريات #'.$number, $purchase->notes, -$total);
            $paidOnInvoice = max(0, (float) $purchase->paid_amount - (float) ($linkedPurchasePayments[$purchase->id] ?? 0));
            $push('purchase-paid-'.$purchase->id, 'purchase', $purchase->id, $date, 'سند صرف — فاتورة المشتريات #'.$number, null, min($total, $paidOnInvoice));
        }

        foreach ($party->purchaseReturns as $purchaseReturn) {
            $push('purchase-return-'.$purchaseReturn->id, 'purchase_return', $purchaseReturn->id, $purchaseReturn->created_at,
                'مردود مشتريات #'.($purchaseReturn->reference_number ?: $purchaseReturn->id), $purchaseReturn->notes, abs((float) $purchaseReturn->total));
        }

        /*
        |--------------------------------------------------------------------------
        | Vouchers: a receipt lowers what the party owes us, a payment raises it.
        |--------------------------------------------------------------------------
        */

        foreach ($party->payments as $payment) {
            $amount = abs((float) $payment->amount);
            $description = $payment->purchase_return_id
                ? 'سند قبض — مردود مشتريات'
                : $this->paymentDescription($payment, $payment->type === 'payment' && ($payment->purchase_id !== null || ($payment->order_id === null && in_array($party->type, ['supplier', 'both'], true))));
            $push('payment-'.$payment->id, 'payment', $payment->id, $payment->payment_date ?: $payment->created_at,
                $description, $payment->notes, $payment->type === 'receipt' ? -$amount : $amount);
        }

        /*
        |--------------------------------------------------------------------------
        | Sort
        |--------------------------------------------------------------------------
        */

        return $transactions
            ->sortBy(function ($transaction) {
                return $transaction['date']
                    ? \Carbon\Carbon::parse($transaction['date'])->timestamp
                    : 0;
            })
            ->values();
    }


    /**
     * وصف الدفعة.
     */
    private function paymentDescription($payment, bool $isSupplier = false): string
    {
        $description = $payment->type === 'receipt' ? 'سند قبض' : 'سند صرف';

        if (! empty($payment->voucher_number)) {
            $description .= ' #'.$payment->voucher_number;
        }

        if (! empty($payment->order_id)) {
            $description .= ' — للفاتورة #'.$payment->order_id;
        }

        return $description;
    }


    /**
     * حساب الرصيد النهائي من كشف الحساب.
     */
    private function calculateStatementBalance(
        Party $party,
        Collection $transactions
    ): float {
        $balance = (float) $party->opening_balance;

        foreach ($transactions as $transaction) {
            $balance += (float) $transaction['debit'];

            $balance -= (float) $transaction['credit'];
        }

        return $balance;
    }


    /**
     * إنشاء بيانات كاملة للكشف.
     */
    private function buildStatementData(Party $party): array
    {
        $transactions = $this->buildStatementTransactions($party);

        $finalBalance = $this->calculateStatementBalance(
            $party,
            $transactions
        );

        return [
            'party' => $party,

            'transactions' => $transactions,

            'opening_balance' => (float) $party->opening_balance,

            'current_balance' => (float) $party->current_balance,

            'final_balance' => $finalBalance,

            'difference' => round(
                $finalBalance - (float) $party->current_balance,
                2
            ),
        ];
    }


    /**
     * فتح كشف الحساب.
     */
    public function openStatementModal(int $id): void
    {
        $this->selectedPartyForStatement =
            $this->getPartyForStatement($id);

        $this->showStatementModal = true;
    }

    public function reconcilePartyBalance(int $id): void
    {
        $user = auth()->user();
        abort_unless($user?->isTenantOwner() || $user?->isSaaSAdmin(), 403);

        $tenantId = $this->getTenantId();
        abort_unless($tenantId, 404);

        $party = DB::transaction(function () use ($tenantId, $id): Party {
            $party = Party::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($id);
            // Reconcile against the same ledger the statement report uses, so both always agree.
            $party->current_balance = (new \App\Services\ReportingService(auth()->user(), null, false))->partyLedgerBalance($party->id);
            $party->save();

            return $party->fresh();
        });

        $this->selectedPartyForStatement = $party;
        $this->dispatch('toast', type: 'success', message: 'تمت تسوية الرصيد مع حركات كشف الحساب.');
    }


    /**
     * إغلاق كشف الحساب.
     */
    public function closeStatementModal(): void
    {
        $this->showStatementModal = false;

        if ($this->selectedPartyForStatement) {
            $this->dispatch('statement-closed', id: $this->selectedPartyForStatement->id);
        }

        $this->selectedPartyForStatement = null;
    }

    public function openStatementDocument(string $sourceType, int $sourceId): void
    {
        $tenantId = $this->getTenantId();
        $partyId = $this->selectedPartyForStatement?->id;

        abort_unless($tenantId && $partyId, 404);

        if ($sourceType === 'order') {
            $order = Order::query()->where('tenant_id', $tenantId)->where('customer_id', $partyId)->findOrFail($sourceId);
            $route = $order->type === 'return' ? 'tenant.pos' : 'tenant.sale-invoices';

            $this->dispatch(
                'open-statement-document',
                url: route($route, $route === 'tenant.pos' ? ['invoice' => $order->id, 'customer_copy' => 1] : ['invoice' => $order->id]),
                title: 'فاتورة مبيعات '.$order->invoice_number,
            );

            return;
        }

        if ($sourceType === 'delivery') {
            $order = Order::query()->where('tenant_id', $tenantId)->where('delivery_party_id', $partyId)->findOrFail($sourceId);
            $this->dispatch(
                'open-statement-document',
                url: route('tenant.sale-invoices', ['invoice' => $order->id]),
                title: 'فاتورة مبيعات '.$order->invoice_number,
            );

            return;
        }

        if ($sourceType === 'purchase') {
            Purchase::query()->where('tenant_id', $tenantId)->where('supplier_id', $partyId)->findOrFail($sourceId);
            $this->dispatch(
                'open-statement-document',
                url: route('tenant.purchase-invoices', ['purchase' => $sourceId]),
                title: 'فاتورة مشتريات',
            );

            return;
        }

        if ($sourceType === 'purchase_return') {
            PurchaseReturn::query()->where('tenant_id', $tenantId)->where('supplier_id', $partyId)->findOrFail($sourceId);
            $this->dispatch(
                'open-statement-document',
                url: route('tenant.purchase-returns', ['return' => $sourceId]),
                title: 'مرتجع مشتريات',
            );

            return;
        }

        $payment = Payment::query()->where('tenant_id', $tenantId)->where('party_id', $partyId)->findOrFail($sourceId);
        $this->dispatch(
            'open-statement-document',
            url: route($payment->type === 'receipt' ? 'tenant.receipts' : 'tenant.payment', ['voucher' => $payment->id]),
            title: $payment->type === 'receipt' ? 'سند قبض' : 'سند صرف',
        );
    }


    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    /**
     * إرسال كشف الحساب عبر WhatsApp.
     */
    public function sendStatementWhatsapp(int $partyId): void
    {
        $party = $this->getPartyForStatement($partyId);

        $phone = preg_replace(
            '/[^0-9]/',
            '',
            (string) $party->phone
        );

        if ($phone === '') {
            session()->flash(
                'error',
                'لا يوجد رقم هاتف مسجل لهذا الطرف.'
            );

            return;
        }

        $statement = $this->buildStatementData($party);

        $storeName = $this->getTenantName();

        $msg = "📜 *كشف حساب*\n";

        if ($storeName !== '') {
            $msg .= "المتجر: *{$storeName}*\n";
        }

        $msg .= "----------------------------\n";

        $msg .= "الطرف: {$party->name}\n";

        $msg .= "النوع: " . $this->partyTypeLabel($party->type) . "\n";

        $msg .= "الهاتف: " . ($party->phone ?? '-') . "\n";

        $msg .= "التاريخ: " . now()->format('Y-m-d H:i') . "\n";

        $msg .= "----------------------------\n";

        $msg .= "الرصيد الافتتاحي: "
            . number_format(
                $statement['opening_balance'],
                2
            )
            . " شيكل\n";


        foreach ($statement['transactions'] as $transaction) {
            $date = $transaction['date']
                ? \Carbon\Carbon::parse($transaction['date'])->format('Y-m-d')
                : '-';

            $msg .= "• {$date} | "
                . "{$transaction['description']} | "
                . "مدين: "
                . number_format(
                    $transaction['debit'],
                    2
                )
                . " | "
                . "دائن: "
                . number_format(
                    $transaction['credit'],
                    2
                )
                . "\n";

            if (trim((string) ($transaction['notes'] ?? '')) !== '') {
                $msg .= "  ملاحظات: {$transaction['notes']}\n";
            }
        }


        $msg .= "----------------------------\n";

        $msg .= "الرصيد الحالي: *"
            . number_format(
                $statement['current_balance'],
                2
            )
            . " شيكل*\n";

        $msg .= "الرصيد المحسوب: *"
            . number_format(
                $statement['final_balance'],
                2
            )
            . " شيكل*\n";

        /*
         * في حال وجود فرق.
         */
        if (abs($statement['difference']) > 0.009) {
            $msg .= "⚠️ يوجد فرق: "
                . number_format(
                    $statement['difference'],
                    2
                )
                . " شيكل\n";
        }

        $msg .= "شكراً لتعاملكم معنا 🙏";


        $whatsappUrl = "https://wa.me/{$phone}?text="
            . urlencode($msg);


        $this->dispatch(
            'open-whatsapp',
            url: $whatsappUrl
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Thermal Print
    |--------------------------------------------------------------------------
    */

    /**
     * طباعة كشف الحساب حراريًا باستخدام RawBT.
     */
    public function printStatementThermal(int $partyId): void
    {
        $party = $this->getPartyForStatement($partyId);

        $statement = $this->buildStatementData($party);

        $itemsFormatted = [];


        foreach ($statement['transactions'] as $transaction) {
            $itemsFormatted[] = [
                'date' => $transaction['date']
                    ? \Carbon\Carbon::parse(
                        $transaction['date']
                    )->format('Y-m-d')
                    : '-',

                'desc' => $transaction['description'],

                'notes' => $transaction['notes'] ?? '',

                'debit' => number_format(
                    $transaction['debit'],
                    2
                ),

                'credit' => number_format(
                    $transaction['credit'],
                    2
                ),

                'bal' => number_format(
                    $this->calculateBalanceUntilTransaction(
                        $party,
                        $statement['transactions'],
                        $transaction['id']
                    ),
                    2
                ),
            ];
        }


        $statementData = [
            'store_name' => $this->getTenantName(),

            'party_name' => $party->name,

            'party_type' => $this->partyTypeLabel(
                $party->type
            ),

            'party_phone' => $party->phone ?? '-',

            'opening_balance' => number_format(
                $statement['opening_balance'],
                2
            ),

            'current_balance' => number_format(
                $statement['current_balance'],
                2
            ),

            'final_balance' => number_format(
                $statement['final_balance'],
                2
            ),

            'difference' => number_format(
                $statement['difference'],
                2
            ),

            'date' => now()->format('Y-m-d H:i'),

            'items' => $itemsFormatted,
        ];


        $this->dispatch(
            'do-statement-print',
            data: $statementData
        );
    }


    /**
     * حساب الرصيد حتى حركة معينة.
     */
    private function calculateBalanceUntilTransaction(
        Party $party,
        Collection $transactions,
        string $transactionId
    ): float {
        $balance = (float) $party->opening_balance;

        foreach ($transactions as $transaction) {
            $balance += (float) $transaction['debit'];

            $balance -= (float) $transaction['credit'];

            if ($transaction['id'] === $transactionId) {
                break;
            }
        }

        return $balance;
    }


    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * ترجمة نوع الطرف.
     */
    private function partyTypeLabel(?string $type): string
    {
        return match ($type) {
            'customer' => 'زبون',
            'supplier' => 'مورد',
            'both' => 'زبون ومورد',
            default => '-',
        };
    }


    /**
     * لون الرصيد.
     */
    private function balanceClass(float $balance): string
    {
        if ($balance > 0) {
            return 'text-red-600 dark:text-red-400';
        }

        if ($balance < 0) {
            return 'text-emerald-600 dark:text-emerald-400';
        }

        return 'text-gray-600 dark:text-gray-300';
    }


    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->getTenantId();

        $query = Party::query()
            ->where('tenant_id', $tenantId);

        /*
         * عرض الأطراف النشطة فقط.
         */
        if (in_array($this->statusFilter, ['active', 'inactive'], true)) {
            $query->where('is_active', $this->statusFilter === 'active');
        }
        if (in_array($this->typeFilter, ['customer', 'supplier', 'both'], true)) {
            $query->where('type', $this->typeFilter);
        }
        match ($this->balanceFilter) {
            'debit' => $query->where('current_balance', '>', 0),
            'credit' => $query->where('current_balance', '<', 0),
            'settled' => $query->where('current_balance', 0),
            default => null,
        };


        /*
         * البحث.
         */
        if (filled($this->search)) {
            $search = trim($this->search);

            $query->where(function ($q) use ($search) {
                $q->where(
                    'name',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhere(
                    'phone',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhere(
                    'address',
                    'like',
                    '%' . $search . '%'
                )

                ->orWhere(
                    'tax_number',
                    'like',
                    '%' . $search . '%'
                )->orWhere('code', 'like', '%' . $search . '%');
            });
        }


        match ($this->sort) {
            'name' => $query->orderBy('name')->orderBy('id'),
            'balance_desc' => $query->orderByDesc('current_balance')->orderBy('id'),
            'balance_asc' => $query->orderBy('current_balance')->orderBy('id'),
            default => $query->latest('id'),
        };
        // 0 = «الكل»: one page holding every matching party.
        $pageSize = $this->perPage === 0
            ? max(1, (clone $query)->count())
            : (in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);
        $parties = $query->paginate($pageSize);


        return $this->view([
            'parties' => $parties,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">

    <div
        class="p-3 sm:p-6 bg-gray-50 dark:bg-gray-900 min-h-screen space-y-4 sm:space-y-6"
        dir="rtl"
    >

        {{-- =========================================================
            HEADER
        ========================================================== --}}

        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >

            <div>

                <div class="flex items-center gap-2">

                    <h1
                        class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-white"
                    >
                        الزباين والموردون
                    </h1>

                    <span
                        class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300"
                    >
                        {{ $parties->total() }}
                    </span>

                </div>

                <p
                    class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1"
                >
                    قائمة الزباين والموردين
                </p>

            </div>


            <button
                type="button"
                wire:click="openCreateModal"
                class="w-full sm:w-auto justify-center bg-gray-900 hover:bg-black dark:bg-gray-700 dark:hover:bg-gray-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium flex items-center gap-2 shadow-sm transition"
            >

                <span>
                    إضافة طرف جديد
                </span>

                <span class="text-lg leading-none">
                    +
                </span>

            </button>

        </div>


        {{-- =========================================================
            FLASH MESSAGES
        ========================================================== --}}

        @if (session()->has('message'))

            <div
                class="p-4 text-sm text-green-800 bg-green-100 rounded-lg border border-green-200 dark:bg-gray-800 dark:text-green-400 dark:border-green-800"
            >
                {{ session('message') }}
            </div>

        @endif


        @if (session()->has('error'))

            <div
                class="p-4 text-sm text-red-800 bg-red-100 rounded-lg border border-red-200 dark:bg-gray-800 dark:text-red-400 dark:border-red-800"
            >
                {{ session('error') }}
            </div>

        @endif


        {{-- List and statement side by side: picking a customer shows their statement next to the list on wide screens. --}}
        <div data-customers-workspace @class(['customers-workspace', 'has-statement' => $showStatementModal && $selectedPartyForStatement])>
        <div class="customers-list-pane space-y-6">

        {{-- =========================================================
            SEARCH / FILTER
        ========================================================== --}}

        <div
            class="bg-white dark:bg-gray-800 p-3 sm:p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700"
        >

            <div
                class="flex flex-col sm:flex-row gap-3 items-center justify-between"
            >

                <div
                    class="relative w-full sm:w-1/2 md:w-1/3"
                >

                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        x-on:keydown.arrow-down.prevent="document.querySelector('[data-party-row]')?.focus()"
                        placeholder="ابحث بالرمز، الاسم، الهاتف، العنوان أو الرقم الضريبي..."
                        class="w-full pl-4 pr-10 py-2.5 border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600"
                    >

                    <span
                        class="absolute right-3 top-2.5 text-gray-400"
                    >
                        🔍
                    </span>

                    @if (filled($search))

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="absolute left-3 top-2.5 text-gray-400 hover:text-gray-700 dark:hover:text-white"
                            title="مسح البحث"
                        >
                            ×
                        </button>

                    @endif

                </div>


                <div
                    class="flex items-center gap-2 w-full sm:w-auto"
                >

                    <label
                        class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap"
                    >
                        عرض
                    </label>

                    <select
                        wire:model.live="perPage"
                        class="w-full sm:w-auto border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg text-sm p-2 text-gray-600 bg-white"
                    >

                        <option value="10">
                            10 لكل صفحة
                        </option>

                        <option value="25">
                            25 لكل صفحة
                        </option>

                        <option value="50">
                            50 لكل صفحة
                        </option>

                        <option value="100">
                            100 لكل صفحة
                        </option>

                        <option value="0">
                            الكل
                        </option>

                    </select>

                </div>

            </div>

        </div>


        <div class="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-700 dark:bg-gray-800">
            <label class="text-xs">نوع الطرف<select wire:model.live="typeFilter" class="mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 text-sm dark:bg-gray-700"><option value="">الكل</option><option value="customer">زبون</option><option value="supplier">مورد</option><option value="both">زبون ومورد</option></select></label>
            <label class="text-xs">الحالة<select wire:model.live="statusFilter" class="mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 text-sm dark:bg-gray-700"><option value="">كل الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></select></label>
            <label class="text-xs">الترتيب<select wire:model.live="sort" class="mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 text-sm dark:bg-gray-700"><option value="latest">الأحدث</option><option value="name">الاسم</option></select></label>
            <button type="button" wire:click="clearFilters" class="self-end rounded-lg border border-gray-200 p-2 text-sm">مسح الفلاتر</button>
        </div>

        {{-- =========================================================
            TABLE
        ========================================================== --}}

        <div
            class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-x-auto"
        >

            <div class="app-table-scroll" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">

            <table
                class="w-full text-right border-collapse min-w-[900px] dark:text-gray-300"
            >

                <thead>

                    <tr
                        class="bg-gray-50 dark:bg-gray-700 border-b border-gray-100 dark:border-gray-600 text-xs font-semibold text-gray-500 dark:text-gray-300"
                    >

                        <th data-party-extra class="p-3 sm:p-4">
                            #
                        </th>

                        <th class="p-3 sm:p-4">
                            رمز الزبون
                        </th>

                        <th class="p-3 sm:p-4">
                            الطرف
                        </th>

                        <th data-party-extra class="p-3 sm:p-4">
                            النوع
                        </th>

                        <th data-party-extra class="p-3 sm:p-4">
                            الهاتف
                        </th>

                        <th data-party-extra class="p-3 sm:p-4 hidden sm:table-cell">
                            العنوان
                        </th>

                        <th data-party-extra class="p-3 sm:p-4 text-center">
                            الإجراءات
                        </th>

                    </tr>

                </thead>


                <tbody
                    x-data data-party-rows data-bold-rows x-on:statement-closed.window="$nextTick(() => $el.querySelector(`[data-party-row='${$event.detail.id}']`)?.focus())" x-on:keydown.arrow-down.prevent="(rows => rows[Math.min(rows.length - 1, rows.indexOf(document.activeElement) + 1)]?.focus())([...$el.querySelectorAll('[data-nav-row]')])" x-on:keydown.arrow-up.prevent="(rows => rows[Math.max(0, rows.indexOf(document.activeElement) - 1)]?.focus())([...$el.querySelectorAll('[data-nav-row]')])"
                    class="divide-y divide-gray-100 dark:divide-gray-700 text-xs sm:text-sm [&_td]:font-extrabold! [&_td_div]:font-extrabold! [&_td]:text-[#172554] [&_td_div]:text-[#172554] dark:[&_td]:text-blue-200 dark:[&_td_div]:text-blue-200"
                >

                    @forelse ($parties as $party)

                        <tr
                            wire:key="party-{{ $party->id }}"
                            tabindex="0" data-nav-row data-party-row="{{ $party->id }}" x-on:keydown.enter.self.prevent="$wire.openStatementModal({{ $party->id }})" x-on:click="if (! $event.target.closest('button, a, input, select')) $wire.openStatementModal({{ $party->id }})" @if ($showStatementModal && $selectedPartyForStatement?->id === $party->id) data-statement-party-active @endif
                            class="group hover:bg-gray-50/50 transition focus:bg-[#123b86]! focus:shadow-[inset_0_0_0_2px_#0c2f70] focus:outline-none focus:[&>td]:text-white! focus:[&>td_*:not([data-party-type-badge])]:text-white! dark:hover:bg-gray-700/50"
                        >

                            <td data-party-extra
                                class="p-3 sm:p-4 text-gray-400"
                            >
                                {{ $parties->firstItem() + $loop->index }}
                            </td>

                            <td class="p-3 sm:p-4 font-semibold whitespace-nowrap" dir="ltr" data-party-code="{{ $party->code }}">
                                {{ $party->code ?? '-' }}
                            </td>


                            <td
                                class="p-3 sm:p-4"
                            >

                                <div class="font-semibold text-gray-800 dark:text-white">
                                    {{ $party->name }}
                                </div>

                                @if ($party->email)

                                    <div
                                        class="text-[11px] text-gray-400 mt-0.5"
                                    >
                                        {{ $party->email }}
                                    </div>

                                @endif

                            </td>


                            <td data-party-extra class="p-3 sm:p-4 whitespace-nowrap">

                                @if ($party->type === 'customer')

                                    <span
                                        data-party-type-badge
                                        class="bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 group-focus:bg-white! group-focus:text-[#123b86]! rounded-md dark:bg-blue-900/40 dark:text-blue-300"
                                    >
                                        زبون
                                    </span>

                                @elseif ($party->type === 'supplier')

                                    <span
                                        data-party-type-badge
                                        class="bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 group-focus:bg-white! group-focus:text-[#123b86]! rounded-md dark:bg-amber-900/40 dark:text-amber-300"
                                    >
                                        مورد
                                    </span>

                                @else

                                    <span
                                        data-party-type-badge
                                        class="bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 group-focus:bg-white! group-focus:text-[#123b86]! rounded-md dark:bg-purple-900/40 dark:text-purple-300"
                                    >
                                        زبون ومورد
                                    </span>

                                @endif

                            </td>


                            <td data-party-extra
                                class="p-3 sm:p-4 text-gray-600 dark:text-gray-300 whitespace-nowrap"
                                dir="ltr"
                            >
                                {{ $party->phone ?? '-' }}
                            </td>


                            <td data-party-extra
                                class="p-3 sm:p-4 text-gray-700 dark:text-gray-300 font-medium hidden sm:table-cell"
                            >
                                {{ $party->address ?? '-' }}
                            </td>


                            {{-- Actions --}}

                            <td data-party-extra
                                class="p-3 sm:p-4 text-center whitespace-nowrap"
                            >

                                <div
                                    class="flex justify-center items-center gap-2"
                                >

                                    {{-- Edit --}}

                                    <button
                                        type="button"
                                        data-party-action="edit"
                                        wire:click="editParty({{ $party->id }})"
                                        class="p-1.5 rounded-md text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition"
                                        title="تعديل"
                                    >
                                        ✏️
                                    </button>


                                    {{-- Deactivate --}}

                                    <button
                                        type="button"
                                        data-party-action="deactivate"
                                        wire:click="deactivateParty({{ $party->id }})"
                                        wire:confirm="هل أنت متأكد من تعطيل هذا الطرف؟ لن يظهر ضمن الأطراف النشطة."
                                        class="p-1.5 rounded-md text-gray-400 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition"
                                        title="تعطيل"
                                    >
                                        ⏸️
                                    </button>



                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="7"
                                class="p-12 text-center"
                            >

                                <div class="text-4xl mb-3">
                                    👥
                                </div>

                                <div
                                    class="font-semibold text-gray-600 dark:text-gray-300"
                                >
                                    لا توجد أطراف
                                </div>

                                <div
                                    class="text-xs text-gray-400 mt-1"
                                >
                                    @if (filled($search))
                                        لا توجد نتائج مطابقة للبحث الحالي.
                                    @else
                                        لم تتم إضافة أي طرف حتى الآن.
                                    @endif
                                </div>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>
            </div>

        </div>


        {{-- Pagination --}}

        @if ($parties->hasPages())

            <div class="mt-4">
                {{ $parties->links() }}
            </div>

        @endif

        </div>

        {{-- =========================================================
            STATEMENT MODAL
        ========================================================== --}}

        @if ($showStatementModal && $selectedPartyForStatement)

            @php
                $statementData = $this->buildStatementData(
                    $selectedPartyForStatement
                );

                $statementTransactions =
                    $statementData['transactions'];

                $statementRunningBalance =
                    $statementData['opening_balance'];

                $statementDifference =
                    $statementData['difference'];
            @endphp


            <div
                data-customer-statement x-data x-on:keydown.escape.window.stop="$wire.closeStatementModal()"
                class="app-modal-overlay fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto"
                wire:key="statement-modal-{{ $selectedPartyForStatement->id }}"
            >

                <div
                    role="dialog" aria-modal="true" aria-labelledby="customer-statement-title"
                    class="customer-statement-dialog bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-6xl my-auto overflow-hidden border border-gray-100 dark:border-gray-700 max-h-[90vh] flex flex-col"
                >

                    {{-- Header --}}

                    <div
                        class="customer-statement-header flex justify-between items-center p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700"
                    >

                        <div>

                            <div class="flex items-center gap-2">

                                <h3
                                    id="customer-statement-title" class="text-base sm:text-lg font-bold text-gray-800 dark:text-white"
                                >
                                    كشف حساب
                                </h3>


                                <span
                                    class="px-2 py-0.5 rounded-md text-[10px] font-semibold
                                    {{ $selectedPartyForStatement->type === 'supplier'
                                        ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
                                        : ($selectedPartyForStatement->type === 'both'
                                            ? 'bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'
                                            : 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300') }}"
                                >
                                    {{ $this->partyTypeLabel($selectedPartyForStatement->type) }}
                                </span>

                            </div>


                            <p
                                class="text-xs text-gray-500 dark:text-gray-400 mt-1"
                            >

                                {{ $selectedPartyForStatement->name }}

                                @if ($selectedPartyForStatement->phone)

                                    <span class="mx-1">
                                        •
                                    </span>

                                    <span dir="ltr">
                                        {{ $selectedPartyForStatement->phone }}
                                    </span>

                                @endif

                            </p>

                        </div>


                        <button
                            type="button"
                            wire:click="closeStatementModal"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl font-bold leading-none"
                        >
                            &times;
                        </button>

                    </div>


                    {{-- Body --}}

                    <div
                        class="customer-statement-body p-4 sm:p-6 space-y-4 overflow-y-auto flex-1"
                    >

                        {{-- Summary --}}

                        <div
                            class="customer-statement-summary grid grid-cols-1 sm:grid-cols-3 gap-3"
                        >

                            <div
                                class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg"
                            >

                                <div
                                    class="text-[11px] text-gray-500 dark:text-gray-400"
                                >
                                    الرصيد الافتتاحي
                                </div>

                                <div
                                    dir="ltr" class="font-bold mt-1 text-gray-800 dark:text-white"
                                >
                                    {{ number_format(
                                        $statementData['opening_balance'],
                                        2
                                    ) }}
                                </div>

                            </div>


                            <div
                                class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg"
                            >

                                <div
                                    class="text-[11px] text-blue-600 dark:text-blue-400"
                                >
                                    الرصيد الحالي
                                </div>

                                <div
                                    dir="ltr" class="font-bold mt-1 text-blue-700 dark:text-blue-300"
                                >
                                    {{ number_format(
                                        $statementData['current_balance'],
                                        2
                                    ) }}
                                </div>

                            </div>


                            <div
                                class="bg-emerald-50 dark:bg-emerald-900/20 p-3 rounded-lg"
                            >

                                <div
                                    class="text-[11px] text-emerald-600 dark:text-emerald-400"
                                >
                                    الرصيد المحسوب
                                </div>

                                <div
                                    dir="ltr" class="font-bold mt-1 text-emerald-700 dark:text-emerald-300"
                                >
                                    {{ number_format(
                                        $statementData['final_balance'],
                                        2
                                    ) }}
                                </div>

                            </div>

                        </div>


                        {{-- Difference Warning --}}

                        @if (abs($statementDifference) > 0.009)

                            <div
                                class="p-3 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-300 text-xs"
                            >

                                <div class="font-semibold mb-1">
                                    ⚠️ يوجد اختلاف في الرصيد
                                </div>

                                <div>
                                    الرصيد المسجل يختلف عن الرصيد المحسوب من الحركات بمقدار
                                    <strong>
                                        {{ number_format(abs($statementDifference), 2) }}
                                    </strong>
                                    شيكل.
                                </div>

                                @if (auth()->user()?->isTenantOwner() || auth()->user()?->isSaaSAdmin())
                                    <button
                                        type="button"
                                        wire:click="reconcilePartyBalance({{ $selectedPartyForStatement->id }})"
                                        wire:confirm="سيتم تحديث الرصيد الحالي ليطابق رصيد كشف الحركات. هل تريد المتابعة؟"
                                        class="mt-3 rounded-lg bg-amber-700 px-3 py-2 font-bold text-white hover:bg-amber-800"
                                    >
                                        تسوية الرصيد حسب الكشف
                                    </button>
                                @endif

                            </div>

                        @endif


                        <div class="customer-statement-table-heading"><h4>حركات الحساب</h4><span>{{ $statementTransactions->count() }} حركة · اضغط على الحركة لفتح المستند</span></div>

                        <div
                            class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700"
                        >

                            <div class="app-table-scroll" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">

                            <table
                            class="customer-statement-table w-full text-right text-xs dark:text-gray-300"
                            >

                                <thead>

                                    <tr
                                        class="bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"
                                    >

                                        <th class="p-3">
                                            التاريخ
                                        </th>

                                        <th class="p-3">
                                            البيان
                                        </th>

                                        <th class="p-3">
                                            ملاحظات
                                        </th>

                                        <th class="p-3">
                                            مدين
                                        </th>

                                        <th class="p-3">
                                            دائن
                                        </th>

                                        <th class="p-3">
                                            الرصيد
                                        </th>

                                    </tr>

                                </thead>


                                <tbody
                                    x-data x-init="$nextTick(() => $el.querySelector('[data-nav-row]')?.focus())" x-on:keydown.arrow-down.prevent="(rows => rows[Math.min(rows.length - 1, rows.indexOf(document.activeElement) + 1)]?.focus())([...$el.querySelectorAll('[data-nav-row]')])" x-on:keydown.arrow-up.prevent="(rows => rows[Math.max(0, rows.indexOf(document.activeElement) - 1)]?.focus())([...$el.querySelectorAll('[data-nav-row]')])"
                                    class="divide-y divide-gray-100 dark:divide-gray-700"
                                >

                                    {{-- Opening --}}

                                    <tr
                                        class="bg-gray-50/70 dark:bg-gray-700/30"
                                    >

                                        <td data-label="التاريخ"
                                            class="p-3 whitespace-nowrap"
                                        >
                                            {{ $selectedPartyForStatement->created_at?->format('Y-m-d') ?? '-' }}
                                        </td>

                                        <td data-label="البيان"
                                            class="p-3 font-medium"
                                        >
                                            رصيد افتتاحي
                                        </td>

                                        <td data-label="ملاحظات" class="p-3 text-gray-500">—</td>

                                        <td data-label="مدين" dir="ltr"
                                            class="p-3 whitespace-nowrap"
                                        >

                                            @if ($statementData['opening_balance'] > 0)
                                                {{ number_format($statementData['opening_balance'], 2) }}
                                            @else
                                                -
                                            @endif

                                        </td>

                                        <td data-label="دائن" dir="ltr"
                                            class="p-3 whitespace-nowrap"
                                        >

                                            @if ($statementData['opening_balance'] < 0)
                                                {{ number_format(abs($statementData['opening_balance']), 2) }}
                                            @else
                                                -
                                            @endif

                                        </td>

                                        <td data-label="الرصيد" dir="ltr"
                                            class="p-3 whitespace-nowrap font-bold"
                                        >
                                            {{ number_format(
                                                $statementRunningBalance,
                                                2
                                            ) }}
                                        </td>

                                    </tr>


                                    {{-- Transactions --}}

                                    @foreach ($statementTransactions as $item)

                                        @php
                                            $statementRunningBalance +=
                                                (
                                                    (float) $item['debit']
                                                    -
                                                    (float) $item['credit']
                                                );
                                        @endphp

                                        <tr
                                            wire:key="customer-statement-{{ $item['source_type'] }}-{{ $item['source_id'] }}-{{ $loop->index }}" tabindex="0" data-nav-row data-statement-row x-on:keydown.enter.prevent="$el.click()"
                                            wire:click="openStatementDocument('{{ $item['source_type'] }}', {{ $item['source_id'] }})"
                                            class="cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-950/30 focus:outline-none focus:bg-indigo-50 dark:focus:bg-indigo-950/30"
                                        >

                                            <td data-label="التاريخ"
                                                class="p-3 whitespace-nowrap"
                                            >
                                                {{ $item['date']
                                                    ? \Carbon\Carbon::parse($item['date'])->format('Y-m-d · H:i')
                                                    : '-' }}
                                            </td>


                                            <td data-label="البيان" class="p-3">
                                                {{ $item['description'] }}
                                            </td>

                                            <td data-label="ملاحظات" class="p-3 min-w-48 whitespace-pre-line text-gray-600 dark:text-gray-300">
                                                {{ $item['notes'] ?: '—' }}
                                            </td>


                                            <td data-label="مدين" dir="ltr"
                                                class="p-3 whitespace-nowrap text-red-600 dark:text-red-400"
                                            >

                                                @if ($item['debit'] > 0)

                                                    {{ number_format(
                                                        $item['debit'],
                                                        2
                                                    ) }}

                                                @else

                                                    -

                                                @endif

                                            </td>


                                            <td data-label="دائن" dir="ltr"
                                                class="p-3 whitespace-nowrap text-emerald-600 dark:text-emerald-400"
                                            >

                                                @if ($item['credit'] > 0)

                                                    {{ number_format(
                                                        $item['credit'],
                                                        2
                                                    ) }}

                                                @else

                                                    -

                                                @endif

                                            </td>


                                            <td data-label="الرصيد" dir="ltr"
                                                class="p-3 whitespace-nowrap font-semibold"
                                            >
                                                {{ number_format(
                                                    $statementRunningBalance,
                                                    2
                                                ) }}
                                            </td>

                                        </tr>

                                    @endforeach


                                    @if ($statementTransactions->isEmpty())

                                        <tr>

                                            <td
                                                colspan="6"
                                                class="p-8 text-center text-gray-400"
                                            >
                                                لا توجد حركات مالية بعد الرصيد الافتتاحي.
                                            </td>

                                        </tr>

                                    @endif

                                </tbody>
                                <tfoot data-statement-totals><tr><th colspan="3">الإجمالي مع الرصيد الافتتاحي</th><td dir="ltr">{{ number_format($statementTransactions->sum('debit') + max(0, $statementData['opening_balance']), 2) }}</td><td dir="ltr">{{ number_format($statementTransactions->sum('credit') + max(0, -$statementData['opening_balance']), 2) }}</td><td dir="ltr">{{ number_format($statementData['final_balance'], 2) }}</td></tr></tfoot>

                            </table>
                            </div>

                        </div>

                    </div>


                    {{-- Footer --}}

                    <div
                        class="customer-statement-footer flex flex-col sm:flex-row justify-end gap-2 p-4 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800"
                    >

                        {{-- WhatsApp --}}

                        <button
                            type="button"
                            wire:click="sendStatementWhatsapp({{ $selectedPartyForStatement->id }})"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium flex items-center justify-center gap-2 transition"
                        >
                            <span>
                                إرسال واتساب
                            </span>

                            💬
                        </button>


                        {{-- Thermal Print --}}

                        <button
                            type="button"
                            wire:click="printStatementThermal({{ $selectedPartyForStatement->id }})"
                            class="px-4 py-2 bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white rounded-lg text-xs font-medium flex items-center justify-center gap-2 transition"
                        >
                            <span>
                                طباعة حرارية
                            </span>

                            🧾
                        </button>


                        {{-- Close --}}

                        <button
                            type="button"
                            wire:click="closeStatementModal"
                            class="px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 text-center transition"
                        >
                            إغلاق
                        </button>

                    </div>

                </div>

            </div>

        @endif

        </div>


        {{-- =========================================================
            CREATE / EDIT MODAL
        ========================================================== --}}

        @if ($showModal)

            <div
                class="app-modal-overlay fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto"
                wire:key="party-form-modal"
            >

                <div
                    class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-xl my-auto overflow-hidden border border-gray-100 dark:border-gray-700 max-h-[90vh] flex flex-col"
                >

                    {{-- Header --}}

                    <div
                        class="flex justify-between items-center p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700"
                    >

                        <div>

                            <h3
                                class="text-base sm:text-lg font-bold text-gray-800 dark:text-white"
                            >
                                {{ $partyId ? 'تعديل بيانات الطرف' : 'إضافة طرف جديد' }}
                            </h3>

                            <p
                                class="text-[11px] text-gray-400 mt-1"
                            >
                                {{ $partyId
                                    ? 'تعديل البيانات الأساسية فقط.'
                                    : 'أدخل بيانات الطرف والرصيد الافتتاحي.' }}
                            </p>

                        </div>


                        <button
                            type="button"
                            wire:click="closeModal"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl font-bold leading-none"
                        >
                            &times;
                        </button>

                    </div>


                    {{-- Form --}}

                    <form
                        wire:submit="save"
                        class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1"
                    >

                        {{-- Name --}}

                        <div>

                            <label
                                class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                            >
                                الاسم الكامل
                                <span class="text-red-500">*</span>
                            </label>

                            <input
                                type="text"
                                wire:model="name"
                                autofocus
                                class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none"
                            >

                            @error('name')

                                <span class="text-red-500 text-xs mt-1 block">
                                    {{ $message }}
                                </span>

                            @enderror

                        </div>


                        {{-- Type + Phone --}}

                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 gap-4"
                        >

                            <div>

                                <label
                                    class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                                >
                                    نوع الطرف
                                    <span class="text-red-500">*</span>
                                </label>

                                <select
                                    wire:model="type"
                                    class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none bg-white"
                                >

                                    <option value="customer">
                                        زبون
                                    </option>

                                    <option value="supplier">
                                        مورد
                                    </option>

                                    <option value="both">
                                        زبون ومورد
                                    </option>

                                </select>

                                @error('type')

                                    <span class="text-red-500 text-xs mt-1 block">
                                        {{ $message }}
                                    </span>

                                @enderror

                            </div>


                            <div>

                                <label
                                    class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                                >
                                    رقم الهاتف
                                </label>

                                <input
                                    type="text"
                                    wire:model="phone"
                                    dir="ltr"
                                    class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none"
                                >

                                @error('phone')

                                    <span class="text-red-500 text-xs mt-1 block">
                                        {{ $message }}
                                    </span>

                                @enderror

                            </div>

                        </div>


                        {{-- Email + Tax --}}

                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 gap-4"
                        >

                            <div>

                                <label
                                    class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                                >
                                    البريد الإلكتروني
                                </label>

                                <input
                                    type="email"
                                    wire:model="email"
                                    dir="ltr"
                                    class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none"
                                >

                                @error('email')

                                    <span class="text-red-500 text-xs mt-1 block">
                                        {{ $message }}
                                    </span>

                                @enderror

                            </div>


                            <div>

                                <label
                                    class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                                >
                                    الرقم الضريبي
                                </label>

                                <input
                                    type="text"
                                    wire:model="tax_number"
                                    class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none"
                                >

                                @error('tax_number')

                                    <span class="text-red-500 text-xs mt-1 block">
                                        {{ $message }}
                                    </span>

                                @enderror

                            </div>

                        </div>


                        {{-- Address --}}

                        <div>

                            <label
                                class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                            >
                                الموقع / العنوان
                            </label>

                            <input
                                type="text"
                                wire:model="address"
                                placeholder="مثال: نابلس - شارع سفيان"
                                class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none"
                            >

                            @error('address')

                                <span class="text-red-500 text-xs mt-1 block">
                                    {{ $message }}
                                </span>

                            @enderror

                        </div>


                        {{-- Opening Balance --}}

                        <div>

                            <label
                                class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1"
                            >
                                الرصيد الافتتاحي
                            </label>


                                <div class="relative">

                                    <input
                                        type="number"
                                        step="0.01"
                                        wire:model="opening_balance"
                                        placeholder="0.00"
                                        dir="ltr"
                                        class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 pl-16 text-sm focus:ring-2 focus:ring-gray-200 dark:focus:ring-gray-600 focus:outline-none"
                                    >

                                    <span
                                        class="absolute left-3 top-2.5 text-xs text-gray-400"
                                    >
                                        شيكل
                                    </span>

                                </div>

                                <p
                                    class="text-[11px] text-gray-500 dark:text-gray-400 mt-1"
                                >
                                    @if ($partyId)
                                        عند تغيير الرصيد الافتتاحي يتحرك الرصيد الحالي بنفس الفرق.
                                    @else
                                        عند إنشاء الطرف سيبدأ الرصيد الحالي بنفس قيمة الرصيد الافتتاحي.
                                    @endif
                                </p>

                                @error('opening_balance')

                                    <span class="text-red-500 text-xs mt-1 block">
                                        {{ $message }}
                                    </span>

                                @enderror

                        </div>


                        {{-- Footer --}}

                        <div
                            class="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700"
                        >

                            <button
                                type="button"
                                wire:click="closeModal"
                                class="px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                            >
                                إلغاء
                            </button>


                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="px-5 py-2 bg-gray-900 hover:bg-black dark:bg-gray-700 dark:hover:bg-gray-600 text-white rounded-lg text-sm font-medium transition disabled:opacity-50"
                            >

                                <span wire:loading.remove wire:target="save">
                                    {{ $partyId ? 'تحديث البيانات' : 'حفظ البيانات' }}
                                </span>

                                <span wire:loading wire:target="save">
                                    جاري الحفظ...
                                </span>

                            </button>

                        </div>

                    </form>

                </div>

            </div>

        @endif



    </div>

    @can('customers.edit')
        <livewire:pages::tenant.customers.shop-account />
        <livewire:pages::tenant.customers.telegram-link />
    @endcan
</flux:main>


@script

<script>

    /*
     * ============================================================
     * Thermal Print - RawBT
     * ============================================================
     */

    $wire.on('do-statement-print', (event) => {

        const s = event.data;

        let text = "";

        text += "--------------------------------\n";

        if (s.store_name) {

            text +=
                "        " +
                s.store_name +
                "        \n";

        }

        text += "          كشف حساب          \n";

        text += "--------------------------------\n";

        text +=
            "الطرف: " +
            s.party_name +
            "\n";

        text +=
            "النوع: " +
            s.party_type +
            "\n";

        text +=
            "الهاتف: " +
            s.party_phone +
            "\n";

        text +=
            "التاريخ: " +
            s.date +
            "\n";

        text += "--------------------------------\n";

        text +=
            "الرصيد الافتتاحي: " +
            s.opening_balance +
            "\n";

        text +=
            "الرصيد الحالي: " +
            s.current_balance +
            "\n";

        text += "--------------------------------\n";


        s.items.forEach(item => {

            text +=
                item.date +
                " | " +
                item.desc +
                "\n";

            if (item.notes) {
                text += "  ملاحظات: " + item.notes + "\n";
            }

            text +=
                "  مدين: " +
                item.debit +
                " | دائن: " +
                item.credit +
                "\n";

            text +=
                "  الرصيد: " +
                item.bal +
                "\n";

            text +=
                "................................\n";

        });


        text += "--------------------------------\n";

        text +=
            "الرصيد النهائي: " +
            s.final_balance +
            " شيكل\n";

        if (s.difference !== "0.00") {

            text +=
                "فرق الرصيد: " +
                s.difference +
                " شيكل\n";

        }

        text += "--------------------------------\n\n\n\n";


        const intentUrl =
            "intent:" +
            encodeURIComponent(text) +
            "#Intent;" +
            "scheme=rawbt;" +
            "package=ru.a402d.rawbtprinter;" +
            "S.type=text/plain;" +
            "end;";


        window.location.href = intentUrl;

    });


    /*
     * ============================================================
     * WhatsApp
     * ============================================================
     */

    $wire.on('open-whatsapp', (event) => {

        window.open(
            event.url,
            '_blank'
        );

    });

    $wire.on('open-statement-document', ({ url, title }) => {
        if (!url) return;

        // The customer statement can itself be displayed inside a workspace
        // iframe. Ask the top-level WaslaPOS workspace to create its own tab,
        // rather than opening a separate browser tab.
        let shell = window;
        try {
            if (window.top !== window && window.top.document.querySelector('.workspace-shell')) shell = window.top;
        } catch (_) {}
        if (!shell.document.querySelector('.workspace-shell')) {
            window.location.assign(url);
            return;
        }
        // Build the event in the shell's own window so its listener always receives it.
        shell.dispatchEvent(new shell.CustomEvent('workspace:open-page', {
            detail: { url, title: title || 'المستند' },
        }));
    });

</script>

@endscript
