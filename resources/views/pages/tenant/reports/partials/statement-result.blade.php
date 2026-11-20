@php
    $summary = $definition['summary'];
    $hasParty = array_key_exists('رصيد أول الفترة', $summary);
    $money = fn ($value): string => number_format((float) $value, 2);
@endphp
<div class="reports-table-wrap" data-statement-scroll x-data="{ selectedRow: null }">
    <table class="reports-statement-table">
        <thead><tr>@foreach ($definition['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
            @if ($hasParty && $rows->onFirstPage())
                <tr data-statement-opening><td><span class="reports-date"><flux:icon.calendar variant="outline" />{{ $from }}</span></td><td>—</td><td>رصيد افتتاحي</td><td>—</td><td>{{ $money(0) }}</td><td>{{ $money(0) }}</td><td class="reports-balance">{{ $money($summary['رصيد أول الفترة']) }}</td></tr>
            @endif
            @forelse ($rows as $row)
                <tr wire:key="statement-row-{{ $rows->currentPage() }}-{{ $loop->index }}" data-statement-row tabindex="0" x-on:click="selectedRow = @js($rows->currentPage().'-'.$loop->index)" x-bind:class="selectedRow === @js($rows->currentPage().'-'.$loop->index) ? 'is-selected' : ''" @if(isset($row->source_order, $row->source_id)) x-on:keydown.enter.prevent="$el.click()" @endif class="{{ isset($row->source_order, $row->source_id) ? 'cursor-pointer' : '' }}">
                    @foreach ($definition['columns'] as $key => $label)
                        @if ($key === 'event_date')
                            <td><span class="reports-date"><flux:icon.calendar variant="outline" />{{ substr((string) $row->event_date, 0, 10) }}</span></td>
                        @elseif ($key === 'reference')
                            <td><button type="button" x-on:keydown.enter.stop class="reports-document-link" @if(isset($row->source_order, $row->source_id)) wire:click.stop="openStatementDocument({{ $row->source_order }}, {{ $row->source_id }})" @endif><flux:icon.arrow-top-right-on-square variant="outline" />{{ $row->reference ?: '—' }}</button></td>
                        @elseif ($key === 'balance')
                            <td class="reports-balance">{{ \App\Services\ReportingService::formatCell($key, $row->{$key} ?? null) }}</td>
                        @elseif ($key === 'description' && isset($invoicePaid[($row->source_order ?? '').':'.($row->source_id ?? '')]))
                            <td>{{ $row->description }} <span class="reports-invoice-paid" data-statement-invoice-paid>(مدفوع {{ $money($invoicePaid[$row->source_order.':'.$row->source_id]) }})</span></td>
                        @elseif ($key === 'notes')
                            <td class="reports-statement-notes">{{ \App\Services\ReportingService::formatCell($key, $row->{$key} ?? null) }}</td>
                        @else
                            <td>{{ \App\Services\ReportingService::formatCell($key, $row->{$key} ?? null) }}</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                @unless ($hasParty)
                    <tr><td colspan="{{ max(1, count($definition['columns'])) }}" class="reports-empty">اختر زبوناً أو مورداً من القائمة لعرض كشف الحساب.</td></tr>
                @endunless
            @endforelse
        </tbody>
        @if ($hasParty)
            <tfoot><tr data-statement-total><th colspan="{{ count($definition['columns']) - 3 }}">الإجمالي</th><td>{{ $money($summary['إجمالي المدين']) }}</td><td>{{ $money($summary['إجمالي الدائن']) }}</td><td class="reports-balance">{{ $money($summary['رصيد آخر الفترة']) }}</td></tr></tfoot>
        @endif
    </table>
</div>
@if ($rows->hasPages())
    <div class="reports-statement-pages">{{ $rows->links() }}</div>
@endif
