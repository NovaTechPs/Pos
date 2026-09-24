      @if ($showOpenShiftModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-300">
                    <div class="bg-indigo-900 text-white p-3.5 flex justify-between items-center font-bold text-sm">
                        <span>🔓 فتح شِفت جديد / بداية الدوام</span>
                        <button wire:click="$set('showOpenShiftModal', false)"
                            class="text-slate-300 hover:text-white font-bold">✕</button>
                    </div>
                    <div class="p-4 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الرصيد الافتتاحي في الدرج
                                (الفكة):</label>
                            <input type="number" step="0.01" wire:model="opening_cash"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-lg font-black font-mono text-center focus:outline-indigo-600">
                        </div>
                        <button wire:click="openShift"
                            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold p-3 rounded-xl text-xs shadow transition-all active:scale-95">
                            بدء العمل وفتح الصندوق
                        </button>
                    </div>
                </div>
            </div>
        @endif
