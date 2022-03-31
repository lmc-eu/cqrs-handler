<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Types\Utils as BaseUtils;
use Lmc\Cqrs\Types\ValueObject\DecodedValue;

/**
 * @internal
 */
class Utils
{
    /** @param mixed $value */
    public static function getType($value): string
    {
        // todo - move to Types and use it as default

        if ($value instanceof DecodedValue) {
            return sprintf('DecodedValue<%s>', self::getType($value->getValue()));
        }

        return BaseUtils::getType($value);
    }
}
