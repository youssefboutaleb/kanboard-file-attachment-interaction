<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

use Kanboard\Plugin\FileInteractionCore\Core\Contract\StreamEmitterInterface;

/**
 * Production emitter writing straight to PHP's SAPI header table.
 */
class HttpStreamEmitter implements StreamEmitterInterface
{
    public function emitHeader(string $name, string $value): void
    {
        if (headers_sent()) {
            return;
        }

        header($name . ': ' . $value, true);
    }

    public function removeHeader(string $name): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove($name);
    }

    public function emitBody(string $content): void
    {
        echo $content;
    }
}
