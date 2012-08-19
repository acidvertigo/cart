<?php namespace Cart;

class InvalidCartInstanceException extends \Exception {}

class DuplicateCartInstanceException extends \Exception {}

class InvalidStorageImplementationException extends \Exception {}

class Manager
{
    /**
     * Available cart instances
     * @var array
     */
    protected static $instances = array();

    /**
     * The ID of the current cart in context
     * @var string
     */
    protected static $context = '';

    /**
     * The configuration options associated with the carts in the cart manager / cart manager itself
     * @var array
     */
    protected static $config = '';

    /**
     * Initializes the cart manager. Loads in the config and instantiates any carts declared in the config file.
     *
     * @static
     * @param array $config The configuration options associated with this cart manager
     */
    public static function init($config)
    {
        //cache passed config options
        static::$config = $config;

        //if there are carts defined in the config
        if (count($config['carts']) > 0) {
            foreach ($config['carts'] as $cartID => $cartConfig) {
                $cartConfig = array_merge($config['defaults'], $cartConfig); //merge global config with cart specific config
                static::$config['carts'][$cartID] = $cartConfig; //update the config
                static::newCartInstance($cartID, $cartConfig, true, false);
            }

            //set context to first cart in array
            static::$context = key($config['carts']);
        }
    }

    /**
     * Sets the current context if a cart ID is supplied, or gets the current context if no cart ID is supplied
     *
     * @static
     * @param bool|string $cartID If false then the current context is returned, otherwise the current context is set
     * @return string The current context if this is being retrieved
     * @throws InvalidCartInstanceException
     */
    public static function context($cartID = false)
    {
        if ($cartID) {
            if (isset(static::$instances[$cartID])) {
                static::$context = $cartID;
            }
            else {
                throw new InvalidCartInstanceException('There is no cart instance with the id: ' . $cartID);
            }
        }

        return static::$context;

    }

    /**
     * Checks to see if there is an instance of a cart with a specific ID
     *
     * @static
     * @param string $cartID The ID of the cart to check for
     * @return bool True if the cart instance exists, false otherwise
     */
    public static function cartInstanceAvailable($cartID)
    {
        return array_key_exists($cartID,static::$instances);
    }

    /**
     * Gets a cart instance. If no $cartID is passed then the cart in the current context
     * is returned. Otherwise requested instance is returned
     *
     * @static
     * @param string|bool $cartID The Id of the cart instance to return
     * @return object The requested cart instance or the current cart instance in context if no $cartID provided
     * @throws InvalidCartInstanceException
     */
    public static function getCartInstance($cartID = false)
    {
        $cartID or $cartID = static::$context;
        if (static::cartInstanceAvailable($cartID)) {
            return static::$instances[$cartID];
        }
        else {
            throw new InvalidCartInstanceException('There is no cart instance with the id: ' . $cartID);
        }
    }

    /**
     * Create a new cart instance
     *
     * @static
     * @param string $cartID The ID for the cart instance
     * @param bool|array $cartConfig The configuration options associated with this cart
     * @param bool $overwrite If the cart instance already exists should if be overwritten
     * @param bool $switchContext Should the context be switched to this cart instance
     * @return mixed The newly created cart instance
     * @throws DuplicateCartInstanceException
     */
    public static function newCartInstance($cartID, $cartConfig = false, $overwrite = true, $switchContext = true)
    {
        if (!static::cartInstanceAvailable($cartID) or $overwrite) {
            $cartConfig or $cartConfig = static::getCartConfig($cartID);
            static::$instances[$cartID] = new Cart($cartID, $cartConfig);

            /*
             * is there storage options associated with this instance of the cart?
             * if so we need to retrieve any saved data
             */
            if ($cartConfig['storage']['driver']) {
                static::restoreState($cartID);
            }
            if ($cartConfig['storage']['autosave']) {
                //register shutdown function for auto save
                register_shutdown_function(array('\Cart\Manager', 'saveState'), $cartID);
            }

            if ($switchContext) {
                static::$context = $cartID;
            }

            return static::$instances[$cartID];
        }
        else {
            throw new DuplicateCartInstanceException('There is already a cart instance with the id: ' . $cartID);
        }
    }

