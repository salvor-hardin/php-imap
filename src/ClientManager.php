<?php

namespace Webklex\PHPIMAP;

class ClientManager
{
    /**
     * All library config.
     */
    public static array $config = [];

    /**
     * The array of resolved accounts.
     */
    protected array $accounts = [];

    /**
     * ClientManager constructor.
     */
    public function __construct(array|string $config = [])
    {
        $this->setConfig($config);
    }

    /**
     * Dynamically pass calls to the default account.
     *
     * @throws Exceptions\MaskNotFoundException
     */
    public function __call(string $method, array $parameters): mixed
    {
        $callable = [$this->account(), $method];

        return call_user_func_array($callable, $parameters);
    }

    /**
     * Safely create a new client instance which is not listed in accounts.
     */
    public function make(array $config): Client
    {
        return new Client($config);
    }

    /**
     * Get a dotted config parameter.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);

        $value = null;

        foreach ($parts as $part) {
            if ($value === null) {
                if (isset(self::$config[$part])) {
                    $value = self::$config[$part];
                } else {
                    break;
                }
            } else {
                if (isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    break;
                }
            }
        }

        return $value === null ? $default : $value;
    }

    /**
     * Get the mask for a given section.
     *
     * @param  string  $section  section name such as "message" or "attachment"
     */
    public static function getMask(string $section): ?string
    {
        $default_masks = ClientManager::get('masks');

        if (isset($default_masks[$section])) {
            if (class_exists($default_masks[$section])) {
                return $default_masks[$section];
            }
        }

        return null;
    }

    /**
     * Resolve a account instance.
     *
     * @throws Exceptions\MaskNotFoundException
     */
    public function account(?string $name = null): Client
    {
        $name = $name ?: $this->getDefaultAccount();

        // If the connection has not been resolved we will resolve it now as all
        // the connections are resolved when they are actually needed, so we do
        // not make any unnecessary connection to the various queue end-points.
        if (! isset($this->accounts[$name])) {
            $this->accounts[$name] = $this->resolve($name);
        }

        return $this->accounts[$name];
    }

    /**
     * Resolve an account.
     */
    protected function resolve(string $name): Client
    {
        $config = $this->getClientConfig($name);

        return new Client($config);
    }

    /**
     * Get the account configuration.
     */
    protected function getClientConfig(?string $name): array
    {
        if ($name === null || $name === 'null' || $name === '') {
            return ['driver' => 'null'];
        }

        $account = self::$config['accounts'][$name] ?? [];

        return is_array($account) ? $account : [];
    }

    /**
     * Get the name of the default account.
     */
    public function getDefaultAccount(): string
    {
        return self::$config['default'];
    }

    /**
     * Set the name of the default account.
     */
    public function setDefaultAccount(string $name): void
    {
        self::$config['default'] = $name;
    }

    /**
     * Merge the vendor settings with the local config.
     *
     * The default account identifier will be used as default for any missing account parameters.
     * If however the default account is missing a parameter the package default account parameter will be used.
     * This can be disabled by setting imap.default in your config file to 'false'
     */
    public function setConfig(array|string $config): ClientManager
    {
        if (is_string($config)) {
            $config = require $config;
        }

        $config_key = 'imap';
        $path = __DIR__.'/config/'.$config_key.'.php';

        $vendor_config = require $path;

        $config = $this->array_merge_recursive_distinct($vendor_config, $config);

        if (is_array($config)) {
            if (isset($config['default'])) {
                if (isset($config['accounts']) && $config['default']) {
                    $default_config = $vendor_config['accounts']['default'];

                    if (isset($config['accounts'][$config['default']])) {
                        $default_config = array_merge($default_config, $config['accounts'][$config['default']]);
                    }

                    if (is_array($config['accounts'])) {
                        foreach ($config['accounts'] as $account_key => $account) {
                            $config['accounts'][$account_key] = array_merge($default_config, $account);
                        }
                    }
                }
            }
        }

        self::$config = $config;

        return $this;
    }

    /**
     * Marge arrays recursively and distinct.
     *
     * Merges any number of arrays / parameters recursively, replacing
     * entries with string keys with values from latter arrays.
     * If the entry or the next value to be assigned is an array, then it
     * automatically treats both arguments as an array.
     * Numeric entries are appended, not replaced, but only if they are
     * unique
     *
     * @return array|mixed
     *
     * @link   http://www.php.net/manual/en/function.array-merge-recursive.php#96201
     *
     * @author Mark Roduner <mark.roduner@gmail.com>
     */
    protected function array_merge_recursive_distinct(): mixed
    {
        $arrays = func_get_args();
        $base = array_shift($arrays);

        // From https://stackoverflow.com/a/173479
        $isAssoc = function (array $arr) {
            if ($arr === []) {
                return false;
            }

            return array_keys($arr) !== range(0, count($arr) - 1);
        };

        if (! is_array($base)) {
            $base = empty($base) ? [] : [$base];
        }

        foreach ($arrays as $append) {
            if (! is_array($append)) {
                $append = [$append];
            }

            foreach ($append as $key => $value) {
                if (! array_key_exists($key, $base) and ! is_numeric($key)) {
                    $base[$key] = $value;

                    continue;
                }

                if (
                    (
                        is_array($value)
                        && $isAssoc($value)
                    )
                    || (
                        is_array($base[$key])
                        && $isAssoc($base[$key])
                    )
                ) {
                    // If the arrays are not associates we don't want to array_merge_recursive_distinct
                    // else merging $baseConfig['dispositions'] = ['attachment', 'inline'] with $customConfig['dispositions'] = ['attachment']
                    // results in $resultConfig['dispositions'] = ['attachment', 'inline']
                    $base[$key] = $this->array_merge_recursive_distinct($base[$key], $value);
                } elseif (is_numeric($key)) {
                    if (! in_array($value, $base)) {
                        $base[] = $value;
                    }
                } else {
                    $base[$key] = $value;
                }
            }
        }

        return $base;
    }
}
