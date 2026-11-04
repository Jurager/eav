<?php

declare(strict_types=1);

namespace Jurager\Eav\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Eav\Registry\SchemaVersion;

/**
 * Invalidate the cached schema across processes.
 */
#[Signature('eav:clear')]
#[Description('Drop the attribute schema every process holds in memory.')]
class ClearCommand extends Command
{
    public function handle(SchemaVersion $version): void
    {
        $version->bump();

        $this->components->info('Attribute schema cleared — every process reads it again.');
    }
}
