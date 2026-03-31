<?php

namespace Jurager\Eav\Managers\Schema;

use Jurager\Eav\Models\AttributeType;
use Jurager\Eav\Support\EavModels;

class TypeSchema extends BaseSchema
{
    public function find(int $id): AttributeType
    {
        return EavModels::query('attribute_type')->findOrFail($id);
    }
}
