<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller;

// Require stub if running standalone outside Kanboard runtime
if (!class_exists('Kanboard\Controller\BaseController')) {
    require_once __DIR__ . '/../../tests/stubs/BaseController.php';
}

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\FileInteractionCore\Controller\Concerns\HandlesAttachmentInteraction;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileContentFetcherInterface;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\FileHandlerInterface;
use Kanboard\Plugin\FileInteractionCore\Core\FileInteractionManager;
use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;
use Kanboard\Plugin\FileInteractionCore\Handler\CodePreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\CsvPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\DocxPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\ExcelPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\HtmlPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\JsonPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\MarkdownPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\PdfPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\PptxPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Handler\TextPreviewHandler;
use Kanboard\Plugin\FileInteractionCore\Service\BinaryContentDetector;
use Kanboard\Plugin\FileInteractionCore\Service\CsvDelimiterRegistry;
use Kanboard\Plugin\FileInteractionCore\Service\FileEditValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;
use Kanboard\Plugin\FileInteractionCore\Service\PreviewViewModeRegistry;
use Kanboard\Plugin\FileInteractionCore\Service\SyntaxLanguageRegistry;

/**
 * Controller handling safe attachment preview requests with ACL enforcement.
 *
 * @property mixed $helper
 */
class FilePreviewController extends BaseController
{
    use HandlesAttachmentInteraction;

    /**
     * Absolute ceiling on bytes pulled into memory for a preview.
     *
     * Task 36 lets attachments with unrecognised extensions reach the preview, so
     * an arbitrary upload — a 500 MB archive — can now be the target. When the
     * attachment row declares a size above this, the content is never read and
     * the request is answered from metadata alone. Matches the largest per-format
     * cap (PDF, 10 MB), so no format that previously previewed is affected.
     */
    public const CONTENT_READ_CEILING_BYTES = FileValidationService::PDF_MAX_SIZE_BYTES;

    private PermissionService $permissionService;
    private FileValidationService $validationService;
    private FileInteractionManager $interactionManager;
    private ?FileContentFetcherInterface $contentFetcher;
    private SyntaxLanguageRegistry $languageRegistry;
    private BinaryContentDetector $binaryDetector;
    private CsvDelimiterRegistry $delimiterRegistry;
    private PreviewViewModeRegistry $viewModeRegistry;

    /**
     * @param mixed $container
     */
    public function __construct(
        $container = null,
        ?PermissionService $permissionService = null,
        ?FileValidationService $validationService = null,
        ?FileInteractionManager $interactionManager = null,
        ?FileContentFetcherInterface $contentFetcher = null,
        ?SyntaxLanguageRegistry $languageRegistry = null,
        ?BinaryContentDetector $binaryDetector = null,
        ?CsvDelimiterRegistry $delimiterRegistry = null,
        ?PreviewViewModeRegistry $viewModeRegistry = null
    ) {
        if ($container !== null && is_object($container)) {
            parent::__construct($container);
        }
        $this->permissionService = $permissionService ?? new PermissionService();
        $this->validationService = $validationService ?? new FileValidationService();
        $this->languageRegistry = $languageRegistry ?? new SyntaxLanguageRegistry();
        $this->binaryDetector = $binaryDetector ?? new BinaryContentDetector();
        $this->delimiterRegistry = $delimiterRegistry ?? new CsvDelimiterRegistry();
        $this->viewModeRegistry = $viewModeRegistry ?? new PreviewViewModeRegistry();

        if ($interactionManager === null) {
            $interactionManager = new FileInteractionManager();
            // Registration order is significant — first match wins:
            //   1. Pdf, Excel, Docx, Pptx claim binary document formats and must precede
            //      every text handler so they never fall through to an escaped-text
            //      view.
            //   2. Csv/Markdown/Html/Json claim narrow, unambiguous formats.
            //   3. Code claims the remaining source & config extensions. It is
            //      registered AFTER Json so .json keeps its pretty-printed view.
            //   4. Text is the catch-all: it accepts ANY text/* MIME type, so it
            //      must always be registered last.
            $interactionManager->registerHandler(new PdfPreviewHandler());
            $interactionManager->registerHandler(new ExcelPreviewHandler());
            $interactionManager->registerHandler(new DocxPreviewHandler());
            $interactionManager->registerHandler(new PptxPreviewHandler());
            $interactionManager->registerHandler(new CsvPreviewHandler());
            $interactionManager->registerHandler(new MarkdownPreviewHandler());
            $interactionManager->registerHandler(new HtmlPreviewHandler());
            $interactionManager->registerHandler(new JsonPreviewHandler());
            $interactionManager->registerHandler(new CodePreviewHandler());
            $interactionManager->registerHandler(new TextPreviewHandler());
        }

        $this->interactionManager = $interactionManager;
        $this->contentFetcher = $contentFetcher;
    }

