@php
    $arabic = new \ArPHP\I18N\Arabic();

    $invoiceTotal = abs((float) $order->total);
    $receivedAmount = max(0, (float) ($order->collected_amount ?? $order->paid_amount ?? 0));
    $settledFromInvoice = min($invoiceTotal, $receivedAmount);
    $invoiceRemaining = max(0, $invoiceTotal - $settledFromInvoice);
    $creditFromPayment = max(0, $receivedAmount - $invoiceTotal);

    $ar = function ($text) use ($arabic) {
        $text = (string) ($text ?? '');

        if ($text === '') {
            return '';
        }

        return $arabic->utf8Glyphs($text, 150, true, true);
    };
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">

    <style>
        @page {
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            direction: rtl;
            text-align: right;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 12px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .store-name {
            font-size: 22px;
            font-weight: bold;
        }

        .invoice-title {
            margin-top: 6px;
            font-size: 16px;
            font-weight: bold;
        }

        .info {
            width: 100%;
            margin-bottom: 18px;
            border-collapse: collapse;
            direction: rtl;
        }

        .info td {
            padding: 5px;
            direction: rtl;
            text-align: right;
            vertical-align: top;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            direction: rtl;
        }

        .items th {
            background: #1e293b;
            color: #ffffff;
            padding: 8px;
            text-align: center;
            font-weight: bold;
        }

        .items td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px;
            text-align: right;
            direction: rtl;
        }

        .items td.number {
            text-align: center;
            direction: ltr;
        }

        .number {
            text-align: center;
        }

        .summary {
            width: 320px;
            margin-right: 0;
            margin-left: auto;
            margin-top: 20px;
            border-collapse: collapse;
            direction: rtl;
        }

        .summary td {
            padding: 7px;
            text-align: right;
            direction: rtl;
        }

        .summary td.number {
            text-align: center;
            direction: ltr;
        }

        .total {
            font-size: 16px;
            font-weight: bold;
        }

        .notes {
            margin-top: 20px;
            direction: rtl;
            text-align: right;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            color: #64748b;
        }
    </style>
</head>

<body>

    {{-- رأس الفاتورة --}}
    <div class="header">

        <div class="store-name">
            {{ $ar($storeName ?? $order->branch?->name ?? 'المتجر') }}
        </div>

        <div class="invoice-title">
            {{ $ar(
                $order->type === 'return'
                    ? 'فاتورة مرتجع'
                    : 'فاتورة بيع'
            ) }}
        </div>

    </div>


    {{-- معلومات الفاتورة --}}
    <table class="info">

        <tr>

            <td>
                <strong>
                    {{ $ar('التاريخ:') }}
                </strong>

                {{ $order->invoice_date?->format('Y/m/d') ?? optional($order->created_at)->format('Y/m/d H:i') }}
            </td>

            <td>
                <strong>
                    {{ $ar('رقم الفاتورة:') }}
                </strong>

                {{ $order->invoice_number }}
            </td>

        </tr>

        <tr>

            <td>
                <strong>
                    {{ $ar('الكاشير:') }}
                </strong>

                {{ $ar($order->user?->name ?? 'الكاشير') }}
            </td>

            <td>
                <strong>
                    {{ $ar('العميل:') }}
                </strong>

                {{ $ar($order->customer_name ?? 'زبون عام') }}
            </td>

        </tr>

    </table>


    {{-- الأصناف --}}
    <table class="items">

        <thead>
            <tr>

                <th>
                    {{ $ar('الإجمالي') }}
                </th>

                <th>
                    {{ $ar('السعر') }}
                </th>

                <th>
                    {{ $ar('الكمية') }}
                </th>

                <th>
                    {{ $ar('الصنف') }}
                </th>

                <th>
                    #
                </th>

            </tr>
        </thead>

        <tbody>

            @foreach ($order->items as $index => $item)

                <tr>

                    <td class="number">
                        {{ number_format((float) $item->total_price, 2) }}
                    </td>

                    <td class="number">
                        {{ number_format((float) $item->unit_price, 2) }}
                    </td>

                    <td class="number">
                        {{ number_format((float) $item->quantity, 2) }}
                    </td>

                    <td>
                        {{ $ar(
                            ($item->product?->name ?? 'منتج غير محدد') . ($item->is_gift ? ' (هدية)' : '')
                        ) }}
                    </td>

                    <td class="number">
                        {{ $index + 1 }}
                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>


    {{-- ملخص الفاتورة --}}
    <table class="summary">

        <tr>

            <td class="number">
                {{ number_format((float) $order->subtotal, 2) }}
            </td>

            <td>
                {{ $ar('الإجمالي') }}
            </td>

        </tr>


        <tr>

            <td class="number">
                {{ number_format((float) $order->discount, 2) }}
            </td>

            <td>
                {{ $ar('الخصم') }}
            </td>

        </tr>


        <tr class="total">

            <td class="number">
                {{ number_format(
                    $invoiceTotal,
                    2
                ) }}
            </td>

            <td>
                {{ $ar('الصافي') }}
            </td>

        </tr>


        <tr>

            <td class="number">
                {{ number_format(
                    $receivedAmount,
                    2
                ) }}
            </td>

            <td>
                {{ $ar('المبلغ المستلم من العميل') }}
            </td>

        </tr>


        <tr>

            <td class="number">

                {{
                    number_format(
                        $invoiceRemaining,
                        2
                    )
                }}

            </td>

            <td>
                {{ $ar('المتبقي من الفاتورة') }}
            </td>

        </tr>

        @if ($creditFromPayment > 0.001)
            <tr>
                <td class="number">{{ number_format($creditFromPayment, 2) }}</td>
                <td>{{ $ar('رصيد لصالح العميل من هذه الدفعة') }}</td>
            </tr>
        @endif

    </table>


    @if (isset($accountBalance))
        @php
            $balanceAfterPayment = (float) $accountBalance;
            $balanceLabel = $balanceAfterPayment < -0.001
                ? 'رصيد لصالح العميل بعد الدفعة'
                : 'الرصيد المتبقي على العميل بعد الدفعة';
        @endphp
        <table class="summary"><tr><td class="number">{{ number_format(abs($balanceAfterPayment), 2) }}</td><td>{{ $ar($balanceLabel) }}</td></tr></table>
    @endif

    {{-- الملاحظات --}}
    @if ($order->notes)

        <div class="notes">

            <strong>
                {{ $ar('ملاحظات:') }}
            </strong>

            {{ $ar($order->notes) }}

        </div>

    @endif


    {{-- التذييل --}}
    <div class="footer">
        {{ $ar('شكراً لتعاملكم معنا') }}
    </div>

    <x-invoice-copyright :pdf="true" />
</body>
</html>
