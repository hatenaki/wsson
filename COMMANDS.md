command: **b**;

description: broadcast no-framed short text;

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: **no**;

example:
```
$frame = '15bHello, World!!!';
```

command: **B**;

description: broadcast no-framed short binary;

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: **no**;

example: 
```
$frame = '10B'.random_bytes(10);
```

command: **c**;

description: confirmed addressed no-framed short text;

derection: to ws(s)on;

token: **yes**;

message: **optional**;

acknowledgment: command **A** on success; command **N** on fail;

example: 
```
// '38' ~ 32 (token length) + 6 (strlen('Direct'))
// 'c' ~ command
// '32febad1f0cf83f38aceb31ad63866c4' ~ token
// 'Direct' ~ message
$frame = '38c32febad1f0cf83f38aceb31ad63866c4Direct';
```

command: **C**;

description: confirmed addressed no-framed short binary;

derection: to ws(s)on;

token: **yes**;

message: **optional**;

acknowledgment: command **A** on success; command **N** on fail;

example: 
```
// '34' ~ 32 (token length) + 2 (strlen("\x00\xFF"))
// 'C' ~ command
// '32febad1f0cf83f38aceb31ad63866c4' ~ token
// "\x00\xFF" ~ message
$frame = "34C32febad1f0cf83f38aceb31ad63866c4\x00\xFF";
```

command: **d**;

description: unconfirmed addressed no-framed short text;

derection: to ws(s)on;

token: **yes**;

message: **optional**;

acknowledgment: **no**;

example: 
```
// '44' ~ 32 (token length) + 12 (strlen('New message!'))
// 'd' ~ command
// '32febad1f0cf83f38aceb31ad63866c4' ~ token
// 'New message!' ~ message
$frame = '44d32febad1f0cf83f38aceb31ad63866c4New message!';
```

command: **D**;

description: unconfirmed addressed no-framed short binary;

derection: to ws(s)on;

token: **yes**;

message: **optional**;

acknowledgment: **no**;

example: 
```
// '33' ~ 32 (token length) + 1 (strlen("\xFF"))
// 'D' ~ command
// '32febad1f0cf83f38aceb31ad63866c4' ~ token
// "\xFF" ~ message
$frame = "33D32febad1f0cf83f38aceb31ad63866c4\xFF";
```

command: **l**; `// ~ strtolower('L')`

description: lock client for fragmented send;

derection: to ws(s)on;

token: **yes**;

message: **no**;

acknowledgment: command **A** on success; command **N** on fail;

example: 
```
$frame = '32l32febad1f0cf83f38aceb31ad63866c4';
```

command: **f**;

description: addressed text frame to locked client (the first, not FIN);

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: command **a** on success; command **n** on fail;

example: 
```
$frame = '5fHello';
```

command: **F**;

description: addressed binary frame to locked client (the first, not FIN)

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: command **a** on success; command **n** on fail;

example: 
```
$frame = '1F'.chr(255);
```

command: **i**;

description: addressed text frame to locked client (intermediate, not FIN);

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: command **a** on success; command **n** on fail;

example: 
```
$frame = '2i, ';
```

command: **I**; `// ~ strtoupper('i')`

description: addressed binary frame to locked client (intermediate, not FIN);

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: command **a** on success; command **n** on fail;

example: 
```
$frame = '10I'.str_repeat("\0", 10);
```

command: **s**;

description: addressed text frame to locked client (the last, FIN);

additional: unlock client on success;

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: command **a** on success; command **n** on fail;

example: 
```
$frame = '6sWorld!';
```

command: **S**;

description: addressed binary frame to locked client (the last, FIN);

additional: unlock client on success;

derection: to ws(s)on;

token: **no**;

message: **optional**;

acknowledgment: command **a** on success; command **n** on fail;

example: 
```
$frame = '5S'.random_bytes(5);
```

command: **m**;

description: request the freest server socket domain address;

derection: to ws(s)on;

token: **no**;

message: **no**;

response: command **M**;

example: 
```
$frame = '0m';
```

command: **M**;

description: freest server socket domain address;

derection: from ws(s)on;

token: **no**;

message: **yes**;

example: 
```
$frame = '15Mws1.example.com';
```

command: **p**;

description: ping;

derection: both;

token: **no**;

message: **no**;

acknowledgment: command **P**;

example: 
```
$frame = '0p';
```

command: **P**;

description: pong;

derection: both;

token: **no**;

message: **no**;

acknowledgment: **no**;

example: 
```
$frame = '0P';
```

command: **q**, **Q**;

description: quit;

derection: to ws(s)on;

token: **no**;

message: **no**;

acknowledgment: **no**;

example: 
```
$frame = '0q';
```

command: **E**;

description: protocol error or buffer overflow;

derection: from ws(s)on;

token: **no**;

message: **no**;

example: 
```
$frame = '0E';
```

command: **A**;

description: ACK;

derection: from ws(s)on;

token: **yes**;

message: **no**;

example: 
```
$frame = "32A{$token}";
```

command: **N**;

description: NACK;

derection: from ws(s)on;

token: **yes**;

message: **no**;

example: 
```
$frame = "32N{$token}";
```

command: **a**;

description: ack;

derection: from ws(s)on;

token: **no**;

message: **no**;

example: 
```
$frame = '0a';
```

command: **n**;

description: nack;

derection: from ws(s)on;

token: **no**;

message: **no**;

example: 
```
$frame = '0n';
```
