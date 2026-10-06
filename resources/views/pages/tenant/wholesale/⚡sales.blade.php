<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<div dir="rtl" style="padding:20px">

    <button
        type="button"
        id="printTestButton"
        style="
            width:100%;
            padding:18px;
            background:#198754;
            color:white;
            border:none;
            border-radius:10px;
            font-size:20px;
            font-weight:bold;
        "
    >
        🖨️ طباعة فاتورة تجريبية
    </button>

    <div id="testReceipt" style="
        width:58mm;
        padding:4mm;
        box-sizing:border-box;
        background:#fff;
        color:#000;
        font-family:Arial,sans-serif;
        direction:rtl;
        font-size:13px;
    ">

        <div style="text-align:center;font-size:18px;font-weight:bold">
            المتجر الإلكتروني
        </div>

        <div style="text-align:center">
            تسوق آمن ومباشر
        </div>

        <hr>

        <div>فاتورة رقم: 000001</div>
        <div>التاريخ: 06/10/2026</div>
        <div>الوقت: 23:47</div>

        <hr>

        <div style="display:flex;justify-content:space-between">
            <span>بطاريات قلم</span>
            <span>10.00</span>
        </div>

        <div style="display:flex;justify-content:space-between">
            <span>شاحن هاتف</span>
            <span>25.00</span>
        </div>

        <div style="display:flex;justify-content:space-between">
            <span>كابل USB</span>
            <span>15.00</span>
        </div>

        <hr>

        <div style="
            display:flex;
            justify-content:space-between;
            font-weight:bold;
            font-size:16px;
        ">
            <span>الإجمالي</span>
            <span>50.00</span>
        </div>

        <hr>

        <div style="text-align:center;font-weight:bold">
            شكراً لزيارتكم
        </div>

        <div style="text-align:center">
            نتمنى لكم يوماً سعيداً
        </div>

        <br>
        <br>
        <br>

    </div>

</div>


<style>

@media print {

    @page {
        size: 58mm auto;
        margin: 0;
    }

    html,
    body {
        margin:0 !important;
        padding:0 !important;
        width:58mm !important;
    }

    body > * {
        display:none !important;
    }

    #testReceipt {
        display:block !important;
        width:58mm !important;
        margin:0 !important;
        padding:3mm !important;
    }

}

</style>


@script
<script>

    document.getElementById('printTestButton')?.addEventListener('click', function () {

        console.log('PRINT BUTTON CLICKED');

        window.print();

    });

</script>
@endscript
