<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IgnoredDuplicate extends Model
{
    public const TYPE_SITE   = 'site';
    public const TYPE_BALISE = 'balise';

    protected $fillable = [
        'entity_type',
        'entity_a_id',
        'entity_b_id',
        'ignored_by',
    ];

    protected $casts = [
        'entity_a_id' => 'integer',
        'entity_b_id' => 'integer',
        'ignored_by'  => 'integer',
    ];

    /**
     * Ordonne canoniquement une paire (a_id < b_id) et renvoie [type, a, b].
     *
     * @return array{0:string,1:int,2:int}
     */
    public static function pairKey(string $type, int $idA, int $idB): array
    {
        if ($idA === $idB) {
            throw new \InvalidArgumentException('Une paire de doublon doit concerner deux entités distinctes.');
        }
        return $idA < $idB
            ? [$type, $idA, $idB]
            : [$type, $idB, $idA];
    }

    public function ignorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by');
    }
}
