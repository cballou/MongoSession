Overview
--------
The MongoSession PHP session handler class was built as a drop-in for easily switching to handling sessions using Mongo. It's a 
great replacement to memcache(d) for VPS servers where you risk memory being reshuffled in the pool and taking performance hits. 
There are currently inherent risks in using MongoDB as your session handler that you can read about on the documentation page:

http://www.jqueryin.com/projects/mongo-session/

The session handler has recently been updated to perform atomic updates as a quick (although not 100% effective) patch regarding the 
issue of race conditions caused by issuing AJAX commands. This generally shouldn't be a problem for most sites unless you are 
performing a series of AJAX requests which modify the same session data fields asynchronously.

Notes
-----
-  Although the $config array contains the parameter "persistent," it is not currently used.
-  Please make requests for additional functionality as I'm looking for ideas.

Default Usage 
-------------
If you only have a single server running Mongo on localhost, you can simply load the class and it'll use default config values. 
There's no need to create the database or collection in advance, as such functionality does not exist in Mongo. If neither the 
database nor collection exist, they will be created upon the first session insert. Please bare in mind this code should be placed at 
the top of your files before you make any calls to header():

    // include the session handler
    require_once('MongoSession.php');

    // load the session
    $session = new MongoSession();

Advanced Usage
--------------

If you are currently running multiple Mongo servers (i.e. replica pairs or sharding), there are more advanced configuration options
available to you for specifying multiple servers.  This functionality has not yet been tested but the configuration is documented 
on the website. If you fork this library and make any substantial updates or bug fixes, please consider sending a push request.
