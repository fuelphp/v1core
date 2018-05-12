<?php
/**
 * Fuel is a fast, lightweight, community driven PHP 5.4+ framework.
 *
 * @package    Fuel
 * @version    develop
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2018 Fuel Development Team
 * @link       http://fuelphp.com
 */


/**
 * Register all the error/shutdown handlers
 */
register_shutdown_function(function ()
{
	// reset the autoloader
	\Autoloader::_reset();

	// if we have sessions loaded, and native session emulation active
	if (\Config::get('session.native_emulation', false))
	{
		// close the name session
		session_id() and session_write_close();
	}

	// make sure we're having an output filter so we can display errors
	// occuring before the main config file is loaded
	\Config::get('security.output_filter', null) or \Config::set('security.output_filter', 'Security::htmlentities');

	try
	{
		// fire any app shutdown events
		\Event::instance()->trigger('shutdown', '', 'none', true);

		// fire any framework shutdown events
		\Event::instance()->trigger('fuel-shutdown', '', 'none', true);
	}
	catch (\Exception $e)
	{
		if (\Fuel::$is_cli)
		{
			\Cli::error("Error: ".$e->getMessage()." in ".$e->getFile()." on ".$e->getLine());
			\Cli::beep();
			exit(1);
		}
		else
		{
			logger(\Fuel::L_ERROR, 'shutdown - ' . $e->getMessage()." in ".$e->getFile()." on ".$e->getLine());
		}
	}
	return \Errorhandler::shutdown_handler();
});

set_exception_handler(function ($e)
{
	// reset the autoloader
	\Autoloader::_reset();

	// deal with PHP bugs #42098/#54054
	if ( ! class_exists('Errorhandler'))
	{
		include COREPATH.'classes/errorhandler.php';
		class_alias('\Fuel\Core\Errorhandler', 'Errorhandler');
		class_alias('\Fuel\Core\PhpErrorException', 'PhpErrorException');
	}

	return \Errorhandler::exception_handler($e);
});

set_error_handler(function ($severity, $message, $filepath, $line)
{
	// reset the autoloader
	\Autoloader::_reset();

	// deal with PHP bugs #42098/#54054
	if ( ! class_exists('Errorhandler'))
	{
		include COREPATH.'classes/errorhandler.php';
		class_alias('\Fuel\Core\Errorhandler', 'Errorhandler');
		class_alias('\Fuel\Core\PhpErrorException', 'PhpErrorException');
	}

	return \Errorhandler::error_handler($severity, $message, $filepath, $line);
});
