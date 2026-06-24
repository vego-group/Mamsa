<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit trail — NFR-014. Use AuditLog::record() to write entries;
 * the model has no updated_at because rows are never mutated.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'actor_id',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after'  => 'array',
    ];

    /**
     * Record an audit entry for a model transition.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        Model $auditable,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
    ): self {
        return static::create([
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id'   => $auditable->getKey(),
            'action'         => $action,
            'actor_id'       => $actorId ?? auth()->id(),
            'before'         => $before,
            'after'          => $after,
        ]);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
