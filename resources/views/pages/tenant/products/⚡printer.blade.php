<?php

use Livewire\Component;
use App\Models\Product;

new class extends Component
{
    public ?int $selectedProductId = null;
    public ?Product $product = null;

    // الباركود المحدد للطباعة
    public string $selectedBarcode = '';

    // خيارات إظهار / إخفاء العناصر
    public bool $showName = true;
    public bool $showBarcode = true;
    public bool $showPrice = true;
    public bool $showSku = false;

    // الأبعاد بالملم
    public string $labelWidth = '50';
    public string $labelHeight = '25';

    public function updatedSelectedProductId($value): void
    {
        if ($value) {
            $this->product = Product::with('barcodes')->find($value);

            $barcodes = $this->getBarcodesList();
            $this->selectedBarcode = $barcodes[0] ?? ($this->product->product_number ?? (string)$this->product->id);
        } else {
            $this->product = null;
            $this->selectedBarcode = '';
        }
    }

    public function getBarcodesList(): array
    {
        if (!$this->product) {
            return [];
        }

        $list = [];

        if ($this->product->relationLoaded('barcodes') && $this->product->barcodes->count() > 0) {
            foreach ($this->product->barcodes as $b) {
                if (!empty($b->barcode)) {
                    $list[] = $b->barcode;
                }
            }
        }

        if (empty($list)) {
            $list[] = $this->product->product_number ?? (string)$this->product->id;
        }

        return array_unique(array_filter($list));
    }

    public function selectBarcode(string $code): void
    {
        $this->selectedBarcode = $code;
    }

    public function printLabel(): void
    {
        if (!$this->product) {
            return;
        }

        if (empty($this->selectedBarcode)) {
            return;
        }

        $this->dispatch('open-print-window', [
            'id' => $this->product->id,
            'barcode' => $this->selectedBarcode,
            'showName' => $this->showName ? 1 : 0,
            'showBarcode' => $this->showBarcode ? 1 : 0,
            'showPrice' => $this->showPrice ? 1 : 0,
            'showSku' => $this->showSku ? 1 : 0,
            'w' => $this->labelWidth,
            'h' => $this->labelHeight,
        ]);
    }
};
?>