    /**
     * Handle attachment preview request.
     *
     * @param int|null $projectId
     * @param int|null $taskId
     * @param int|null $fileId
     * @param string|null $filename
     * @param string|null $rawContent
     * @param string|null $forcedFormat
     * @param string|null $mimeType
     * @param string|null $requestedLanguage Explicit language picked in the modal.
     * @param string|null $delimiterToken CSV delimiter token picked in the modal.
     * @param bool|null $hasHeaderRow Whether the CSV first row is a header.
     * @param string|null $viewMode `rendered` (rich view) or `raw` (escaped source).
     * @return mixed
     *
     * @throws AccessDeniedException
     * @throws InvalidFileException
     */
    public function show(
        ?int $projectId = null,
        ?int $taskId = null,
        ?int $fileId = null,
        ?string $filename = null,
        ?string $rawContent = null,
        ?string $forcedFormat = null,
        ?string $mimeType = null,
        ?string $requestedLanguage = null,
        ?string $delimiterToken = null,
        ?bool $hasHeaderRow = null,
        ?string $viewMode = null
    ) {
        $hasRequest = $this->hasService('request');

        // Extract parameters from Kanboard HTTP request if not directly passed
        if (empty($fileId) && $hasRequest) {
            $fileId = (int) $this->request->getIntegerParam('file_id');
        }
        if (empty($taskId) && $hasRequest) {
            $taskId = (int) $this->request->getIntegerParam('task_id');
        }
        if (empty($projectId) && $hasRequest) {
            $projectId = (int) $this->request->getIntegerParam('project_id');
        }
        if (empty($forcedFormat) && $hasRequest) {
            $forcedFormat = (string) $this->request->getStringParam('format') ?: null;
        }
        if (empty($requestedLanguage) && $hasRequest) {
            $requestedLanguage = (string) $this->request->getStringParam('lang') ?: null;
        }

        // Never trust the picker parameter: an unrecognised id is discarded here
        // and the extension default applies instead.
        $selectedLanguage = $this->languageRegistry->normalize($requestedLanguage);

        if ($viewMode === null && $hasRequest) {
            $viewMode = (string) $this->request->getStringParam('view') ?: null;
        }
        // Anything unrecognised is treated as the rendered view.
        $viewMode = $this->viewModeRegistry->normalizeViewMode($viewMode);

        if ($delimiterToken === null && $hasRequest) {
            $delimiterToken = (string) $this->request->getStringParam('delimiter') ?: null;
        }
        // Unrecognised tokens collapse to auto-detection rather than reaching the parser.
        $delimiterToken = $this->delimiterRegistry->normalizeToken($delimiterToken);

        /**
         * The header toggle is checked by default, so its parameter is tri-state:
         * absent means "not yet touched" (default on), and only an explicit `0`
         * turns it off. Reading it as a plain boolean would make the default off.
         */
        if ($hasHeaderRow === null && $hasRequest) {
            $headerParam = (string) $this->request->getStringParam('header');
            $hasHeaderRow = $headerParam === '' ? true : $headerParam === '1';
        }
        $hasHeaderRow = $hasHeaderRow ?? true;

        $fileId = $fileId ?? 0;
        $taskId = $taskId ?? 0;
        $projectId = $projectId ?? 0;

        // Project attachments live in a different table than task attachments
        $source = 'task';
        if ($hasRequest) {
            $source = $this->request->getStringParam('source') === 'project' ? 'project' : 'task';
        }
        $modelName = $source === 'project' ? 'projectFileModel' : 'taskFileModel';

        $file = null;

        // Fetch file metadata & path from Kanboard core models if running inside Kanboard
        if ($fileId > 0 && $this->hasService($modelName)) {
            try {
                $file = $this->{$modelName}->getById($fileId);
            } catch (\Throwable $e) {
                $file = null;
            }
        }

        // Size the attachment row declares, used both to skip pointless reads and
        // to keep the cap honest when the content was never loaded.
        $declaredSize = 0;

        if (!empty($file) && is_array($file)) {
            $taskId = $taskId > 0 ? $taskId : (int)($file['task_id'] ?? 0);
            $projectId = $projectId > 0 ? $projectId : (int)($file['project_id'] ?? 0);
            $filename = $filename ?? ($file['name'] ?? null);
            $declaredSize = (int) ($file['size'] ?? 0);

            // Refuse to pull an oversized attachment into memory at all. Without
            // this, allowing unknown extensions through would let any upload be
            // fully buffered before the size cap is ever consulted.
            if ($declaredSize > self::CONTENT_READ_CEILING_BYTES) {
                $rawContent = null;
            } elseif ($rawContent === null && !empty($file['path'])) {
                if ($this->hasService('objectStorage')) {
                    try {
                        $rawContent = $this->objectStorage->get($file['path']);
                    } catch (\Throwable $e) {
                        $rawContent = null;
                    }
                }

                if (empty($rawContent)) {
                    // Fallback to direct file read from FILES_DIR if objectStorage wrapper is uninitialized
                    $filesDir = defined('FILES_DIR') ? FILES_DIR : '/var/www/app/data/files';
                    $filePath = $filesDir . '/' . $file['path'];
                    if (file_exists($filePath) && is_readable($filePath)) {
                        $contentRead = file_get_contents($filePath);
                        if ($contentRead !== false) {
                            $rawContent = $contentRead;
                        }
                    }
                }
            }
        }

        // Resolve the owning project when only a task id was supplied (direct route access)
        if ($projectId === 0 && $taskId > 0 && $this->hasService('taskFinderModel')) {
            try {
                $projectId = (int) $this->taskFinderModel->getProjectId($taskId);
            } catch (\Throwable $e) {
                $projectId = 0;
            }
        }

        // Fallback to content fetcher interface if rawContent still null
        if ($rawContent === null && $this->contentFetcher !== null) {
            $rawContent = $this->contentFetcher->getFileContent($fileId);
        }

        $content = $rawContent ?? '';

        // Rendering is only possible when both the template engine and the HTTP
        // response service are present (i.e. running inside the Kanboard runtime).
        $canRender = $this->hasService('response') && $this->hasService('template');

        try {
            $safeFilename = $filename !== null ? $this->validationService->sanitizeFilename($filename) : 'attachment.txt';

            // Step 1: Enforce ACL permissions
            $this->permissionService->assertUserCanReadFile($projectId, $taskId, $fileId);

            $rawExtension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));

