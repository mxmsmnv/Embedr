<?php namespace ProcessWire;

require_once __DIR__ . '/Embedrs.php';
require_once __DIR__ . '/EmbedrTypes.php';

/**
 * Embedr
 *
 * Core API and configuration module for reusable embeds and field-backed
 * content builders. The ProcessEmbedr module provides the admin UI.
 */
class Embedr extends WireData implements Module, ConfigurableModule {

    protected static $defaultConfig = [
        'componentsPath' => 'components/',
        'openTag' => '((',
        'closeTag' => '))',
        'autoDiscoverTypes' => false,
        'showTypeIcons' => true,
        'debugMode' => false,
        'fieldCacheExpire' => 86400,
    ];

    /** @var Embedrs|null */
    protected $embedrs = null;

    /** @var EmbedrTypes|null */
    protected $types = null;

    public static function getModuleInfo() {
        return [
            'title' => 'Embedr',
            'version' => '0.4.0',
            'summary' => 'Reusable embeds and field-backed content blocks',
            'author' => 'Maxim Semenov',
            'href' => 'https://smnv.org',
            'icon' => 'code',
            'singular' => true,
            'autoload' => false,
            'requires' => 'ProcessWire>=3.0.0',
            'installs' => 'ProcessEmbedr,TextformatterEmbedr',
        ];
    }

    public function __construct() {
        foreach(self::$defaultConfig as $key => $value) {
            if(!isset($this->$key)) $this->$key = $value;
        }
        parent::__construct();
    }

    public function init() {
        $this->embedrs = $this->wire(new Embedrs());
        $this->types = $this->wire(new EmbedrTypes());
    }

    public function ___install() {
        $this->types()->install();
        $this->embeds()->install();
        $this->migrateLegacyConfig();
    }

    public function ___uninstall() {
        $this->embeds()->uninstall();
        $this->types()->uninstall();
    }

    /**
     * Return the embed collection.
     */
    public function embeds() {
        if($this->embedrs === null) $this->embedrs = $this->wire(new Embedrs());
        return $this->embedrs;
    }

    /**
     * Return the embed type collection.
     */
    public function types() {
        if($this->types === null) $this->types = $this->wire(new EmbedrTypes());
        return $this->types;
    }

    /**
     * Get an embed item by name or ID.
     *
     * @param string|int $name
     * @return EmbedrItem|null
     */
    public function getEmbed($name) {
        return $this->embeds()->get($name);
    }

    /**
     * Render a named embed.
     */
    public function render($name, array $context = []) {
        $embed = $this->getEmbed($name);
        if(!$embed || !$embed->id) {
            return "<!-- Embedr: '" . $this->wire('sanitizer')->name((string) $name) . "' not found -->";
        }
        return $embed->render($context);
    }

    /**
     * Render a Page field through a site template.
     *
     * This works with FieldtypeTable, Repeaters, PageTable, Page Reference
     * fields, scalar fields, and custom iterable field values. The renderer
     * receives $page, $field, $value, $rows, $embedr and supplied variables.
     *
     * Options:
     * - template: path relative to /site/templates/
     * - cache: cache lifetime in seconds; false/0 disables caching
     * - cacheKey: optional stable cache namespace
     * - variables: additional variables for the renderer
     * - empty: markup returned for an empty value
     */
    public function renderField(Page $page, $fieldName, array $options = []) {
        $fieldName = $this->wire('sanitizer')->fieldName((string) $fieldName);
        if(!$fieldName || !$page->hasField($fieldName)) return '';

        $field = $this->wire('fields')->get($fieldName);
        $value = $page->get($fieldName);
        if($this->isEmptyValue($value)) return (string) ($options['empty'] ?? '');

        $template = (string) ($options['template'] ?? ($this->componentsPath . $fieldName . '.php'));
        $templatePath = $this->resolveTemplatePath($template);
        if($templatePath === '') {
            $this->wire('log')->save('embedr-errors', "Field renderer not found: {$template}");
            return '<!-- Embedr: field renderer not found -->';
        }

        $cacheExpire = array_key_exists('cache', $options)
            ? (int) $options['cache']
            : (int) $this->fieldCacheExpire;
        $cacheKey = '';
        if($cacheExpire > 0) {
            $cacheKey = $this->fieldCacheKey($page, $fieldName, $templatePath, (string) ($options['cacheKey'] ?? ''));
            $cached = $this->wire('cache')->get($cacheKey);
            if(is_string($cached) && $cached !== '') return $cached;
        }

        $variables = is_array($options['variables'] ?? null) ? $options['variables'] : [];
        $variables = array_merge($variables, [
            'page' => $page,
            'field' => $field,
            'value' => $value,
            'rows' => $value,
            'embedr' => $this,
        ]);

        try {
            $markup = $this->renderTemplate($templatePath, $variables);
        } catch(\Throwable $error) {
            $this->wire('log')->save('embedr-errors', sprintf(
                'Field renderer: %s | Page: %d | Error: %s',
                basename($templatePath),
                (int) $page->id,
                $error->getMessage()
            ));
            return '<!-- Embedr: field render error -->';
        }

        if($cacheKey !== '' && $markup !== '') {
            $this->wire('cache')->save($cacheKey, $markup, $cacheExpire);
        }

        return $markup;
    }

