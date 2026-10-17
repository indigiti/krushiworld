<?php
declare(strict_types=1);

namespace KrushiPulse\Security;
use RuntimeException;

/** Login orchestration; modes are explicitly controlled by server environment. */
final class AdminAuth {
    private ?AdminIdentity $identity=null;
    public function __construct(?AdminIdentity $identity=null) {$this->identity=$identity;}
    public function mode(): string {
        $mode=strtolower(trim((string)(getenv('APMC_AUTH_MODE') ?: 'legacy')));
        return in_array($mode,['legacy','staging','production'],true)?$mode:'disabled';
    }
    public function enabled(): bool { return $this->mode()!=='legacy'; }
    public function store(): AdminIdentity {
        if (!$this->enabled()) throw new RuntimeException('Identity login is not enabled');
        return $this->identity ??= new AdminIdentity(APMC_STORAGE.'/auth/identity.sqlite');
    }
    public function githubAllowed(): bool {
        return $this->mode()==='staging'
            && (bool)preg_match('/^[a-zA-Z0-9._-]{5,150}$/D',(string)(getenv('APMC_GITHUB_CLIENT_ID')?:''))
            && trim((string)getenv('APMC_GITHUB_CLIENT_SECRET'))!==''
            && trim((string)getenv('APMC_GITHUB_ADMIN_IDS'))!==''
            && $this->store()->providerEnabled();
    }
    public function browserSession(): void {
        if(session_status()===PHP_SESSION_ACTIVE)return;
        if(headers_sent())throw new RuntimeException('Cannot initiate authentication session after response');
        session_name('kp_admin_browser');
        $path=apmc_app_base_path() ?: '/';
        session_set_cookie_params(['lifetime'=>0,'path'=>$path,'secure'=>apmc_request_is_https(),'httponly'=>true,'samesite'=>'Lax']);
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        session_start();
        if(!isset($_SESSION['kp_csrf']))$_SESSION['kp_csrf']=bin2hex(random_bytes(32));
    }
    public function csrf(): string { $this->browserSession();return (string)$_SESSION['kp_csrf']; }
    public function validCsrf(string $token): bool {
        return $token!=='' && hash_equals($this->csrf(),$token);
    }
    public function user(): ?array {
        if(!$this->enabled() || $this->mode()==='disabled')return null;
        $token=(string)($_COOKIE['kp_admin_auth']??'');
        if($token==='')return null;
        try {
            $session=$this->store()->session($token);
            if ($session && $session['provider']==='github') {
                $allowed=array_filter(array_map('trim',explode(',',(string)getenv('APMC_GITHUB_ADMIN_IDS'))),'ctype_digit');
                if ($this->mode()!=='staging' || !$this->githubAllowed()
                    || !in_array((string)$session['github_id'],$allowed,true)) return null;
            }
            return $session;
        }catch(\Throwable){return null;}
    }
    public function superAdmin(): bool {return ($this->user()['role']??'')==='super_admin';}
    public function canOperate(): bool {return in_array($this->user()['role']??'',['operator','admin','super_admin'],true);}
    private function cookie(string $token,int $expiry): void {
        $path=apmc_app_base_path()?:'/';
        setcookie('kp_admin_auth',$token,['expires'=>$expiry,'path'=>$path,
            'secure'=>apmc_request_is_https(),'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['kp_admin_auth']=$token;
    }
    public function signIn(int $id,string $provider): void {
        $host=(string)($_SERVER['HTTP_HOST']??'');
        if(!apmc_request_is_https() && !in_array($host,['127.0.0.1','localhost','127.0.0.1:18739'],true))
            throw new RuntimeException('HTTPS required for individual administrator login');
        $this->browserSession();
        session_regenerate_id(true);$_SESSION['kp_csrf']=bin2hex(random_bytes(32));
        $prior=(string)($_COOKIE['kp_admin_auth']??'');
        if($prior!=='')$this->store()->sessionDestroy($prior);
        $session=$this->store()->sessionCreate($id,$provider);
        $this->cookie($session['token'],time()+8*3600);
    }
    public function logout(): void {
        $token=(string)($_COOKIE['kp_admin_auth']??'');
        if($token!=='')$this->store()->sessionDestroy($token);
        $this->cookie('',time()-3600);
        $this->browserSession();$_SESSION=[];session_regenerate_id(true);
        $_SESSION['kp_csrf']=bin2hex(random_bytes(32));
    }
    public function redirectUri(): string { return apmc_absolute_url('/admin/'); }
    public function githubStart(): string {
        if(!$this->githubAllowed() || !apmc_request_is_https()) throw new RuntimeException('Staging GitHub login not available');
        $this->browserSession();
        $state=bin2hex(random_bytes(32));
        $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        $_SESSION['kp_github_flow']=['hash'=>hash('sha256',$state),'verifier'=>$verifier,'created'=>time()];
        $params=http_build_query(['client_id'=>(string)getenv('APMC_GITHUB_CLIENT_ID'),
            'redirect_uri'=>$this->redirectUri(),'state'=>$state,'allow_signup'=>'false',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),
            'code_challenge_method'=>'S256'], '', '&', PHP_QUERY_RFC3986);
        return 'https://github.com/login/oauth/authorize?'.$params;
    }
    private static function jsonCall(string $url,?array $fields=null,?string $token=null): array {
        if(!extension_loaded('curl'))throw new RuntimeException('HTTPS API client unavailable');
        $curl=curl_init($url);
        $headers=['Accept: application/json','User-Agent: KrushiPulse-AdminAuth/1','X-GitHub-Api-Version: 2022-11-28'];
        if($token!==null)$headers[]='Authorization: Bearer '.$token;
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>8,
            CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_MAXREDIRS=>0];
        if($fields!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=http_build_query($fields);}
        curl_setopt_array($curl,$opts);
        $body=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if(!is_string($body)||$http!==200||strlen($body)>200000)throw new RuntimeException('GitHub verification failed');
        $data=json_decode($body,true);
        if(!is_array($data))throw new RuntimeException('Unexpected GitHub verification result');
        return $data;
    }
    public function githubCallback(string $code,string $state): bool {
        if(!$this->githubAllowed() || !apmc_request_is_https())return false;
        $this->browserSession();
        $flow=$_SESSION['kp_github_flow']??null;
        unset($_SESSION['kp_github_flow']); // Strict single use.
        if(!is_array($flow)||time()-(int)($flow['created']??0)>300
            || !preg_match('/^[a-f0-9]{64}$/D',$state)
            || !hash_equals((string)($flow['hash']??''),hash('sha256',$state))
            || !preg_match('/^[a-zA-Z0-9_-]{1,400}$/D',$code))return false;
        try{
            $data=self::jsonCall('https://github.com/login/oauth/access_token',[
                'client_id'=>(string)getenv('APMC_GITHUB_CLIENT_ID'),
                'client_secret'=>(string)getenv('APMC_GITHUB_CLIENT_SECRET'),
                'code'=>$code,'redirect_uri'=>$this->redirectUri(),
                'code_verifier'=>(string)$flow['verifier']
            ]);
            $token=(string)($data['access_token']??'');
            if(!preg_match('/^[a-zA-Z0-9_]{15,300}$/D',$token))return false;
            $profile=self::jsonCall('https://api.github.com/user',null,$token);
            $githubId=(string)($profile['id']??'');
            if(!ctype_digit($githubId))return false;
            $allowed=array_filter(array_map('trim',explode(',',(string)getenv('APMC_GITHUB_ADMIN_IDS'))),'ctype_digit');
            $user=$this->store()->githubBootstrap($githubId,$allowed);
            if(!$user)return false;
            $this->signIn((int)$user['id'],'github');return true;
        }catch(\Throwable){return false;}
    }
    public static function totpNew(): string {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
        foreach(str_split(random_bytes(20)) as $char)$bits.=str_pad(decbin(ord($char)),8,'0',STR_PAD_LEFT);
        $out='';foreach(str_split($bits,5) as $part)$out.=$alphabet[bindec($part)];
        return $out;
    }
    private function key(): string {
        $hex=trim((string)(getenv('APMC_AUTH_KEY')?:''));
        if(!preg_match('/^[a-f0-9]{64}$/iD',$hex))throw new RuntimeException('Set a unique 32-byte APMC_AUTH_KEY (64 hex characters) in the server environment');
        return hex2bin($hex);
    }
    public function seal(string $secret): string {
        if(!function_exists('sodium_crypto_secretbox'))throw new RuntimeException('PHP sodium extension required');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($secret,$nonce,$this->key()));
    }
    public function open(string $blob): string {
        $raw=base64_decode($blob,true);
        if($raw===false||strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+16)throw new RuntimeException('Invalid encrypted MFA secret');
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$this->key());
        if($plain===false)throw new RuntimeException('MFA encryption key mismatch');
        return $plain;
    }
    private static function base32Decode(string $secret): string {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
        foreach(str_split(strtoupper($secret)) as $c){$v=strpos($alphabet,$c);if($v===false)return ''; $bits.=str_pad(decbin($v),5,'0',STR_PAD_LEFT);}
        $out='';foreach(str_split($bits,8) as $chunk){if(strlen($chunk)===8)$out.=chr(bindec($chunk));}
        return $out;
    }
    public static function totpStep(string $secret,int $step): string {
        $key=self::base32Decode($secret);if($key===''||$step<0)return '';
        $pack=pack('N2',intdiv($step,4294967296),$step%4294967296);
        $h=hash_hmac('sha1',$pack,$key,true);$offset=ord($h[19])&15;
        $binary=unpack('N',substr($h,$offset,4))[1]&0x7fffffff;
        return str_pad((string)($binary%1000000),6,'0',STR_PAD_LEFT);
    }
    public function verifyMfa(array $user,string $code): bool {
        $id=(int)$user['id'];$step=intdiv(time(),30);
        if(preg_match('/^[0-9]{6}$/D',$code)){
            try{$secret=$this->open((string)$user['totp_encrypted']);}catch(\Throwable){return false;}
            foreach([$step-1,$step,$step+1] as $tick)
                if(hash_equals(self::totpStep($secret,$tick),$code)&&$this->store()->checkTotpReplay($id,$tick))return true;
        }
        // Recovery codes are allowed only after password verification in localLogin.
        if($user['status']==='active' && $this->store()->useRecovery($id,$code))return true;
        return false;
    }
    public function localLogin(string $username,string $password,string $otp): bool {
        $host=(string)($_SERVER['HTTP_HOST']??'');
        if(!in_array($this->mode(),['staging','production'],true) || (!apmc_request_is_https() && !in_array($host,['127.0.0.1','localhost','127.0.0.1:18739'],true)))return false;
        $id='local:'.strtolower($username);
        $db=$this->store();
        if(!$db->rateLimit($id))return false;
        $user=$db->userByName($username);
        $valid=$user && is_string($user['password_hash']) && $user['password_hash']!==''
          && in_array($user['status'],['active','pending_mfa'],true)
          && password_verify($password,$user['password_hash']);
        if(!$valid||!$this->verifyMfa($user,$otp)){
            $db->loginFailure($id);return false;
        }
        $db->loginSuccess($id);
        if($user['status']==='pending_mfa')$db->markLocalVerified((int)$user['id']);
        $this->signIn((int)$user['id'],'local');
        return true;
    }
    private function verifyLocalStep(string $password,string $code): int {
        $session=$this->user();
        if(!$session || $session['provider']!=='local')throw new RuntimeException('Local authenticated account required');
        $user=$this->store()->user((int)$session['user_id']);
        if(!$user || !is_string($user['password_hash']) || !password_verify($password,$user['password_hash'])
            || !$this->verifyMfa($user,$code))throw new RuntimeException('Current credentials or fresh MFA code not verified');
        return (int)$user['id'];
    }
    public function passwordChange(string $current,string $code,string $new): void {
        $id=$this->verifyLocalStep($current,$code);
        $this->store()->changePassword($id,$new);
        $this->logout();
    }
    public function recoveryRotate(string $password,string $code): array {
        $id=$this->verifyLocalStep($password,$code);
        $codes=[];
        for($i=0;$i<8;$i++){
            $hex=strtoupper(bin2hex(random_bytes(8)));
            $codes[]=substr($hex,0,8).'-'.substr($hex,8,8);
        }
        $this->store()->replaceRecovery($id,$codes);
        return $codes;
    }
    public function createUser(string $username,string $password,string $role): array {
        if(!$this->superAdmin())throw new RuntimeException('Super Admin access required');
        // The bootstrap GitHub session may provision a pending local administrator.
        $secret=self::totpNew();
        $id=$this->store()->createLocal($username,$password,$this->seal($secret),$role,(int)$this->user()['user_id']);
        $codes=[];for($i=0;$i<8;$i++){$hex=strtoupper(bin2hex(random_bytes(8)));$codes[]=substr($hex,0,8).'-'.substr($hex,8,8);}
        $this->store()->setRecovery($id,$codes);
        return ['id'=>$id,'username'=>$username,'secret'=>$secret,'recovery'=>$codes,
            'uri'=>'otpauth://totp/KrushiPulse:'.rawurlencode($username).'?secret='.$secret.'&issuer=KrushiPulse&digits=6&period=30'];
    }
}
