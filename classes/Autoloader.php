<?php
/**
 * Fuel is a fast, lightweight, community driven PHP 5.4+ framework.
 *
 * @package    Fuel
 * @version    1.9-dev
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2018 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Core;

/**
 * The Autoloader is responsible for all class loading.  It allows you to define
 * different load paths based on namespaces.  It also lets you set explicit paths
 * for classes to be loaded from.
 *
 * @package     Fuel
 * @subpackage  Core
 */
class Autoloader
{
	/**
	 * @var  ClassLoader  $composer  Composer instance
	 */
	protected static $composer;

	/**
	 * @var  array  $classes  holds all the classes and paths
	 */
	protected static $classes = array();

	/**
	 * @var  array  holds all the namespace paths
	 */
	protected static $namespaces = array();

	/**
	 * @var  array  list off namespaces of which classes will be aliased to global namespace
	 */
	protected static $core_namespaces = array(
		'Fuel\\Core',
	);

	/**
	 * @var  array  the default path to look in if the class is not in a package
	 */
	protected static $default_path = null;

	/**
	 * @var  bool  whether to initialize a loaded class
	 */
	protected static $auto_initialize = null;

	/**
	 * Adds a namespace search path.  Any class in the given namespace will be
	 * looked for in the given path.
	 *
	 * @param   string  $namespace  the namespace
	 * @param   string  $path       the path
	 * @param   bool    $psr        whether this is a PSR-0 compliant class
	 * @return  void
	 */
	public static function add_namespace($namespace, $path)
	{
		static::$namespaces[$namespace] = $path;
	}

	/**
	 * Adds an array of namespace paths. See {add_namespace}.
	 *
	 * @param   array  $namespaces  the namespaces
	 * @param   bool   $prepend     whether to prepend the namespace to the search path
	 * @return  void
	 */
	public static function add_namespaces(array $namespaces, $prepend = false)
	{
		if ( ! $prepend)
		{
			static::$namespaces = array_merge(static::$namespaces, $namespaces);
		}
		else
		{
			static::$namespaces = $namespaces + static::$namespaces;
		}
	}

	/**
	 * Returns the namespace's path or false when it doesn't exist.
	 *
	 * @param   string      $namespace  the namespace to get the path for
	 * @return  array|bool  the namespace path or false
	 */
	public static function namespace_path($namespace)
	{
		if ( ! array_key_exists($namespace, static::$namespaces))
		{
			return false;
		}

		return static::$namespaces[$namespace];
	}

	/**
	 * Adds a classes load path.  Any class added here will not be searched for
	 * but explicitly loaded from the path.
	 *
	 * @param   string  $class  the class name
	 * @param   string  $path   the path to the class file
	 * @return  void
	 */
	public static function add_class($class, $path)
	{
		static::$classes[$class] = $path;
	}

	/**
	 * Adds multiple class paths to the load path. See {@see Autoloader::add_class}.
	 *
	 * @param   array  $classes  the class names and paths
	 * @return  void
	 */
	public static function add_classes($classes)
	{
		foreach ($classes as $class => $path)
		{
			static::$classes[$class] = $path;
		}
	}

	/**
	 * Aliases the given class into the given Namespace.  By default it will
	 * add it to the global namespace.
	 *
	 * <code>
	 * Autoloader::alias_to_namespace('Foo\\Bar');
	 * Autoloader::alias_to_namespace('Foo\\Bar', '\\Baz');
	 * </code>
	 *
	 * @param  string  $class      the class name
	 * @param  string  $namespace  the namespace to alias to
	 */
	public static function alias_to_namespace($class, $namespace = '')
	{
		empty($namespace) or $namespace = rtrim($namespace, '\\').'\\';
		$parts = explode('\\', $class);
		$root_class = $namespace.array_pop($parts);
		class_alias($class, $root_class);
	}

	/**
	 * Register's the autoloader to the SPL autoload stack.
	 *
	 * @return	void
	 */
	public static function register()
	{
		// define our v1 autoloader first
		spl_autoload_register('Autoloader::load', true, true);
	}

	/**
	 * Add a namespace for which classes may be used without the namespace prefix and
	 * will be auto-aliased to the global namespace.
	 * Prefixing the classes will overwrite core classes and previously added namespaces.
	 *
	 * @param  string $namespace
	 * @param  bool   $prefix
	 * @return void
	 */
	public static function add_core_namespace($namespace, $prefix = true)
	{
		if ($prefix)
		{
			array_unshift(static::$core_namespaces, $namespace);
		}
		else
		{
			static::$core_namespaces[] = $namespace;
		}
	}

