Overview
--------
The MongoSession PHP session handler class was built as a drop-in for easily switching to handling sessions using Mongo. It's a great replacement to memcache(d) for VPS servers where you risk memory being reshuffled in the pool and taking performance hits.

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

*More documentation will be available soon.*
