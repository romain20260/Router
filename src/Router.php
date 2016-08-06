<?php

namespace Akibatech;

/**
 * Class Router
 *
 * @package Akiba
 */
class Router
{
    /**
     * @var self
     */
    protected static $router;

    /**
     * @var array
     */
    protected $routes = [];

    /**
     * @var array
     */
    protected $names = [];

    /**
     * Stocke l'index de la route courante qui a matchée.
     *
     * @var int
     */
    protected $current;

    /**
     * Stocke les paramètres de routing matchés.
     *
     * @var array
     */
    protected $matched_parameters = [];

    /**
     * Store the default namespace for dispatching actions.
     *
     * @var null|string
     */
    protected $namespace;

    /**
     * @var array
     */
    protected $methods = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE'
    ];

    //-------------------------------------------------------------------------

    /**
     * Router constructor.
     *
     * @param   void
     * @return  self
     */
    private function __construct()
    {
        self::$router = $this;
    }

    //-------------------------------------------------------------------------

    /**
     * Retourne le singleton du Router.
     *
     * @param   void
     * @return  Router
     */
    public static function getInstance()
    {
        if (is_null(self::$router))
        {
            self::$router = new self;
        }

        return self::$router;
    }

    //-------------------------------------------------------------------------

    /**
     * Le routeur écoute...
     *
     * @param   int $dispatch
     * @return  self
     */
    public function listen()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = trim($_SERVER['REQUEST_URI'], '/');

        if (count($this->routes) > 0)
        {
            // Boucle sur les routes...
            foreach ($this->routes as $key => $route)
            {
                if ($this->routeMatch($key, $uri, $method) === true)
                {
                    return $this->dispatchCurrent();
                }
            }
        }

        return $this;
    }

    //-------------------------------------------------------------------------

    /**
     * Utilise un callback pour charger les routes.
     *
     * @param   string|callable $routes
     * @return  self
     */
    public function routes(callable $callback)
    {
        $callback($this);

        return $this;
    }

    //-------------------------------------------------------------------------

    /**
     * Ajoute une nouvelle route.
     *
     * @param   array  $method
     * @param   string $uri
     * @param   string $action
     * @param   string $name
     * @return  self
     */
    public function add(array $methods, $uri, $action, $as = null)
    {
        $methods = $this->validateRouteMethods($methods);
        $uri     = $this->validateRouteUri($uri);
        $index   = count($this->routes) + 1;

        // Ajoute la route.
        $this->routes[$index] = [
            'methods' => $methods,
            'uri'     => $uri,
            'action'  => $action
        ];

        // La route est nommée.
        if (is_null($as) === false)
        {
            $as = $this->validateRouteNamed($as);

            $this->names[$index] = $as;
        }

        return $this;
    }

    //-------------------------------------------------------------------------

    /**
     * Retourne une route par son index.
     *
     * @param   int $index
     * @return  array
     */
    public function getIndexedRoute($index)
    {
        // L'index existe
        if (array_key_exists($index, $this->routes))
        {
            return $this->routes[$index];
        }

        throw new \RuntimeException("No route indexed with \"$index\".");
    }

    //-------------------------------------------------------------------------

    /**
     * Vérifie une méthode ou plusieurs méthodes.
     *
     * @param   string|array $methods
     * @return  string|array
     */
    protected function validateRouteMethods($methods)
    {
        // Tableau de méthode fournie.
        if (is_array($methods))
        {
            foreach ($methods as &$method)
            {
                $method = $this->validateRouteMethods($method);
            }

            unset($method);

            return $methods;
        }
        else
        {
            $method = strtoupper($methods);

            if (in_array($method, $this->methods))
            {
                return $method;
            }
        }

        throw new \InvalidArgumentException("Given method \"$method\" is invalid.");
    }

    //-------------------------------------------------------------------------

    /**
     * Valide une URI, transforme les segments d'URL en regex.
     *
     * @param   string $uri
     * @return  string
     */
    protected function validateRouteUri($uri)
    {
        // Supprime les "/" de début et de fin.
        $uri = trim($uri, '/');
        $uri = str_replace([
            '.',
            '-',
            '?',
            '&'
        ], [
            '\.',
            '\-',
            '\?',
            '\&'
        ], $uri);

        // Boucle tant que la chaîne contient { ou }.
        // Attention le != est volontaire : preg_match peut retourner 0 ou false.
        while (false != preg_match('/\{(.*)+\}/', $uri))
        {
            // Transforme :num
            $uri = preg_replace('/\{\:num\}/i', '([0-9]+)', $uri);

            // Transforme :string
            $uri = preg_replace('/\{\:alpha\}/i', '([a-z]+)', $uri);

            // Transforme :any
            $uri = preg_replace('/\{\:any\}/i', '([a-z0-9\_\.\:\,\-]+)', $uri);

            // Transforme :slug
            $uri = preg_replace('/\{\:slug\}/i', '([a-z0-9\-]+)', $uri);
        }

        return $uri;
    }

    //-------------------------------------------------------------------------

    /**
     * Valide le nom d'une route.
     * Notamment qu'il ne soit pas déjà pris...
     *
     * @param   string $as
     * @return  string
     */
    protected function validateRouteNamed($as)
    {
        if (in_array($as, $this->names) === false)
        {
            return $as;
        }

        throw new \InvalidArgumentException("Duplicate route named \"$as\"");
    }

    //-------------------------------------------------------------------------

    /**
     * Vérifie si une route matche à la méthode et l'URI fournie.
     *
     * @param   int    $index Index de la route.
     * @param   string $uri
     * @param   string $method
     * @return  bool
     */
    protected function routeMatch($index, $uri = '', $method = 'GET')
    {
        // Récupère la route
        $route = $this->getIndexedRoute($index);

        // Construit le pattern
        $pattern = '#^' . $route['uri'] . '$#i';

        // Stocke temporairement les paramètres matchés
        $matches = [];

        if (preg_match_all($pattern, $uri, $matches) > 0
            AND in_array($method, $route['methods'])
        )
        {
            // Prépare les paramètres matchés.
            if (count($matches) > 0)
            {
                // Supprime le premier élément du tableau des matches regex.
                array_shift($matches);

                foreach ($matches as $match)
                {
                    $this->matched_parameters[] = $match[0];
                }
            }

            $this->current = $index;

            return true;
        }

        return false;
    }

    //-------------------------------------------------------------------------

    /**
     * Exécute une route.
     *
     * @param   null|int $route
     * @return  mixed
     */
    protected function dispatchCurrent()
    {
        // Pas de route courante ?
        if (is_null($this->current))
        {
            throw new \RuntimeException("Trying to dispatch non-matched route.");
        }

        $route = $this->getIndexedRoute($this->current);

        // Callback fournie.
        if (is_callable($route['action']))
        {
            return call_user_func_array($route['action'], $this->matched_parameters);
        }

        $call       = explode('@', $route['action']);
        $className  = $call[0];
        $methodName = $call[1];

        // Ajout le namespace par défaut à la classe.
        if (is_null($this->namespace) === false)
        {
            $className = $this->namespace . $className;
        }

        // Classe + méthode fournie.
        if (class_exists($className))
        {
            // Instancie la classe.
            $class = new $className;

            // La méthode existe sur la classe.
            if (method_exists($class, $methodName))
            {
                return call_user_func_array([
                    $class,
                    $methodName
                ], $this->matched_parameters);
            }
        }

        throw new \RuntimeException("Unable to dispatch matched route.");
    }

    //-------------------------------------------------------------------------

    /**
     * Définit un namespace par défaut pour dispatcher les actions.
     *
     * @param   string $namespace
     * @return  self
     */
    public function namespaceWith($namespace = '')
    {
        // Pas de namespace.
        if (empty($namespace))
        {
            $namespace = null;
        }
        else if (stripos($namespace, -1, 1) != '\\')
        {
            $namespace = $namespace . '\\';
        }

        $this->namespace = $namespace;

        return $this;
    }

    //-------------------------------------------------------------------------

    /**
     * Appel de méthode dynamique.
     * Utile pour les méthodes get(), post(), put() qui sont des alias de add().
     *
     * @param   string $method
     * @param   array  $args
     * @return  self
     */
    public function __call($method, $args = [])
    {
        // Ajout dynamique par verbe HTTP.
        if (in_array(strtoupper($method), $this->methods))
        {
            return $this->add([$method], $args[0], $args[1], $args[2]);
        }
        // Méthode "any" => Correspond à tous les verbes.
        else if ($method === 'any')
        {
            return $this->add($this->methods, $args[0], $args[1], $args[2]);
        }

        throw new \BadMethodCallException("Invalid method \"$method\".");
    }

    //-------------------------------------------------------------------------
}