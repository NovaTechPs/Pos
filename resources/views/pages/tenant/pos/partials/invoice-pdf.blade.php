@php
    $arabic = new \ArPHP\I18N\Arabic();

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
            margin-right: auto;
            margin-left: 0;
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
            {{ $ar($order->branch?->name ?? 'نقطة البيع') }}
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
                    {{ $ar('رقم الفاتورة:') }}
                </strong>

                {{ $order->invoice_number }}
            </td>

            <td>
                <strong>
                    {{ $ar('التاريخ:') }}
                </strong>

                {{ optional($order->created_at)->format('Y/m/d H:i') }}
            </td>

        </tr>

        <tr>

            <td>
                <strong>
                    {{ $ar('العميل:') }}
                </strong>

                {{ $ar($order->customer_name ?? 'زبون عام') }}
            </td>

            <td>
                <strong>
                    {{ $ar('الكاشير:') }}
                </strong>

                {{ $ar($order->user?->name ?? 'الكاشير') }}
            </td>

        </tr>

    </table>


    {{-- الأصناف --}}
    <table class="items">

        <thead>
            <tr>

                <th>
                    #
                </th>

                <th>
                    {{ $ar('الصنف') }}
                </th>

                <th>
                    {{ $ar('الكمية') }}
                </th>

                <th>
                    {{ $ar('السعر') }}
                </th>

                <th>
                    {{ $ar('الإجمالي') }}
                </th>

            </tr>
        </thead>

        <tbody>

            @foreach ($order->items as $index => $item)

                <tr>

                    <td class="number">
                        {{ $index + 1 }}
                    </td>

                    <td>
                        {{ $ar(
                            $item->product?->name ?? 'منتج غير محدد'
                        ) }}
                    </td>

                    <td class="number">
                        {{ number_format((float) $item->quantity, 2) }}
                    </td>

                    <td class="number">
                        {{ number_format((float) $item->unit_price, 2) }}
                    </td>

                    <td class="number">
                        {{ number_format((float) $item->total_price, 2) }}
                    </td>

                </tr>

            @endforeach

        </tbody>

    </table>


    {{-- ملخص الفاتورة --}}
    <table class="summary">

        <tr>

            <td>
                {{ $ar('الإجمالي') }}
            </td>

            <td class="number">
                {{ number_format((float) $order->subtotal, 2) }}
            </td>

        </tr>


        <tr>

            <td>
                {{ $ar('الخصم') }}
            </td>

            <td class="number">
                {{ number_format((float) $order->discount, 2) }}
            </td>

        </tr>


        <tr class="total">

            <td>
                {{ $ar('الصافي') }}
            </td>

            <td class="number">
                {{ number_format(
                    abs((float) $order->total),
                    2
                ) }}
            </td>

        </tr>


        <tr>

            <td>
                {{ $ar('المدفوع') }}
            </td>

            <td class="number">
                {{ number_format(
                    (float) $order->paid_amount,
                    2
                ) }}
            </td>

        </tr>


        <tr>

            <td>
                {{ $ar('الباقي') }}
            </td>

            <td class="number">

                {{
                    number_format(
                        max(
                            0,
                            (float) $order->paid_amount
                            - abs((float) $order->total)
                        ),
                        2
                    )
                }}

            </td>

        </tr>

    </table>


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

</body>
</html>
