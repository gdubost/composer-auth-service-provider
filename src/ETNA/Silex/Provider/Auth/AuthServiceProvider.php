<?php

namespace ETNA\Silex\Provider\Auth;

use ETNA\RSA\RSA;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use Silex\ServiceProviderInterface;
use Exception;

class AuthServiceProvider implements ServiceProviderInterface
{
    private $app = null;
    private $rsa = null;
    
    /**
     * Check configuration and load public key
     */
    public function boot(Application $app)
    {
        switch (true) {
            case ($key = "auth.authenticator_url"  ) && (!isset($app[$key]) || !\trim($app[$key])):
            case ($key = "auth.public_key.tmp_path") && (!isset($app[$key]) || !\trim($app[$key])):
            case ($key = "auth.force_guest"        ) && (!isset($app[$key])):
            case ($key = "auth.cookie_expiration"  ) && (!isset($app[$key])):
                throw new \Exception("\$app['{$key}']: invalid key");
                break;
        }
        

        $app["auth.authenticator_url"] = \trim($app["auth.authenticator_url"], "/");

        $file = $app["auth.public_key.tmp_path"];
        if (!file_exists($file) || filemtime($file) < strtotime("-30seconds")) {
            $key = file_get_contents("{$app["auth.authenticator_url"]}/public.key");
            
            file_put_contents($file, $key);
        }

        $this->app = $app;
        $this->rsa = RSA::loadPublicKey($file);
    }

    /**
     * Unload the public key
     */
    public function __destruct()
    {
        unset($this->rsa);
    }

    /**
     * Register before callbacks
     *
     * $app["user.authenticated"]     => user must be authenticated to run the action
     * $app["user.in.group"]($groups) => user must have all defined groups to run the action
     */
    public function register(Application $app)
    {
        $app->before([$this, "addUserToRequest"]);

        $app["auth.authenticated"] = [$this, "authenticated"];
        $app["auth.secure"]        = [$this, "userHasGroup"];
    }
    
    /**
     * Add a user object to the current request
     */
    public function addUserToRequest(Request $req)
    {
        $req->user = null;
        if ($req->cookies->has("authenticator")) {
            $req->user = $this->extract($req->cookies->get("authenticator"));

            // Je suis authentifié depuis trop longtemps
            if ($this->app["auth.cookie_expiration"] && strtotime("{$req->user->login_date}{$this->app["auth.cookie_expiration"]}") < strtotime("now")) {
                $req->user->login_date = null;
            }

            // La conf me demande de forcer le guest
            
            if (!isset($req->user->login_date) || ($this->app["auth.force_guest"] && $req->user->login_date == null)) {
                $req->user = null;
            }
        }
    }

    /**
     * Throw an \Exception if user is not authenticated
     *
     * WARNING: it may be set to something even if he failed to authenticate. You have to check the user->login_date to be sure
     * @see $app["auth.force_guest"] if you want to force $req->user to null even after a password failure
     */
    public function authenticated(Request $req)
    {
        if ($req->user == null) {
            throw new \Exception("Authentication required");
        }
    }

    /**
     * Throw an \Exception if user does not have the $group
     */
    public function userHasGroup($group)
    {
        return function (Request $req) use ($group) {
            if (!in_array($group, $req->user->groups)) {
                throw new \Exception("Access Denied");
            }
        };
    }


    /**
     * Extract cookie information
     */
    protected function extract($cookie_string)
    {
        $cookie = base64_decode($cookie_string);
        if ($cookie == false) {
            throw new Exception("Cookie decode failed");
        }
        $cookie = json_decode($cookie);
        if ($cookie == false) {
            throw new Exception("Cookie decode failed");
        }

        if (!$this->rsa->verify($cookie->identity, $cookie->signature)) {
            return $this->app["json"]("Cookie check fail", 401);
        }

        $user = base64_decode($cookie->identity);
        if ($user == false) {
            throw new Exception("Identity decode failed");
        }
        $user = json_decode($user);
        if ($user == false) {
            throw new Exception("Identity decode failed");
        }

        return $user;
    }
}
