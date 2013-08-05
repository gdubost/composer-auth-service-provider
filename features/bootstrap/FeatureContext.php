<?php

use Behat\Behat\Context\BehatContext;
use Behat\Behat\Context\ClosuredContextInterface;
use Behat\Behat\Context\TranslatedContextInterface;
use Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use ETNA\Silex\Provider\Auth\AuthServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Features context.
 */
class FeatureContext extends BehatContext
{
    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        // Initialize your context here
    }

    /**
     * @BeforeSuite
     */
    public static function createKeys()
    {
        // Clef principale
        passthru("bash -c '[ -d tmp/key ] || mkdir -p tmp/key'");
        passthru("bash -c '[ -f tmp/key/private.key ] || openssl genrsa  -out tmp/key/private.key 2048'");
        passthru("bash -c '[ -f tmp/key/public.key ]  || openssl rsa -in tmp/key/private.key -pubout -out tmp/key/public.key'");

        // Clef pour tenter d'usurper une identité
        passthru("bash -c '[ -d tmp/key2 ] || mkdir -p tmp/key2'");
        passthru("bash -c '[ -f tmp/key2/private.key ] || openssl genrsa  -out tmp/key2/private.key 2048'");
        passthru("bash -c '[ -f tmp/key2/public.key ]  || openssl rsa -in tmp/key2/private.key -pubout -out tmp/key2/public.key'");
    }

    /**
     * @BeforeScenario
     */
    public static function clearCache()
    {
        @unlink("tmp/public.key");
    }

    /**
     * @Given /^que j\'instancie un nouvel objet$/
     */
    public function queJInstancieUnNouvelObjet()
    {
        $this->private  = openssl_pkey_get_private("file://" . __DIR__ . "/../../tmp/key/private.key");
        $this->private2 = openssl_pkey_get_private("file://" . __DIR__ . "/../../tmp/key2/private.key");
        $this->e        = null;
        $this->request  = new Request();
        $this->app      = new Silex\Application();
        $this->app->register(new AuthServiceProvider());
        $this->app->get("/", function (Request $req) {
            return json_encode($req->user);
        });
    }

    /**
     * @Given /^Silex boot mon provider$/
     */
    public function silexBootMonProvider()
    {
        try {
            $this->app->boot();
        } catch (Exception $e) {
            $this->e = $e;
        }
    }

    /**
     * @Given /^je dois avoir une exception$/
     */
    public function jeDoisAvoirUneException()
    {
        if (!$this->e) {
            throw new Exception("No exception catched");
        }
    }

    /**
     * @Given /^j\'injecte "([^"]*)" dans "([^"]*)"$/
     */
    public function jInjecteUneChaineDans($value, $key)
    {
        $this->app[$key] = str_replace("__DIR__", realpath(__DIR__ . "/../../"), $value);
    }

    /**
     * @Given /^j\'injecte ([\d]+) dans "([^"]*)"$/
     */
    public function jInjecteUnNombre($value, $key)
    {
        $this->app[$key] = (int) $value;
    }

    /**
     * @Given /^j\'injecte (true|false) dans "([^"]*)"$/
     */
    public function jInjecteUnBoolean($value, $key)
    {
        $this->app[$key] = $value == "true";
    }

    /**
     * @Given /^je ne dois pas avoir d\'exception$/
     */
    public function jeNeDoisPasAvoirDException()
    {
        if ($this->e) {
            throw new Exception("Exception catched : {$this->e->getMessage()}");
        }
    }

    /**
     * @Given /^je ne suis pas authentifié$/
     */
    public function jeNeSuisPasAuthentifie()
    {
        $response = $this->app->handle($this->request, HttpKernelInterface::MASTER_REQUEST, true)->getContent();
        $response = json_decode($response);
        if ($response !== null) {
            throw new Exception("\$req ne devrait pas avoir d'objet utilisateur");
        }
    }

    /**
     * Generate / Sign Authenticator Cokkie
     */
    public function addAuthenticatorCookie($identity, $private_key)
    {
        $identity = json_decode($identity);
        if ($identity === null && json_last_error()) {
            throw new Exception("JSON decode error");
        }
        
        if (!openssl_sign(base64_encode(json_encode($identity)), $signature, $private_key)) {
            throw new \Exception("Error signing cookie");
        }

        $identity = [
            "identity"  => base64_encode(json_encode($identity)),
            "signature" => base64_encode($signature),
        ];
        
        $this->request->cookies->set("authenticator", base64_encode(json_encode($identity)));
    }


    /**
     * @Given /^mon identité est$/
     */
    public function monIdentiteEst(PyStringNode $identity)
    {
        $this->addAuthenticatorCookie($identity, $this->private);
    }

    /**
     * @Given /^ma fausse identité est$/
     */
    public function maFausseIdentiteEst(PyStringNode $identity)
    {
        $this->addAuthenticatorCookie($identity, $this->private2);
    }

    /**
     * @Given /^je suis authentifié en tant que "([^"]*)" depuis "([^"]*)"$/
     */
    public function jeSuisAuthentifieEnTantQueDepuis($login, $login_date)
    {
        $response = $this->app->handle($this->request, HttpKernelInterface::MASTER_REQUEST, true)->getContent();
        $response = json_decode($response);
        if ($response === null) {
            throw new Exception("\$req devrait avoir d'objet utilisateur");
        }
        
        if ($login !== $response->login) {
            throw new Exception("\$req->login devrait être '{$login}'");
        }
        
        if ($login_date !== $response->login_date) {
            throw new Exception("\$req->login_date devrait être '{$login_date}'");
        }
        
        if (false !== $response->logas) {
            throw new Exception("\$req->logas devrait être 'false'");
        }
        
        $this->user = $response;
    }


    /**
     * @Given /^je suis logas en tant que "([^"]*)" depuis "([^"]*)"$/
     */
    public function jeSuisLogasEnTantQueDepuis($login, $login_date)
    {
        $response = $this->app->handle($this->request, HttpKernelInterface::MASTER_REQUEST, true)->getContent();
        $response = json_decode($response);
        if ($response === null) {
            throw new Exception("\$req devrait avoir d'objet utilisateur");
        }
        
        if ($login !== $response->login) {
            throw new Exception("\$req->login devrait être '{$login}'");
        }
        
        if ($login_date !== $response->login_date) {
            throw new Exception("\$req->login_date devrait être '{$login_date}'");
        }
        
        if (false === $response->logas) {
            throw new Exception("\$req->logas ne devrait pas être 'false'");
        }
        
        $this->user = $response;
    }

    /**
     * @Given /^en vrai, je suis "([^"]*)" depuis "([^"]*)"$/
     */
    public function enVraiJeSuisDepuis($login, $login_date)
    {
        if ($login !== $this->user->logas->login) {
            throw new Exception("\$req->logas->login devrait être '{$login}'");
        }
        
        if ($login_date !== $this->user->logas->login_date) {
            throw new Exception("\$req->logas->login_date devrait être '{$login_date}'");
        }
    }
    /**
     * @Given /^j\'ai les roles "([^"]*)"$/
     */
    public function jAiLesRoles($roles)
    {
        $roles = explode(",", $roles);
        sort($roles);
        sort($this->user->groups);
        if ($roles != $this->user->groups) {
            $roles = implode(",", $roles);
            throw new Exception("\$req->groups devrait être '{$roles}'");
        }
    }
}