	/**
	 * Loads a class.
	 *
	 * @param   string  $class  Class to load
	 * @return  bool    If it loaded the class
	 */
	public static function load($class)
	{
		// deal with funny is_callable('static::classname') side-effect
		if (strpos($class, 'static::') === 0)
		{
			// is called from within the class, so it's already loaded
			return true;
		}

		$loaded = false;
		$class = ltrim($class, '\\');
		$pos = strripos($class, '\\');

		if (empty(static::$auto_initialize))
		{
			static::$auto_initialize = $class;
		}

		// check first if it is a registered class
		if (isset(static::$classes[$class]))
		{
			$file = str_replace('/', DS, static::$classes[$class]);
			if (is_file($file))
			{
				include $file;
				static::init_class($class);
				$loaded = true;
			}
		}

		// check next if it's a known namespace, and we know where they live
		if ( ! $loaded and $full_ns = substr($class, 0, $pos))
		{
			foreach (static::$namespaces as $ns => $path)
			{
				$ns = ltrim($ns, '\\');
				if (stripos($full_ns, $ns) === 0)
				{
					$path .= static::class_to_path(
						substr($class, strlen($ns) + 1)
					);
					if (is_file($path))
					{
						include $path;
						static::init_class($class);
						$loaded = true;
						break;
					}
					elseif (is_file($path = strtolower($path)))
					{
						include $path;
						static::init_class($class);
						$loaded = true;
						logger(\Fuel::L_WARNING, "File $path does not conform to PSR-4 naming!");
					}
				}
			}
		}

		// still not found? could be defined in the app
		if ( ! $loaded)
		{
			$path = APPPATH.'classes'.DS.static::class_to_path($class);

			// fallback to lowercase files, for BC reasons
			if (is_file($path))
			{
				include $path;
				static::init_class($class);
				$loaded = true;
			}
			elseif (is_file($path = strtolower($path)))
			{
				include $path;
				static::init_class($class);
				$loaded = true;
				logger(\Fuel::L_WARNING, "File $path does not conform to PSR-4 naming!");
			}
		}

		// last chance, could be a core class
		if ( ! $loaded and ! $full_ns)
		{
			foreach (static::$core_namespaces as $ns)
			{
				$full_class = $ns.'\\'.$class;
				if (class_exists($full_class))
				{
					class_alias($full_class, $class);
					static::init_class($class);
					$loaded = true;
					break;
				}
				elseif (interface_exists($full_class) or trait_exists($full_class))
				{
					class_alias($full_class, $class);
					$loaded = true;
					break;
				}
			}
		}

		// Prevent failed load from keeping other classes from initializing
		if (static::$auto_initialize == $class)
		{
			static::$auto_initialize = null;
		}

		return $loaded;
	}

	/**
	 * Reset the auto initialize state after an autoloader exception.
	 * This method is called by the exception handler, and is considered an
	 * internal method!
	 *
	 * @access protected
	 */
	public static function _reset()
	{
		static::$auto_initialize = null;
	}

	/**
	 * Takes a class name and turns it into a path according to the
	 * PSR-4 standard
	 *
	 * Note: This does not check if the file exists...just gets the path
	 *
	 * @param   string  $class  Class name
	 * @return  string  Path for the class
	 */
	protected static function class_to_path($class)
	{
		// convert the classname to a filename
		$file  = '';
		if ($last_ns_pos = strripos($class, '\\'))
		{
			$namespace = substr($class, 0, $last_ns_pos);
			$class = substr($class, $last_ns_pos + 1);
			$file = str_replace('\\', DS, $namespace).DS;
		}

		// if the old Fuel standard, underscores indicate a directory seperator
		$file .= str_replace('_', DS, $class).'.php';

		return $file;
	}

	/**
	 * Prepares a given path by making sure the directory separators are correct.
	 *
	 * @param   string  $path  Path to prepare
	 * @return  string  Prepped path
	 */
	protected static function prep_path($path)
	{
		return $path ? str_replace(array('/', '\\'), DS, $path) : null;
	}

	/**
	 * Checks to see if the given class has a static _init() method.  If so then
	 * it calls it.
	 *
	 * @param string $class the class name
	 * @throws \Exception
	 * @throws \FuelException
	 */
	protected static function init_class($class)
	{
		// if the loaded file contains a class...
		if (class_exists($class, false))
		{
			// call the classes static init if needed
			if (static::$auto_initialize === $class)
			{
				static::$auto_initialize = null;
				if (method_exists($class, '_init') and is_callable($class.'::_init'))
				{
					call_user_func($class.'::_init');
				}
			}
		}

		// or an interface...
		elseif (interface_exists($class, false))
		{
			// nothing to do here
		}

		// or a trait if you're not on 5.3 anymore...
		elseif (function_exists('trait_exists') and trait_exists($class, false))
		{
			// nothing to do here
		}

		// else something went wrong somewhere, barf and exit now
		else
		{
			throw new \FuelException('Class "'.$class.'" is not defined');
		}
	}
}