<?php
/*
 * This MongoDB session handler is intended to store any data you see fit.
 */
class MongoSession {
    
    // example config with support for multiple servers
    // (helpful for sharding and replication setups)
    protected $_config = array(
        // cookie related vars
        'cookie_path'   => '/',
        'cookie_domain' => '.mofollow.com', // .mydomain.com

        // session related vars
        'lifetime'      => 3600,        // session lifetime in seconds
        'database'      => 'session',   // name of MongoDB database
        'collection'    => 'session',   // name of MongoDB collection

		// array of mongo db servers
        'servers'   	=> array(
            array(
                'host'          => Mongo::DEFAULT_HOST,
                'port'          => Mongo::DEFAULT_PORT,
                'username'      => null,
                'password'      => null,
                'persistent'    => false
            )
        )
    );

	// stores the mongo db
	protected $mongo;

	// stores session data results
	private $session;

    /**
     * Default constructor.
     *
     * @access  public
     * @param   array   $config
     */
    public function __construct($config = array()) {
        // initialize the database
        $this->_init(empty($config) ? $this->_config : $config);

        // set object as the save handler
        session_set_save_handler(
            array(&$this, 'open'),
            array(&$this, 'close'),
            array(&$this, 'read'),
            array(&$this, 'write'),
            array(&$this, 'destroy'),
            array(&$this, 'gc')
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
        session_set_cookie_params($this->_config['lifetime'],
                                  $this->_config['cookie_path'],
                                  $this->_config['cookie_domain']);
        // name the session
        session_name('mongo_sess');
    
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
        $this->_config = $config;
        
        // generate server connection strings
        $connections = array();
        if (!empty($this->_config['servers'])) {
            foreach ($this->_config['servers'] as $server) {
                $str = '';
                if (!empty($server['username']) && !empty($server['password'])) {
                    $str .= $server['username'] . ':' . $server['password'] . '@';
                }
                $str .= $server['host'] . ':' . $server['port'];
                array_push($connections, $str);
            }
        } else {
            // use default connection settings
            array_push($connections, Mongo::DEFAULT_HOST . ':' . Mongo::DEFAULT_PORT);
        }
        
        // load mongo servers
        $mongo = new Mongo('mongodb://' . implode(',', $connections));
        
        // load db
        try {
            $mongo = $mongo->selectDB($this->_config['database']);
        } catch (InvalidArgumentException $e) {
            throw new Exception('The MongoDB database specified in the config does not exist.');
        }
        
        // load collection
        try {
            $this->mongo = $mongo->selectCollection($this->_config['collection']);
        } catch(Exception $e) {
            throw new Exception('The MongoDB collection specified in the config does not exist.');
        }
        
        // ensure we have proper indexing on both the session_id and expiry params
        $this->mongo->ensureIndex('id', array('id' => 1));
        $this->mongo->ensureIndex('expiry', array('expiry' => 1));   
    }

    /**
     * Open does absolutely nothing as we already have an open connection.
     *
     * @access  public
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
     */
    public function close()
    {
        return true;
    }

    /**
     * Read the session data.
     */
    public function read($id)
    {
        // retrieve valid session data
        $expiry = time() + (int) $this->_config['lifetime'];

        // exclude results that are inactive or expired
        $result = $this->mongo->findOne(
                                    array(
                                        'id'        => $id,
                                        'expiry'    => array('$gte' => $expiry),
                                        'active'    => 1
                                    )
        );

        $result = $this->mongo->findOne(array('id' => $id));
        if ($result) {
            $this->session = $result;
            return $result['data'];
        }
        
        return '';
   }

    /**
     * Write data to the session.
     *
     * @access  public
     * @param   string  $id
     * @param   mixed   $data
     */
    public function write($id, $data)
    {
        // create expires
        $expiry = time() + $this->_config['lifetime'];
        
        // create new session data
        $new_obj = array(
            'id'        => $id,
            'data'      => $data,
            'active'    => 1,
            'expiry'    => $expiry
        );
        
        // check for existing session for merge
        if (!empty($this->session)) {
            $obj = (array) $this->session;
            $new_obj = array_merge($obj, $new_obj);
        }
  
        $this->mongo->save($new_obj);
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
        $this->mongo->remove(array('id' => $id), true);
        return true;
    }

    /**
     * Garbage collection. Remove all expired entries.
     *
     * @access  public
     */
	public function gc()
    {
		// update expired elements and set to inactive
		$this->mongo->update(
                            array('expiry' => array(':lt' => time())),
                            array('$set' => array('active' => 0))
        );

    	return true;
   	}

}
