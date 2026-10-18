<?php
declare(strict_types=1);
namespace KrushiPulse\Security;

/** All security mutations are POST + origin + CSRF and require authenticated Super Admin. */
final class AdminHttp {
    public static function run(AdminAuth $auth): void {
        $GLOBALS['kp_auth_notice'] = '';
        $GLOBALS['kp_auth_enrollment'] = null;
        $GLOBALS['kp_auth_recovery'] = null;
        $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
        if($method==='GET' && isset($_GET['code'],$_GET['state'])){
            $ok=$auth->githubCallback((string)$_GET['code'],(string)$_GET['state']);
            if($ok){self::redirect();return;}
            header('Location: '.apmc_absolute_url('/admin/?auth_failed=1'),true,303);
            exit;
        }
        if($method==='GET' && ($_GET['auth']??'')==='github-start'){
            try {header('Location: '.$auth->githubStart(),true,302);exit;}
            catch(\Throwable){$GLOBALS['kp_auth_notice']='Staging GitHub sign-in is not configured or allowed.';}
            return;
        }
        if($method!=='POST'||!isset($_POST['auth_action']))return;
        if(!apmc_same_origin_request() || !$auth->validCsrf((string)($_POST['_csrf']??''))){
            http_response_code(403);$GLOBALS['kp_auth_notice']='Request could not be verified.';return;
        }
        $action=(string)($_POST['auth_action']??'');
        try {
            if($action==='bootstrap_owner') {
                $GLOBALS['kp_auth_enrollment']=OwnerSetup::enroll(
                    $auth,
                    trim((string)($_POST['github_pat']??'')),
                    trim((string)($_POST['username']??'')),
                    (string)($_POST['password']??'')
                );
                header('Cache-Control: no-store');
                $GLOBALS['kp_auth_notice']='Super Admin created pending MFA verification. Save the setup secret and recovery codes now; sign in above using your new account and authenticator.';
                return;
            }
            if($action==='bootstrap_local') {
                $GLOBALS['kp_auth_enrollment']=WebBootstrap::enroll(
                    $auth,
                    trim((string)($_POST['setup_grant']??'')),
                    trim((string)($_POST['username']??'')),
                    (string)($_POST['password']??'')
                );
                $GLOBALS['kp_auth_notice']='Local Super Admin is pending first MFA login. Save the one-time setup key and recovery codes below. Then log in above with your authenticator code.';
                return;
            }
            if($action==='local_login'){
                $ok=$auth->localLogin(
                    trim((string)($_POST['username']??'')),(string)($_POST['password']??''),
                    trim((string)($_POST['totp']??'')));
                if(!$ok){$GLOBALS['kp_auth_notice']='Invalid credentials or verification code.';return;}
                self::redirect();
            }
            if($action==='logout'){
                if(!$auth->user())throw new \RuntimeException('Login required');
                $auth->logout();self::redirect();
            }
            if($action==='change_password') {
                $auth->passwordChange((string)($_POST['current_password']??''),
                    trim((string)($_POST['fresh_otp']??'')),(string)($_POST['new_password']??''));
                self::redirect();
            }
            if($action==='rotate_recovery'){
                $GLOBALS['kp_auth_recovery']=$auth->recoveryRotate(
                    (string)($_POST['current_password']??''),trim((string)($_POST['fresh_otp']??'')));
                $GLOBALS['kp_auth_notice']='Old recovery codes are now invalid. Save the new one-time codes securely.';
                return;
            }
            $actor=$auth->user();
            if(!$actor || $actor['role']!=='super_admin'){
                http_response_code(403);throw new \RuntimeException('Super Admin authorization required');
            }
            $db=$auth->store();
            if($action==='save_national_key') {
                if (($actor['provider'] ?? '') !== 'local' || !$actor['local_verified_at'])
                    throw new \RuntimeException('Verified local Super Admin required');
                PrivateAppSettings::saveNationalKey(APMC_STORAGE, trim((string)($_POST['national_api_key']??'')));
                $GLOBALS['kp_auth_notice']='data.gov.in key saved in private storage. The scheduled AGMARKNET collector will use it on its next run.';
                return;
            }
            if($action==='create_user'){
                $GLOBALS['kp_auth_enrollment']=$auth->createUser(trim((string)($_POST['username']??'')),
                    (string)($_POST['password']??''),(string)($_POST['role']??'viewer'));
                $GLOBALS['kp_auth_notice']='Account created pending authenticator verification. Save the following setup secret and recovery codes now. The user must log in separately with password and TOTP to activate.';
            } elseif($action==='provider_disable'||$action==='provider_enable'){
                if($auth->mode()!=='staging')throw new \RuntimeException('Provider settings are locked by the server environment');
                if($actor['provider']!=='local' || !$actor['local_verified_at'])
                    throw new \RuntimeException('Reauthenticate with a verified local Super Admin before changing login providers');
                $enable=$action==='provider_enable';
                $db->setProvider($enable,(int)$actor['user_id']);
                $GLOBALS['kp_auth_notice']=$enable?'GitHub staging login enabled.':'GitHub staging login disabled; existing GitHub sessions revoked.';
            } elseif($action==='disable_user'||$action==='enable_user'){
                $id=(int)($_POST['user_id']??0);
                if($id<1)throw new \RuntimeException('Invalid user');
                $db->statusSet($id,$action==='disable_user'?'disabled':'active',(int)$actor['user_id']);
                $GLOBALS['kp_auth_notice']='Account status updated and sessions revoked as applicable.';
            } else {
                http_response_code(400);throw new \RuntimeException('Unknown security action');
            }
        }catch(\Throwable $e){
            // Safe messages only: never show database/cryptographic/provider internals in response.
            $safe=['Invalid account identifier or role','Password must be 14–256 characters',
                'Cannot disable your own account','Cannot disable the last verified local Super Admin',
                'A verified local Super Admin is required before disabling GitHub.',
                'Reauthenticate with a verified local Super Admin before changing login providers',
                'Provider settings are locked by the server environment',
                'Super Admin authorization required','Invalid user'];
            $GLOBALS['kp_auth_notice']=in_array($e->getMessage(),$safe,true)?$e->getMessage():'Security action was rejected. Check account state and server configuration.';
        }
    }
    private static function redirect(): never {
        header('Cache-Control: no-store');
        header('Location: '.apmc_absolute_url('/admin/'),true,303);
        exit;
    }
}
