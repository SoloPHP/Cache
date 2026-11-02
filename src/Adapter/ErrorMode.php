<?php

declare(strict_types=1);

namespace Solo\Cache\Adapter;

enum ErrorMode
{
    /** Storage failures raise Solo\Cache\Exception\CacheException. */
    case Throw;

    /** Storage failures fall back: reads return the default, writes and deletes return false. */
    case Fail;
}
