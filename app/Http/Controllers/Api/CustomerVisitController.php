<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerVisit;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The sales rep's daily customer list in the mobile app: add customers, then check each one off with
 * what came of the visit and a note.
 */
class CustomerVisitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $this->date($request);

        return response()->json([
            'date' => $date,
            'outcomes' => CustomerVisit::OUTCOMES,
            'visits' => $this->listFor($request->user(), $date),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = $this->date($request);
        $validated = $request->validate([
            'party_ids' => ['required', 'array', 'min:1', 'max:200'],
            'party_ids.*' => ['integer', 'distinct'],
        ]);
        $partyIds = $this->customers($user)->whereIn('id', $validated['party_ids'])->pluck('id');
        abort_if($partyIds->count() !== count($validated['party_ids']), 404, 'أحد الزبائن غير متاح.');

        foreach ($partyIds as $partyId) {
            CustomerVisit::addToList($user, (int) $partyId, $date);
        }

        return response()->json(['date' => $date, 'visits' => $this->listFor($user, $date)], 201);
    }

    public function update(Request $request, CustomerVisit $visit): JsonResponse
    {
        $this->authorizeOwnVisit($request->user(), $visit);
        $validated = $request->validate([
            'outcome' => ['nullable', Rule::in(array_keys(CustomerVisit::OUTCOMES))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $outcome = $validated['outcome'] ?? null;
        $visit->update([
            'outcome' => $outcome,
            'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
            'visited_at' => $outcome ? ($visit->visited_at ?? now()) : null,
        ]);

        return response()->json(['visit' => $visit->load('party')->toApi()]);
    }

    public function destroy(Request $request, CustomerVisit $visit): JsonResponse
    {
        $this->authorizeOwnVisit($request->user(), $visit);
        abort_if($visit->outcome !== null, 422, 'لا يمكن حذف زيارة مسجلة؛ امسح نتيجتها أولاً.');
        $visit->delete();

        return response()->json(['deleted' => true]);
    }

    private function date(Request $request): string
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return $request->filled('date') ? (string) $request->input('date') : Carbon::today()->toDateString();
    }

    /** Active customers the rep may see: their branch's and the store-wide ones. */
    private function customers(User $user): Builder
    {
        return Party::query()->where('tenant_id', $user->tenant_id)->whereIn('type', ['customer', 'both'])->where('is_active', true)
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where(fn ($branch) => $branch->where('branch_id', $user->branch_id)->orWhereNull('branch_id')));
    }

    /** @return list<array<string, mixed>> */
    private function listFor(User $user, string $date): array
    {
        return CustomerVisit::query()->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->whereDate('visit_date', $date)
            ->with('party')->orderBy('position')->orderBy('id')->get()
            ->map(fn (CustomerVisit $visit): array => $visit->toApi())->all();
    }

    private function authorizeOwnVisit(User $user, CustomerVisit $visit): void
    {
        abort_unless((int) $visit->tenant_id === (int) $user->tenant_id && (int) $visit->user_id === (int) $user->id, 404);
    }
}