            /**
             * Step 2: unknown or missing extension — classify by content instead.
             *
             * The whitelist cannot speak for these files, so the payload decides:
             * printable text gets an escaped preview with the language picker,
             * anything binary gets a download notice. Neither outcome parses or
             * executes the attachment.
             */
            $isUnclassified = ($rawExtension === '' || !$this->validationService->isAllowedExtension($rawExtension))
                // Image/audio/video stay rejected: core owns those viewers, and it
                // keeps active content like SVG out of every preview path.
                && !$this->validationService->isCoreMediaExtension($rawExtension);

            if ($isUnclassified) {
                return $this->showUnclassifiedAttachment(
                    $content,
                    $rawContent === null && $declaredSize > self::CONTENT_READ_CEILING_BYTES,
                    $declaredSize,
                    $selectedLanguage,
                    $canRender,
                    [
                        'projectId' => $projectId,
                        'taskId' => $taskId,
                        'fileId' => $fileId,
                        'filename' => $safeFilename,
                        'extension' => $rawExtension,
                        'source' => $source,
                    ]
                );
            }

            // Validate file extension & size bounds.
            // The extension drives the size cap: PDFs get 10 MB, text stays at 500 KB.
            $extension = $this->validationService->validateExtension($safeFilename);

            // The declared size stands in when the content was skipped as oversized,
            // so the cap still reports the real violation instead of passing on a
            // zero-length buffer.
            $this->validationService->validateFileSize(max(strlen($content), $declaredSize), $extension);

