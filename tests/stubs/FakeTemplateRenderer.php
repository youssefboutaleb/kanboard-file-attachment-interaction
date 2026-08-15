<?php

declare(strict_types=1);

namespace Kanboard\Plugin\FileInteractionCore\Tests\Stubs;

/**
 * Test harness reproducing how Kanboard renders a plugin template.
 *
 * Templates are plain PHP files in the global namespace where `$this` is
 * Kanboard's `Core\Template` object, exposing helpers through `__get()`. The
 * render() flow here (extract + output buffer + include) mirrors
 * Kanboard\Core\Template::render() exactly, so the markup asserted in tests is
 * the markup the runtime produces.
 */
class FakeTemplateRenderer
{
    private FakeUrlHelper $urlHelper;
    private FakeModalHelper $modalHelper;
    private FakeTextHelper $textHelper;
    private FakeUserHelper $userHelper;
    private FakeFileHelper $fileHelper;
    private FakeRendererFormHelper $formHelper;

    public function __construct(?FakeUrlHelper $urlHelper = null, ?FakeUserHelper $userHelper = null)
    {
        $this->urlHelper = $urlHelper ?? FakeUrlHelper::withPluginRoutes();
        $this->modalHelper = new FakeModalHelper($this->urlHelper);
        $this->textHelper = new FakeTextHelper();
        $this->userHelper = $userHelper ?? new FakeUserHelper(true);
        $this->fileHelper = new FakeFileHelper();
        $this->formHelper = new FakeRendererFormHelper();
    }

    /**
     * Resolve a helper, like Kanboard\Core\Template::__get().
     *
     * @return mixed
     */
    public function __get(string $helper)
    {
        return match ($helper) {
            'url' => $this->urlHelper,
            'modal' => $this->modalHelper,
            'text' => $this->textHelper,
            'user' => $this->userHelper,
            'file' => $this->fileHelper,
            'form' => $this->formHelper,
            default => null,
        };
    }

    /**
     * Render a nested template, like Kanboard\Core\Template::render().
     *
     * Templates call `$this->render('FileInteractionCore:file/partial', [...])` to
     * pull in a partial, so the harness must resolve the `plugin:path` form the
     * same way core's getTemplateFile() does.
     *
     * @param array<string, mixed> $args
     */
    public function render(string $template, array $args = []): string
    {
        $path = $template;

        if (str_contains($template, ':')) {
            [, $path] = explode(':', $template, 2);
        }

        return $this->renderPluginTemplate($path, $args);
    }

    /**
     * Render a plugin template file with the given variables in scope.
     *
     * @param array<string, mixed> $__template_args
     */
    public function renderFile(string $__template_file, array $__template_args = []): string
    {
        extract($__template_args);
        ob_start();
        include $__template_file;

        return (string) ob_get_clean();
    }

    /**
     * Render a template from the plugin's Template/ directory.
     *
     * @param array<string, mixed> $args
     */
    public function renderPluginTemplate(string $name, array $args = []): string
    {
        return $this->renderFile(__DIR__ . '/../../Template/' . $name . '.php', $args);
    }
}

/**
 * Faithful stand-in for Kanboard's UrlHelper + Route url lookup.
 *
 * Reproducing the real resolution rules matters: Route::findUrl() only matches a
 * pretty route when the supplied params are exactly the route's params, and
 * otherwise silently degrades to a `?controller=…&action=…` query string. Tests
 * that assert on generated stream URLs would be worthless against a stub that
 * always returned one shape.
 */
class FakeUrlHelper
{
    /**
     * @var array<string, array<string, array<string, list<array{path: string, params: array<string, bool>, count: int}>>>>
     */
    private array $urls = [];

    private string $directory = '/';

    /**
     * Route table matching the one Plugin::initialize() registers.
     */
    public static function withPluginRoutes(): self
    {
        $helper = new self();
        $helper->addRoute('/b/:project_id/task/:task_id/file/:file_id/preview', 'FilePreviewController', 'show', 'FileInteractionCore');
        $helper->addRoute('/b/:project_id/task/:task_id/file/:file_id/edit', 'FileEditController', 'edit', 'FileInteractionCore');
        $helper->addRoute('/b/:project_id/task/:task_id/file/:file_id/update', 'FileEditController', 'update', 'FileInteractionCore');
        $helper->addRoute('/b/:project_id/task/:task_id/file/:file_id/stream', 'FileStreamController', 'inline', 'FileInteractionCore');

        return $helper;
    }

