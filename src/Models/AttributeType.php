<?php

namespace Jurager\Eav\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Jurager\Eav\Support\EavModels;

/**
 * @property int $id
 * @property string $code
 * @property bool $localizable
 * @property bool $multiple
 * @property bool $unique
 * @property bool $filterable
 * @property bool $searchable
 */
class AttributeType extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'localizable', 'multiple', 'unique', 'filterable', 'searchable'];

    /**
     * Force any flags in $data to false for capabilities this type does not support.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function constrain(array $data): array
    {
        foreach (['localizable', 'multiple', 'unique', 'filterable', 'searchable'] as $flag) {
            if (array_key_exists($flag, $data) && ! $this->{$flag}) {
                $data[$flag] = false;
            }
        }

        return $data;
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(EavModels::class('attribute'), 'attribute_type_id');
    }

    protected function casts(): array
    {
        return [
            'localizable' => 'boolean',
            'multiple' => 'boolean',
            'unique' => 'boolean',
            'filterable' => 'boolean',
            'searchable' => 'boolean',
        ];
    }
}
