<?php
/**
 * wson websocket server core
 * 
 * @package     wson
 * @author      Maxim Luzgin <luzginma@gmail.com>
 * @copyright   2018 Maxim Luzgin
 * @license     BSD 2-Clause
 */
class Wson
{
    protected const CLOSING = 1;
    protected const CLOSED = 2;
    protected const HANDSHAKE = 4;
    protected const READY = 8;
    protected const ALIVE = 16;
    
    protected const MAX_BUFFER_LEN = 16384;
    protected const MAX_LEN_NO_HSK = 1024;
    protected const MAX_C_BUFFER_LEN = 65536;
    protected const WS_TOKEN_LIFETIME = 86400;
    protected const ALIVE_INTERVAL = 15;
    protected const AUTH_TOKEN_LEN = 32;
    
    protected $error = '';
    protected $action;
    protected $server_sockets;
    protected $control_socket;
    protected $pid_file;
    protected $domains;
    protected $secret;
    protected $origins;
    protected $server_els = [];
    protected $control_el;
    protected $servers_loads = [];
    
    protected $e_buffers = [];
    protected $r_buffers = [];
    protected $relations = [];
    protected $conn_states = [];
    protected $ctrl_states = [];
    protected $locked_dscs = [];
    
    protected $broadcasts = [];
    protected $current_bcast = 0;
    protected $broadcast_ptr = 0;
    protected $min_conn_descriptor = 0;
    protected $max_conn_descriptor = 0;
    
    protected function onStart()
    {
        
    }
    
    protected function onHandshake($e_buffer, $descriptor)
    {
        
    }
    
    protected function onTextMessage($e_buffer, $descriptor, &$message)
    {
        
    }
    
    protected function onBinaryMessage($e_buffer, $descriptor, &$message)
    {
        
    }
    
    /**
     * constructor
     * 
     * See config description in 'Configuration' section (README.md)
     * You should set your own secret,
     * otherwise all connections will be refused
     * 
     * @param array $config initial parameters
     * @param string $action 'start', 'stop' or 'restart'
     */
    public function __construct($config, $action = 'start')
    {
        if (!extension_loaded('event')) {
            $this->error = 'The Event PECL extension required';
            return;
        }
        
        $this->action = $action;
        $this->server_sockets =
            empty($config['servers']) ? [''] : $config['servers'];
        $this->control_socket =
            empty($config['control']) ? '' : $config['control'];
        $this->pid_file =
            empty($config['pid']) ? '' : $config['pid'];
        $this->domains =
            empty($config['domains']) ? [] : $config['domains'];
        $this->secret =
            empty($config['secret']) ? random_bytes(16) : $config['secret'];
        $this->origins =
            empty($config['origins']) ? [] : $config['origins'];
        
        foreach (array_merge($this->server_sockets,
            [$this->control_socket, $this->pid_file]) as $path
        ) {
            if ((substr($path, 0, 7) != 'unix://') &&
                (substr($path, -4) != '.pid')
            ) {
                continue;
            }
            $path = str_replace('unix://', '', $path);
            $path = substr($path, 0, strrpos($path, '/'));
            if (!file_exists($path) || !is_dir($path)) {
                $this->error = "Directory {$path} does not exist";
                return;
            }
        }
        
        $pid = file_exists($this->pid_file) ?
            (int)file_get_contents($this->pid_file) : 0;
        $alive = (posix_getpgid($pid) !== false);
        if (($action != 'start') && $pid) {
            if ($alive && !posix_kill($pid, SIGTERM)) {
                $this->error = 'Unable to send kill signal';
                return;
            }
            if ($alive) {
                echo date('Y-m-d H:i:s ');
                echo 'Waiting for process termination (5s max)';
            }
            for ($i = 0; ($i < 20) && $alive; $i++) {
                $alive = (posix_getpgid($pid) !== false);
                if ($alive) {
                    usleep(250000);
                }
                if ($i % 4 == 3) {
                    echo '.';
                }
            }
            if ($i) echo "\n";
            if ($alive) {
                $this->error = 'Unable to stop server';
                return;
            } else {
                if (!unlink($this->pid_file)) {
                    $this->error = 'Unable to unlink pid file';
                    return;
                } else {
                    $pid = 0;
                    echo date('Y-m-d H:i:s ')."Stopped successfully\n";
                }
            }
        } elseif ($action == 'stop') {
            echo date('Y-m-d H:i:s ')."Server is not running\n";
        }
        
        if ($pid) {
            if (posix_getpgid($pid) !== false) {
                $this->error = 'Server already started';
                return;
            }
            if (!unlink($this->pid_file)) {
                $this->error = 'Unable to unlink pid file';
                return;
            }
        }
        
        foreach (array_merge($this->server_sockets,
            [$this->control_socket]) as $chk_socket
        ) {
            if (substr($chk_socket, 0, 7) == 'unix://') {
                $chk_socket = substr($chk_socket, 7);
                if (file_exists($chk_socket)) {
                    if (!unlink($chk_socket)) {
                        $this->error = "Unable to unlink socket {$chk_socket}";
                        return;
                    }
                }
            }
        }
    }
    
