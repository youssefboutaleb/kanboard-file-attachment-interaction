<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Stubs;

/**
 * Minimal ArrayAccess container mirroring Pimple\Container for controller tests.
 *
 * @implements \ArrayAccess<string, mixed>
 */
class FakeContainer implements \ArrayAccess
{
    /**
     * @var array<string, mixed>
     */
    private array $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    /**
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->services[(string) $offset]);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->services[(string) $offset] ?? null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        $this->services[(string) $offset] = $value;
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->services[(string) $offset]);
    }
}

/**
 * Records the parameters Kanboard would receive on an HTTP request.
 */
class FakeRequest
{
    /**
     * @var array<string, mixed>
     */
    private array $params;

    private bool $ajax;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [], bool $ajax = false)
    {
        $this->params = $params;
        $this->ajax = $ajax;
    }

    public function isAjax(): bool
    {
        return $this->ajax;
    }

    public function getIntegerParam(string $name, int $default = 0): int
    {
        return isset($this->params[$name]) ? (int) $this->params[$name] : $default;
    }

    public function getStringParam(string $name, string $default = ""): string
    {
        return isset($this->params[$name]) ? (string) $this->params[$name] : $default;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getValue(string $name, $default = null)
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->params;
    }
}

/**
 * Captures the rendered template name and variables.
 */
class FakeTemplate
{
    public string $renderedTemplate = "";

    /**
     * @var array<string, mixed>
     */
    public array $renderedVars = [];

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $this->renderedTemplate = $template;
        $this->renderedVars = $vars;

        return '<div class="page-header">rendered:' . $template . '</div>';
    }
}

/**
 * Captures the emitted HTML body and status code.
 */
class FakeResponse
{
    public ?string $body = null;
    public int $statusCode = 0;

    public function statusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    /**
     * Mirrors Kanboard's Response::status(), used by the binary stream path to
     * set a failure code without emitting an HTML body.
     */
    public function status(int $code): void
    {
        $this->statusCode = $code;
    }

    public function html(string $data, int $statusCode = 200): string
    {
        $this->body = $data;
        $this->statusCode = $statusCode;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function json(array $data, int $statusCode = 200): string
    {
        $this->body = (string) json_encode($data);
        $this->statusCode = $statusCode;

        return $this->body;
    }

    public function redirect(string $url, bool $self = false): string
    {
        $this->body = $url;
        $this->statusCode = 302;

        return $url;
    }
}

/**
 * Returns a fixed attachment row, like Kanboard's TaskFileModel.
 */
class FakeFileModel
{
    /**
     * @var array<string, mixed>
     */
    private array $file;

    /**
     * @param array<string, mixed> $file
     */
    public function __construct(array $file)
    {
        $this->file = $file;
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(int $fileId): array
    {
        return $this->file;
    }
}

/**
 * Returns fixed binary content, like Kanboard's ObjectStorage.
 */
class FakeObjectStorage
{
    private string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function get(string $path): string
    {
        return $this->content;
    }

    public function put(string $path, string $content): bool
    {
        $this->content = $content;
        return true;
    }
}

/**
 * Records headers and body written by FileStreamController instead of sending
 * them, so the emitted header set can be asserted on.
 */
class RecordingStreamEmitter implements \Kanboard\Plugin\FileInteractionCore\Core\Contract\StreamEmitterInterface
{
    /**
     * @var array<string, string>
     */
    public array $headers = [];

    /**
     * @var list<string>
     */
    public array $removedHeaders = [];

    public string $body = "";

    public function emitHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function removeHeader(string $name): void
    {
        $this->removedHeaders[] = $name;
        unset($this->headers[$name]);
    }

    public function emitBody(string $content): void
    {
        $this->body .= $content;
    }
}

/**
 * Returns fixed user session ID, like Kanboard's UserSession.
 */
class FakeUserSession
{
    private int $userId;

    public function __construct(int $userId = 1)
    {
        $this->userId = $userId;
    }

    public function getId(): int
    {
        return $this->userId;
    }
}

class FakeLayout
{
    public function app(string $template, array $data = []): string
    {
        return "LAYOUT_APP:{$template}:" . ($data['title'] ?? "");
    }
}

class FakeUrl
{
    public function to(string $controller, string $action, array $params = []): string
    {
        return "/?controller={$controller}&action={$action}";
    }

    public function href(string $controller, string $action, array $params = []): string
    {
        return "/?controller={$controller}&action={$action}";
    }
}

class FakeFlash
{
    public string $message = "";

    public function success(string $message): void
    {
        $this->message = $message;
    }
}

class FakeHelper
{
    public FakeLayout $layout;
    public FakeUrl $url;
    public FakeFlash $flash;

    public function __construct()
    {
        $this->layout = new FakeLayout();
        $this->url = new FakeUrl();
        $this->flash = new FakeFlash();
    }
}
