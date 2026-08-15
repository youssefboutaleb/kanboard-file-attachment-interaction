<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Controller;

// Require stub if running standalone outside Kanboard runtime
if (!class_exists('Kanboard\Controller\BaseController')) {
    require_once __DIR__ . '/../../tests/stubs/BaseController.php';
}

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\FileInteractionCore\Core\Contract\StreamEmitterInterface;
use Kanboard\Plugin\FileInteractionCore\Exception\AccessDeniedException;
use Kanboard\Plugin\FileInteractionCore\Exception\InvalidFileException;
use Kanboard\Plugin\FileInteractionCore\Service\FileValidationService;
use Kanboard\Plugin\FileInteractionCore\Service\HttpStreamEmitter;
use Kanboard\Plugin\FileInteractionCore\Service\PermissionService;

/**
 * Streams binary attachments inline so the embedded viewer can render them.
 *
 * WHY THIS EXISTS (Task 35): Kanboard core's `FileViewerController::browser`
 * already answers with the correct `Content-Type: application/pdf`, but every
 * core response also carries `X-Frame-Options: DENY`, stamped unconditionally by
 * `BootstrapMiddleware::sendHeaders()` whenever `ENABLE_XFRAME` is on (the
 * default). Browsers render an embedded PDF inside a nested browsing context, so
 * `DENY` aborts that navigation and `<object>` falls through to its child
 * content — the "Inline PDF viewing is not supported" banner. No `data`
 * attribute can work around a response header, and core must not be patched, so
 * the plugin serves the stream itself and owns the framing policy:
 *
 *   - `X-Frame-Options` is dropped rather than downgraded to `SAMEORIGIN`, which
 *     has historically also blocked Chrome's out-of-process PDF viewer.
 *   - `Content-Security-Policy: frame-ancestors 'self'` replaces it. That is the
 *     standardized, CSP-2 equivalent and is honoured by every browser able to
 *     display an embedded PDF, so cross-origin embedding stays blocked.
 *
 * Only formats on the INLINE_MIME_TYPES allow-list are ever streamed. Active
 * content (`html`, `svg`, …) is deliberately absent: serving those inline from
 * our own origin would turn an attachment into stored XSS.
 */
