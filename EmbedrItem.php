<?php namespace ProcessWire;

/**
 * Embedr Item
 * 
 * Represents a single embed instance
 * 
 * @property int $id
 * @property string $name
 * @property string $title
 * @property int $type_id
 * @property EmbedrType $type
 * @property string $selector
 * @property int $created
 * @property int $modified
 */
class EmbedrItem extends WireData {
    
    /**
     * Default values
     * 
     * @var array
     */
    protected $defaults = [
        'id' => 0,
        'name' => '',
        'title' => '',
        'type_id' => 0,
        'selector' => '',
        'created' => 0,
        'modified' => 0,
    ];
    
    /**
     * Type object
     *
     * @var EmbedrType|null
     */
    protected $typeObject = null;

    /**
     * Cached debug mode flag (null = not loaded yet)
     *
     * @var bool|null
     */
    protected $debugMode = null;
    
    /**
     * Construct
     */
    public function __construct() {
        $this->setArray($this->defaults);
        parent::__construct();
    }
    
    /**
     * Set property
     *
     * @param string $key
     * @param mixed $value
     * @return self|WireData
     */
    public function set($key, $value) {
        if($key === 'type' && $value instanceof EmbedrType) {
            $this->typeObject = $value;
            $this->type_id = $value->id;
            return $this;
        }

        // Prevent an existing ID from being silently reset to 0 (e.g. via setArray with POST data)
        if($key === 'id') {
            $currentId = (int) $this->get('id');
            $newId = (int) $value;
            if($currentId > 0 && $newId === 0) return $this;
        }

        if(isset($this->defaults[$key]) && is_int($this->defaults[$key])) {
            $value = (int) $value;
        }

        return parent::set($key, $value);
    }
    
    /**
     * Get property
     * 
     * @param string $key
     * @return mixed
     */
    public function get($key) {
        if($key === 'type') {
            return $this->getType();
        }
        
        return parent::get($key);
    }
    
    /**
     * Lazy-load debug mode from module config (cached per instance)
     *
     * @return bool
     */
    protected function getDebugMode() {
        if($this->debugMode !== null) return $this->debugMode;
        try {
            $config = $this->wire('modules')->getModuleConfigData('Embedr');
            $this->debugMode = !empty($config['debugMode']);
        } catch(\Exception $e) {
            $this->debugMode = false;
        }
        return $this->debugMode;
    }

    /**
     * Get type object
     *
     * @return EmbedrType|null
     */
    public function getType() {
        if($this->typeObject !== null) {
            return $this->typeObject;
        }
        
        if(!$this->type_id) {
            return null;
        }
        
        $types = $this->wire(new EmbedrTypes());
        $this->typeObject = $types->getById($this->type_id);
        
        return $this->typeObject;
    }
    
