<?php

use App\Models\CustomerVisit;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $date = '';

    #[Url(as: 'rep')]
    public ?int $repId = null;

    public string $customerSearch = '';

    public function mount(): void
    {
        $this->authorize('customer_visits.manage');
        $this->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) ? $this->date : today()->toDateString();
        if ($this->repId && ! $this->reps()->contains('id', $this->repId)) {
            $this->repId = null;
        }
    }

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    /** @return Collection<int, User> */
    private function reps(): Collection
    {
        return User::query()->where('tenant_id', $this->tenantId())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'branch_id']);
    }

    /** Adds a customer to the selected rep's list for the day. */
    public function addCustomer(int $partyId): void
    {
        $this->authorize('customer_visits.manage');
        $rep = $this->reps()->firstWhere('id', $this->repId);
        abort_unless($rep, 422, 'اختر المندوب أولاً.');
        $party = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', ['customer', 'both'])->where('is_active', true)->findOrFail($partyId);

        CustomerVisit::addToList(User::query()->findOrFail($rep->id), $party->id, $this->date, auth()->id());
        $this->customerSearch = '';
        $this->dispatch('customer-visit-added');
    }

    /** The manager's instruction to the rep for this customer, shown in the rep's app. */
    public function saveManagerNote(int $visitId, ?string $note): void
    {
        $this->authorize('customer_visits.manage');
        $note = trim((string) $note);
        if (mb_strlen($note) > 1000) {
            $this->addError('managerNote.'.$visitId, 'الملاحظة أطول من 1000 حرف.');

            return;
        }
        CustomerVisit::query()->where('tenant_id', $this->tenantId())->findOrFail($visitId)->update(['manager_note' => $note !== '' ? $note : null]);
    }

    public function removeVisit(int $visitId): void
    {
        $this->authorize('customer_visits.manage');
        $visit = CustomerVisit::query()->where('tenant_id', $this->tenantId())->findOrFail($visitId);
        abort_if($visit->outcome !== null, 422, 'لا يمكن حذف زيارة سجّل المندوب نتيجتها.');
        $visit->delete();
    }

    public function render(): mixed
    {
        $this->authorize('customer_visits.manage');
        $visits = CustomerVisit::query()->where('tenant_id', $this->tenantId())->whereDate('visit_date', $this->date)
            ->when($this->repId, fn ($query) => $query->where('user_id', $this->repId))
            ->with(['party:id,name,phone,current_balance', 'user:id,name'])
            ->orderBy('user_id')->orderBy('position')->get();
        $search = trim($this->customerSearch);
        $customers = $this->repId && mb_strlen($search) >= 2
            ? Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', ['customer', 'both'])->where('is_active', true)
                ->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))
                ->whereNotIn('id', $visits->pluck('party_id'))->orderBy('name')->limit(8)->get(['id', 'name', 'phone'])
            : collect();

        return $this->view([
            'reps' => $this->reps(),
            'visits' => $visits,
            'customers' => $customers,
            'counts' => collect(CustomerVisit::OUTCOMES)->map(fn (string $label, string $key): int => $visits->where('outcome', $key)->count()),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="space-y-4" data-customer-visits-page>

    <div>
        <flux:heading size="xl">زيارات المندوبين</flux:heading>
        <flux:text class="mt-1">جهّز قائمة زبائن كل مندوب لليوم، وتابع ما سجّله لكل زبون: بيع، أو تحصيل فقط، أو يوجد طلبية.</flux:text>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:input type="date" label="اليوم" wire:model.live="date" />
        <flux:select label="المندوب" wire:model.live="repId">
            <option value="">كل المندوبين</option>
            @foreach ($reps as $rep)
                <option value="{{ $rep->id }}">{{ $rep->name }}</option>
            @endforeach
        </flux:select>
        @if ($repId)
            {{-- Arrows move through the results and Enter adds the highlighted customer. --}}
            <div class="relative" data-customer-search
                x-on:customer-visit-added.window="$nextTick(() => $el.querySelector('input')?.focus())"
                x-on:keydown.arrow-down.prevent="const items = [...$el.querySelectorAll('[data-customer-result]')]; const index = items.indexOf(document.activeElement); items[Math.min(index + 1, items.length - 1)]?.focus()"
                x-on:keydown.arrow-up.prevent="const items = [...$el.querySelectorAll('[data-customer-result]')]; const index = items.indexOf(document.activeElement); index <= 0 ? $el.querySelector('input')?.focus() : items[index - 1].focus()"
                x-on:keydown.escape="$wire.set('customerSearch', '')">
                <flux:input label="إضافة زبون لقائمته" wire:model.live.debounce.250ms="customerSearch" placeholder="اسم الزبون أو هاتفه... ثم ↓ و Enter" autocomplete="off" />
                @if ($customers->isNotEmpty())
                    <div class="absolute z-20 mt-1 w-full divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:divide-slate-800 dark:border-slate-700 dark:bg-slate-900">
                        @foreach ($customers as $customer)
                            <button type="button" data-customer-result wire:key="add-customer-{{ $customer->id }}" wire:click="addCustomer({{ $customer->id }})" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm outline-none hover:bg-indigo-50 focus:bg-indigo-100 focus:ring-2 focus:ring-inset focus:ring-indigo-500 dark:hover:bg-slate-800 dark:focus:bg-slate-800">
                                <span class="font-bold">{{ $customer->name }}</span><span class="font-mono text-xs text-slate-500">{{ $customer->phone }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-3 text-center dark:border-slate-700 dark:bg-slate-900"><div class="text-xs text-slate-500">لم تُزر بعد</div><div class="text-xl font-black">{{ $visits->whereNull('outcome')->count() }}</div></div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-center"><div class="text-xs text-emerald-700">بيع</div><div class="text-xl font-black text-emerald-700">{{ $counts['sale'] }}</div></div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-center"><div class="text-xs text-sky-700">تحصيل فقط</div><div class="text-xl font-black text-sky-700">{{ $counts['collection'] }}</div></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-center"><div class="text-xs text-amber-700">يوجد طلبية</div><div class="text-xl font-black text-amber-700">{{ $counts['order'] }}</div></div>
    </div>

    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <table class="w-full min-w-[900px] text-right text-sm">
            <thead class="bg-slate-50 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                <tr><th class="p-3">المندوب</th><th class="p-3">الزبون</th><th class="p-3">الرصيد</th><th class="p-3">ملاحظة للمندوب</th><th class="p-3">النتيجة</th><th class="p-3">ملاحظة المندوب</th><th class="p-3">الوقت</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($visits as $visit)
                    <tr wire:key="visit-{{ $visit->id }}" data-customer-visit>
                        <td class="p-3">{{ $visit->user?->name }}</td>
                        <td class="p-3 font-bold">{{ $visit->party?->name }}<div class="font-mono text-xs font-normal text-slate-500">{{ $visit->party?->phone }}</div></td>
                        <td class="p-3 font-mono">{{ number_format((float) $visit->party?->current_balance, 2) }}</td>
                        <td class="p-3">
                            <input type="text" value="{{ $visit->manager_note }}" maxlength="1000" placeholder="مثال: حصّل 500 / اعرض الصنف الجديد"
                                wire:change="saveManagerNote({{ $visit->id }}, $event.target.value)" x-on:keydown.enter="$el.blur()"
                                aria-label="ملاحظة للمندوب عن {{ $visit->party?->name }}" data-manager-note
                                class="w-full min-w-48 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800">
                            @error('managerNote.'.$visit->id)<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror
                        </td>
                        <td class="p-3">
                            @if ($visit->outcome)
                                <span @class(['rounded-full px-2.5 py-1 text-xs font-bold',
                                    'bg-emerald-100 text-emerald-800' => $visit->outcome === 'sale',
                                    'bg-sky-100 text-sky-800' => $visit->outcome === 'collection',
                                    'bg-amber-100 text-amber-800' => $visit->outcome === 'order'])>{{ \App\Models\CustomerVisit::OUTCOMES[$visit->outcome] }}</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">لم تُزر بعد</span>
                            @endif
                        </td>
                        <td class="p-3 text-slate-600">{{ $visit->notes }}</td>
                        <td class="p-3 font-mono text-xs">{{ $visit->visited_at?->format('H:i') }}</td>
                        <td class="p-3">
                            @if (! $visit->outcome)
                                <button type="button" wire:click="removeVisit({{ $visit->id }})" aria-label="حذف الزبون من القائمة" class="rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-bold text-rose-700">حذف</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-8 text-center text-slate-500">{{ $repId ? 'لا توجد زيارات لهذا المندوب في هذا اليوم. أضف زبائن من خانة البحث.' : 'لا توجد زيارات في هذا اليوم.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

</flux:main>
