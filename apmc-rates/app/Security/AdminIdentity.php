<?php
declare(strict_types=1);

namespace KrushiPulse\Security;

use PDO;
use RuntimeException;

/**
 * Private SQLite identity store. No GitHub/PAT token is persisted.
 * All users and sessions remain in persistent private storage, never the release artifact.
 */
final class AdminIdentity {
    private PDO $db;
    public function __construct(string $file) {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Admin identity requires PHP pdo_sqlite');
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) throw new RuntimeException('Private identity storage unavailable');
        $this->db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=3000');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            github_id TEXT UNIQUE, password_hash TEXT, totp_encrypted TEXT, status TEXT NOT NULL,
            role TEXT NOT NULL, local_verified_at INTEGER, created_at INTEGER NOT NULL,
            last_login_at INTEGER, last_totp_step INTEGER NOT NULL DEFAULT 0
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, provider TEXT NOT NULL,
            csrf_hash TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
            last_seen INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS recovery (
            user_id INTEGER NOT NULL, code_hash TEXT NOT NULL UNIQUE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, happened_at INTEGER NOT NULL,
            actor_id INTEGER, event TEXT NOT NULL, subject_id INTEGER, detail TEXT NOT NULL DEFAULT ""
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS sessions_by_user ON sessions(user_id, expires_at)');
    }
    private function one(string $sql, array $params=[]): ?array {
        $s=$this->db->prepare($sql);$s->execute($params);$r=$s->fetch();return $r ?: null;
    }
    private function exec(string $sql,array $params=[]): int {
        $s=$this->db->prepare($sql);$s->execute($params);return $s->rowCount();
    }
    public function user(int $id): ?array { return $this->one('SELECT * FROM users WHERE id=?',[$id]); }
    public function userByName(string $name): ?array { return $this->one('SELECT * FROM users WHERE username=? COLLATE NOCASE',[$name]); }
    public function userByGithub(string $id): ?array { return $this->one('SELECT * FROM users WHERE github_id=?',[$id]); }
    public function githubBootstrap(string $githubId, array $allowlist): ?array {
        if (!in_array($githubId,$allowlist,true)) return null;
        $name='github-' . $githubId;
        $this->exec('INSERT OR IGNORE INTO users(username,github_id,status,role,created_at) VALUES(?,?,"active","super_admin",?)',[$name,$githubId,time()]);
        $user=$this->userByGithub($githubId);
        return $user && $user['status']==='active' ? $user : null;
    }
    public function createLocal(string $username,string $password,string $encryptedTotp,string $role,int $actor): int {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/D',$username) || !in_array($role,['super_admin','admin','operator','viewer'],true))
            throw new RuntimeException('Invalid account identifier or role');
        if (strlen($password)<14 || strlen($password)>256) throw new RuntimeException('Password must be 14–256 characters');
        $hash=password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>3,'threads'=>1]);
        if (!is_string($hash)) throw new RuntimeException('Password hashing unavailable');
        $this->exec('INSERT INTO users(username,password_hash,totp_encrypted,role,status,created_at) VALUES(?,?,?,?,"pending_mfa",?)',
            [$username,$hash,$encryptedTotp,$role,time()]);
        $id=(int)$this->db->lastInsertId();
        $this->event($actor,'account_created',$id);
        return $id;
    }
    public function changePassword(int $id,string $new): void {
        if(strlen($new)<14 || strlen($new)>256)throw new RuntimeException('Password must be 14–256 characters');
        $hash=password_hash($new,PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>3,'threads'=>1]);
        if(!is_string($hash))throw new RuntimeException('Password hashing unavailable');
        $this->exec('UPDATE users SET password_hash=? WHERE id=? AND password_hash IS NOT NULL',[$hash,$id]);
        $this->sessionDestroyUser($id);
        $this->event($id,'password_changed',$id);
    }
    public function replaceRecovery(int $id,array $codes): void {
        $this->db->beginTransaction();
        try {
            $this->exec('DELETE FROM recovery WHERE user_id=?',[$id]);
            $this->setRecovery($id,$codes);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        $this->event($id,'recovery_rotated',$id);
    }
    public function setRecovery(int $id,array $codes): void {
        $stmt=$this->db->prepare('INSERT INTO recovery(user_id,code_hash) VALUES(?,?)');
        foreach($codes as $code)$stmt->execute([$id,hash('sha256',strtoupper($code))]);
    }
    public function useRecovery(int $id,string $code): bool {
        if (!preg_match('/^[A-F0-9]{8}-[A-F0-9]{8}$/i',$code)) return false;
        return $this->exec('DELETE FROM recovery WHERE user_id=? AND code_hash=?',[$id,hash('sha256',strtoupper($code))])===1;
    }
    public function markLocalVerified(int $id): void {
        $this->exec('UPDATE users SET status="active",local_verified_at=? WHERE id=? AND password_hash IS NOT NULL',[time(),$id]);
        $this->event($id,'local_mfa_verified',$id);
    }
    public function checkTotpReplay(int $id,int $step): bool {
        return $this->exec('UPDATE users SET last_totp_step=? WHERE id=? AND last_totp_step<?',[$step,$id,$step])===1;
    }
    public function localReady(): bool {
        return (int)$this->db->query('SELECT COUNT(*) FROM users WHERE role="super_admin" AND status="active" AND local_verified_at IS NOT NULL AND password_hash IS NOT NULL AND totp_encrypted IS NOT NULL')->fetchColumn()>0;
    }
    public function sessionCreate(int $id,string $provider): array {
        $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));$now=time();
        $this->exec('INSERT INTO sessions(token_hash,user_id,provider,csrf_hash,created_at,expires_at,last_seen) VALUES(?,?,?,?,?,?,?)',
            [hash('sha256',$token),$id,$provider,hash('sha256',$csrf),$now,$now+8*3600,$now]);
        $this->exec('UPDATE users SET last_login_at=? WHERE id=?',[$now,$id]);
        $this->event($id,'login_'.$provider,$id);
        return ['token'=>$token,'csrf'=>$csrf];
    }
    public function session(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $hash=hash('sha256',$token);
        $r=$this->one('SELECT s.*,u.username,u.role,u.status,u.local_verified_at,u.github_id
                       FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=?',[$hash]);
        if (!$r || $r['status']!=='active' || (int)$r['expires_at']<time() || (int)$r['last_seen']<time()-1800){
            $this->exec('DELETE FROM sessions WHERE token_hash=?',[$hash]);return null;
        }
        $this->exec('UPDATE sessions SET last_seen=? WHERE token_hash=?',[time(),$hash]);
        return $r;
    }
    public function sessionDestroy(string $token): void {
        if (preg_match('/^[a-f0-9]{64}$/D',$token)) $this->exec('DELETE FROM sessions WHERE token_hash=?',[hash('sha256',$token)]);
    }
    public function sessionDestroyUser(int $id): void { $this->exec('DELETE FROM sessions WHERE user_id=?',[$id]); }
    public function providerEnabled(): bool {
        $r=$this->one('SELECT value FROM settings WHERE key="github_enabled"');
        return !$r || $r['value']==='1';
    }
    public function setProvider(bool $enabled,int $actor): void {
        if (!$enabled && !$this->localReady()) throw new RuntimeException('A verified local Super Admin is required before disabling GitHub.');
        $this->exec('INSERT INTO settings(key,value) VALUES("github_enabled",?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$enabled?'1':'0']);
        if (!$enabled) $this->exec('DELETE FROM sessions WHERE provider="github"');
        $this->event($actor,$enabled?'github_enabled':'github_disabled');
    }
    public function users(): array { return $this->db->query('SELECT id,username,role,status,github_id,local_verified_at,created_at,last_login_at FROM users ORDER BY id')->fetchAll(); }
    public function statusSet(int $id,string $status,int $actor): void {
        if (!in_array($status,['active','disabled'],true)) throw new RuntimeException('Invalid account state');
        $r=$this->user($id);
        if(!$r)throw new RuntimeException('User not found');
        if($id===$actor && $status==='disabled') throw new RuntimeException('Cannot disable your own account');
        if($status==='active' && $r['status']==='pending_mfa') throw new RuntimeException('MFA enrollment must be completed by local login');
        if($r['role']==='super_admin' && $status==='disabled' && $r['password_hash']!==null && $this->localVerifiedCount()<=1)
            throw new RuntimeException('Cannot disable the last verified local Super Admin');
        $this->exec('UPDATE users SET status=? WHERE id=?',[$status,$id]);
        if($status==='disabled')$this->sessionDestroyUser($id);
        $this->event($actor,'account_'.$status,$id);
    }
    private function localVerifiedCount(): int {
        return (int)$this->db->query('SELECT COUNT(*) FROM users WHERE role="super_admin" AND status="active" AND local_verified_at IS NOT NULL AND password_hash IS NOT NULL')->fetchColumn();
    }
    public function event(?int $actor,string $event,?int $subject=null): void {
        $this->exec('INSERT INTO events(happened_at,actor_id,event,subject_id) VALUES(?,?,?,?)',[time(),$actor,$event,$subject]);
    }
    public function audit(): array { return $this->db->query('SELECT happened_at,actor_id,event,subject_id FROM events ORDER BY id DESC LIMIT 35')->fetchAll(); }
    public function rateLimit(string $identifier): bool {
        // Rate limit failed logins across processes; no password/secret is stored.
        $key='attempts:' . hash('sha256',strtolower($identifier));
        $r=$this->one('SELECT value FROM settings WHERE key=?',[$key]);
        $d=$r?json_decode($r['value'],true):null;$now=time();
        return !is_array($d) || ($now-(int)($d['since']??0)>900) || (int)($d['count']??0)<5;
    }
    public function loginFailure(string $identifier): void {
        $key='attempts:' . hash('sha256',strtolower($identifier));$now=time();
        $r=$this->one('SELECT value FROM settings WHERE key=?',[$key]);
        $d=$r?json_decode($r['value'],true):null;
        if (!is_array($d)||$now-(int)($d['since']??0)>900) $d=['since'=>$now,'count'=>0];
        $d['count']++;
        $this->exec('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[$key,json_encode($d)]);
    }
    public function loginSuccess(string $identifier): void {
        $this->exec('DELETE FROM settings WHERE key=?',['attempts:' . hash('sha256',strtolower($identifier))]);
    }
}
