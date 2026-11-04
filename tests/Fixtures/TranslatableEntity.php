<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasTranslations;

/**
 * A model with translated labels, backed by the products table.
 */
class TranslatableEntity extends Model
{
    use HasTranslations;

    protected $table = 'products';

    protected $fillable = ['name'];
}
