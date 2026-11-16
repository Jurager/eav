<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jurager\Eav\Concerns\HasTranslations;

/**
 * A soft-deleting model with translated labels.
 */
class SoftDeletingTranslatableEntity extends Model
{
    use HasTranslations;
    use SoftDeletes;

    protected $table = 'soft_deleting_entities';

    protected $fillable = ['name'];
}
