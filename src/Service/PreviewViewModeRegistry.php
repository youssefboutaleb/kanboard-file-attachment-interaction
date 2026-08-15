<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Service;

/**
 * Friendly file-type names and the rendered/raw view-mode rules.
 *
 * Two jobs that both answer "what is the user looking at?":
 *
 *   1. `getTypeLabel()` supplies the human name shown in the unified action bar,
 *      replacing the internal handler class names (`PdfPreviewHandler`, …) that used
 *      to leak into the UI.
 *   2. `supportsRawView()` decides which formats offer the Rendered/Raw toggle —
 *      only those with a rich rendering to switch away FROM.
 */
class PreviewViewModeRegistry
{
    public const VIEW_RENDERED = 'rendered';
    public const VIEW_RAW = 'raw';

    /**
     * Handlers that produce a rich rendering, so "show me the source instead" is a
     * meaningful request.
     *
     * PDF and Excel are absent: PDF has no text source to fall back to, and Excel (.xlsx)
     * is a binary ZIP spreadsheet that should always render directly.
     *
     * @var list<string>
     */
    private const RICH_HANDLERS = [
        'MarkdownPreviewHandler',
        'HtmlPreviewHandler',
        'CodePreviewHandler',
        'CsvPreviewHandler',
    ];

    /**
     * Friendly name per extension, preferred over the handler-derived name.
     *
     * @var array<string, string>
     */
    private const EXTENSION_LABELS = [
        'pdf' => 'PDF Document',
        'csv' => 'CSV Table',
        'tsv' => 'TSV Table',
        'xlsx' => 'Spreadsheet',
        'xls' => 'Spreadsheet',
        'md' => 'Markdown',
        'markdown' => 'Markdown',
        'json' => 'JSON',
        'xml' => 'XML',
        'html' => 'HTML',
        'htm' => 'HTML',
        'yaml' => 'YAML',
        'yml' => 'YAML',
        'env' => 'Config',
        'ini' => 'Config',
        'conf' => 'Config',
        'log' => 'Log',
        'txt' => 'Text',
        'sh' => 'Shell Script',
        'bash' => 'Shell Script',
        'py' => 'Python',
        'php' => 'PHP',
        'js' => 'JavaScript',
        'css' => 'CSS',
        'sql' => 'SQL',
    ];

    /**
     * Fallback name per handler, for attachments with no recognised extension.
     *
     * @var array<string, string>
     */
    private const HANDLER_LABELS = [
        'PdfPreviewHandler' => 'PDF Document',
        'CsvPreviewHandler' => 'CSV Table',
        'ExcelPreviewHandler' => 'Spreadsheet',
        'MarkdownPreviewHandler' => 'Markdown',
        'CodePreviewHandler' => 'Source',
        'JsonPreviewHandler' => 'JSON',
        'TextPreviewHandler' => 'Text',
        'BinaryNotice' => 'Binary File',
    ];

    /**
     * Human name for the action bar, e.g. "PDF Document" or "Spreadsheet".
     *
     * Never returns a class name: an unrecognised handler falls back to the neutral
     * "File" rather than leaking internals into the UI.
     */
    public function getTypeLabel(string $extension, string $handlerName): string
    {
        $normalized = strtolower(ltrim(trim($extension), '.'));

        if (isset(self::EXTENSION_LABELS[$normalized])) {
            return self::EXTENSION_LABELS[$normalized];
        }

        return self::HANDLER_LABELS[$handlerName] ?? 'File';
    }

    /**
     * Whether this view offers the Rendered/Raw toggle.
     */
    public function supportsRawView(string $handlerName): bool
    {
        return in_array($handlerName, self::RICH_HANDLERS, true);
    }

    /**
     * Validate the `view` request parameter, defaulting to the rendered view.
     *
     * Anything unrecognised is treated as rendered rather than reaching a branch.
     */
    public function normalizeViewMode(?string $view): string
    {
        if ($view === null) {
            return self::VIEW_RENDERED;
        }

        return strtolower(trim($view)) === self::VIEW_RAW ? self::VIEW_RAW : self::VIEW_RENDERED;
    }

    public function isRawView(?string $view): bool
    {
        return $this->normalizeViewMode($view) === self::VIEW_RAW;
    }

    /**
     * The view mode a toggle control should switch TO from the current one.
     */
    public function oppositeViewMode(string $currentView): string
    {
        return $this->normalizeViewMode($currentView) === self::VIEW_RAW
            ? self::VIEW_RENDERED
            : self::VIEW_RAW;
    }
}
