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

// --------------------------------------------------------------------

class Session_Redis extends \Session_Driver
{
	/**
	 * array of driver config defaults
	 */
	protected static $_defaults = array(
		'cookie_name' => 'fuelrid',				// name of the session cookie for redis based sessions
		'database'    => 'default',				// name of the redis database to use (as configured in config/db.php)
	);

	/*
	 * @var	storage for the redis object
	 */
	protected $redis = false;

	// --------------------------------------------------------------------

	public function __construct($config = array())
	{
		parent::__construct($config);

		// merge the driver config with the global config
		$this->config = array_merge($config, is_array($config['redis']) ? $config['redis'] : static::$_defaults);

		$this->config = $this->_validate_config($this->config);
	}

	// --------------------------------------------------------------------

	/**
	 * destroy the current session
	 *
	 * @return	\Session_Redis
	 */
	public function destroy()
	{
		// do we have something to destroy?
		if ( ! empty($this->keys))
		{
			// delete the key from the redis server
			$this->redis->del($this->keys['session_id']);
		}

		parent::destroy();

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * driver initialisation
	 *
	 * @return	void
	 */
	protected function init()
	{
		// generic driver initialisation
		parent::init();

		if ($this->redis === false)
		{
			// get the redis database instance
			$this->redis = \Redis_Db::instance($this->config['database']);
		}
	}

	// --------------------------------------------------------------------

	/**
	 * read the session
	 *
	 * @param	bool	$force	set to true if we want to force a new session to be created
	 * @return	\Session_Driver
	 * @throws	\FuelException
	 */
	protected function read($force = false)
	{
		// get the session cookie
		$cookie = $this->_get_cookie();

		// if a cookie was present, find the session record
		if ($cookie and ! $force and isset($cookie[0]))
		{
			// read the session file
			$payload = $this->_read_redis($cookie[0]);

			if ($payload === false)
			{
				// cookie present, but session record missing. force creation of a new session
				return $this->read(true);
			}

			// unpack the payload
			$payload = $this->_unserialize($payload);

			// session referral?
			if (isset($payload['rotated_session_id']))
			{
				$payload = $this->_read_redis($payload['rotated_session_id']);
				if ($payload === false)
				{
					// cookie present, but session record missing. force creation of a new session
					return $this->read(true);
				}

				// unpack the payload
				$payload = $this->_unserialize($payload);
			}

			if ( ! isset($payload[0]) or ! is_array($payload[0]))
			{
				logger('DEBUG', 'Error: not a valid redis session payload!');
			}
			elseif ($payload[0]['updated'] + $this->config['expiration_time'] <= $this->time->get_timestamp())
			{
				logger('DEBUG', 'Error: session id has expired!');
			}
			elseif ($this->config['match_ip'] and $payload[0]['ip_hash'] !== md5(\Input::ip().\Input::real_ip()))
			{
				logger('DEBUG', 'Error: IP address in the session doesn\'t match this requests source IP!');
			}
			elseif ($this->config['match_ua'] and $payload[0]['user_agent'] !== \Input::user_agent())
			{
				logger('DEBUG', 'Error: User agent in the session doesn\'t match the browsers user agent string!');
			}
			else
			{
				// session is valid, retrieve the rest of the payload
				if (isset($payload[0]) and is_array($payload[0]))
				{
					$this->keys  = $payload[0];
				}
				if (isset($payload[1]) and is_array($payload[1]))
				{
					$this->data  = $payload[1];
				}
				if (isset($payload[2]) and is_array($payload[2])){
					$this->flash = $payload[2];
				}
			}
		}

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * write the session
	 *
	 * @return	\Session_Redis
	 */
	protected function write()
	{
		// do we have something to write?
		if ( ! empty($this->keys) or ! empty($this->data) or ! empty($this->flash))
		{
			// rotate the session id if needed
			$this->rotate(false);

			// record the last update time of the session
			$this->keys['updated'] = $this->time->get_timestamp();

			// session payload
			$payload = $this->_serialize(array($this->keys, $this->data, $this->flash));

			// create the session file
			$this->_write_redis($this->keys['session_id'], $payload);

			// was the session id rotated?
			if ( isset($this->keys['previous_id']) and $this->keys['previous_id'] != $this->keys['session_id'])
			{
				// point the old session file to the new one, we don't want to lose the session
				$payload = $this->_serialize(array('rotated_session_id' => $this->keys['session_id']));
				$this->_write_redis($this->keys['previous_id'], $payload);
			}

			$this->_set_cookie(array($this->keys['session_id']));
		}

		return $this;
	}

	// --------------------------------------------------------------------

	/**
	 * Writes the redis entry
	 *
	 * @param	$session_id
	 * @param	$payload
	 * @return	boolean, true if it was an existing session, false if not
	 */
	protected function _write_redis($session_id, $payload)
	{
		// write it to the redis server
		$this->redis->set($session_id, $payload);
		$this->redis->expire($session_id, $this->config['expiration_time']);
	}

	// --------------------------------------------------------------------

	/**
	 * Reads the redis entry
	 *
	 * @param	$session_id
	 * @return	mixed, the payload if the file exists, or false if not
	 */
	protected function _read_redis($session_id)
	{
		// fetch the session data from the redis server
		return $this->redis->get($session_id);
	}

	// --------------------------------------------------------------------

	/**
	 * validate a driver config value
	 *
	 * @param	array	array with configuration values
	 * @return 	array	validated and consolidated config
	 */
	public function _validate_config($config)
	{
		$validated = array();

		foreach ($config as $name => $item)
		{
			// filter out any driver config
			if (!is_array($item))
			{
				switch ($item)
				{
					case 'cookie_name':
						if ( empty($item) or ! is_string($item))
						{
							$item = 'fuelrid';
						}
					break;

					case 'database':
						// do we have a servers config
						if ( empty($item) or ! is_array($item))
						{
							$item = 'default';
						}
					break;

					default:
					break;
				}

				// global config, was validated in the driver
				$validated[$name] = $item;
			}
		}

		// validate all global settings as well
		return parent::_validate_config($validated);
	}

}
