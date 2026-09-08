<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasTranslations;

/**
 * Minimal non-EAV model with a direct entity_translations relation — stands in for
 * models like Region/Payment/Currency that use HasTranslations, not HasAttributes.
 * Backed by the existing `products` table purely for storage (id/name columns).
 */
class TranslatableEntity extends Model
{
    use HasTranslations;

    protected $table = 'products';

    protected $fillable = ['name'];
}
