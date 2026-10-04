<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\BranchProduct;
use App\Models\ProductBarcode;
use App\Models\Branch;

new class extends Component
{
    use WithFileUploads;

    public $file;
    public $tenantId = 1;

    public function import()
    {
        $this->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ], [
            'file.required' => 'يرجى اختيار الملف أولاً.',
            'file.mimes'    => 'يجب أن يكون الملف بصيغة CSV.',
            'file.max'      => 'حجم الملف يجب ألا يتجاوز 10MB.',
        ]);

        $tenantId = (int) $this->tenantId;

        // جميع فروع هذا الـ Tenant فقط.
        $branches = Branch::where('tenant_id', $tenantId)->get();

        if ($branches->isEmpty()) {
            session()->flash(
                'error',
                'لا توجد فروع مسجلة لهذا المستأجر لإضافة المنتجات إليها.'
            );
            return;
        }

        $path = $this->file->getRealPath();

        // قراءة الملف ومعالجة BOM والترميز العربي.
        $fileContent = file_get_contents($path);
        $fileContent = preg_replace('/^\xEF\xBB\xBF/', '', $fileContent);

        if (!mb_check_encoding($fileContent, 'UTF-8')) {
            $converted = @iconv('Windows-1256', 'UTF-8//IGNORE', $fileContent);

            if ($converted !== false) {
                $fileContent = $converted;
            }
        }

        $tempStream = fopen('php://memory', 'r+');
        fwrite($tempStream, $fileContent);
        rewind($tempStream);

        // تحديد الفاصلة تلقائياً: , أو ;
        $firstLine = fgets($tempStream);
        rewind($tempStream);

        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ','))
            ? ';'
            : ',';

        // تخطي عناوين الأعمدة.
        fgetcsv($tempStream, 0, $delimiter);

        DB::beginTransaction();

        try {
            /*
             |--------------------------------------------------------------
             | الأعمدة حسب ملف Excel / CSV الحالي:
             |
             | A = رقم الصنف
             | B = اسم الصنف
             | C = الكمية الحالية
             | D = آخر سعر شراء
             | E = سعر بيع الوحدة الرئيسية
             | F = الباركود الرئيسي
             | G = باركودات الوحدات الأخرى
             | H = غير مستخدم في هذا الاستيراد
             |--------------------------------------------------------------
             */

            $createdProducts = 0;
            $existingProducts = 0;
            $addedBarcodes = 0;
            $skippedBarcodes = 0;
            $processedRows = 0;
            $skippedRows = 0;

            // تخزين المنتجات التي تمت معالجتها داخل نفس الملف.
            $productsCache = [];

            // لتجنب فحص نفس الباركود عدة مرات داخل نفس عملية الاستيراد.
            $barcodeCache = [];

            while (($data = fgetcsv($tempStream, 0, $delimiter)) !== false) {

                $processedRows++;

                $data = array_map(function ($value) {
                    return is_string($value) ? trim($value) : $value;
                }, $data);

                /*
                 | رقم الصنف هو مفتاح المنتج الأساسي.
                 | لا نعتمد على الاسم لتحديد تطابق المنتجات.
                 */
                $itemNumber = trim((string) ($data[0] ?? ''));
                $name = trim((string) ($data[1] ?? ''));

                // C = الكمية الحالية
                $stockQty = $this->parseNumber($data[2] ?? 0);

                // D = آخر سعر شراء -> retail_price
                $purchasePrice = $this->parseNumber($data[3] ?? 0);

                // E = سعر بيع الوحدة الرئيسية -> wholesale_price
                $mainUnitSalePrice = $this->parseNumber($data[4] ?? 0);

                // F = الباركود الرئيسي
                $mainBarcode = trim((string) ($data[5] ?? ''));

                // G = باركودات الوحدات الأخرى
                $otherBarcodes = trim((string) ($data[6] ?? ''));

                if ($name === '' && $itemNumber === '') {
                    $skippedRows++;
                    continue;
                }

                /*
                 |----------------------------------------------------------
                 | إيجاد المنتج:
                 |
                 | 1) إذا كان رقم الصنف موجوداً، نبحث في قاعدة البيانات
                 |    بواسطة tenant_id + product_number.
                 |
                 | 2) إذا تكرر نفس الرقم في نفس الملف، نستخدم المنتج نفسه.
                 |
                 | هذا يمنع:
                 | 100001812 بطاريات قلم
                 | 100001812 بطاريات قلم
                 |
                 | من إنشاء Productين.
                 |----------------------------------------------------------
                 */
                if ($itemNumber !== '') {
                    $productKey = $itemNumber;

                    if (isset($productsCache[$productKey])) {
                        $product = $productsCache[$productKey];
                    } else {
                        $product = Product::where('tenant_id', $tenantId)
                            ->where('product_number', $itemNumber)
                            ->first();

                        if ($product) {
                            $existingProducts++;
                        } else {
                            /*
                             | استخدم assignment بدلاً من create حتى لا تعتمد
                             | على fillable في Product لإضافة product_number.
                             */
                            $product = new Product();
                            $product->tenant_id = $tenantId;
                            $product->product_number = $itemNumber;
                            $product->name = $name;
                            $product->cost_price = $purchasePrice;
                            $product->show_in_website = true;
                            $product->is_price_unified = false;
                            $product->images = [];
                            $product->save();

                            $createdProducts++;
                        }

                        $productsCache[$productKey] = $product;
                    }
                } else {
                    /*
                     | لا يوجد رقم صنف.
                     | لا نستخدم الاسم كمفتاح دائم حتى لا ندمج منتجات
                     | مختلفة تحمل نفس الاسم.
                     | ننشئ المنتج بدون product_number.
                     */
                    $product = new Product();
                    $product->tenant_id = $tenantId;
                    $product->product_number = null;
                    $product->name = $name;
                    $product->cost_price = $purchasePrice;
                    $product->show_in_website = true;
                    $product->is_price_unified = false;
                    $product->images = [];
                    $product->save();

                    $createdProducts++;
                }

                /*
                 |----------------------------------------------------------
                 | BranchProduct
                 |
                 | المنتج الواحد له BranchProduct واحد لكل فرع.
                 | إذا كان المنتج موجوداً مسبقاً، نحدّث بياناته بدلاً من
                 | إنشاء سجل مكرر.
                 |----------------------------------------------------------
                 */
                foreach ($branches as $branch) {
                    $branchProduct = BranchProduct::where('tenant_id', $tenantId)
                        ->where('branch_id', $branch->id)
                        ->where('product_id', $product->id)
                        ->first();

                    if (!$branchProduct) {
                        $branchProduct = new BranchProduct();
                        $branchProduct->tenant_id = $tenantId;
                        $branchProduct->branch_id = $branch->id;
                        $branchProduct->product_id = $product->id;
                    }

                    $branchProduct->stock_quantity = $stockQty;
                    $branchProduct->alert_quantity = $branchProduct->alert_quantity ?? 5.00;

                    // آخر سعر شراء
                    $branchProduct->retail_price = $purchasePrice;

                    // سعر بيع الوحدة الرئيسية
                    $branchProduct->wholesale_price = $mainUnitSalePrice;

                    $branchProduct->save();
                }

                /*
                 |----------------------------------------------------------
                 | دالة داخلية لإضافة باركود واحد.
                 | لا نضيف الباركود إذا كان موجوداً مسبقاً لنفس الـ Tenant.
                 |----------------------------------------------------------
                 */
                $addBarcode = function (string $barcode) use (
                    $tenantId,
                    $product,
                    &$barcodeCache,
                    &$addedBarcodes,
                    &$skippedBarcodes
                ) {
                    $barcode = trim($barcode);

                    if ($barcode === '') {
                        return;
                    }

                    $cacheKey = $tenantId . '|' . $barcode;

                    // نفس الباركود تمت معالجته أثناء هذا الاستيراد.
                    if (array_key_exists($cacheKey, $barcodeCache)) {
                        $skippedBarcodes++;
                        return;
                    }

                    $existingBarcode = ProductBarcode::where('tenant_id', $tenantId)
                        ->where('barcode', $barcode)
                        ->first();

                    if ($existingBarcode) {
                        /*
                         | إذا كان الباركود موجوداً، لا نأخذه ولا ننقله
                         | إلى منتج آخر حتى لا يحدث تعارض.
                         */
                        $barcodeCache[$cacheKey] = true;
                        $skippedBarcodes++;
                        return;
                    }

                    ProductBarcode::create([
                        'tenant_id'  => $tenantId,
                        'product_id' => $product->id,
                        'barcode'    => $barcode,
                    ]);

                    $barcodeCache[$cacheKey] = true;
                    $addedBarcodes++;
                };

                // F = الباركود الرئيسي
                if ($mainBarcode !== '') {
                    $addBarcode($mainBarcode);
                }

                /*
                 | G قد يحتوي باركوداً واحداً أو عدة باركودات، مثلاً:
                 |
                 | 2012334434350
                 | 2012334434329
                 |
                 | أو:
                 |
                 | 2012334434350,2012334434329
                 */
                if ($otherBarcodes !== '') {
                    $otherBarcodeList = preg_split(
                        '/[\s,;|]+/',
                        $otherBarcodes,
                        -1,
                        PREG_SPLIT_NO_EMPTY
                    );

                    foreach ($otherBarcodeList as $otherBarcode) {
                        $addBarcode($otherBarcode);
                    }
                }
            }

            DB::commit();

            session()->flash(
                'success',
                "تم الاستيراد بنجاح. " .
                "المنتجات الجديدة: {$createdProducts}، " .
                "المنتجات الموجودة: {$existingProducts}، " .
                "الباركودات المضافة: {$addedBarcodes}، " .
                "الباركودات المتجاهلة/المكررة: {$skippedBarcodes}، " .
                "الصفوف المتجاهلة: {$skippedRows}."
            );

            $this->reset('file');

        } catch (\Throwable $e) {
            DB::rollBack();

            session()->flash(
                'error',
                'حدث خطأ أثناء الاستيراد: ' . $e->getMessage()
            );
        } finally {
            fclose($tempStream);
        }
    }

    /**
     * تحويل الأرقام القادمة من Excel / CSV إلى رقم عشري.
     */
    private function parseNumber($value): float
    {
        if ($value === null || $value === '') {
            return 0.00;
        }

        $value = trim((string) $value);
        $value = str_replace(["\u{00A0}", ' '], '', $value);

        // إزالة علامات العملة إن وجدت.
        $value = preg_replace('/[^0-9,\.\-]+/u', '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            // 1,234.56
            if (strrpos($value, '.') > strrpos($value, ',')) {
                $value = str_replace(',', '', $value);
            } else {
                // 1.234,56
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        } elseif (str_contains($value, ',')) {
            // في ملفك القيم مثل 0,600 و -260,000
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.00;
    }
};
?>

<div class="p-6 bg-white rounded-lg shadow-md max-w-xl mx-auto my-8">
    <h2 class="text-xl font-bold mb-4 text-gray-800">استيراد المنتجات من ملف CSV / Excel</h2>

    @if (session()->has('success'))
        <div class="p-3 mb-4 text-sm text-green-700 bg-green-100 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-3 mb-4 text-sm text-red-700 bg-red-100 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <form wire:submit.prevent="import" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">اختر الملف:</label>

            <input
                type="file"
                wire:model="file"
                accept=".csv,.txt"
                class="w-full border border-gray-300 p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >

            <div wire:loading wire:target="file" class="text-blue-500 text-xs mt-1">
                جاري رفع الملف المعاين...
            </div>

            @error('file')
                <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
            @enderror
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="file, import"
            class="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white font-bold py-2 px-4 rounded-md transition duration-200"
        >
            <span wire:loading.remove wire:target="import">رفع واستيراد البيانات</span>
            <span wire:loading wire:target="import">جاري الاستيراد والمعالجة...</span>
        </button>
    </form>
</div>
