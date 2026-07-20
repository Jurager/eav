<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Product extends Model implements Attributable
{
    use HasAttributes;

    protected $table = 'products';

    protected $fillable = ['name'];

    /** @var array<string, callable> Test-only hook for AttributeValidatorTest. */
    public static array $uniqueScopes = [];

    public function getEavEntityType(): string
    {
        return 'product';
    }

    public static function attributeUniqueScopes(): array
    {
        return static::$uniqueScopes;
    }
}
