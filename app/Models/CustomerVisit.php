<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerVisit extends Model
{
    use BelongsToTenant;

    /** @var array<string, string> What came of a visit. */
    public const OUTCOMES = [
        'sale' => 'بيع',
        'collection' => 'تحصيل فقط',
        'order' => 'يوجد طلبية',
    ];

    protected $fillable = ['tenant_id', 'branch_id', 'user_id', 'party_id', 'visit_date', 'position', 'outcome', 'notes', 'visited_at', 'created_by'];

    protected $casts = ['visit_date' => 'date', 'visited_at' => 'datetime', 'position' => 'integer'];

    /** Puts a customer on a rep's list for the day, at the end, unless the customer is already on it. */
    public static function addToList(User $rep, int $partyId, string $date, ?int $createdBy = null): self
    {
        $existing = self::query()->where('user_id', $rep->id)->where('party_id', $partyId)->whereDate('visit_date', $date)->first();
        if ($existing) {
            return $existing;
        }
        $position = (int) self::query()->where('user_id', $rep->id)->whereDate('visit_date', $date)->max('position');

        return self::query()->create([
            'tenant_id' => $rep->tenant_id, 'branch_id' => $rep->branch_id, 'user_id' => $rep->id, 'party_id' => $partyId,
            'visit_date' => $date, 'position' => $position + 1, 'created_by' => $createdBy ?? $rep->id,
        ]);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'visit_date' => $this->visit_date?->toDateString(),
            'position' => $this->position,
            'outcome' => $this->outcome,
            'outcome_label' => self::OUTCOMES[$this->outcome] ?? null,
            'notes' => $this->notes,
            'visited_at' => $this->visited_at?->toIso8601String(),
            'customer' => $this->party ? [
                'id' => $this->party->id,
                'name' => $this->party->name,
                'code' => $this->party->code,
                'phone' => $this->party->phone,
                'address' => $this->party->address,
                'current_balance' => (float) $this->party->current_balance,
            ] : null,
        ];
    }
}