    /**
     * Render this embed
     *
     * @return string
     */
    public function render(array $context = []) {
        $debugMode = $this->getDebugMode();

        $user = $this->wire('user');
        $userName = $user ? $user->name : 'unknown';
        $isGuest  = $user ? $user->isGuest() : true;

        if($debugMode) {
            $this->wire('log')->save('embedr-debug', sprintf(
                '[Embedr::render] Starting | Embed=%s (ID=%s), Selector=%s | User=%s (guest=%s)',
                $this->name, $this->id, $this->selector,
                $userName, $isGuest ? 'YES' : 'NO'
            ));
        }

        $type = $this->getType();

        if(!$type) {
            if($debugMode) $this->wire('log')->save('embedr-debug', '[Embedr::render] ERROR: Type not found');
            return "<!-- Embedr: Type not found -->";
        }

        if($debugMode) {
            $this->wire('log')->save('embedr-debug', sprintf(
                '[Embedr::render] Type loaded | Name=%s, Template=%s, Mode=%s',
                $type->name, $type->template ?: 'none', $type->mode ?: 'array'
            ));
        }

        try {
            if($debugMode) {
                $this->wire('log')->save('embedr-debug', '[Embedr::render] Executing selector: ' . $this->selector);
            }

            $items = $this->wire('pages')->find($this->selector);

            if($debugMode) {
                $this->wire('log')->save('embedr-debug', sprintf(
                    '[Embedr::render] Selector found %d items', $items->count()
                ));
            }

            if($type->template && $type->templateExists()) {
                $templatePath = $type->getTemplatePath();

                if($debugMode) {
                    $this->wire('log')->save('embedr-debug',
                        '[Embedr::render] Using PHP template: ' . basename($templatePath)
                    );
                }

                try {
                    $page      = $this->wire('page');
                    $config    = $this->wire('config');
                    $input     = $this->wire('input');
                    $sanitizer = $this->wire('sanitizer');
                    $embed     = $this;
                    $embedContext = $context;

                    ob_start();
                    try {
                        include $templatePath;
                    } catch(\Throwable $e) {
                        ob_end_clean();
                        throw $e;
                    }
                    $output = ob_get_clean();

                    if($debugMode) {
                        $this->wire('log')->save('embedr-debug', sprintf(
                            '[Embedr::render] PHP template rendered (%d chars)', strlen($output)
                        ));
                    }

                    return $output;

                } catch(\Throwable $e) {
                    $errorMsg = sprintf(
                        '[Embedr::render] Template ERROR: %s in %s:%d',
                        $e->getMessage(), $e->getFile(), $e->getLine()
                    );
                    if($debugMode) $this->wire('log')->save('embedr-debug', $errorMsg);

                    $this->wire('log')->save('embedr-errors', sprintf(
                        'Template: %s | Embed: %s | User: %s | Error: %s',
                        basename($templatePath), $this->name, $this->wire('user')->name, $e->getMessage()
                    ));

                    // Do not expose server path or exception message in public output
                    return "<!-- Embedr: render error -->";
                }

            } else {
                if(!$items->count()) {
                    if($debugMode) $this->wire('log')->save('embedr-debug', '[Embedr::render] No items found');
                    return "<!-- Embedr: No items found -->";
                }

                if($debugMode) {
                    $reason = $type->template ? 'template file not found' : 'no template specified';
                    $this->wire('log')->save('embedr-debug', '[Embedr::render] Using visual renderer (' . $reason . ')');
                }

                require_once(__DIR__ . '/EmbedrRenderer.php');
                $renderer = new EmbedrRenderer($type, $items);
                $output   = $renderer->render();

                if($debugMode) {
                    $this->wire('log')->save('embedr-debug', sprintf(
                        '[Embedr::render] Visual renderer output (%d chars)', strlen($output)
                    ));
                }

                return $output;
            }

        } catch(\Exception $e) {
            $errorMsg = sprintf(
                '[Embedr::render] EXCEPTION: %s in %s:%d',
                $e->getMessage(), $e->getFile(), $e->getLine()
            );
            if($debugMode) $this->wire('log')->save('embedr-debug', $errorMsg);

            $this->wire('log')->save('embedr-errors', sprintf(
                'Embed: %s | User: %s | Selector: %s | Error: %s',
                $this->name, $this->wire('user')->name, $this->selector, $e->getMessage()
            ));

            return "<!-- Embedr: render error -->";
        }
    }
    
    /**
     * Get result count without rendering
     * 
     * @return int
     */
    public function getCount() {
        try {
            return $this->wire('pages')->count($this->selector);
        } catch(\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Validate embed data
     * 
     * @return bool|string Returns true if valid, error message if not
     */
    public function validate() {
        if(empty($this->name)) {
            return 'Name is required';
        }
        
        if(!preg_match('/^[a-z][a-z0-9_-]*$/i', $this->name)) {
            return 'Name must start with a letter and contain only letters, numbers, hyphens and underscores';
        }
        
        if(empty($this->title)) {
            return 'Title is required';
        }
        
        if(!$this->type_id) {
            return 'Type is required';
        }
        
        $type = $this->getType();
        if(!$type) {
            return 'Invalid type';
        }
        
        if(empty($this->selector)) {
            return 'Selector is required';
        }
        
        // Validate selector syntax
        try {
            $this->wire('pages')->find($this->selector);
        } catch(\Exception $e) {
            return 'Invalid selector: ' . $e->getMessage();
        }
        
        return true;
    }
    
    /**
     * Get shortcode for this embed
     * 
     * @return string
     */
    public function getShortcode() {
        $embedr = $this->wire('modules')->get('Embedr');
        $openTag = $embedr ? $embedr->openTag : '((';
        $closeTag = $embedr ? $embedr->closeTag : '))';
        
        return $openTag . $this->name . $closeTag;
    }
    
    /**
     * String representation
     * 
     * @return string
     */
    public function __toString() {
        return $this->title ?: $this->name;
    }
}
