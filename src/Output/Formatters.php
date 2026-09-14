<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Exception\ConfigException;

final class Formatters
{
    public static function for(string $format): FormatterInterface
    {
        switch ($format) {
            case 'table':
                return new TableFormatter();
            case 'json':
                return new JsonFormatter();
            default:
                throw new ConfigException('Unknown output format "'.$format.'"');
        }
    }
}
