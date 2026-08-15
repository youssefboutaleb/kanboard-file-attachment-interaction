<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Canonical registry of syntax highlighting languages offered by the preview modal.
 *
 * Single source of truth for three things that used to be implicit:
 *   1. the option list rendered in the modal's language picker,
 *   2. the default language inferred from a file extension,
 *   3. the comment prefixes and keyword set the highlighter applies.
 *
 * Language switching is a SERVER-SIDE round trip: the picker reloads the preview
 * modal with a `lang` parameter, and highlighting stays in PHP where the payload
 * is already entity-escaped. Re-highlighting in the browser would mean porting
 * the tokenizer to JavaScript and maintaining two copies of an XSS-sensitive code
 * path.
 */
class SyntaxLanguageRegistry
{
    /**
     * Neutral language id meaning "no highlighting, escaped plain text".
     *
     * Resolves to TextPreviewHandler rather than CodePreviewHandler.
     */
    public const PLAIN_TEXT = 'text';

    /**
     * Supported languages, in the order the picker renders them.
     *
     * `comments` entries are matched against the ALREADY ENTITY-ESCAPED line, so
     * markup uses `&lt;!--` rather than `<!--`.
     *
     * @var array<string, array{label: string, comments: list<string>, keywords: list<string>}>
     */
    private const LANGUAGES = [
        'json' => [
            'label' => 'JSON',
            // JSON has no comment syntax.
            'comments' => [],
            'keywords' => ['true', 'false', 'null'],
        ],
        'yaml' => [
            'label' => 'YAML',
            'comments' => ['#'],
            'keywords' => ['true', 'false', 'null', 'yes', 'no', 'on', 'off'],
        ],
        'bash' => [
            'label' => 'Bash',
            'comments' => ['#'],
            'keywords' => [
                'if', 'then', 'else', 'elif', 'fi', 'for', 'while', 'do', 'done', 'case', 'esac',
                'function', 'return', 'export', 'local', 'readonly', 'source', 'echo', 'exit', 'set',
            ],
        ],
        'python' => [
            'label' => 'Python',
            'comments' => ['#'],
            'keywords' => [
                'def', 'class', 'return', 'if', 'elif', 'else', 'for', 'while', 'try', 'except',
                'finally', 'raise', 'import', 'from', 'as', 'with', 'lambda', 'yield', 'pass',
                'break', 'continue', 'global', 'nonlocal', 'assert', 'del', 'None', 'True', 'False',
                'and', 'or', 'not', 'in', 'is', 'async', 'await',
            ],
        ],
        'sql' => [
            'label' => 'SQL',
            'comments' => ['--'],
            'keywords' => [
                'select', 'insert', 'update', 'delete', 'from', 'where', 'join', 'inner', 'left',
                'right', 'outer', 'on', 'group', 'order', 'by', 'having', 'limit', 'offset', 'union',
                'into', 'values', 'set', 'create', 'alter', 'drop', 'table', 'index', 'view',
                'primary', 'foreign', 'key', 'not', 'null', 'and', 'or', 'as', 'distinct', 'count',
            ],
        ],
        'php' => [
            'label' => 'PHP',
            'comments' => ['//', '#', '/*', '*'],
            'keywords' => [
                'function', 'class', 'interface', 'trait', 'extends', 'implements', 'return', 'if',
                'else', 'elseif', 'foreach', 'for', 'while', 'switch', 'case', 'break', 'continue',
                'try', 'catch', 'finally', 'throw', 'new', 'public', 'private', 'protected', 'static',
                'const', 'abstract', 'final', 'namespace', 'use', 'echo', 'print', 'require',
                'require_once', 'include', 'include_once', 'array', 'true', 'false', 'null', 'instanceof',
            ],
        ],
        'css' => [
            'label' => 'CSS',
            // CSS only has block comments; a line opening or continuing one is
            // treated as a comment line.
            'comments' => ['/*', '*'],
            'keywords' => [
                'important', 'media', 'import', 'keyframes', 'supports', 'font-face', 'root',
                'inherit', 'initial', 'unset', 'auto', 'none', 'flex', 'grid', 'block', 'inline',
            ],
        ],
        'javascript' => [
            'label' => 'JavaScript',
            'comments' => ['//', '/*', '*'],
            'keywords' => [
                'function', 'class', 'extends', 'return', 'if', 'else', 'for', 'while', 'switch',
                'case', 'break', 'continue', 'try', 'catch', 'finally', 'throw', 'new', 'const',
                'let', 'var', 'async', 'await', 'yield', 'import', 'export', 'from', 'default',
                'typeof', 'instanceof', 'delete', 'in', 'of', 'this', 'true', 'false', 'null', 'undefined',
            ],
        ],
        'markup' => [
            'label' => 'XML / HTML',
            'comments' => ['&lt;!--'],
            'keywords' => [],
        ],
        'config' => [
            'label' => 'Config',
            'comments' => ['#', ';'],
            'keywords' => ['true', 'false', 'yes', 'no', 'on', 'off', 'null'],
        ],
        self::PLAIN_TEXT => [
            'label' => 'Plain Text',
            'comments' => [],
            'keywords' => [],
        ],
    ];

