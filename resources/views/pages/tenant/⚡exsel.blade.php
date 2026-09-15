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
            'file' => 'required|file',
        ], [
            'file.required' => 'يرجى اختيار الملف أولاً.',
        ]);

        // 1. جلب جميع الفروع التابعة للمستأجر
        $branches = Branch::where('tenant_id', $this->tenantId)->get();

        if ($branches->isEmpty()) {
            session()->flash('error', 'لا توجد فروع مسجلة لهذا المستأجر لإضافة المنتجات إليها.');
            return;
        }

        $path = $this->file->getRealPath();

        // 2. قراءة محتوى الملف مع المعالجة للغة العربية
        $fileContent = file_get_contents($path);
        $fileContent = preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $fileContent);

        if (!mb_check_encoding($fileContent, 'UTF-8')) {
            $fileContent = iconv('Windows-1256', 'UTF-8//IGNORE', $fileContent);
        }

        $tempStream = fopen('php://memory', 'r+');
        fwrite($tempStream, $fileContent);
        rewind($tempStream);

        // 3. تحديد الفاصلة المعتمدة (, أو ;)
        $firstLine = fgets($tempStream);
        rewind($tempStream);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        // تخطي السطر الأول (عناوين الأعمدة)
        fgetcsv($tempStream, 1000, $delimiter);

        DB::beginTransaction();

        try {
            while (($data = fgetcsv($tempStream, 1000, $delimiter)) !== FALSE) {
                $name           = trim($data[1] ?? '');
                $stockQty       = (float) str_replace(',', '.', $data[2] ?? 0);
                $costPrice      = (float) str_replace(',', '.', $data[3] ?? 0);
                $retailPrice    = (float) str_replace(',', '.', $data[4] ?? 0);
                $barcode        = trim($data[5] ?? '');
                $wholesalePrice = (float) str_replace(',', '.', $data[6] ?? 0);

                if (empty($name)) continue;

                // أ. إنشاء المنتج الأساسي مع تعيين is_price_unified إلى false
                $product = Product::create([
                    'tenant_id'        => $this->tenantId,
                    'name'             => $name,
                    'cost_price'       => $costPrice,
                    'show_in_website'  => 1,
                    'is_price_unified' => false,
                    'images'           => json_encode([]),
                ]);

                // ب. إضافة بيانات المنتج لجميع فروع المستأجر
                foreach ($branches as $branch) {
                    BranchProduct::create([
                        'tenant_id'       => $this->tenantId,
                        'branch_id'       => $branch->id,
                        'product_id'      => $product->id,
                        'stock_quantity'  => $stockQty,
                        'alert_quantity'  => 5.00,
                        'retail_price'    => $retailPrice,
                        'wholesale_price' => $wholesalePrice,
                    ]);
                }

                // ج. إضافة الباركود (مع تجاوز الباركود المكرر إن وجد)
                if (!empty($barcode)) {
                    $exists = ProductBarcode::where('tenant_id', $this->tenantId)
                        ->where('barcode', $barcode)
                        ->exists();

                    if (!$exists) {
                        ProductBarcode::create([
                            'tenant_id'  => $this->tenantId,
                            'product_id' => $product->id,
                            'barcode'    => $barcode,
                        ]);
                    }
                }
            }

            DB::commit();
            session()->flash('success', 'تم استيراد المنتجات وإضافتها لجميع الفروع بنجاح!');
            $this->reset('file');

        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage());
        } finally {
            fclose($tempStream);
        }
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
            <input type="file" wire:model="file" class="w-full border border-gray-300 p-2 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            @error('file') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>

        <button type="submit" wire:loading.attr="disabled" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md transition duration-200">
            <span wire:loading.remove>رفع واستيراد البيانات</span>
            <span wire:loading>جاري الاستيراد...</span>
        </button>
    </form>
</div>
