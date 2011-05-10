Overview
--------
The MongoSession PHP session handler class was built as a drop-in for easily switching to handling sessions using Mongo. It's a 
great replacement to memcache(d) for VPS servers where you risk memory being reshuffled in the pool and taking performance hits. 
There are currently inherent risks in using MongoDB as your session handler that you can read about on the documentation page:

http://www.jqueryin.com/projects/mongo-session/

The session handler has recently been updated to perform atomic updates as a quick (although not 100% effective) patch regarding the 
issue of race conditions caused by issuing AJAX commands. This generally shouldn't be a problem for most sites unless you are 
performing a series of AJAX requests which modify the same session data fields asynchronously.

Default Usage 
-------------
If you only have a single server running Mongo on localhost, you can simply load the class and it'll use default config values. 
There's no need to create the database or collection in advance, as such functionality does not exist in Mongo. If neither the 
database nor collection exist, they will be created upon the first session insert. Please bare in mind this code should be placed at 
the top of your files before you make any calls to header():

```` PHP
// include the session handler
require_once('MongoSession.php');

// load the session
$session = new MongoSession();
````

Overriding Default Config Settings
--------------
You can override configuration settings in MongoSession by passing in an array with any of the following parameters below.
The default is below as a reference.

````PHP
$config = array(
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
    'servers'   	=> array(
        array(
            'host'          => Mongo::DEFAULT_HOST,
            'port'          => Mongo::DEFAULT_PORT,
            'username'      => null,
            'password'      => null
        )
    )
);

$session = new MongoSession($config);
````

Reason for Active Flag
---------------------

The reason garbage collection, gc(), updates the active flag and does not delete the entry is that it is intended to be a speed optimization.
The theoretical usage of this class is to setup a periodical cronjob to batch delete any sessions that have their active flag set to 0.

A future version of this library will include the cronjob example. For those of you who do not care to implement this, you may remove
all references to the active flag entirely in read(), write(), and gc(). Lastly, you will need to update gc() to delete the document:

````PHP
public function gc()
{
    // define the query
    $query = array('expiry' => array('$lt' => time()));
    
    // update options
    $options = array(
        'justOne'	=> TRUE,
        'safe'		=> TRUE,
        'fsync'		=> TRUE
    );
    
    // update expired elements and set to inactive
    $this->_mongo->remove($query, $options);

    return true;
}
````

Advanced Usage
--------------

If you are currently running multiple Mongo servers (i.e. replica pairs or sharding), there are more advanced configuration options
available to you for specifying multiple servers.  This functionality has not yet been tested.

Note
----

If you fork this library and make any substantial updates or bug fixes, please consider sending a push request.
