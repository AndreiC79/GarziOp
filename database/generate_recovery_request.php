<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$username=strtolower(trim((string)($argv[1]??'')));
if(!preg_match('/^[a-z0-9._-]{3,100}$/',$username)){fwrite(STDERR,"Utilizare: php database/generate_recovery_request.php username\n");exit(1);}
$token='recovery_'.bin2hex(random_bytes(32));
$request=['username'=>$username,'token_hash'=>hash('sha256',$token),'expires_at'=>(new DateTimeImmutable('+60 minutes'))->format(DATE_ATOM),'created_at'=>(new DateTimeImmutable())->format(DATE_ATOM)];
$target=dirname(__DIR__).'/storage/recovery-request.json';
if(file_put_contents($target,json_encode($request,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false){fwrite(STDERR,"Fisierul de recuperare nu a putut fi creat.\n");exit(1);}
fwrite(STDOUT,"Incarca prin FTP fisierul storage/recovery-request.json pe server.\nToken unic (valabil 60 minute):\n{$token}\n");
