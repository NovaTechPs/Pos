<tr>
    @foreach ($definition['columns'] as $key => $label)
        <th class="p-3" wire:key="column-filter-{{ $report }}-{{ $key }}" scope="col">
            <label class="reports-column-filter">
                <input type="search" wire:model.live.debounce.300ms="columnFilters.{{ $key }}" aria-label="بحث في {{ $label }}" placeholder="بحث…" maxlength="200" autocomplete="off" />
                <span>{{ $label }}</span>
            </label>
        </th>
    @endforeach
</tr>
@if (collect($columnFilters)->contains(fn ($value) => trim((string) $value) !== ''))
    <tr>
        <td colspan="{{ count($definition['columns']) }}" class="reports-column-search-status">
            <button type="button" wire:click="clearColumnFilters">مسح بحث الأعمدة</button>
            <span>الإجماليات والأرصدة تخص الفترة كاملة قبل بحث الأعمدة.</span>
        </td>
    </tr>
@endif
