<?php

declare(strict_types=1);

/**
 * Global template helpers provided by the Kanboard runtime.
 *
 * Plugin templates are plain PHP files in the GLOBAL namespace, so t() must be
 * declared here rather than inside a namespaced test case.
 */

if (!function_exists('t')) {
    /**
     * Sprintf-style translation stand-in for Kanboard's t().
     *
     * @param mixed ...$args
     */
    function t(string $text, ...$args): string
    {
        return $args === [] ? $text : vsprintf($text, $args);
    }
}
