<?php
declare(strict_types=1);
/** Small SMTP client with explicit transport, verified TLS and no shell calls. */
class SMTPMailer {
 private $socket=null;
 public function __construct(private string $host,private int $port,private bool $tls,private string $user,private string $pass){}
 public function send(string $from,string $to,string $subject,string $body,?string $html=null):bool{
  $transport=alphano_config('mail_transport');if($transport==='disabled')return false;
  foreach([$from,$to,$subject] as $v)if(strpbrk($v,"\r\n")!==false)throw new InvalidArgumentException('Intestazione non valida.');
  if(!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($to,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Indirizzo email non valido.');
  require_once __DIR__.'/email-theme.inc.php';$html??=alphano_email_render($subject,$body);$boundary='alphano_'.bin2hex(random_bytes(18));
  $headers=['From: '.$from,'To: '.$to,'Subject: =?UTF-8?B?'.base64_encode($subject).'?=','Date: '.date(DATE_RFC2822),'Message-ID: <'.bin2hex(random_bytes(16)).'@'.(parse_url(alphano_config('base_url'),PHP_URL_HOST)?:'localhost').'>','MIME-Version: 1.0','Content-Type: multipart/alternative; boundary="'.$boundary.'"'];
  $parts='';foreach([['text/plain',$body],['text/html',$html]] as [$mime,$text])$parts.="--$boundary\r\nContent-Type: $mime; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text),76,"\r\n");
  $message=implode("\r\n",$headers)."\r\n\r\n".$parts."--$boundary--\r\n";
  if($transport==='log'){$dir=(string)alphano_config('mail_dir');if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.eml';if(file_put_contents($path,$message,LOCK_EX)===false)throw new RuntimeException('Salvataggio email non riuscito.');chmod($path,0600);return true;}
  if($transport==='mail'){
   if(!function_exists('mail'))throw new RuntimeException('La funzione mail() non è disponibile su questo hosting.');
   $extra=array_values(array_filter($headers,static fn($h)=>!str_starts_with($h,'To: ')&&!str_starts_with($h,'Subject: ')));
   return mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$parts."--$boundary--\r\n",implode("\r\n",$extra));
  }
  if($transport!=='smtp'||$this->host==='')throw new RuntimeException('SMTP non configurato.');
  $security=(string)alphano_config('smtp_security');$context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$this->host,'allow_self_signed'=>false]]);
  $scheme=$security==='smtps'?'tls':'tcp';$socket=@stream_socket_client($scheme.'://'.$this->host.':'.$this->port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);if(!$socket)throw new RuntimeException('Connessione SMTP non riuscita.');$this->socket=$socket;stream_set_timeout($socket,15);
  try{
   $this->expect(220);$this->command('EHLO localhost',250);
   if($security==='starttls'){$this->command('STARTTLS',220);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('TLS SMTP non riuscito.');$this->command('EHLO localhost',250);}
   if($this->user!==''){$this->command('AUTH LOGIN',334);$this->command(base64_encode($this->user),334);$this->command(base64_encode($this->pass),235);}
   $this->command('MAIL FROM:<'.$from.'>',250);$this->command('RCPT TO:<'.$to.'>',[250,251]);$this->command('DATA',354);$this->write(preg_replace('/(?m)^\./','..',$message)."\r\n.\r\n");$this->expect(250);$this->command('QUIT',221);return true;
  }finally{fclose($socket);$this->socket=null;}
 }
 private function write(string $data):void{while($data!==''){$n=fwrite($this->socket,$data);if($n===false||$n===0)throw new RuntimeException('Scrittura SMTP interrotta.');$data=substr($data,$n);}}
 private function command(string $line,int|array $expected):void{$this->write($line."\r\n");$this->expect($expected);}
 private function expect(int|array $expected):void{for($i=0;$i<100;$i++){$line=fgets($this->socket,4096);if($line===false)throw new RuntimeException('Risposta SMTP assente.');if(strlen($line)<4)throw new RuntimeException('Risposta SMTP non valida.');if($line[3]===' '){if(!in_array((int)substr($line,0,3),(array)$expected,true))throw new RuntimeException('SMTP ha rifiutato la richiesta ('.(int)substr($line,0,3).').');return;}}throw new RuntimeException('Risposta SMTP troppo lunga.');}
}
