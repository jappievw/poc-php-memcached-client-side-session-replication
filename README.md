# POC for PHP sessions backed by memcached

## Design considerations

Given we have to re-evaluate whether the sessions are going to work reliable we have to take a few things into consideration.

* The Memcached daemon does not have any features to act in a highly available manner. In case the daemon gets restarted it forgets everything what was stored in there. The `libmemcached` library supports a poor man's HA option though from the clients by writing to two nodes.
* We have two Memcached nodes.
* In case we lose one node, we don't want the use of sessions in PHP to be interrupted.

## How?

In order to be able to validate the session behaviour an isolated environment has been created with Docker. The `docker-compose.yml` contains a definition of the stack: a simple php application and two memcached nodes. The php web server gets the memcached extension installed. The configuration file for `pecl-memcached` is included from the `./php-app/php-memcached-opt.ini` file during container image build time. The memcached containers are started in very verbose mode in order to see all commands being issued. A simple PHP application starts a session, displays the variables in the session and adds all query parameters in the session.

In order to start playing around just start the stack with `docker-compose up --build`. This will start both the application and the memcached containers. Any change to the `./php-app/php-memcached-opt.ini` file needs restarting the stack. Any change to the php file is reflected right away. Simulating a memcached node being unavailable `docker stop pocphpmemcachedsessions-memcached-1` or `2` of course.

__Note:__ Want to test on PHP7? Look in the `docker-compose.yml` file which of the ports you have to use.

| PHP setting | Value | Reasoning |
|:------------- |:--------------|:------|
| `memcached.sess_locking` | `Off` | This feature results in only one php process can access the session for a single user. Although this is very useful, it was not supported in the former extension, so disabled. It adds a lot of chatter to the memcached daemon to handle the locking. We don't need it either. |
| `memcached.sess_prefix` | `session.` | Logical separation of keys to prevent collisions. |
| `memcached.sess_remove_failed` | `1` | Automatically remove a Memcached server from the pool as soon it becomes unavailable. As soon it becomes available again it will be available for sessions again. See Known quirks below. |
| `memcached.sess_consistent_hash` | `On` | This is a very important setting for failure scenario's. The session configuration contains the two memcached servers. When PHP starts the session it assigns a Memcached server to act as a primary store based on the session Id. With this setting switched to off, it will assign the Memcached server statically. In case that server is unavailable, PHP will emit warnings and the session not able to be retrieved nor stored. Setting this value to `On` will result in a dynamic assignment of a primary Memcached server based on server availability. |
| `memcached.sess_number_of_replicas` | `1` | This makes libmemcached store the session in both configured Memcached servers. |
| `memcached.sess_binary` | `On` | Without this, the replication wouldn't work. No implications in user space though. |
| `memcached.sess_randomize_replica_read` | `Off` | Setting is not properly documented. Setting this to off did not result in different behaviour combined with the other settings. Configured like this because it feels safer to only read from one server. Keep in mind though that each session is assigned a primary Memcached server, based on a consistent hashing algorithm. |
| `memcached.sess_connect_timeout` | `100` | Time in milliseconds to use as a timeout to mark a Memcached server as unavailable. Given we only use a LAN any value higher than this will mean that we have bigger issues in the network. |
| `session.save_handler` | `memcached` | Obvious |
| `session.save_path` | `memcached-1:11211,memcached-2:11211` | Reference the two nodes. The documentation states that a retry_interval can be configured. It also states that a different syntax should be used. That's just nonsense. This works. |

__Note:__ the `session.gc_maxlifetime` has not been set, since it wasn't set earlier. Default value is 1440 seconds (24 min).

## Testing

In order to validate the behaviour of these settings with different versions of php and memcached extensions a suite of tests has been set up.

### Requirements
 * `docker` version `1.12`
 * `docker-compose` version `1.9.0-rc4`
 * `ansible` version `2.2.0.0`

### Environments
The following environments are used for testing:
 * PHP `5.6.29` with pecl-memcached (latest) on port 83
 * PHP `7.0.14` with pecl-memcached (master branch) on port 84
 * PHP `5.6.29` with elasticache from git master branch HEAD on port 85
 * PHP `7.0.14` with elasticache from git php7 branch HEAD on port 87

### Tests
The main ansible playbook `ansible/tests.yml` contains the definitions of the tests. With every test the stack defined in `docker-compose.yml` is destroyed and upped again. We do the following tests:
 * `ansible/roles/basic-set-values/tasks/main.yml` does basic interaction with the session in subsequent http requests.
 * `ansible/roles/single-node-failure/tasks/main.yml` validates the behaviour for primary and secondary memcached node failures.

### Running the tests
 * All tests for all environments:
        
        ansible-playbook ansible/tests.yml -vv
 
 * Running all tests based on php5:
        
        ansible-playbook ansible/tests.yml -vv --tags php5
 
 * Running only the elasticache tests for both php5 and php7:
 
        ansible-playbook ansible/tests.yml -vv --tags elasticache

 * Running the failing elasticache test for php7:
 
        ansible-playbook ansible/tests.yml -vv --tags php7-elasticache-node-failure

 * With a bit of creativity the other specific tests can be figured out. Or just look at the `tests.yml` file.

## Known quirks

* In case one Memcached daemon becomes unavailable for the web application servers we won't loose any functionalities in the site. As soon as this daemon becomes available again 50% of the sessions will be lost immediately.
* Can't think of more really.
