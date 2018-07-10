The **ws(s)on** is the [event](http://pecl.php.net/package/event
"PECL::Package::event")-driven
PHP [WebSocket](https://tools.ietf.org/html/rfc6455 "RFC 6455") server
--------------------------------------------------------------------------------
### 1. Overview

The *ws(s)on* provides two asynchronous 
interconnected socket servers:
* WebSocket endpoint logical unit;
* external applications control logical unit.

Each of these units manages connections on its own TCP or Unix domain socket.
The WebSocket endpoint provides connection interactions according to the
RFC 6455 protocol description. Besides, this unit sets up additional
authorization protocol based on shared secret with timestamps (see description
below). The control logical unit lets users connects to server from external
applications (incl. other PHP scripts, of course) for realization direct and
broadcast messaging.

### 2. Recomendations and requirements

* PHP 7.1 or higher recommended (developed and tested with version 7.1);
* Event - PECL extension version 2.3.0 (stable) recommended (the same reason);
* POSIX-compliant operating system requiered.

### 3. Configuration

All available configuration parameters are described below.


name: **servers**;

description: represents the list of TCP or UDS sockets for websocket
clients connections;

required: **yes**;

examples:
```
	'servers' => ['tcp://127.0.0.1:8099']

	'servers' => ['unix://./server.sock']

	'servers' => [
		'tcp://127.0.0.1:8099',
		'tcp://127.0.0.1:8100',
		'tcp://127.0.0.2:8099'
	]
```

name: **domains**;

description: represents the list of domain names associated with sockets
(in order of **servers**);

required: **no**;

examples:
```
	'domains' => ['localhost:8099']

	'domains' => [
		'ws1.example.com',
		'ws2.example.com'
	]
```

name: **control**;

description: defines the control server TCP or UDS socket;

required: **yes**;

examples:
```
	'control' => 'unix://./control.sock'

	'control' => 'tcp://127.0.0.1:8098'
```

name: **pid**;

description: defines the path to the process pid file;

required: **yes**;

examples:
```
	'pid' => './server.pid'
	
	'pid' => '/path/to/the/pid/wson.pid'
```

name: **secret**;

defines the pre-shared server-to-server authorization key;

required: **yes**;

examples:
```
// don't use these examples!!!
// generate and use your own passphrase!!!

	'secret' => 'uhD27usxm2F61p6gs4unU12mHUiGrbf6'

	'secret' => 'eyZsz5dJDg28oNr385YjG4UQasx7D4q9'
```

name: **origins**;

represents the list of allowed origins;

required: **yes**;

examples:
```
	'origins' => ['http://localhost']

	'origins' => [
		'http://first.example.com',
		'http://second.example.com'
	]
```

### 4. Authorization

The authorization procedure is based on short-lifetime tokens which with their
timestamp signed by hashing with secret pre-shared passphrase. The lifetime
interval defined by the WS_TOKEN_LIFETIME constant (default: 86400). That
mechanism requieres the following list of cookies in the corresponding request
header:

name: **token**;
description: access token value;
length: const AUTH_TOKEN_LEN (default: 32);
example:
```
token=32febad1f0cf83f38aceb31ad63866c4
```
name: **time**;
description: creation time of the token (produced by *time()* php function);
length: *variable*;
example:
```
time=1531124337
```
name: **digest**;
description: hash digest calculated on the main web server as
```
md5("{$this->secret}{$cookies['token']}{$cookies['time']}")
```
length: 32;
example:
```
digest=2556ba17173ee7f4947681e721afc1b3
```
If you have the high security requirements, you can replace *md5()*
or write your own authorization protocol.
Also you can replace all `'token'` ocurrences in the core class file with
authorization cookie name used in your system (e.g. `'PHPSESSID'`).

### 5. Customization

For customization the server behavior you can override following functions:
```
protected function onStart() { }
protected function onHandshake($e_buffer, $descriptor) { }
protected function onTextMessage($e_buffer, $descriptor, &$message) { }
protected function onBinaryMessage($e_buffer, $descriptor, &$message) { }
```
Also you can employ two useful internal methods:
```
protected function sendDirect($message, $destination, $binary = false);
protected function sendBroadcast($message, $binary = false);
```
### 6. Control protocol

The control server unit communications is carried out by the custom protocol
described frames. These frames has a simple structure:
```
$frame = "{$payload_len}{$command}{$payload}";
```
`$payload_len` here is a result of `strlen($payload)`.
`$command` part represents a single letter describing the command.
`$payload` in its turn consist of two optional parts:
```
$payload = "{$token}{$message}";
```
All available commands are described in [COMMANDS.md](./COMMANDS.md).

### 7. Planned features

* Take out the authorization in the separate function.
* Rewrite the origin check.
* Add correct http/ws response on buffer overflow before the connection closing.
* Add "usage" and "hints/troubleshooting" in README.
* Add status info command in the control protocol.
* Add reflection for echo status info with `status` CLI argument.
* Write the ws**s**on (websocket server core with SSL context).
* Correct connections close on SIGTERM.

### 8. License

Code is under the [BSD 2-clause "Simplified" License](./LICENSE.txt).

### 9. Donations

BTC: 13PyAroLFMaqWxVTHdxBkUJf5wksVadiAa

ETH: 0x8c834ef633c29afdaaa12fe34b6afe3cd9ea8a20