class FileStreamController extends BaseController
{
    /**
     * Formats cleared for inline streaming, mapped to their forced MIME type.
     *
     * Deliberately far narrower than FileValidationService::ALLOWED_EXTENSIONS —
     * being previewable through an escaping handler does not make a format safe
     * to hand to the browser as a live document.
     *
     * @var array<string, string>
     */
    public const INLINE_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
    ];

    /**
     * Number of leading bytes scanned for a format's magic signature.
     *
     * Real-world PDFs occasionally carry junk ahead of the `%PDF` header, so the
     * signature is searched within a window rather than anchored at offset 0 —
     * the same tolerance libmagic applies.
     */
    private const MAGIC_SCAN_BYTES = 1024;

    /**
     * Required magic signature per streamable extension.
     *
     * @var array<string, string>
     */
    private const MAGIC_SIGNATURES = [
        'pdf' => '%PDF',
        'docx' => 'PK',
        'dotx' => 'PK',
        'pptx' => 'PK',
        'potx' => 'PK',
    ];

    private PermissionService $permissionService;
    private FileValidationService $validationService;
    private StreamEmitterInterface $emitter;

    /**
     * @param mixed $container
     */
    public function __construct(
        $container = null,
        ?PermissionService $permissionService = null,
        ?FileValidationService $validationService = null,
        ?StreamEmitterInterface $emitter = null
    ) {
        if ($container !== null && is_object($container)) {
            parent::__construct($container);
        }

        $this->permissionService = $permissionService ?? new PermissionService();
        $this->validationService = $validationService ?? new FileValidationService();
        $this->emitter = $emitter ?? new HttpStreamEmitter();
    }

    /**
     * Determine whether a Kanboard container service is available.
     *
     * NOTE: `Kanboard\Core\Base` exposes services through `__get()` but does NOT
     * implement `__isset()`, so `isset($this->request)` is ALWAYS false at
     * runtime. Probe the container directly instead.
     */
    private function hasService(string $name): bool
    {
        $container = $this->container;

        return $container instanceof \ArrayAccess && (bool) $container->offsetExists($name);
    }

    /**
     * Stream an attachment inline for the embedded viewer.
     *
     * @return array<string, mixed> Descriptor of what was emitted (headers, size,
     *                              status), so both the runtime and the test
     *                              suite can assert on the outcome.
     */
    public function inline(
        ?int $projectId = null,
        ?int $taskId = null,
        ?int $fileId = null,
        ?string $filename = null,
        ?string $rawContent = null
    ): array {
        $hasRequest = $this->hasService('request');

        if (empty($fileId) && $hasRequest) {
            $fileId = (int) $this->request->getIntegerParam('file_id');
        }
        if (empty($taskId) && $hasRequest) {
            $taskId = (int) $this->request->getIntegerParam('task_id');
        }
        if (empty($projectId) && $hasRequest) {
            $projectId = (int) $this->request->getIntegerParam('project_id');
        }

        $fileId = $fileId ?? 0;
        $taskId = $taskId ?? 0;
        $projectId = $projectId ?? 0;

        $source = 'task';
        if ($hasRequest) {
            $source = $this->request->getStringParam('source') === 'project' ? 'project' : 'task';
        }
        $modelName = $source === 'project' ? 'projectFileModel' : 'taskFileModel';

        $file = null;

        if ($fileId > 0 && $this->hasService($modelName)) {
            try {
                $file = $this->{$modelName}->getById($fileId);
            } catch (\Throwable $e) {
                $file = null;
            }
        }

        if (!empty($file) && is_array($file)) {
            $taskId = $taskId > 0 ? $taskId : (int) ($file['task_id'] ?? 0);
            $projectId = $projectId > 0 ? $projectId : (int) ($file['project_id'] ?? 0);
            $filename = $filename ?? ($file['name'] ?? null);

            if ($rawContent === null && !empty($file['path'])) {
                $rawContent = $this->readAttachment((string) $file['path']);
            }
        }

        // Resolve the owning project when only a task id was supplied
        if ($projectId === 0 && $taskId > 0 && $this->hasService('taskFinderModel')) {
            try {
                $projectId = (int) $this->taskFinderModel->getProjectId($taskId);
            } catch (\Throwable $e) {
                $projectId = 0;
            }
        }

        try {
            [$safeFilename, $extension, $content] = $this->validateStreamRequest(
                $filename,
                $rawContent,
                $projectId,
                $taskId,
                $fileId
            );
        } catch (AccessDeniedException | InvalidFileException $e) {
            return $this->emitFailure($e);
        }

        $headers = $this->buildStreamHeaders($safeFilename, $extension, strlen($content));

        foreach ($headers as $name => $value) {
            $this->emitter->emitHeader($name, $value);
        }

        // Core queues `X-Frame-Options: DENY` for every response; strip it so the
        // frame-ancestors directive above is the only framing policy in force.
        $this->emitter->removeHeader('X-Frame-Options');

        $this->emitter->emitBody($content);

        return [
            'success' => true,
            'status' => 200,
            'filename' => $safeFilename,
            'extension' => $extension,
            'sizeBytes' => strlen($content),
            'headers' => $headers,
        ];
    }

    /**
     * Run every gate a stream request must clear, in order.
     *
     * @return array{0: string, 1: string, 2: string} Sanitized filename, resolved
     *                                                extension, attachment bytes.
     *
     * @throws AccessDeniedException
     * @throws InvalidFileException
     */
    private function validateStreamRequest(
        ?string $filename,
        ?string $rawContent,
        int $projectId,
        int $taskId,
        int $fileId
    ): array {
        if ($filename === null) {
            throw new InvalidFileException('Attachment could not be resolved.');
        }

        $safeFilename = $this->validationService->sanitizeFilename($filename);

        // Step 1: ACL — identical gate to the preview modal.
        $this->permissionService->assertUserCanReadFile($projectId, $taskId, $fileId);

        // Step 2: only allow-listed binary formats may be streamed inline.
        $extension = $this->resolveInlineExtension($safeFilename);

        if ($rawContent === null || $rawContent === '') {
            throw new InvalidFileException('Attachment content is empty or unreadable.');
        }

        // Step 3: size cap (10 MB for PDF, per spec 004).
        $this->validationService->validateFileSize(strlen($rawContent), $extension);

        // Step 4: the bytes must actually be the format we are about to announce,
        // so a mislabelled payload cannot be served as a live document.
        $this->assertMagicSignature($extension, $rawContent);

        return [$safeFilename, $extension, $rawContent];
    }

    /**
     * Build the exact header set for an inline attachment stream.
     *
     * @return array<string, string>
     */
    public function buildStreamHeaders(string $safeFilename, string $extension, int $sizeBytes): array
    {
        $normalizedExt = strtolower(ltrim(trim($extension), '.'));

        return [
            'Content-Type' => self::INLINE_MIME_TYPES[$normalizedExt] ?? 'application/octet-stream',
            // `inline` (not `attachment`) is what lets the viewer render the
            // document instead of raising a save dialog.
            'Content-Disposition' => 'inline; filename="' . $this->escapeHeaderFilename($safeFilename) . '"',
            'Content-Length' => (string) $sizeBytes,
            'X-Content-Type-Options' => 'nosniff',
            // Locks the response down to being framed by this origin only, and
            // forbids it pulling any subresource of its own.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
            // Attachments are ACL-protected: never let a shared cache keep them.
            'Cache-Control' => 'private, max-age=300',
        ];
    }

    /**
     * Validate that the attachment is a format cleared for inline streaming.
     *
     * @throws InvalidFileException
     */
    private function resolveInlineExtension(string $safeFilename): string
    {
        $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));

        if (!isset(self::INLINE_MIME_TYPES[$extension])) {
            throw new InvalidFileException(sprintf(
                'File extension ".%s" cannot be streamed inline. Streamable formats: %s.',
                $extension,
                implode(', ', array_map(static fn (string $ext): string => '.' . $ext, array_keys(self::INLINE_MIME_TYPES)))
            ));
        }

        return $extension;
    }

    /**
     * Reject payloads whose bytes do not match the announced format.
     *
     * @throws InvalidFileException
     */
    private function assertMagicSignature(string $extension, string $content): void
    {
        $signature = self::MAGIC_SIGNATURES[$extension] ?? null;

        if ($signature === null) {
            return;
        }

        if (!str_contains(substr($content, 0, self::MAGIC_SCAN_BYTES), $signature)) {
            throw new InvalidFileException(sprintf(
                'Attachment does not carry a valid ".%s" signature and will not be streamed.',
                $extension
            ));
        }
    }

    /**
     * Neutralise quotes, backslashes and control characters before a filename is
     * interpolated into the Content-Disposition header, so it cannot break out of
     * the quoted-string or inject a second header line.
     */
    private function escapeHeaderFilename(string $safeFilename): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '_', $safeFilename);

        return $stripped ?? 'attachment';
    }

    /**
     * Read attachment bytes from object storage, falling back to a direct read.
     */
    private function readAttachment(string $path): ?string
    {
        if ($this->hasService('objectStorage')) {
            try {
                $content = $this->objectStorage->get($path);
                if (is_string($content) && $content !== '') {
                    return $content;
                }
            } catch (\Throwable $e) {
                // fall through to the filesystem fallback
            }
        }

        // Fallback for runtimes where the objectStorage wrapper is uninitialized.
        // basename() on each segment is not possible here (paths are nested), so
        // reject any traversal sequence outright instead.
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        $filesDir = defined('FILES_DIR') ? FILES_DIR : '/var/www/app/data/files';
        $filePath = rtrim($filesDir, '/') . '/' . ltrim($path, '/');

        if (is_file($filePath) && is_readable($filePath)) {
            $contentRead = file_get_contents($filePath);
            if ($contentRead !== false) {
                return $contentRead;
            }
        }

        return null;
    }

    /**
     * Emit a minimal, escaped error body for a rejected stream request.
     *
     * The `<object>` fallback content is what the user actually sees, so this
     * only needs to carry an accurate status code rather than a styled modal.
     *
     * @return array<string, mixed>
     */
    private function emitFailure(\Throwable $e): array
    {
        $status = $e instanceof AccessDeniedException ? 403 : 400;

        $this->emitter->emitHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->emitter->emitHeader('X-Content-Type-Options', 'nosniff');
        $this->emitter->emitHeader('Cache-Control', 'private, no-store');

        if ($this->hasService('response')) {
            $this->response->status($status);
        }

        $this->emitter->emitBody(htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return [
            'success' => false,
            'status' => $status,
            'reason' => $e instanceof AccessDeniedException ? 'access_denied' : 'invalid_file',
            'message' => $e->getMessage(),
        ];
    }
}
