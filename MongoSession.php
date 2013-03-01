<?php
/*
 * This MongoDB session handler is intended to store any data you see fit.
 * One interesting optimization to note is the setting of the active flag
 * to 0 when a session has expired. The intended purpose of this garbage
 * collection is to allow you to create a batch process for removal of
 * all expired sessions. This should most likely be implemented as a cronjob
 * script.
 *
 * @author		Corey Ballou
 * @copyright	Corey Ballou (2010)
 * 
 */
class MongoSession {
	
    // default config with support for multiple servers
    // (helpful for sharding and replication setups)
    protected $_config = array(
        // cookie related vars
        'cookie_path'   => '/',
        'cookie_domain' => '.mydomain.com', // .mydomain.com

        // session related vars
        'lifetime'      => 3600,        // session lifetime in seconds
        'database'      => 'session',   // name of MongoDB database
        'collection'    => 'session',   // name of MongoDB collection

		// persistent related vars
		'persistent' 	=> false, 			// persistent connection to DB?
        'persistentId' 	=> 'MongoSession', 	// name of persistent connection
		
		// whether we're supporting replicaSet
		'replicaSet'		=> false,

		// array of mongo db servers
		'connectionString'    => ''
    );

	// stores the connection
	protected $_connection;

	// stores the mongo db
	protected $_mongo;

	// stores session data results
	protected $_session;

    /**
     * Default constructor.
     *
     * @access  public
     * @param   array   $config
     */
    public function __construct($config = array())
	{
        // initialize the database
        $this->_init(empty($config) ? $this->_config : $config);

        // set object as the save handler
        session_set_save_handler(
            array($this, 'open'),
            array($this, 'close'),
            array($this, 'read'),
            array($this, 'write'),
            array($this, 'destroy'),
            array($this, 'gc')
        );

        // set some important session vars
        ini_set('session.auto_start',               0);
        ini_set('session.gc_probability',           1);
        ini_set('session.gc_divisor',               100);
        ini_set('session.gc_maxlifetime',           $this->_config['lifetime']);
        ini_set('session.referer_check',            '');
        ini_set('session.entropy_file',             '/dev/urandom');
        ini_set('session.entropy_length',           16);
        ini_set('session.use_cookies',              1);
        ini_set('session.use_only_cookies',         1);
        ini_set('session.use_trans_sid',            0);
        ini_set('session.hash_function',            1);
        ini_set('session.hash_bits_per_character',  5);

        // disable client/proxy caching
        session_cache_limiter('nocache');
        
        // set the cookie parameters
        session_set_cookie_params(
			$this->_config['lifetime'],
			$this->_config['cookie_path'],
			$this->_config['cookie_domain']
		);

        // name the session
        session_name('mongo_sess');
    
        register_shutdown_function('session_write_close');
        
        // start it up
        session_start();
	}

    /**
     * Initialize MongoDB. There is currently no support for persistent
     * connections.  It would be very easy to implement, I just didn't need it.
     *
     * @access  private
     * @param   array   $config
     */
    private function _init($config)
    {
        // ensure they supplied a database
        if (empty($config['database'])) {
            throw new Exception('You must specify a MongoDB database to use for session storage.');
        }
        
        if (empty($config['collection'])) {
            throw new Exception('You must specify a MongoDB collection to use for session storage.');
        }
        
        // update config
        $this->_config = array_merge($this->_config, $config);
        
        
        
		// add immediate connection
		$opts = array('connect' => true);
		
		// support persistent connections
		if ($this->_config['persistent'] && !empty($this->_config['persistentId'])) {
            $opts['persist'] = $this->_config['persistentId'];
        }

		// support replica sets
		if ($this->_config['replicaSet']) {
			$opts['replicaSet'] = $this->_config['replicaSet'];
		}
		
        // load mongo server connection
		try {
			$this->_connection = new Mongo($this->_config['connectionString'], $opts);
		} catch (Exception $e) {
			throw new Exception('Can\'t connect to the MongoDB server.');
		}
        
        // load the db
        try {
            $mongo = $this->_connection->selectDB($this->_config['database']);
        } catch (InvalidArgumentException $e) {
            throw new Exception('The MongoDB database specified in the config does not exist.');
        }
        
        // load collection
        try {
            $this->_mongo = $mongo->selectCollection($this->_config['collection']);
        } catch(Exception $e) {
            throw new Exception('The MongoDB collection specified in the config does not exist.');
        }
        
        // proper indexing on the expiration
        $this->_mongo->ensureIndex(
			array('expiry' => 1),
			array('name' => 'expiry',
				  'unique' => false,
				  'dropDups' => true,
				  'safe' => true,
                  'sparse' => true,
			)
		);
		
		// proper indexing of session id and lock
		$this->_mongo->ensureIndex(
			array('session_id' => 1, 'lock' => 1),
			array('name' => 'session_id',
				  'unique' => true,
				  'dropDups' => true,
				  'safe' => true
			)
		);
    }