    /**
     * Default language per file extension.
     *
     * Every extension the preview whitelist accepts maps to one of the languages
     * above, so the picker always opens on a meaningful selection.
     *
     * @var array<string, string>
     */
    private const EXTENSION_MAP = [
        'json' => 'json',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'sh' => 'bash',
        'bash' => 'bash',
        'py' => 'python',
        'sql' => 'sql',
        'php' => 'php',
        'css' => 'css',
        'js' => 'javascript',
        'xml' => 'markup',
        'html' => 'markup',
        'htm' => 'markup',
        'env' => 'config',
        'ini' => 'config',
        'conf' => 'config',
        'txt' => self::PLAIN_TEXT,
        'log' => self::PLAIN_TEXT,
        'text' => self::PLAIN_TEXT,
    ];

    /**
     * Picker options as `id => label`, in render order.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        $options = [];

        foreach (self::LANGUAGES as $id => $definition) {
            $options[$id] = $definition['label'];
        }

        return $options;
    }

    public function isSupported(string $languageId): bool
    {
        return isset(self::LANGUAGES[$this->normalizeToken($languageId)]);
    }

    /**
     * Validate a user-supplied language id, returning null when unrecognised.
     *
     * Never trust the `lang` request parameter: an unknown value falls back to
     * the extension default rather than reaching the highlighter.
     */
    public function normalize(?string $languageId): ?string
    {
        if ($languageId === null) {
            return null;
        }

        $normalized = $this->normalizeToken($languageId);

        return isset(self::LANGUAGES[$normalized]) ? $normalized : null;
    }

    /**
     * Infer the default language for a file extension.
     *
     * Unmapped extensions fall back to plain text, which is what the unknown
     * extension flow relies on.
     */
    public function resolveFromExtension(string $extension): string
    {
        $normalized = $this->normalizeToken($extension);
        $normalized = ltrim($normalized, '.');

        return self::EXTENSION_MAP[$normalized] ?? self::PLAIN_TEXT;
    }

    public function getLabel(string $languageId): string
    {
        $normalized = $this->normalizeToken($languageId);

        return self::LANGUAGES[$normalized]['label'] ?? strtoupper($normalized);
    }

    /**
     * Comment prefixes for a language, matched against entity-escaped lines.
     *
     * @return list<string>
     */
    public function getCommentPrefixes(string $languageId): array
    {
        $normalized = $this->normalizeToken($languageId);

        return self::LANGUAGES[$normalized]['comments'] ?? [];
    }

    /**
     * Keyword set for a language.
     *
     * @return list<string>
     */
    public function getKeywords(string $languageId): array
    {
        $normalized = $this->normalizeToken($languageId);

        return self::LANGUAGES[$normalized]['keywords'] ?? [];
    }

    /**
     * True when the language means "render as escaped plain text, no highlighting".
     */
    public function isPlainText(string $languageId): bool
    {
        return $this->normalizeToken($languageId) === self::PLAIN_TEXT;
    }

    private function normalizeToken(string $value): string
    {
        return strtolower(trim($value));
    }
}