            // Infer default MIME type if not provided
            $resolvedMime = $mimeType ?? match ($extension) {
                'json' => 'application/json',
                'csv' => 'text/csv',
                'tsv' => 'text/tab-separated-values',
                'md', 'markdown' => 'text/markdown',
                'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xls' => 'application/vnd.ms-excel',
                default => 'text/plain',
            };

            /**
             * Step 3: Resolve appropriate handler.
             *
             * An explicit language choice overrides format detection: "Plain Text"
             * routes to the escaped text view, any other language to the
             * highlighter — even for a file whose extension says otherwise.
             */
            $handler = $this->resolveHandlerForPreview($extension, $resolvedMime, $forcedFormat, $selectedLanguage);

            if ($handler === null) {
                throw new InvalidFileException(sprintf('No handler available to preview file ".%s".', $extension));
            }

            /**
             * Step 3b: the Raw view mode.
             *
             * `view=raw` asks for the source instead of the rendering, so the rich
             * handler is swapped for the syntax highlighter. `renderedHandlerName`
             * is kept because it is what decides whether the toggle is offered at
             * all — after the swap the handler is always CodePreviewHandler, which
             * would make the control disappear the moment it was used.
             */
            $renderedHandlerName = $handler->getHandlerName();

            if (
                $this->viewModeRegistry->isRawView($viewMode)
                && $this->viewModeRegistry->supportsRawView($renderedHandlerName)
            ) {
                /**
                 * A binary-backed format (an `.xlsx` is a ZIP) has no readable
                 * source, so the raw request is answered with the download notice
                 * plus a Render button rather than a screen of mojibake.
                 */
                if ($this->binaryDetector->isBinary($content)) {
                    return $this->renderBinaryNotice(
                        $content,
                        $declaredSize,
                        $canRender,
                        [
                            'projectId' => $projectId,
                            'taskId' => $taskId,
                            'fileId' => $fileId,
                            'filename' => $safeFilename,
                            'extension' => $extension,
                            'source' => $source,
                        ],
                        'binary_source'
                    );
                }

                $rawHandler = $this->findHandlerByName('CodePreviewHandler');

                if ($rawHandler !== null) {
                    $handler = $rawHandler;
                }
            }
        } catch (AccessDeniedException | InvalidFileException $e) {
            // Inside the HTTP context, surface a clean modal instead of a 500 error page
            if ($canRender) {
                return $this->renderError($e, $filename);
            }

            throw $e;
        }

        // Step 4: Generate safe preview result.
        // The extension is forwarded so CodePreviewHandler can label the language;
        // an explicit picker choice is forwarded alongside and takes precedence.
        $previewOptions = ['extension' => $extension];
        if ($selectedLanguage !== null) {
            $previewOptions['language'] = $selectedLanguage;
        }
        // The CSV table honours an explicit delimiter choice; the token is
        // validated by the registry inside the handler.
        $previewOptions['delimiterToken'] = $delimiterToken;

        $result = $handler->preview($content, $previewOptions);

        $context = ['projectId' => $projectId, 'taskId' => $taskId, 'fileId' => $fileId, 'source' => $source];
        $openTabUrl = $this->buildOpenTabUrl($extension, $handler->getHandlerName(), $context, $viewMode);

        $responseData = [
            'success' => true,
            'projectId' => $projectId,
            'taskId' => $taskId,
            'fileId' => $fileId,
            'filename' => $safeFilename,
            'extension' => $extension,
            'handler' => $handler->getHandlerName(),
            'content' => $result->getContent(),
            'isFormatted' => $result->isFormatted(),
            'metadata' => $result->getMetadata(),
            'openTabUrl' => $openTabUrl,
        ]
            + $this->buildLanguageSelectorData($extension, $selectedLanguage, $handler->getHandlerName(), $context)
            + $this->buildCsvControlData(
                $handler->getHandlerName(),
                $result->getMetadata(),
                $hasHeaderRow,
                $context
            )
            + $this->buildEditSwitcherData($extension, $context)
            + $this->buildViewModeData($extension, $renderedHandlerName, $viewMode, $context);

        // If running inside Kanboard HTTP response context, render template HTML
        if ($canRender) {
            return $this->renderTemplateOrLayout(
                $this->resolveTemplateName($handler->getHandlerName()),
                $responseData,
                $safeFilename
            );
        }

        return $responseData;
    }

    /**
     * Render an attachment whose extension the whitelist cannot classify.
     *
     * Three outcomes, all safe:
     *   - too large to read      -> binary/oversized notice, content never loaded
     *   - binary by content      -> "Binary File" notice with a download action
     *   - printable text         -> escaped text preview plus the language picker
     *
     * @param array{projectId: int, taskId: int, fileId: int, filename: string, extension: string, source: string} $context
     * @return mixed
     *
     * @throws InvalidFileException
     */
    private function showUnclassifiedAttachment(
        string $content,
        bool $skippedAsOversized,
        int $declaredSize,
        ?string $selectedLanguage,
        bool $canRender,
        array $context
    ) {
        $inspection = $this->binaryDetector->inspect($content);

        // An oversized attachment is treated as unpreviewable regardless of what
        // its (unread) bytes contain.
        $isUnpreviewable = $skippedAsOversized || $inspection['binary'];

        if ($isUnpreviewable) {
            $notice = [
                'success' => true,
                'projectId' => $context['projectId'],
                'taskId' => $context['taskId'],
                'fileId' => $context['fileId'],
                'filename' => $context['filename'],
                'extension' => $context['extension'],
                'handler' => 'BinaryNotice',
                'content' => '',
                'isFormatted' => false,
                'metadata' => [
                    'handler' => 'BinaryNotice',
                    'isBinary' => true,
                    'reason' => $skippedAsOversized ? 'too_large' : $inspection['reason'],
                    'sizeBytes' => $declaredSize > 0 ? $declaredSize : strlen($content),
                    'sniffedBytes' => $inspection['sniffedBytes'],
                    'controlRatio' => $inspection['controlRatio'],
                    'maxSizeBytes' => self::CONTENT_READ_CEILING_BYTES,
                ],
                'renderParams' => $this->buildPreviewParams($context),
                'renderAvailable' => false,
                'typeLabel' => $this->viewModeRegistry->getTypeLabel($context['extension'], 'BinaryNotice'),
                'openTabUrl' => $this->buildOpenTabUrl($context['extension'], 'BinaryNotice', $context, 'rendered'),
            ];

            if ($canRender) {
                return $this->renderTemplateOrLayout('FileInteractionCore:file/binary_notice', $notice, $context['filename']);
            }

            return $notice;
        }

        // Printable text: enforce the standard text cap before rendering.
        $this->validationService->validateFileSize(max(strlen($content), $declaredSize), null);

        /**
         * `format=text` forces TextPreviewHandler even though supports() would
         * decline an unrecognised extension, so the payload is entity-escaped
         * rather than handed to a parser. When a language is picked, the
         * highlighter takes over instead.
         */
        $handler = $this->resolveHandlerForPreview($context['extension'], 'text/plain', null, $selectedLanguage);

        if ($handler === null) {
            throw new InvalidFileException('No handler available to preview this attachment.');
        }

        $previewOptions = ['extension' => $context['extension']];
        if ($selectedLanguage !== null) {
            $previewOptions['language'] = $selectedLanguage;
        }

        $result = $handler->preview($content, $previewOptions);
        $openTabUrl = $this->buildOpenTabUrl($context['extension'], $handler->getHandlerName(), $context, 'rendered');

        $responseData = [
            'success' => true,
            'projectId' => $context['projectId'],
            'taskId' => $context['taskId'],
            'fileId' => $context['fileId'],
            'filename' => $context['filename'],
            'extension' => $context['extension'],
            'handler' => $handler->getHandlerName(),
            'content' => $result->getContent(),
            'isFormatted' => $result->isFormatted(),
            'metadata' => ['detectedAsText' => true, 'detectionReason' => $inspection['reason']]
                + $result->getMetadata(),
            'openTabUrl' => $openTabUrl,
        ] + $this->buildLanguageSelectorData(
            $context['extension'],
            $selectedLanguage,
            $handler->getHandlerName(),
            $context
        )
            + $this->buildEditSwitcherData($context['extension'], $context)
            // No rich rendering exists for an unclassified attachment, so the
            // Rendered/Raw toggle is withheld rather than shown as a no-op.
            + $this->buildViewModeData($context['extension'], $handler->getHandlerName(), 'rendered', $context);

        if ($canRender) {
            return $this->renderTemplateOrLayout(
                $this->resolveTemplateName($handler->getHandlerName()),
                $responseData,
                $context['filename']
            );
        }

        return $responseData;
    }

    /**
     * Resolve the handler for a preview, honouring an explicit language choice.
     *
     * A picked language is authoritative and CANNOT go through
     * FileInteractionManager::resolveHandler(): that method still consults
     * supports(), and CodePreviewHandler declines extensions outside its own list
     * — so asking it to highlight a `.txt` as Python would silently fall back to
     * the plain text handler. The picked handler is therefore selected by name.
     *
     * An explicit `format` parameter still wins over both: it names a handler
     * outright, a coarser instruction than choosing a highlighting language.
     */
    private function resolveHandlerForPreview(
        string $extension,
        string $resolvedMime,
        ?string $forcedFormat,
        ?string $selectedLanguage
    ): ?FileHandlerInterface {
        if (empty($forcedFormat) && $selectedLanguage !== null) {
            // "Plain Text" means escaped text with no highlighting at all.
            $target = $this->languageRegistry->isPlainText($selectedLanguage)
                ? 'TextPreviewHandler'
                : 'CodePreviewHandler';

            $handler = $this->findHandlerByName($target);

            if ($handler !== null) {
                return $handler;
            }
        }

        // No choice made: an unrecognised extension still needs the text handler
        // forced, since supports() would decline it.
        if (empty($forcedFormat) && !$this->validationService->isAllowedExtension($extension)) {
            $forcedFormat = 'text';
        }

        return $this->interactionManager->resolveHandler($extension, $resolvedMime, $forcedFormat);
    }

    private function findHandlerByName(string $handlerName): ?FileHandlerInterface
    {
        foreach ($this->interactionManager->getHandlers() as $handler) {
            if ($handler->getHandlerName() === $handlerName) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Variables the language picker needs, merged into every text/code response.
     *
     * Enabled only for handlers that render a plain text body. CSV, Excel and PDF
     * have their own views with nothing to highlight, and Markdown is excluded
     * deliberately: its output is sanitized HTML, so a language selection would
     * misrepresent what is on screen.
     *
     * @param array{projectId: int, taskId: int, fileId: int, source?: string} $context
     * @return array{languageOptions: array<string, string>, selectedLanguage: string, languageSelectorEnabled: bool, languageParams: array<string, mixed>}
     */
    private function buildLanguageSelectorData(
        string $extension,
        ?string $selectedLanguage,
        string $handlerName,
        array $context
    ): array {
        $params = $this->buildPreviewParams($context);

        return [
            'languageOptions' => $this->languageRegistry->getOptions(),
            'selectedLanguage' => $selectedLanguage ?? $this->languageRegistry->resolveFromExtension($extension),
            'languageSelectorEnabled' => in_array(
                $handlerName,
                ['CodePreviewHandler', 'TextPreviewHandler', 'JsonPreviewHandler'],
                true
            ),
            'languageParams' => $params,
        ];
    }

    /**
     * Variables the CSV delimiter picker and header toggle need.
     *
     * Only meaningful for the tabular view; every other template ignores them.
     *
     * @param array<string, mixed> $metadata
     * @param array{projectId: int, taskId: int, fileId: int, source?: string} $context
     * @return array{csvControlsEnabled: bool, delimiterOptions: array<string, string>, selectedDelimiter: string, delimiterMode: string, hasHeaderRow: bool, csvParams: array<string, mixed>}
     */
    private function buildCsvControlData(
        string $handlerName,
        array $metadata,
        bool $hasHeaderRow,
        array $context
    ): array {
        return [
            'csvControlsEnabled' => $handlerName === 'CsvPreviewHandler',
            'delimiterOptions' => $this->delimiterRegistry->getOptions(),
            // The token in effect — either the explicit choice or whatever the
            // sniffer resolved to, so the picker reflects reality.
            'selectedDelimiter' => (string) ($metadata['delimiterToken'] ?? CsvDelimiterRegistry::AUTO),
            'delimiterMode' => (string) ($metadata['delimiterMode'] ?? CsvDelimiterRegistry::AUTO),
            'hasHeaderRow' => $hasHeaderRow,
            'csvParams' => $this->buildPreviewParams($context),
        ];
    }

    /**
     * Variables the unified action bar needs for the type name and view toggle.
     *
     * `$renderedHandlerName` is the handler that WOULD render this attachment, not
     * necessarily the one in use: in raw mode the handler has already been swapped
     * for the highlighter, and keying the toggle off that would make the control
     * vanish as soon as it was used.
     *
     * @param array{projectId: int, taskId: int, fileId: int, source?: string} $context
     * @return array{typeLabel: string, viewMode: string, rawViewAvailable: bool, viewToggleParams: array<string, mixed>}
     */
    private function buildViewModeData(
        string $extension,
        string $renderedHandlerName,
        string $viewMode,
        array $context
    ): array {
        return [
            'typeLabel' => $this->viewModeRegistry->getTypeLabel($extension, $renderedHandlerName),
            'viewMode' => $this->viewModeRegistry->normalizeViewMode($viewMode),
            'rawViewAvailable' => $this->viewModeRegistry->supportsRawView($renderedHandlerName),
            'viewToggleParams' => $this->buildPreviewParams($context),
        ];
    }

    /**
     * Render the "Binary File" notice, which emits no attachment content at all.
     *
     * Shared by the unknown-extension flow and by a raw-view request against a
     * binary-backed format such as `.xlsx`.
     *
     * @param array{projectId: int, taskId: int, fileId: int, filename: string, extension: string, source?: string} $context
     * @return mixed
     */
    private function renderBinaryNotice(
        string $content,
        int $declaredSize,
        bool $canRender,
        array $context,
        string $reason
    ) {
        $inspection = $this->binaryDetector->inspect($content);

        $openTabUrl = $this->buildOpenTabUrl($context['extension'], 'BinaryNotice', $context, 'rendered');

        $notice = [
            'success' => true,
            'projectId' => $context['projectId'],
            'taskId' => $context['taskId'],
            'fileId' => $context['fileId'],
            'filename' => $context['filename'],
            'extension' => $context['extension'],
            'handler' => 'BinaryNotice',
            'content' => '',
            'isFormatted' => false,
            'metadata' => [
                'handler' => 'BinaryNotice',
                'isBinary' => true,
                'reason' => $reason === 'binary_source' ? $inspection['reason'] : $reason,
                'sizeBytes' => $declaredSize > 0 ? $declaredSize : strlen($content),
                'sniffedBytes' => $inspection['sniffedBytes'],
                'controlRatio' => $inspection['controlRatio'],
                'maxSizeBytes' => self::CONTENT_READ_CEILING_BYTES,
            ],
            // A "Render" action back to the rich view, which is the only useful next
            // step when the raw source turns out to be binary.
            'renderParams' => $this->buildPreviewParams($context),
            'renderAvailable' => $reason === 'binary_source',
            'typeLabel' => $this->viewModeRegistry->getTypeLabel($context['extension'], 'BinaryNotice'),
            'openTabUrl' => $openTabUrl,
        ];

        if ($canRender) {
            return $this->renderTemplateOrLayout('FileInteractionCore:file/binary_notice', $notice, $context['filename']);
        }

        return $notice;
    }

    /**
     * Variables the in-preview "Edit File" switcher needs.
     *
     * Only the FORMAT question is answered here. The write-permission gate lives in
     * the template, because it needs `hasProjectAccess()` from Kanboard's user
     * helper — our PermissionService defaults to a permissive mock and real ACL is
     * enforced by core's middleware, exactly as Template/file/dropdown.php already
     * does for its own Edit entry.
     *
     * @param array{projectId: int, taskId: int, fileId: int, source?: string} $context
     * @return array{isEditableFormat: bool, editParams: array<string, mixed>}
     */
    private function buildEditSwitcherData(string $extension, array $context): array
    {
        return [
            // Mirrors FileEditValidationService::EDITABLE_EXTENSIONS, which is
            // narrower than the preview whitelist: binary, tabular and
            // active-content formats must never open in a plain-text editor.
            'isEditableFormat' => in_array(
                strtolower(ltrim(trim($extension), '.')),
                FileEditValidationService::EDITABLE_EXTENSIONS,
                true
            ),
            'editParams' => $this->buildPreviewParams($context),
        ];
    }

    /**
     * Base route parameters for a control that reloads the preview modal.
     *
     * @param array{projectId: int, taskId: int, fileId: int, source?: string} $context
     * @return array<string, mixed>
     */
    private function buildPreviewParams(array $context): array
    {
        $params = [
            'plugin' => 'FileInteractionCore',
            'project_id' => $context['projectId'],
            'task_id' => $context['taskId'],
            'file_id' => $context['fileId'],
        ];

        // Project attachments resolve through a different model, so the flag has
        // to survive a control round trip.
        if (($context['source'] ?? 'task') === 'project') {
            $params['source'] = 'project';
        }

        return $params;
    }

    /**
     * Build target URL for "Open in new tab" action.
     *
     * @param array{projectId: int, taskId: int, fileId: int, source?: string} $context
     */
    private function buildOpenTabUrl(string $extension, string $handlerName, array $context, string $currentView): ?string
    {
        if (!$this->hasService('helper') || !isset($this->helper->url)) {
            return null;
        }

        if ($handlerName === 'PdfPreviewHandler' || strtolower($extension) === 'pdf') {
            return $this->helper->url->href('FileStreamController', 'inline', [
                'plugin' => 'FileInteractionCore',
                'project_id' => $context['projectId'],
                'task_id' => $context['taskId'],
                'file_id' => $context['fileId'],
            ]);
        }

        $params = $this->buildPreviewParams($context);
        if ($currentView !== 'rendered') {
            $params['view'] = $currentView;
        }

        return $this->helper->url->href('FilePreviewController', 'show', $params);
    }

    /**
     * Render template HTML: if Ajax, renders partial template for modal dialog;
     * if standalone browser request, wraps with full application layout.
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    private function renderTemplateOrLayout(string $template, array $data, ?string $title = null)
    {
        $isAjax = $this->hasService('request') && is_object($this->request) && method_exists($this->request, 'isAjax') && $this->request->isAjax();
        $data['is_ajax'] = $isAjax;

        $layout = $this->hasService('helper') ? ($this->helper->layout ?? null) : null;
        if (!$isAjax && is_object($layout) && method_exists($layout, 'app')) {
            $data['title'] = $title ?? ($data['filename'] ?? t('File Preview'));
            return $this->response->html(
                $layout->app($template, $data)
            );
        }

        return $this->response->html(
            $this->template->render($template, $data)
        );
    }

    /**
     * Map a resolved handler to its modal template.
     *
     * MarkdownPreviewHandler and CodePreviewHandler both emit pre-sanitized HTML,
     * so they share the rich `markdown_preview` view which renders $content raw.
     * Every other handler returns entity-escaped plain text for `preview`.
     */
    private function resolveTemplateName(string $handlerName): string
    {
        return match ($handlerName) {
            'CsvPreviewHandler' => 'FileInteractionCore:file/csv_preview',
            'ExcelPreviewHandler' => 'FileInteractionCore:file/excel_preview',
            'DocxPreviewHandler' => 'FileInteractionCore:file/docx_preview',
            'PptxPreviewHandler' => 'FileInteractionCore:file/pptx_preview',
            'PdfPreviewHandler' => 'FileInteractionCore:file/pdf_preview',
            'HtmlPreviewHandler' => 'FileInteractionCore:file/html_preview',
            'MarkdownPreviewHandler', 'CodePreviewHandler' => 'FileInteractionCore:file/markdown_preview',
            default => 'FileInteractionCore:file/preview',
        };
    }

    /**
     * Render a safe, escaped error modal for rejected preview requests.
     *
     * @return mixed
     */
    private function renderError(\Throwable $e, ?string $filename)
    {
        $isDenied = $e instanceof AccessDeniedException;
        $statusCode = $isDenied ? 403 : 400;
        $reason = $isDenied ? 'access_denied' : 'invalid_file';

        return $this->renderErrorModalResponse(
            true,
            $e->getMessage(),
            $statusCode,
            $reason,
            null,
            $filename
        );
    }
}