<div dir="rtl" class="p-4 sm:p-6 max-w-5xl mx-auto space-y-6">

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 space-y-5">
        <h1 class="text-xl font-bold text-gray-800 border-b pb-3">طباعة ملصقات الباركود متعددة الأكواد</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            {{-- 1. اختيار الصنف --}}
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">اختر الصنف / المنتج</label>
                <select
                    wire:model.live="selectedProductId"
                    class="w-full rounded-lg border border-gray-300 p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                >
                    <option value="">-- اختر صنفاً من القائمة --</option>
                    @foreach(\App\Models\Product::with('barcodes')->latest()->get() as $p)
                        @php
                            $firstBarcode = $p->barcodes->first()?->barcode ?? $p->product_number ?? 'لا يوجد';
                        @endphp
                        <option value="{{ $p->id }}">
                            الباركود: {{ $firstBarcode }} - {{ $p->name }} - (السعر: {{ $p->cost_price ?? 0 }} NIS)
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- 2. جدول الباركودات المتاحة للصنف --}}
            @if($product)
                <div class="md:col-span-2 border rounded-xl overflow-hidden shadow-sm bg-gray-50">
                    <div class="bg-gray-100 px-4 py-2 font-bold text-xs text-gray-700 border-b flex justify-between items-center">
                        <span>الباركودات المسجلة لهذا الصنف (اختر الباركود للطباعة):</span>
                        <span class="text-blue-600">عدد الباركودات: {{ count($this->getBarcodesList()) }}</span>
                    </div>

                    <div class="overflow-x-auto max-h-48 overflow-y-auto">
                        <table class="w-full text-right text-xs bg-white">
                            <thead class="bg-gray-200 text-gray-700 sticky top-0">
                                <tr>
                                    <th class="p-2 border-b">رقم الصنف</th>
                                    <th class="p-2 border-b">الإسم</th>
                                    <th class="p-2 border-b">السعر</th>
                                    <th class="p-2 border-b">العملة</th>
                                    <th class="p-2 border-b">رقم الباركود</th>
                                    <th class="p-2 border-b text-center">تحديد</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($this->getBarcodesList() as $code)
                                    <tr
                                        wire:click="selectBarcode('{{ $code }}')"
                                        class="hover:bg-blue-50 cursor-pointer transition {{ $selectedBarcode ===$code ? 'bg-blue-100 font-bold text-blue-900' : '' }}"
                                    >
                                        <td class="p-2">{{ $product->product_number ?? $product->id }}</td>
                                        <td class="p-2 truncate max-w-[200px]">{{ $product->name }}</td>
                                        <td class="p-2">{{ $product->cost_price ?? 0 }}</td>
                                        <td class="p-2">NIS</td>
                                        <td class="p-2 font-mono text-sm text-blue-700">{{ $code }}</td>
                                        <td class="p-2 text-center">
                                            <input
                                                type="radio"
                                                name="barcode_select"
                                                value="{{ $code }}"
                                                wire:model.live="selectedBarcode"
                                                class="text-blue-600 focus:ring-blue-500"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- 3. خيارات عناصر الملصق --}}
            <div class="md:col-span-2 border-t pt-4">
                <label class="block text-sm font-semibold text-gray-800 mb-2">العناصر المراد طباعتها على الملصق:</label>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="showName" class="rounded text-blue-600 focus:ring-blue-500">
                        <span>اسم الصنف</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="showBarcode" class="rounded text-blue-600 focus:ring-blue-500">
                        <span>الباركود</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="showPrice" class="rounded text-blue-600 focus:ring-blue-500">
                        <span>السعر</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="showSku" class="rounded text-blue-600 focus:ring-blue-500">
                        <span>رقم الصنف</span>
                    </label>
                </div>
            </div>

            {{-- 4. أبعاد الورق --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">عرض الملصق (مم)</label>
                <input type="number" wire:model.live="labelWidth" class="w-full rounded-lg border border-gray-300 p-2 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ارتفاع الملصق (مم)</label>
                <input type="number" wire:model.live="labelHeight" class="w-full rounded-lg border border-gray-300 p-2 text-sm">
            </div>

        </div>

        {{-- المعاينة والزر --}}
        @if($product)
            <div class="border-t pt-4 space-y-4">
                <label class="block text-xs font-semibold text-gray-500">معاينة الباركود المختار ({{ $selectedBarcode }}):</label>

                <div class="flex justify-center bg-gray-50 p-6 rounded-xl border border-dashed border-gray-300">
                    <div
                        class="bg-white border border-gray-800 p-2 text-center flex flex-col justify-between items-center shadow-sm overflow-hidden"
                        style="width: {{ $labelWidth }}mm; height: {{$labelHeight }}mm; box-sizing: border-box;"
                    >
                        @if($showName)
                            <div class="text-[10px] font-bold truncate w-full">{{ $product->name }}</div>
                        @endif

                        @if($showBarcode)
                            <div class="text-[11px] font-mono border-y border-dashed w-full my-auto py-1">
                                ||||||||||||||||||||||
                                <div class="text-blue-700 font-bold">{{ $selectedBarcode }}</div>
                            </div>
                        @endif

                        <div class="flex justify-between items-center w-full text-[9px] font-bold px-1">
                            @if($showSku)
                                <span>#{{ $product->product_number ?? $product->id }}</span>
                            @endif

                            @if($showPrice)
                                <span>{{ $product->cost_price }} NIS</span>
                            @endif
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    wire:click="printLabel"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg shadow transition flex items-center justify-center gap-2 cursor-pointer"
                >
                    <span>🖨️</span>
                    <span>طباعة الملصق للباركود المحدد ({{ $selectedBarcode }})</span>
                </button>
            </div>
        @else
            <div class="text-center py-4 text-sm text-gray-500 border-t">
                يرجى اختيار صنف لعرض خيارات الباركودات المتاحة
            </div>
        @endif

    </div>

    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('open-print-window', (params) => {
                const data = params[0];
                const url = `/print-barcode-single?id=${data.id}&bc=${encodeURIComponent(data.barcode)}&sn=${data.showName}&sb=${data.showBarcode}&sp=${data.showPrice}&sk=${data.showSku}&w=${data.w}&h=${data.h}`;

                window.open(url, '_blank', 'width=450,height=350');
            });
        });
    </script>
</div>
