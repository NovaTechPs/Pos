<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Party;
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
            $party = Party::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($this->partyId);

            /*
             * الرصيد الافتتاحي ثابت بعد الإنشاء.
             *
             * لا نسمح بتغييره.
             */
            unset($validated['opening_balance']);

            /*
             * current_balance لا يتم تعديله هنا.
             *
             * يتم تغييره فقط بواسطة العمليات المالية.
             */
            unset($validated['current_balance']);

            $party->update($validated);

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
     * حذف الطرف.
     *
     * لا نسمح بالحذف إذا كان لديه حركات مالية.
     */
    public function deleteParty(int $id): void
    {
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

        /*
         * لا نحذف طرفًا لديه رصيد حالي.
         */
        if ((float) $party->current_balance != 0.0) {
            session()->flash(
                'error',
                'لا يمكن حذف الطرف لأن لديه رصيدًا ماليًا قائمًا.'
            );

            return;
        }

        /*
         * لا نحذف طرفًا لديه فواتير.
         */
        $hasOrders = false;

        if (method_exists($party, 'orders')) {
            $hasOrders = $party->orders()->exists();
        }

        /*
         * لا نحذف طرفًا لديه دفعات / سندات.
         */
        $hasPayments = false;

        if (method_exists($party, 'payments')) {
            $hasPayments = $party->payments()->exists();
        }

        if ($hasOrders || $hasPayments) {
            session()->flash(
                'error',
                'لا يمكن حذف الطرف لأنه مرتبط بحركات مالية سابقة. يمكنك تعطيله بدلًا من حذفه.'
            );

            return;
        }

        $party->delete();

        session()->flash(
            'message',
            'تم حذف الطرف بنجاح.'
        );
    }


    /**
     * تعطيل الطرف بدل حذفه.
     */
    public function deactivateParty(int $id): void
    {
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
                'orders' => function ($query) {
                    $query->select(
                        'id',
                        'customer_id',
                        'total',
                        'created_at'
                    );
                },

                'payments' => function ($query) {
                    $query->select(
                        'id',
                        'payable_id',
                        'payable_type',
                        'amount',
                        'created_at',
                        'notes'
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
        $transactions = collect();

        /*
        |--------------------------------------------------------------------------
        | Sales Orders
        |--------------------------------------------------------------------------
        */

        foreach ($party->orders as $order) {
            $transactions->push([
                'id' => 'order-' . $order->id,

                'date' => $order->created_at,

                'description' => 'فاتورة مبيعات #' . $order->id,

                'debit' => (float) $order->total,

                'credit' => 0.00,
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Payments
        |--------------------------------------------------------------------------
        */

        foreach ($party->payments as $payment) {
            $transactions->push([
                'id' => 'payment-' . $payment->id,

                'date' => $payment->created_at,

                'description' => $this->paymentDescription($payment),

                'debit' => 0.00,

                'credit' => (float) $payment->amount,
            ]);
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
    private function paymentDescription($payment): string
    {
        $description = 'سداد دفعة';

        if (!empty($payment->notes)) {
            $description .= ' (' . $payment->notes . ')';
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


    /**
     * إغلاق كشف الحساب.
     */
    public function closeStatementModal(): void
    {
        $this->showStatementModal = false;

        $this->selectedPartyForStatement = null;
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
        $query->where('is_active', true);


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
                );
            });
        }


        $parties = $query
            ->latest()
            ->paginate($this->perPage);


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
                        إدارة الأطراف
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
                    إدارة العملاء والموردين والأطراف التجارية
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
                        placeholder="ابحث بالاسم، الهاتف، العنوان أو الرقم الضريبي..."
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

                    </select>

                </div>

            </div>

        </div>


        {{-- =========================================================
            TABLE
        ========================================================== --}}

        <div
            class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-x-auto"
        >

            <table
                class="w-full text-right border-collapse min-w-[900px] dark:text-gray-300"
            >

                <thead>

                    <tr
                        class="bg-gray-50 dark:bg-gray-700 border-b border-gray-100 dark:border-gray-600 text-xs font-semibold text-gray-500 dark:text-gray-300"
                    >

                        <th class="p-3 sm:p-4">
                            #
                        </th>

                        <th class="p-3 sm:p-4">
                            الطرف
                        </th>

                        <th class="p-3 sm:p-4">
                            النوع
                        </th>

                        <th class="p-3 sm:p-4">
                            الهاتف
                        </th>

                        <th class="p-3 sm:p-4 hidden sm:table-cell">
                            العنوان
                        </th>

                        <th class="p-3 sm:p-4">
                            الافتتاحي
                        </th>

                        <th class="p-3 sm:p-4">
                            الحالي
                        </th>

                        <th class="p-3 sm:p-4 text-center">
                            الإجراءات
                        </th>

                    </tr>

                </thead>


                <tbody
                    class="divide-y divide-gray-100 dark:divide-gray-700 text-xs sm:text-sm"
                >

                    @forelse ($parties as $party)

                        <tr
                            wire:key="party-{{ $party->id }}"
                            class="hover:bg-gray-50/50 dark:hover:bg-gray-700/50 transition"
                        >

                            <td
                                class="p-3 sm:p-4 text-gray-400"
                            >
                                {{ $parties->firstItem() + $loop->index }}
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


                            <td class="p-3 sm:p-4 whitespace-nowrap">

                                @if ($party->type === 'customer')

                                    <span
                                        class="bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 px-2 py-1 rounded-md text-xs font-medium"
                                    >
                                        زبون
                                    </span>

                                @elseif ($party->type === 'supplier')

                                    <span
                                        class="bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 px-2 py-1 rounded-md text-xs font-medium"
                                    >
                                        مورد
                                    </span>

                                @else

                                    <span
                                        class="bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 px-2 py-1 rounded-md text-xs font-medium"
                                    >
                                        زبون ومورد
                                    </span>

                                @endif

                            </td>


                            <td
                                class="p-3 sm:p-4 text-gray-600 dark:text-gray-300 whitespace-nowrap"
                                dir="ltr"
                            >
                                {{ $party->phone ?? '-' }}
                            </td>


                            <td
                                class="p-3 sm:p-4 text-gray-700 dark:text-gray-300 font-medium hidden sm:table-cell"
                            >
                                {{ $party->address ?? '-' }}
                            </td>


                            {{-- Opening Balance --}}

                            <td
                                class="p-3 sm:p-4 font-medium text-gray-800 dark:text-white whitespace-nowrap"
                            >
                                {{ number_format((float) $party->opening_balance, 2) }}
                            </td>


                            {{-- Current Balance --}}

                            <td
                                class="p-3 sm:p-4 font-bold whitespace-nowrap"
                            >

                                <span
                                    class="{{ $this->balanceClass((float) $party->current_balance) }}"
                                >
                                    {{ number_format((float) $party->current_balance, 2) }}
                                </span>

                            </td>


                            {{-- Actions --}}

                            <td
                                class="p-3 sm:p-4 text-center whitespace-nowrap"
                            >

                                <div
                                    class="flex justify-center items-center gap-2"
                                >

                                    {{-- Statement --}}

                                    <button
                                        type="button"
                                        wire:click="openStatementModal({{ $party->id }})"
                                        class="p-1.5 rounded-md text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition"
                                        title="كشف حساب"
                                    >
                                        📜
                                    </button>


                                    {{-- Edit --}}

                                    <button
                                        type="button"
                                        wire:click="editParty({{ $party->id }})"
                                        class="p-1.5 rounded-md text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition"
                                        title="تعديل"
                                    >
                                        ✏️
                                    </button>


                                    {{-- Deactivate --}}

                                    <button
                                        type="button"
                                        wire:click="deactivateParty({{ $party->id }})"
                                        wire:confirm="هل أنت متأكد من تعطيل هذا الطرف؟ لن يظهر ضمن الأطراف النشطة."
                                        class="p-1.5 rounded-md text-gray-400 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition"
                                        title="تعطيل"
                                    >
                                        ⏸️
                                    </button>


                                    {{-- Delete --}}

                                    <button
                                        type="button"
                                        wire:click="deleteParty({{ $party->id }})"
                                        wire:confirm="سيتم حذف الطرف فقط إذا لم يكن مرتبطًا بأي حركة مالية. هل تريد المتابعة؟"
                                        class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                        title="حذف"
                                    >
                                        🗑️
                                    </button>

                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="8"
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


        {{-- Pagination --}}

        @if ($parties->hasPages())

            <div class="mt-4">
                {{ $parties->links() }}
            </div>

        @endif


        {{-- =========================================================
            CREATE / EDIT MODAL
        ========================================================== --}}

        @if ($showModal)

            <div
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto"
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


                            @if ($partyId)

                                <div
                                    class="w-full border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 rounded-lg p-2.5 text-sm text-gray-700 dark:text-gray-300"
                                >
                                    {{ number_format((float) $opening_balance, 2) }}
                                    شيكل
                                </div>

                                <p
                                    class="text-[11px] text-gray-500 dark:text-gray-400 mt-1"
                                >
                                    الرصيد الافتتاحي ثابت ولا يتغير بعد إنشاء الطرف.
                                </p>

                            @else

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
                                    عند إنشاء الطرف سيبدأ الرصيد الحالي بنفس قيمة الرصيد الافتتاحي.
                                </p>

                                @error('opening_balance')

                                    <span class="text-red-500 text-xs mt-1 block">
                                        {{ $message }}
                                    </span>

                                @enderror

                            @endif

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
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto"
                wire:key="statement-modal-{{ $selectedPartyForStatement->id }}"
            >

                <div
                    class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-3xl my-auto overflow-hidden border border-gray-100 dark:border-gray-700 max-h-[90vh] flex flex-col"
                >

                    {{-- Header --}}

                    <div
                        class="flex justify-between items-center p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700"
                    >

                        <div>

                            <div class="flex items-center gap-2">

                                <h3
                                    class="text-base sm:text-lg font-bold text-gray-800 dark:text-white"
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
                        class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1"
                    >

                        {{-- Summary --}}

                        <div
                            class="grid grid-cols-1 sm:grid-cols-3 gap-3"
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
                                    class="font-bold mt-1 text-gray-800 dark:text-white"
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
                                    class="font-bold mt-1 text-blue-700 dark:text-blue-300"
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
                                    class="font-bold mt-1 text-emerald-700 dark:text-emerald-300"
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

                            </div>

                        @endif


                        {{-- Statement Table --}}

                        <div
                            class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700"
                        >

                            <table
                                class="w-full text-right text-xs min-w-[650px] dark:text-gray-300"
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
                                    class="divide-y divide-gray-100 dark:divide-gray-700"
                                >

                                    {{-- Opening --}}

                                    <tr
                                        class="bg-gray-50/70 dark:bg-gray-700/30"
                                    >

                                        <td
                                            class="p-3 whitespace-nowrap"
                                        >
                                            {{ $selectedPartyForStatement->created_at?->format('Y-m-d') ?? '-' }}
                                        </td>

                                        <td
                                            class="p-3 font-medium"
                                        >
                                            رصيد افتتاحي
                                        </td>

                                        <td
                                            class="p-3 whitespace-nowrap"
                                        >

                                            @if ($statementData['opening_balance'] > 0)
                                                {{ number_format($statementData['opening_balance'], 2) }}
                                            @else
                                                -
                                            @endif

                                        </td>

                                        <td
                                            class="p-3 whitespace-nowrap"
                                        >

                                            @if ($statementData['opening_balance'] < 0)
                                                {{ number_format(abs($statementData['opening_balance']), 2) }}
                                            @else
                                                -
                                            @endif

                                        </td>

                                        <td
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
                                            class="hover:bg-gray-50/50 dark:hover:bg-gray-700/30"
                                        >

                                            <td
                                                class="p-3 whitespace-nowrap"
                                            >
                                                {{ $item['date']
                                                    ? \Carbon\Carbon::parse($item['date'])->format('Y-m-d H:i')
                                                    : '-' }}
                                            </td>


                                            <td class="p-3">
                                                {{ $item['description'] }}
                                            </td>


                                            <td
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


                                            <td
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


                                            <td
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
                                                colspan="5"
                                                class="p-8 text-center text-gray-400"
                                            >
                                                لا توجد حركات مالية بعد الرصيد الافتتاحي.
                                            </td>

                                        </tr>

                                    @endif

                                </tbody>

                            </table>

                        </div>

                    </div>


                    {{-- Footer --}}

                    <div
                        class="flex flex-col sm:flex-row justify-end gap-2 p-4 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800"
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

</script>

@endscript