    /**
     * Render a trusted PHP template with isolated variables.
     */
    protected function renderTemplate($templatePath, array $variables) {
        extract($variables, EXTR_SKIP);
        ob_start();
        try {
            include $templatePath;
            return (string) ob_get_clean();
        } catch(\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    protected function resolveTemplatePath($template) {
        $template = ltrim(str_replace('\\', '/', trim((string) $template)), '/');
        if($template === '' || strpos($template, '..') !== false) return '';

        $templatesRoot = realpath($this->wire('config')->paths->templates);
        $candidate = realpath($this->wire('config')->paths->templates . $template);
        if(!$templatesRoot || !$candidate || !is_file($candidate)) return '';
        if(strpos($candidate, $templatesRoot . DIRECTORY_SEPARATOR) !== 0) return '';
        return $candidate;
    }

    protected function fieldCacheKey(Page $page, $fieldName, $templatePath, $namespace) {
        $language = $this->wire('user')->language;
        $languageId = $language ? (int) $language->id : 0;
        $namespace = $namespace !== '' ? $namespace : 'embedr-field';
        return implode('-', [
            $this->wire('sanitizer')->name($namespace),
            (int) $page->id,
            (int) $page->modified,
            $this->wire('sanitizer')->fieldName($fieldName),
            $languageId,
            substr(sha1($templatePath), 0, 10),
        ]);
    }

    protected function isEmptyValue($value) {
        if($value === null || $value === '' || $value === false) return true;
        if(is_array($value) || $value instanceof \Countable) return count($value) === 0;
        return false;
    }

    /**
     * Copy settings from pre-0.4 ProcessEmbedr installations.
     */
    protected function migrateLegacyConfig() {
        $legacy = $this->wire('modules')->getModuleConfigData('ProcessEmbedr');
        if(!is_array($legacy)) $legacy = [];

        $formatter = $this->wire('modules')->getModuleConfigData('TextformatterEmbedr');
        if(is_array($formatter)) {
            foreach(['openTag', 'closeTag'] as $key) {
                if(!array_key_exists($key, $legacy) && array_key_exists($key, $formatter)) {
                    $legacy[$key] = $formatter[$key];
                }
            }
        }

        if(!$legacy) return;

        $current = $this->wire('modules')->getModuleConfigData($this);
        foreach(self::$defaultConfig as $key => $default) {
            if(array_key_exists($key, $legacy) && !array_key_exists($key, $current)) {
                $current[$key] = $legacy[$key];
            }
        }
        $this->wire('modules')->saveModuleConfigData($this, $current);
    }

    public static function getModuleConfigInputfields(array $data) {
        $data = array_merge(self::$defaultConfig, $data);
        $inputfields = new InputfieldWrapper();
        $modules = wire('modules');

        $field = $modules->get('InputfieldText');
        $field->name = 'componentsPath';
        $field->label = 'Components path';
        $field->description = 'Path relative to /site/templates/ for embed and field renderer templates.';
        $field->value = $data['componentsPath'];
        $field->required = true;
        $inputfields->add($field);

        foreach(['openTag' => 'Opening tag', 'closeTag' => 'Closing tag'] as $name => $label) {
            $field = $modules->get('InputfieldText');
            $field->name = $name;
            $field->label = $label;
            $field->value = $data[$name];
            $field->required = true;
            $field->columnWidth = 50;
            $inputfields->add($field);
        }

        $field = $modules->get('InputfieldInteger');
        $field->name = 'fieldCacheExpire';
        $field->label = 'Field renderer cache lifetime';
        $field->description = 'Seconds. Set to 0 to disable caching by default.';
        $field->value = (int) $data['fieldCacheExpire'];
        $field->min = 0;
        $inputfields->add($field);

        foreach([
            'autoDiscoverTypes' => ['Auto-discover types', 'Create embed types from PHP files in the components path.'],
            'showTypeIcons' => ['Show type icons', 'Display type icons in the admin application.'],
            'debugMode' => ['Debug mode', 'Write detailed diagnostics to the embedr-debug log.'],
        ] as $name => $meta) {
            $field = $modules->get('InputfieldCheckbox');
            $field->name = $name;
            $field->label = $meta[0];
            $field->description = $meta[1];
            $field->checked = !empty($data[$name]);
            $inputfields->add($field);
        }

        return $inputfields;
    }
}