    /**
     * init servers and start eventbase infinite loop
     * 
     * There is infinite loop here, but it can be broken by
     * SIGTERM sended to process. Also it may not starts
     * because of error in constructor.
     * 
     * @return string error (empty, if all is correct)
     */
    public function start()
    {
        if ($this->error) {
            return $this->error;
        }
        
        if ($this->action == 'stop') {
            return '';
        }
        
        if (!file_put_contents($this->pid_file, posix_getpid())) {
            return "Unable to write pid to {$this->pid_file}";
        }
        
        $servers = [];
        foreach ($this->server_sockets as $serv_sock) {
            $server = @stream_socket_server($serv_sock, $errno, $errstr);
            if (!$server) {
                return "Unable to create socket server {$serv_sock}\n"
                    ."Error {$errno}".(empty($errstr) ? '' : ": {$errstr}");
            }
            if (!stream_set_blocking($server, false)) {
                return "Unable to set blocking mode for {$serv_sock}";
            }
            $servers[] = $server;
        }
        
        $control = @stream_socket_server($this->control_socket, $errno, $errstr);
        if (!$control) {
            return "Unable to create socket server {$this->control_socket}\n"
                ."Error {$errno}".(empty($errstr) ? '' : ": {$errstr}");
        }
        if (!stream_set_blocking($control, false)) {
            return "Unable to set blocking mode for {$this->control_socket}";
        }
        
        $base = new \EventBase();
        
        foreach ($servers as $key => $server) {
            $server_el = new \EventListener($base,
                [$this, 'serverAccept'], $base,
                \EventListener::OPT_CLOSE_ON_FREE |
                \EventListener::OPT_REUSEABLE,
                -1, $server
            );
            $this->server_els[$key] = $server_el;
            $this->servers_loads[$server_el->fd] = 0;
        }
        
        $this->control_el = new \EventListener($base,
            [$this, 'controlAccept'], $base,
            \EventListener::OPT_CLOSE_ON_FREE | \EventListener::OPT_REUSEABLE,
            -1, $control
        );
        
        $this->onStart();
        if ($this->error) {
            return $this->error;
        }
        echo date('Y-m-d H:i:s ')."Started successfully\n";
        
        $base->dispatch(); // infinite loop
        
        return $this->error;
    }
    
    /**
     * main server connection accept (new connection) callback
     * 
     * @param EventListener $listener EventListener associated object
     * @param integer $descriptor new connection associated file descriptor
     * @param array $address IP (index 0) and port (index 1)
     * @param EventBase $base EventBase associated object
     */
    public function serverAccept($listener, $descriptor, $address, $base)
    {
        $this->servers_loads[$listener->fd]++;
        if (!$this->min_conn_descriptor) {
            $this->min_conn_descriptor = $descriptor;
        }
        if ($this->max_conn_descriptor < $descriptor) {
            $this->max_conn_descriptor = $descriptor;
        }
        $e_buffer = new \EventBufferEvent($base, $descriptor,
            \EventBufferEvent::OPT_CLOSE_ON_FREE,
            [$this, 'serverRead'], [$this, 'serverWrite'],
            [$this, 'serverEvent'], $listener->fd
        );
        $e_buffer->setTimeouts(1.0, 1.0);
        $e_buffer->enable(\Event::READ | \Event::WRITE | \Event::PERSIST);
        $this->e_buffers[$descriptor] = $e_buffer;
        $this->r_buffers[$descriptor] = '';
        $this->conn_states[$descriptor] = self::ALIVE;
    }
    
