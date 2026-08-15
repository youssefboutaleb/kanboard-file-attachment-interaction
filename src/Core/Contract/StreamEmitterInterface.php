<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Core\Contract;

/**
 * Abstraction over raw HTTP header & body emission.
 *
 * Binary attachment streaming cannot go through Kanboard's `Response` service:
 * that object is a container singleton whose header bag is pre-loaded by
 * `BootstrapMiddleware` (CSP, P3P, and critically `X-Frame-Options: DENY`), and
 * it exposes no way to unset an entry. Calling `Response::send()` would always
 * re-emit `DENY` and break inline rendering, so the stream controller writes its
 * own headers instead. This interface keeps that side effect injectable so the
 * emitted header set stays assertable in unit tests.
 */
interface StreamEmitterInterface
{
    /**
     * Emit (or overwrite) a single response header.
     */
    public function emitHeader(string $name, string $value): void;

    /**
     * Drop a header that an earlier layer may already have queued.
     */
    public function removeHeader(string $name): void;

    /**
     * Write the raw response body.
     */
    public function emitBody(string $content): void;
}
