<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Concerns\HasSearchableAttributes;
use Jurager\Eav\Contracts\Attributable;
use Jurager\Eav\Search\Index\Index;

class SearchableCategory extends Model implements Attributable
{
    use HasAttributes;
    use HasSearchableAttributes;

    protected $table = 'search_categories';

    protected $fillable = ['name'];

    public function getEntityType(): string
    {
        return 'searchable_category';
    }

    public function searchableAs(): string
    {
        return 'eav_test_categories';
    }

    public function searchIndex(Index $index): void
    {
        $index->field('name')->searchable();
    }
}