    /**
     * Open does absolutely nothing as we already have an open connection.
     *
     * @access  public
     * @return	bool
     */
    public function open($save_path, $session_name)
    {
        return true;
    }

    /**
     * Close does absolutely nothing as we can assume __destruct handles
     * things just fine.
     *
     * @access  public
     * @return	bool
     */
    public function close()
    {
        return true;
    }

    /**
     * Read the session data.
     *
     * @access	public
     * @param	string	$id
     * @return	string
     */
    public function read($id)
    {
		// obtain a read lock on the data, or subsequently wait for
		// the lock to be released
		$this->_lock($id);

        // exclude results that are inactive or expired
        $result = $this->_mongo->findOne(
			array(
				'session_id'	=> $id,
				'expiry'    	=> array('$gte' => time()),
				'active'    	=> 1
			)
		);

        if (isset($result['data'])) {
            $this->_session = $result;
            return $result['data'];
        }

        return '';
   }

    /**
     * Atomically write data to the session, ensuring we remove any
     * read locks.
     *
     * @access  public
     * @param   string  $id
     * @param   mixed   $data
     * @return	bool
     */
    public function write($id, $data)
    {
        // create expires
        $expiry = time() + $this->_config['lifetime'];

        // create new session data
        $new_obj = array(
            'data'		=> $data,
			'lock'		=> 0,
            'active'		=> 1,
            'expiry'		=> $expiry
        );
        
        // check for existing session for merge
        if (!empty($this->_session)) {
            $obj = (array) $this->_session;
            $new_obj = array_merge($obj, $new_obj);
        }
        unset($new_obj['_id']);
        
		// atomic update
		$query = array('session_id' => $id);
		
		// update options
		$options = array(
			'upsert' 	=> TRUE,
			'safe'		=> TRUE,
			'fsync'		=> FALSE
		);
  
		// perform the update or insert
		try {
			$result = $this->_mongo->update($query, array('$set' => $new_obj), $options);
			return $result['ok'] == 1;
		} catch (Exception $e) {
            throw $e;
			return false;
		}
		
        return true;
    }

    /**
     * Destroys the session by removing the document with
     * matching session_id.
     *
     * @access  public
     * @param   string  $id
     * @return  bool
     */
    public function destroy($id)
    {
        $this->_mongo->remove(array('session_id' => $id), array('w' => true));
        return true;
    }

    /**
     * Garbage collection. Remove all expired entries atomically.
     *
     * @access  public
     * @return	bool
     */
	public function gc()
	{
		// define the query
		$query = array('expiry' => array('$lt' => time()));
		
		// specify the update vars
		$update = array('$set' => array('active' => 0));
		
		// update options
		$options = array(
			'multiple'	=> TRUE,
			'safe'		=> TRUE,
			'fsync'		=> FALSE
		);
		
		// update expired elements and set to inactive
		$this->_mongo->update($query, $update, $options);

		return true;
   	}
	
	/**
	 * Solves issues with write() and close() throwing exceptions.
	 *
	 * @access	public
	 * @return	void
	 */
	public function __destruct()
	{
		session_write_close();
	}
	
	/**
	 * Create a global lock for the specified document.
	 *
	 * @author	Benson Wong (mostlygeek@gmail.com)
	 * @access	private
	 * @param	string	$id
	 */
	private function _lock($id)
	{
        $remaining = 30000000;
		$timeout = 5000;
		
        // Check for a row.
        $result = $this->_mongo->findOne(
			array(
				'session_id'	=> $id,
				'expiry'    	=> array('$gte' => time()),
				'active'    	=> 1
			)
		);
        
        // If we have a row, attempt to get a read lock.
        if (!empty($result)) {
            do {
                try {
                    $query = array('session_id' => $id, 'lock' => 0);
                    $update = array('$set' => array('lock' => 1));
                    $options = array('safe' => true);
                    $result = $this->_mongo->update($query, $update, $options);              
                    if ($result['ok'] == 1) {
                        return true;
                    }

                } catch (MongoCursorException $e) {
                    throw $e;
                    if (substr($e->getMessage(), 0, 26) != 'E11000 duplicate key error') {
                        throw $e; // not a dup key?
                    }
                }

                // force delay in microseconds
                usleep($timeout);
                $remaining -= $timeout;

                // backoff on timeout, save a tree. max wait 1 second
                $timeout = ($timeout < 1000000) ? $timeout * 2 : 1000000;

            } while ($remaining > 0);

            // aww shit.
            throw new Exception('Could not obtain a session lock.');
        }
	}

}