    /**
     * main server connection error/close/timeout event callback
     * 
     * @param EventBufferEvent $e_buffer EventBufferEvent associated object
     * @param integer $mask logical disjunction of the Event class constants
     * @param integer $listener_fd associated EventListener file descriptor
     */
    public function serverEvent($e_buffer, $mask, $listener_fd)
    {
        $descriptor = $e_buffer->fd;
        if ($mask & \EventBufferEvent::TIMEOUT) {
            $state =& $this->conn_states[$descriptor];
            if ((~$state) & (self::HANDSHAKE | self::ALIVE | self::READY)) {
                $mask |= \EventBufferEvent::ERROR;
            } else {
                $state &= ~self::ALIVE;
                $e_buffer->write("\x89\x00");
                $e_buffer->enable(\Event::READ);
            }
        }
        
        if ($mask & (\EventBufferEvent::ERROR | \EventBufferEvent::EOF)) {
            $this->servers_loads[$listener_fd]--;
            $e_buffer->disable(\Event::READ | \Event::WRITE);
            unset($this->e_buffers[$descriptor]);
            unset($this->r_buffers[$descriptor]);
            unset($this->conn_states[$descriptor]);
            $relations =& $this->relations;
            if (($relation = array_search($descriptor, $relations)) !== false) {
                unset($relations[$relation]);
            }
            if ($descriptor == $this->max_conn_descriptor) {
                $max_fd =& $this->max_conn_descriptor;
                $min_fd =& $this->min_conn_descriptor;
                $e_buffers =& $this->e_buffers;
                while (!isset($e_buffers[$max_fd]) && ($max_fd > $min_fd))
                {
                    $max_fd--;
                }
            }
        }
    }
    
    /**
     * main server connection 'write end' event callback
     * 
     * @param EventBufferEvent $e_buffer EventBufferEvent associated object
     * @param integer $listener_fd associated EventListener file descriptor
     */
    public function serverWrite($e_buffer, $listener_fd)
    {
        $descriptor = $e_buffer->fd;
        if ($this->conn_states[$descriptor] & self::CLOSED) {
            $this->serverEvent(
                $e_buffer, \EventBufferEvent::ERROR, $listener_fd
            );
        }
        
        if (!empty($this->broadcasts) &&
            ($this->broadcast_ptr == $descriptor)
        ) {
            $conn_states =& $this->conn_states;
            $bcast_ptr =& $this->broadcast_ptr;
            do
            {
                do
                {
                    $bcast_ptr++;
                }
                while (($bcast_ptr <= $this->max_conn_descriptor) &&
                    (!isset($conn_states[$bcast_ptr]) ||
                        !($conn_states[$bcast_ptr] & self::READY)
                    )
                );
                if ($bcast_ptr > $this->max_conn_descriptor) {
                    unset($this->broadcasts[$this->current_bcast++]);
                    $bcast_ptr = $this->min_conn_descriptor - 1;
                } else {
                    break;
                }
            }
            while (!empty($this->broadcasts));
            if (!empty($this->broadcasts)) {
                $message =& $this->broadcasts[$this->current_bcast];
                $this->e_buffers[$bcast_ptr]->write($message);
            }
        }
    }
    
