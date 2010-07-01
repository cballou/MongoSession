Overview
--------
The MongoSession PHP session handler class was built as a drop-in for easily switching to handling sessions using Mongo. It's a great replacement to memcache(d) for VPS servers where you risk memory being reshuffled in the pool and taking performance hits.

Notes
-----
-  Although the $config array contains the parameter "persistent," it is not currently used.
-  Please make requests for additional functionality as I'm looking for ideas.

*More documentation will be available soon.*
