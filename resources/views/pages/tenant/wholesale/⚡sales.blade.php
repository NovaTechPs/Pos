<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<div dir="rtl" style="padding:20px;">

    {{-- زر الطباعة --}}
    <button
        type="button"
        onclick="printTestInvoice()"
        class="no-print"
        style="
            background:#198754;
            color:#fff;
            border:0;
            border-radius:8px;
            padding:12px 25px;
            font-size:18px;
            cursor:pointer;
        "
    >
        🖨️ طباعة فاتورة تجريبية
    </button>


    {{-- الفاتورة التجريبية --}}
    <div id="test-invoice" class="receipt">

        <div class="center">
            <strong>المتجر الإلكتروني</strong>
        </div>

        <div class="center">
            تسوق آمن ومباشر
        </div>

        <hr>

        <div>
            رقم الفاتورة: 000001
        </div>

        <div>
            التاريخ: 06/10/2026
        </div>

        <div>
            الوقت: 23:47
        </div>

        <hr>

        <div class="item">
            <span>بطاريات قلم</span>
            <span>10.00</span>
        </div>

        <div class="item">
            <span>شاحن هاتف</span>
            <span>25.00</span>
        </div>

        <div class="item">
            <span>كابل USB</span>
            <span>15.00</span>
        </div>

        <hr>

        <div class="item">
            <strong>الإجمالي</strong>
            <strong>50.00</strong>
        </div>

        <div class="item">
            <span>المدفوع</span>
            <span>50.00</span>
        </div>

        <div class="item">
            <span>الباقي</span>
            <span>0.00</span>
        </div>

        <hr>

        <div class="center">
            شكراً لزيارتكم
        </div>

        <div class="center">
            نتمنى لكم يوماً سعيداً
        </div>

        <br>
        <br>
        <br>

    </div>

</div>


<style>

.receipt {
    width: 58mm;
    margin-top: 20px;
    padding: 5mm;
    background: white;
    color: black;
    font-family: Arial, sans-serif;
    font-size: 12px;
    line-height: 1.6;
    direction: rtl;
}

.center {
    text-align: center;
}

.item {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.receipt hr {
    border: 0;
    border-top: 1px dashed #000;
    margin: 8px 0;
}


/* عند الطباعة أخفِ كل شيء ما عدا الفاتورة */
@media print {

    @page {
        size: 58mm auto;
        margin: 0;
    }

    html,
    body {
        width: 58mm;
        margin: 0;
        padding: 0;
    }

    body * {
        visibility: hidden;
    }

    #test-invoice,
    #test-invoice * {
        visibility: visible;
    }

    #test-invoice {
        position: absolute;
        left: 0;
        top: 0;
        width: 58mm;
        margin: 0;
        padding: 3mm;
        box-sizing: border-box;
    }

    .no-print {
        display: none !important;
    }
}

</style>


<script>

function printTestInvoice() {
    window.print();
}

</script>