    /**
     * main server connection 'ready-to-read' event callback
     * 
     * @param EventBufferEvent $e_buffer EventBufferEvent associated object
     * @param integer $listener_fd associated EventListener file descriptor
     */
    public function serverRead($e_buffer, $listener_fd)
    {
        $descriptor = $e_buffer->fd;
        $state =& $this->conn_states[$descriptor];
        if (($state & self::CLOSING) &&
            (time() - $state > self::ALIVE_INTERVAL)
        ) {
            $this->serverEvent(
                $e_buffer, \EventBufferEvent::ERROR, $listener_fd
            );
            return;
        }
        $buffer_limit = ($state & self::HANDSHAKE) ?
            self::MAX_BUFFER_LEN : self::MAX_LEN_NO_HSK;
        
        $r_buffer =& $this->r_buffers[$descriptor];
        $len = strlen($r_buffer);
        do
        {
            $data = $e_buffer->read($buffer_limit - $len);
            $r_buffer .= $data;
            $extracted = strlen($data);
            $len += $extracted;
        }
        while ($extracted && ($len < $buffer_limit));
        
        if ($len == $buffer_limit) {
            $this->serverEvent(
                $e_buffer, \EventBufferEvent::ERROR, $listener_fd
            );
            return;
        }
        
        // start handshake
        
        if (!($state & self::HANDSHAKE)) {
            if (substr($r_buffer, -4) !== "\r\n\r\n") return;
            $headers = explode("\r\n", $r_buffer, 2);
            $r_buffer = '';
            $start_string = trim($headers[0]);
            if (empty($headers[1]) ||
                !preg_match('/^GET .+ HTTP\/[1-9]\.[0-9]$/', $start_string)
            ) {
                $state |= self::CLOSED;
                $e_buffer->write(
                    "HTTP/1.1 400 Bad Request\r\n"
                    ."Connection: close\r\n\r\n"
                );
                return;
            }
            $headers = explode("\r\n", $headers[1]);
            $h_len = count($headers);
            $headers_normalized = [];
            $headers_normalized[0] = trim($headers[0]);
            for ($i = 1, $p = 0; $i < $h_len; $i++) {
                if ($headers[$i] === '') continue;
                if ($headers[$i][0] == "\t" || $headers[$i][0] == ' ') {
                    $headers_normalized[$p] .= trim($headers[$i]);
                    continue;
                }
                $headers_normalized[++$p] = $headers[$i];
            }
            
            $headers = [];
            $cookies = [];
            
            foreach ($headers_normalized as $header) {
                $parts = explode(':', $header, 2);
                if (!isset($parts[1])) {
                    $state |= self::CLOSED;
                    $e_buffer->write(
                        "HTTP/1.1 400 Bad Request\r\n"
                        ."Connection: close\r\n\r\n"
                    );
                    return;
                }
                $headername = strtolower($parts[0]);
                if (in_array($headername, [
                    'host', 'upgrade', 'connection', 'origin',
                    'sec-websocket-key', 'sec-websocket-version', 'cookie'
                ])) {
                    if ($headername != 'cookie') {
                        if ($headername != 'connection') {
                            $headers[$headername] = trim($parts[1]);
                        } else {
                            if (isset($headers['connection'])) {
                                $headers['connection'] .= ', '.trim($parts[1]);
                            } else {
                                $headers['connection'] = trim($parts[1]);
                            }
                        }
                        continue;
                    }
                    $pairs = explode('; ', trim($parts[1]));
                    foreach ($pairs as $cookie_pair) {
                        $pair = explode('=', trim($cookie_pair), 2);
                        if (!empty($pair[1]) && in_array($pair[0],
                            ['token', 'user', 'time', 'digest']
                        )) {
                            $cookies[$pair[0]] = $pair[1];
                        }
                    }
                }
            }
            
            if (!$this->authCheck($cookies)) {
                $state |= self::CLOSED;
                $e_buffer->write(
                    "HTTP/1.1 403 Forbidden\r\n"
                    ."Connection: close\r\n\r\n"
                );
                return;
            }
            
            $relations =& $this->relations;
            if (array_key_exists($cookies['token'], $relations)) {
                $state |= self::CLOSED;
                $e_buffer->write(
                    "HTTP/1.1 429 Too Many Requests\r\n"
                    ."Retry-After: 15\r\n"
                    ."Connection: close\r\n\r\n"
                );
                return;
            }
            
            if (empty($headers['host']) ||
                !isset($headers['upgrade']) ||
                ($headers['upgrade'] !== 'websocket') ||
                !isset($headers['connection']) ||
                !in_array('Upgrade', explode(', ', $headers['connection'])) ||
                !isset($headers['origin']) ||
                !in_array($headers['origin'], $this->origins) ||
                empty($headers['sec-websocket-key']) ||
                !isset($headers['sec-websocket-version']) ||
                ($headers['sec-websocket-version'] !== '13')
            ) {
                $state |= self::CLOSED;
                $e_buffer->write(
                    "HTTP/1.1 400 Bad Request\r\n"
                    ."Connection: close\r\n\r\n"
                );
                return;
            }
            
            $accept = base64_encode(
                pack('H*', sha1($headers['sec-websocket-key']
                    .'258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
            );
            $state |= self::HANDSHAKE | self::READY;
            $e_buffer->setTimeouts(self::ALIVE_INTERVAL, 1.0);
            $e_buffer->write(
                "HTTP/1.1 101 Switching Protocols\r\n"
                ."Upgrade: websocket\r\n"
                ."Connection: Upgrade\r\n"
                ."Sec-WebSocket-Accept: {$accept}\r\n\r\n"
            );
            $state |= ((int)$cookies['time'] + self::WS_TOKEN_LIFETIME) & ~0x1F;
            $relations[$cookies['token']] = $descriptor;
            $this->onHandshake($e_buffer, $descriptor);
            
            return;
        }
        
        // end handshake
        // start lifetime check
        
        if (($state < time()) &&
            !($state & self::CLOSING)
        ) {
            $state |= self::CLOSING;
            $e_buffer->write("\x88\x09\x03\xE8expired");
        }
        
        // end lifetime check
        // start decode
        
        $messages = [];
        $opcodes = [];
        
        $msg_pointer = 0;
        $offset = 0;
        $msg_len = 0;
        
        do
        {
            $frame_len = 2;
            if ($frame_len > $len - $offset) break;
            $first_of_frame = ord($r_buffer[$offset]);
            $second_of_frame = ord($r_buffer[$offset + 1]);
            $payload_len = $second_of_frame & 0x7F;
            if ($payload_len > 125) {
                if ($payload_len == 127) {
                    $frame_len += 8;
                    if ($frame_len > $len - $offset) break;
                    $payload_len =
                        unpack('J', substr($r_buffer, $offset + 2, 8))[1];
                } else {
                    $frame_len += 2;
                    if ($frame_len > $len - $offset) break;
                    $payload_len =
                        unpack('n', substr($r_buffer, $offset + 2, 2))[1];
                }
            }
            if ($second_of_frame & 0x80) {
                $frame_len += 4;
                if ($frame_len > $len - $offset) break;
            }
            $frame_len += $payload_len;
            if ($frame_len > $len - $offset) break;
            $offset += $frame_len;
            
            if (!($first_of_frame & 0x80)) continue;
            
            $pointer = $msg_pointer;
            $messages[$msg_len] = '';
            $opcodes[$msg_len] = ord($r_buffer[$pointer]) & 0x0F;
            
            do
            {
                $pointer++;
                $second_of_frame = ord($r_buffer[$pointer++]);
                $payload_len = $second_of_frame & 0x7F;
                if (!$payload_len) {
                    if ($second_of_frame & 0x80) $pointer += 4;
                    continue;
                }
                if ($payload_len > 125) {
                    if ($payload_len == 127) {
                        $payload_len =
                            unpack('J', substr($r_buffer, $pointer, 8))[1];
                        $pointer += 8;
                    } else {
                        $payload_len =
                            unpack('n', substr($r_buffer, $pointer, 2))[1];
                        $pointer += 2;
                    }
                }
                $mask = 0;
                if ($second_of_frame & 0x80) {
                    $mask = unpack('N',
                        substr($r_buffer, $pointer, 4)
                    )[1];
                    $pointer += 4;
                }
                $appendix_len = (4 - ($payload_len % 4)) % 4;
                $appendix = str_repeat("\0", $appendix_len);
                $data = unpack('N*',
                    substr($r_buffer, $pointer, $payload_len).$appendix
                );
                $data_len = ($payload_len + $appendix_len) / 4;
                for ($i = 1; $i <= $data_len; $i++) {
                    $data[$i] ^= $mask;
                }
                $messages[$msg_len] .=
                    substr(pack('N*', ...$data), 0, $payload_len);
                $pointer += $payload_len;
            }
            while ($pointer != $offset);
            
            $msg_pointer = $offset;
            $msg_len++;
        }
        while ($offset < $len);
        
        if ($msg_pointer) {
            $r_buffer = substr($r_buffer, $msg_pointer);
        }
        
        // end decode
        // start protocol dependencies
        
        for ($i = 0; $i < $msg_len; $i++) {
            switch ($opcodes[$i])
            {
                case 1:
                    $this->onTextMessage(
                        $e_buffer, $descriptor, $messages[$i]
                    );
                    break;
                case 2:
                    $this->onBinaryMessage(
                        $e_buffer, $descriptor, $messages[$i]
                    );
                    break;
                case 8:
                    if ($state & self::CLOSING) {
                        $this->serverEvent(
                            $e_buffer, \EventBufferEvent::ERROR, $listener_fd
                        );
                        return;
                    }
                    $state |= self::CLOSED;
                    if (strlen($messages[$i]) > 1) {
                        $close_code = substr($messages[$i], 0, 2);
                        $e_buffer->write("\x88\x02{$close_code}");
                    } else {
                        $e_buffer->write("\x88\x00");
                    }
                    break;
                case 9:
                    $ping_len = strlen($messages[$i]);
                    if ($ping_len > 125) {
                        if ($ping_len > 65535) {
                            $e_buffer->write(
                                "\x8A\x7F".pack('J', $ping_len).$messages[$i]
                            );
                        } else {
                            $e_buffer->write(
                                "\x8A\x7E".pack('n', $ping_len).$messages[$i]
                            );
                        }
                    } else {
                        $e_buffer->write("\x8A".chr($ping_len).$messages[$i]);
                    }
                    break;
                case 10:
                    $state |= self::ALIVE;
                    break;
            }
        }
        
        // end protocol dependencies
    }
    
    /**
     * authentication with cookies
     * 
     * @param array $cookies
     * @return boolean permission
     */
    protected function authCheck(&$cookies)
    {
        return !(empty($cookies['token']) ||
            empty($cookies['digest']) ||
            empty($cookies['user']) ||
            empty($cookies['time']) ||
            (time() - (int)$cookies['time'] > self::WS_TOKEN_LIFETIME) ||
            ($cookies['digest'] != md5("{$this->secret}{$cookies['user']}"
            ."{$cookies['token']}{$cookies['time']}"))
        );
    }
    
    /**
     * control server connection accept (new connection) callback
     * 
     * @param EventListener $listener EventListener associated object
     * @param integer $descriptor associated file descriptor
     * @param array $address array with IP (index 0) and port (index 1)
     * @param EventBase $base EventBase object associated with event
     */
    public function controlAccept($listener, $descriptor, $address, $base)
    {
        $e_buffer = new \EventBufferEvent($base, $descriptor,
            \EventBufferEvent::OPT_CLOSE_ON_FREE,
            [$this, 'controlRead'], [$this, 'controlWrite'],
            [$this, 'controlEvent'], $descriptor
        );
        $e_buffer->setTimeouts(1.0, 1.0);
        $e_buffer->enable(\Event::READ | \Event::WRITE | \Event::PERSIST);
        $this->e_buffers[$descriptor] = $e_buffer;
        $this->r_buffers[$descriptor] = '';
        $this->ctrl_states[$descriptor] = self::ALIVE;
    }
    
    /**
     * control server connection error/close/timeout event callback
     * 
     * @param EventBufferEvent $e_buffer EventBufferEvent associated object
     * @param integer $mask logical disjunction of the Event class constants
     * @param integer $descriptor associated file descriptor
     */
    public function controlEvent($e_buffer, $mask, $descriptor)
    {
        $state =& $this->ctrl_states[$descriptor];
        if ($mask & \EventBufferEvent::TIMEOUT) {
            if ($state & self::ALIVE) {
                $state &= ~self::ALIVE;
                $e_buffer->write('0p'); // send ping
                $e_buffer->enable(\Event::READ);
            } else {
                $mask |= \EventBufferEvent::ERROR;
            }
        }
        
        if ($mask & (\EventBufferEvent::ERROR | \EventBufferEvent::EOF)) {
            $e_buffer->disable(\Event::READ | \Event::WRITE);
            unset($this->e_buffers[$descriptor]);
            unset($this->r_buffers[$descriptor]);
            unset($this->ctrl_states[$descriptor]);
            $locked_dscs =& $this->locked_dscs;
            if (isset($locked_dscs[$descriptor])) {
                $relations =& $this->relations;
                if (isset($relations[$locked_dscs[$descriptor]])) {
                    $related_ws = $relations[$locked_dscs[$descriptor]];
                    // now close related locked websocket
                    $this->conn_states[$related_ws] |= self::CLOSING;
                    $this->e_buffers[$related_ws]->write(
                        "\x88\x12\x03\xF3framesource lost"
                    );
                }
                unset($locked_dscs[$descriptor]);
            }
        }
    }
    
    /**
     * control server connection 'write ends' event callback
     * 
     * @param EventBufferEvent $e_buffer EventBufferEvent associated object
     * @param integer $descriptor associated file descriptor
     */
    public function controlWrite($e_buffer, $descriptor)
    {
        if (!($this->ctrl_states[$descriptor] & self::CLOSED)) return;
        $this->controlEvent($e_buffer, \EventBufferEvent::ERROR, $descriptor);
    }
    
    /**
     * control server connection 'ready-to-read' event callback
     * 
     * @param EventBufferEvent $e_buffer EventBufferEvent associated object
     * @param integer $descriptor associated file descriptor
     */
    public function controlRead($e_buffer, $descriptor)
    {
        $state =& $this->ctrl_states[$descriptor];
        $r_buffer =& $this->r_buffers[$descriptor];
        
        $len = strlen($r_buffer);
        do
        {
            $data = $e_buffer->read(self::MAX_C_BUFFER_LEN - $len);
            $r_buffer .= $data;
            $extracted = strlen($data);
            $len += $extracted;
        }
        while ($extracted && ($len < self::MAX_C_BUFFER_LEN));
        
        if ($len == self::MAX_C_BUFFER_LEN) {
            $e_buffer->write('0E'); // buffer overflow
            $state |= self::CLOSED;
            return;
        }
        
        $offset = 0;
        do
        {
            $pack_len = (string)((int)substr($r_buffer, $offset));
            $head_len = strlen($pack_len);
            $offset = strpos($r_buffer, $pack_len, $offset);
            if ($offset === false) {
                if (!$pack_len) break;
                $e_buffer->write('0E'); // protocol error
                $state |= self::CLOSED;
                return;
            }
            $pack_len = (int)$pack_len;
            if ($head_len + $pack_len + $offset + 1 > $len) break;
            $offset += $head_len;
            $command = substr($r_buffer, $offset, 1);
            $offset++;
            switch ($command)
            {
                case 'b': // broadcast no-framed short text
                case 'B': // broadcast no-framed short binary
                    $this->sendBroadcast(
                        substr($r_buffer, $offset, $pack_len), $command == 'B'
                    );
                    break;
                case 'c': // confirmed addressed no-framed short text
                case 'C': // confirmed addressed no-framed short binary
                case 'd': // unconfirmed addressed no-framed short text
                case 'D': // unconfirmed addressed no-framed short binary
                    $confirm = ($command == 'c' || $command == 'C');
                    if ($pack_len < self::AUTH_TOKEN_LEN) {
                        $e_buffer->write('0E'); // protocol error
                        $state |= self::CLOSED;
                        return;
                    }
                    $token = substr($r_buffer, $offset, self::AUTH_TOKEN_LEN);
                    $destination =
                        isset($this->relations[$token]) ?
                        $this->relations[$token] : 0;
                    $success = ($destination &&
                        ($this->conn_states[$destination] & self::READY));
                    $offset += self::AUTH_TOKEN_LEN;
                    $pack_len -= self::AUTH_TOKEN_LEN;
                    if (!$pack_len || !$success) {
                        if ($confirm) { // (N)ACK ~ it can be user status check
                            $e_buffer->write(self::AUTH_TOKEN_LEN
                                .($success ? 'A' : 'N').$token
                            );
                        }
                        break;
                    }
                    $success = $this->sendDirect(
                        substr($r_buffer, $offset, $pack_len),
                        $destination,
                        ($command == 'C' || $command == 'D')
                    );
                    if ($confirm) {
                        $e_buffer->write(self::AUTH_TOKEN_LEN
                            .($success ? 'A' : 'N').$token
                        );
                    }
                    break;
                case 'l': // lock client for fragmented send
                    if ($pack_len != self::AUTH_TOKEN_LEN) {
                        $e_buffer->write('0E'); // protocol error
                        $state |= self::CLOSED;
                        return;
                    }
                    $token = substr($r_buffer, $offset, self::AUTH_TOKEN_LEN);
                    $destination =
                        isset($this->relations[$token]) ?
                        $this->relations[$token] : 0;
                    if ($destination && 
                        ($this->conn_states[$destination] & self::READY)
                    ) { // lock it (clear READY flag)
                        $this->conn_states[$destination] &= ~self::READY;
                        $this->locked_dscs[$descriptor] = $token;
                        $e_buffer->write(self::AUTH_TOKEN_LEN."A{$token}");
                    } else { // unable to lock
                        $e_buffer->write(self::AUTH_TOKEN_LEN."N{$token}");
                    }
                    break;
                case 'f': // addressed text frame (the first, not FIN)
                case 'F': // addressed binary frame (the first, not FIN)
                case 'i': // addressed text frame (intermediate, not FIN)
                case 'I': // addressed binary frame (intermediate, not FIN)
                case 's': // addressed text frame (the last, FIN)
                case 'S': // addressed binary frame (the last, FIN)
                    $token = (isset($this->locked_dscs[$descriptor]) ?
                        $this->locked_dscs[$descriptor] : '');
                    $destination =
                        $token && isset($this->relations[$token]) ?
                        $this->relations[$token] : 0;
                    if (!$destination) {
                        unset($this->locked_dscs[$descriptor]);
                        $e_buffer->write('0n'); // NACK
                        break;
                    }
                    switch ($command)
                    {
                        case 'f':
                            $msg = "\x01";
                            break;
                        case 'F':
                            $msg = "\x02";
                            break;
                        case 's':
                        case 'S':
                            $msg = "\x80";
                            break;
                        default:
                            $msg = "\x00";
                            break;
                    }
                    if ($pack_len > 125) {
                        if ($pack_len > 65535) {
                            $msg .= "\x7F".pack('J', $pack_len);
                        } else {
                            $msg .= "\x7E".pack('n', $pack_len);
                        }
                    } else {
                        $msg .= chr($pack_len);
                    }
                    $this->e_buffers[$destination]->write(
                        $msg.substr($r_buffer, $offset, $pack_len)
                    );
                    if ($command == 's' || $command == 'S') {
                        $this->conn_states[$destination] |= self::READY;
                        unset($this->locked_dscs[$descriptor]);
                    }
                    $e_buffer->write('0a'); // ACK
                    break;
                case 'm': // get the freest server socket domain address
                    $f_serv = array_search(
                        min($this->servers_loads), $this->servers_loads
                    );
                    foreach ($this->server_els as $key => $server_el) {
                        if ($server_el->fd == $f_serv) {
                            $domain = isset($this->domains[$key]) ?
                                $this->domains[$key] :
                                $this->server_sockets[$key];
                            $e_buffer->write(strlen($domain)."M{$domain}");
                            break;
                        }
                    }
                    break;
                case 'p': // 'p' (lower) is ping
                    $e_buffer->write('0P');
                    break;
                case 'P': // 'P' (upper) is pong
                    $state |= self::ALIVE;
                    break;
                case 'q': // quit
                case 'Q':
                    on_c_event(
                        $e_buffer, \EventBufferEvent::ERROR, $descriptor
                    );
                    return;
                    break;
            }
            $offset += $pack_len;
        }
        while($offset < $len);
        
        if ($offset) {
            $r_buffer = substr($r_buffer, $offset);
        }
    }
    
    /**
     * init and start broadcast chain
     * 
     * @param string $message
     * @param boolean $binary frame type
     */
    protected function sendBroadcast($message, $binary = false)
    {
        $header = $binary ? "\x82" : "\x81";
        $msg_len = strlen($message);
        if ($msg_len > 125) {
            if ($msg_len > 65535) {
                $header .= "\x7F".pack('J', $msg_len);
            } else {
                $header .= "\x7E".pack('n', $msg_len);
            }
        } else {
            $header .= chr($msg_len);
        }
        $bcasts =& $this->broadcasts;
        $tail = count($bcasts);
        $index = $this->current_bcast + $tail;
        $bcasts[$index] = "{$header}{$message}";
        if ($tail) return;
        
        $conn_states =& $this->conn_states;
        $bcast_ptr =& $this->broadcast_ptr;
        $bcast_ptr = $this->min_conn_descriptor - 1;
        do
        {
            do
            {
                $bcast_ptr++;
            }
            while (($bcast_ptr <= $this->max_conn_descriptor) &&
                (!isset($conn_states[$bcast_ptr]) ||
                    !($conn_states[$bcast_ptr] & self::READY)
                )
            );
            if ($bcast_ptr > $this->max_conn_descriptor) {
                unset($bcasts[$this->current_bcast++]);
                $bcast_ptr = $this->min_conn_descriptor - 1;
            } else {
                break;
            }
        }
        while (!empty($bcasts));
        if (!empty($bcasts)) {
            $this->e_buffers[$bcast_ptr]->write($bcasts[$this->current_bcast]);
        }
    }
    
    /**
     * send direct message to client by associated file descriptor
     * 
     * @param string $message
     * @param integer $destination
     * @param boolean $binary frame type
     * @return boolean success
     */
    protected function sendDirect($message, $destination, $binary = false)
    {
        if (!isset($this->conn_states[$destination]) ||
            !($this->conn_states[$destination] & self::READY)
        ) {
            return false;
        }
        $header = $binary ? "\x82" : "\x81";
        $msg_len = strlen($message);
        if ($msg_len > 125) {
            if ($msg_len > 65535) {
                $header .= "\x7F".pack('J', $msg_len);
            } else {
                $header .= "\x7E".pack('n', $msg_len);
            }
        } else {
            $header .= chr($msg_len);
        }
        $this->e_buffers[$destination]->write("{$header}{$message}");
        return true;
    }
}