    /**
     * Destroy a cart instance. If the destroyed cart instance is in the current context, the
     * current context is set to nothing.
     *
     * @static
     * @param bool $cartID The ID of the cart to be destroyed
     * @param bool $clearStorage Should the storage associated with the cart instance be cleared
     */
    public static function destroyInstance($cartID = false, $clearStorage = true)
    {
        $cartID or $cartID = static::$context;
        if (static::cartInstanceAvailable($cartID)) {
            unset(static::$instances[$cartID]);

            if ($clearStorage) {
                static::clearState($cartID);
            }

            if (static::$context == $cartID) {
                static::$context = '';
            }
        }
    }

    /**
     * Destroy all cart instances associated with the cart manager. Also clears any saved states unless
     * false is passed.
     *
     * @static
     * @param bool $clearStorage Should the storage associated with a cart instance be cleared
     */
    public static function destroyAllInstances($clearStorage = true)
    {
        foreach (static::$instances as $cartID => $cart) {
            static::destroyInstance($cartID, $clearStorage);
        }
    }

    /**
     * Get the configuration options specified for a specific cart instance. If not configuration exists
     * for the requested instance, the default cart configuration is returned
     *
     * @static
     * @param string $cartID The ID of the cart instance
     * @return array The cart configuration options
     */
    public static function getCartConfig($cartID = '')
    {
        if (array_key_exists($cartID,static::$config['carts'])) {
            return static::$config['carts'][$cartID];
        }
        else {
            return static::$config['defaults'];
        }
    }

    /**
     * Save data associated with a cart instance to the configured storage method
     *
     * @static
     * @param string $cartID The ID of the cart instance
     */
    public static function saveState($cartID)
    {
        $data = serialize(static::$instances[$cartID]->export());
        $driver = static::getStorageDriver(static::getStorageKey($cartID));
        $driver::save(static::getStorageKey($cartID), $data);
    }

    /**
     * Restore data from storage associated with a cart instance
     *
     * @static
     * @param string $cartID The ID of the cart instance
     */
    public static function restoreState($cartID)
    {
        $driver = static::getStorageDriver($cartID);

        $data = unserialize($driver::restore(static::getStorageKey($cartID)));
        static::$instances[$cartID]->import($data);
    }

    /**
     * Clear any saved state associated with a cart instance
     *
     * @static
     * @param string $cartID The ID of the cart instance
     */
    public static function clearState($cartID)
    {
        $driver = static::getStorageDriver($cartID);
        $driver::clear(static::getStorageKey($cartID));
    }

    /**
     * Gets the FQN of the storage implementation associated with a cart instance. Also checks the
     * storage driver is valid
     *
     * @static
     * @param string $cartID The ID of the cart instance
     * @return string The FQN of the storage implementation
     * @throws InvalidStorageImplementationException
     */
    public static function getStorageDriver($cartID)
    {
        $cartConfig = static::getCartConfig($cartID);
        $driver = '\Cart\Storage\\' . ucfirst(strtolower($cartConfig['storage']['driver']));

        //check driver actually exists
        if ( ! class_exists($driver)) {
            throw new InvalidStorageImplementationException('The class: ' . $driver . ' does has not been loaded.');
        }

        //check driver implements StorageInterface
        $driverInstance = new \ReflectionClass($driver);
        if ( ! $driverInstance->implementsInterface('\Cart\Storage\StorageInterface')) {
            throw new InvalidStorageImplementationException('The class: ' . $driver . ' does not implement the StorageInterface.');
        }

        return $driver;
    }

    /**
     * Gets the storage key associated with a cart instances. Takes into account prefix
     * and suffix set in config
     *
     * @static
     * @param string $cartID The ID of the cart instance
     * @return string The storage key associated with the cart instance
     */
    public static function getStorageKey($cartID)
    {
        $cartConfig = static::getCartConfig($cartID);

        $storageKey = '';

        if (array_key_exists('storage_key_prefix',$cartConfig['storage'])) {
            $storageKey .= $cartConfig['storage']['storage_key_prefix'];
        }
        $storageKey .= $cartID;

        if (array_key_exists('storage_key_suffix',$cartConfig['storage'])) {
            $storageKey .= $cartConfig['storage']['storage_key_suffix'];
        }

        return $storageKey;
    }
}