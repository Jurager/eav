<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;

    protected $table = 'products';

    protected $fillable = ['name', 'parent_id'];

    /** @var array<string, callable> Test-only hook for AttributeValidatorTest. */
    public static array $uniqueScopes = [];

    public function getEntityType(): string
    {
        return 'product';
    }

    protected function attributeParentRelationName(): ?string
    {
        return 'parent';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public static function attributeUniqueScopes(): array
    {
        return static::$uniqueScopes;
    }
}
