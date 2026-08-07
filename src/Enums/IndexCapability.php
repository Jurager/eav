<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

use Illuminate\Database\Eloquent\Model;
use Jurager\Eav\Search\Contracts\InteractsWithIndex;

enum IndexCapability: string
{
    case Filter = 'filter';
    case Sort = 'sort';

    /**
     * Whether the model grants this capability on the given index path.
     */
    public function allowed(Model $model, string $path): bool
    {
        if (! $model instanceof InteractsWithIndex) {
            return false;
        }

        $fields = $model->indexFields();

        while ($path !== '') {
            if (in_array($this, $fields[$path] ?? [], true)) {
                return true;
            }

            $path = substr($path, 0, (int) strrpos($path, '.'));
        }

        return false;
    }
}