    public function addRoute(string $path, string $controller, string $action, string $plugin = ''): void
    {
        $path = ltrim($path, '/');
        $params = [];

        foreach (explode('/', $path) as $item) {
            if ($item !== '' && $item[0] === ':') {
                $params[substr($item, 1)] = true;
            }
        }

        $this->urls[$plugin][$controller][$action][] = [
            'path' => $path,
            'params' => $params,
            'count' => count($params),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function href(string $controller, string $action, array $params = []): string
    {
        return $this->build('&amp;', $controller, $action, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function to(string $controller, string $action, array $params = []): string
    {
        return $this->build('&', $controller, $action, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function link(
        string $label,
        string $controller,
        string $action,
        array $params = [],
        bool $csrf = false,
        string $class = '',
        string $title = '',
        bool $newTab = false
    ): string {
        return '<a href="' . $this->href($controller, $action, $params) . '" class="' . $class . '" title=\'' . $title . '\' '
            . ($newTab ? 'target="_blank"' : '') . '>' . $label . '</a>';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function icon(
        string $icon,
        string $label,
        string $controller,
        string $action,
        array $params = [],
        bool $csrf = false,
        string $class = '',
        string $title = '',
        bool $newTab = false
    ): string {
        $html = '<i class="fa fa-fw fa-' . $icon . '" aria-hidden="true"></i>' . $label;

        return $this->link($html, $controller, $action, $params, $csrf, $class, $title, $newTab);
    }

    public function dir(): string
    {
        return $this->directory;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function build(string $separator, string $controller, string $action, array $params): string
    {
        $plugin = '';
        if (isset($params['plugin'])) {
            $plugin = (string) $params['plugin'];
        }

        $path = $this->findUrl($controller, $action, $params, $plugin);
        $qs = [];

        if ($path === '') {
            $qs['controller'] = $controller;
            $qs['action'] = $action;
            $qs += $params;
        } else {
            unset($params['plugin']);
        }

        if ($qs !== []) {
            $path .= '?' . http_build_query($qs, '', $separator);
        }

        return $this->dir() . $path;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function findUrl(string $controller, string $action, array $params, string $plugin): string
    {
        if ($plugin !== '') {
            unset($params['plugin']);
        }

        if (!isset($this->urls[$plugin][$controller][$action])) {
            return '';
        }

        foreach ($this->urls[$plugin][$controller][$action] as $route) {
            if (array_diff_key($params, $route['params']) !== []) {
                continue;
            }

            $url = $route['path'];
            $i = 0;

            foreach ($params as $variable => $value) {
                $url = str_replace(':' . $variable, urlencode((string) $value), $url);
                $i++;
            }

            if ($i === $route['count']) {
                return $url;
            }
        }

        return '';
    }
}

/**
 * Mirrors Kanboard's ModalHelper markup, including the js-modal-* classes.
 */
class FakeModalHelper
{
    private FakeUrlHelper $urlHelper;

    public function __construct(FakeUrlHelper $urlHelper)
    {
        $this->urlHelper = $urlHelper;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function medium(string $icon, string $label, string $controller, string $action, array $params = [], string $title = ''): string
    {
        $ariaLabel = ($title === '' ? 'aria-hidden="true"' : 'role="img" aria-label="' . $title . '"');
        $html = '<i class="fa fa-' . $icon . ' fa-fw js-modal-medium" ' . $ariaLabel . '></i>' . $label;

        return $this->urlHelper->link($html, $controller, $action, $params, false, 'js-modal-medium', $title);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function large(string $icon, string $label, string $controller, string $action, array $params = []): string
    {
        $html = '<i class="fa fa-' . $icon . ' fa-fw js-modal-large" aria-hidden="true"></i>' . $label;

        return $this->urlHelper->link($html, $controller, $action, $params, false, 'js-modal-large');
    }

    /**
     * @param array<string, mixed> $params
     */
    public function confirm(string $icon, string $label, string $controller, string $action, array $params = []): string
    {
        $html = '<i class="fa fa-' . $icon . ' fa-fw js-modal-confirm" aria-hidden="true"></i>' . $label;

        return $this->urlHelper->link($html, $controller, $action, $params, false, 'js-modal-confirm');
    }
}

/**
 * Mirrors Kanboard's TextHelper escaping and byte formatting.
 */
class FakeTextHelper
{
    public function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param int|string $value
     */
    public function bytes($value): string
    {
        return (string) $value . ' b';
    }
}

/**
 * Mirrors Kanboard's FormHelper::csrf(), which the editor form emits.
 *
 * Named distinctly from PluginTest's own FakeFormHelper so both stub sets can be
 * loaded in the same test run without colliding.
 */
class FakeRendererFormHelper
{
    public function csrf(): string
    {
        return '<input type="hidden" name="csrf_token" value="test-token">';
    }
}

/**
 * Mirrors the UserHelper ACL probe used as the editor write gate.
 */
class FakeUserHelper
{
    private bool $projectAccess;

    public function __construct(bool $projectAccess = true)
    {
        $this->projectAccess = $projectAccess;
    }

    public function hasProjectAccess(string $controller, string $action, int $projectId): bool
    {
        return $this->projectAccess;
    }
}

/**
 * Mirrors Kanboard's FileHelper preview-type classification.
 *
 * These two methods are what decide whether core renders its own "View file"
 * entry, so the dropdown integration test needs them to behave like core.
 */
class FakeFileHelper
{
    public function icon(string $filename): string
    {
        return 'fa-file-o';
    }

    public function getPreviewType(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'md', 'markdown' => 'markdown',
            'txt' => 'text',
            default => null,
        };
    }

    public function getBrowserViewType(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'mp3', 'ogg', 'flac', 'wav' => 'audio/mpeg',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
            'mp4' => 'video/mp4',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }
}
