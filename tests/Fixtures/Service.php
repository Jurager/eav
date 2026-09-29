<?php

declare(strict_types=1);

namespace Jurager\Eav\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Concerns\HasAttributes;
use Jurager\Eav\Contracts\Attributable;

class Service extends Model implements Attributable
{
    use HasAttributes;

    protected $table = 'services';

    protected $fillable = ['name'];

    public function getEntityType(): string
    {
        return 'service';
    }
}
