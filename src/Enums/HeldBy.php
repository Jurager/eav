<?php

declare(strict_types=1);

namespace Jurager\Eav\Enums;

/** The side of a parent/variant pair that fills an attribute in. */
enum HeldBy: string
{
    case Parent = 'parent';
    case Variant = 'variant';
    case Both = 'both';

    /** The side an entity belongs to. */
    public static function of(bool $isVariant): self
    {
        return $isVariant ? self::Variant : self::Parent;
    }

    /** The side that keeps the value when this one does not. */
    public function opposite(): self
    {
        return match ($this) {
            self::Parent => self::Variant,
            self::Variant => self::Parent,
            self::Both => self::Both,
        };
    }
}
