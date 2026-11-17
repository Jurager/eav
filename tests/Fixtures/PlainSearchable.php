<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * A model searched with Scout alone: no attributes, no index definition of the package.
 */
class PlainSearchable extends Model
{
    use Searchable;

    protected $table = 'products';

    protected $fillable = ['name'];

    public function searchableAs(): string
    {
        return 'eav_test_plain';
    }
}
