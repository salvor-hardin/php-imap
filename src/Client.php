<?php

namespace Webklex\PHPIMAP;

use ErrorException;
use Throwable;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\LegacyProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\ProtocolNotSupportedException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;
use Webklex\PHPIMAP\Traits\HasEvents;

class Client
{
    use HasEvents;

    /**
     * Connection resource.
     */
    public ?ProtocolInterface $connection = null;

    /**
     * Server hostname.
     */
    public string $host;

    /**
     * Server port.
     */
    public int $port;

    /**
     * Service protocol.
     */
    public string $protocol;

    /**
     * Server encryption.
     * Supported: none, ssl, tls, starttls or notls.
     */
    public string $encryption;

    /**
     * If server has to validate cert.
     */
    public bool $validate_cert = true;

    /**
     * Proxy settings.
     */
    protected array $proxy = [
        'socket' => null,
        'request_fulluri' => false,
        'username' => null,
        'password' => null,
    ];

    /**
     * Connection timeout.
     */
    public int $timeout;

    /**
     * Account username.
     */
    public string $username;

    /**
     * Account password.
     */
    public string $password;

    /**
     * Additional data fetched from the server.
     */
    public array $extensions;

    /**
     * Account authentication method.
     */
    public ?string $authentication;

    /**
     * Active folder path.
     */
    protected ?string $active_folder = null;

    /**
     * Default message mask.
     */
    protected string $default_message_mask = MessageMask::class;

    /**
     * Default attachment mask.
     */
    protected string $default_attachment_mask = AttachmentMask::class;

    /**
     * Used default account values.
     */
    protected array $default_account_config = [
        'host' => 'localhost',
        'port' => 993,
        'protocol' => 'imap',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'username' => '',
        'password' => '',
        'authentication' => null,
        'extensions' => [],
        'proxy' => [
            'socket' => null,
            'request_fulluri' => false,
            'username' => null,
            'password' => null,
        ],
        'timeout' => 30,
    ];

    /**
     * Client constructor.
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
        $this->setMaskFromConfig($config);
        $this->setEventsFromConfig($config);
    }

    /**
     * Client destructor.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Clone the current Client instance.
     */
    public function clone(): Client
    {
        $client = new self;

        $client->events = $this->events;
        $client->timeout = $this->timeout;
        $client->active_folder = $this->active_folder;
        $client->default_message_mask = $this->default_message_mask;
        $client->default_attachment_mask = $this->default_message_mask;
        $client->default_account_config = $this->default_account_config;

        foreach ($config = $this->getAccountConfig() as $key => $value) {
            $client->setAccountConfig($key, $config, $this->default_account_config);
        }

        return $client;
    }

    /**
     * Set the Client configuration.
     */
    public function setConfig(array $config): Client
    {
        $defaultAccount = ClientManager::get('default');
        $defaultConfig = ClientManager::get("accounts.$defaultAccount");

        foreach ($this->default_account_config as $key => $value) {
            $this->setAccountConfig($key, $config, $defaultConfig);
        }

        return $this;
    }

    /**
     * Get the current config.
     */
    public function getConfig(): array
    {
        $config = [];

        foreach ($this->default_account_config as $key => $value) {
            $config[$key] = $this->$key;
        }

        return $config;
    }

    /**
     * Set a specific account config.
     */
    private function setAccountConfig(string $key, array $config, array $default_config): void
    {
        $value = $this->default_account_config[$key];

        if (isset($config[$key])) {
            $value = $config[$key];
        } elseif (isset($default_config[$key])) {
            $value = $default_config[$key];
        }

        $this->$key = $value;
    }

    /**
     * Get the current account config.
     */
    public function getAccountConfig(): array
    {
        $config = [];

        foreach ($this->default_account_config as $key => $value) {
            if (property_exists($this, $key)) {
                $config[$key] = $this->$key;
            }
        }

        return $config;
    }

    /**
     * Look for a possible events in any available config.
     */
    protected function setEventsFromConfig($config): void
    {
        $this->events = ClientManager::get('events');

        if (isset($config['events'])) {
            foreach ($config['events'] as $section => $events) {
                $this->events[$section] = array_merge($this->events[$section], $events);
            }
        }
    }

    /**
     * Look for a possible mask in any available config.
     *
     * @throws MaskNotFoundException
     */
    protected function setMaskFromConfig($config): void
    {
        if (isset($config['masks'])) {
            if (isset($config['masks']['message'])) {
                if (class_exists($config['masks']['message'])) {
                    $this->default_message_mask = $config['masks']['message'];
                } else {
                    throw new MaskNotFoundException('Unknown mask provided: '.$config['masks']['message']);
                }
            } else {
                $default_mask = ClientManager::getMask('message');
                if ($default_mask != '') {
                    $this->default_message_mask = $default_mask;
                } else {
                    throw new MaskNotFoundException('Unknown message mask provided');
                }
            }
            if (isset($config['masks']['attachment'])) {
                if (class_exists($config['masks']['attachment'])) {
                    $this->default_attachment_mask = $config['masks']['attachment'];
                } else {
                    throw new MaskNotFoundException('Unknown mask provided: '.$config['masks']['attachment']);
                }
            } else {
                $default_mask = ClientManager::getMask('attachment');
                if ($default_mask != '') {
                    $this->default_attachment_mask = $default_mask;
                } else {
                    throw new MaskNotFoundException('Unknown attachment mask provided');
                }
            }
        } else {
            $default_mask = ClientManager::getMask('message');
            if ($default_mask != '') {
                $this->default_message_mask = $default_mask;
            } else {
                throw new MaskNotFoundException('Unknown message mask provided');
            }

            $default_mask = ClientManager::getMask('attachment');
            if ($default_mask != '') {
                $this->default_attachment_mask = $default_mask;
            } else {
                throw new MaskNotFoundException('Unknown attachment mask provided');
            }
        }
    }

    /**
     * Get the current imap resource.
     */
    public function getConnection(): ProtocolInterface
    {
        $this->checkConnection();

        return $this->connection;
    }

    /**
     * Determine if connection was established.
     */
    public function isConnected(): bool
    {
        return $this->connection && $this->connection->connected();
    }

    /**
     * Determine if connection was established and connect if not.
     */
    public function checkConnection(): bool
    {
        try {
            if (! $this->isConnected()) {
                $this->connect();

                return true;
            }
        } catch (Throwable) {
            $this->connect();
        }

        return false;
    }

    /**
     * Force the connection to reconnect.
     */
    public function reconnect(): void
    {
        if ($this->isConnected()) {
            $this->disconnect();
        }

        $this->connect();
    }

    /**
     * Connect to server.
     */
    public function connect(): Client
    {
        $this->disconnect();

        $protocol = strtolower($this->protocol);

        if (in_array($protocol, ['imap', 'imap4', 'imap4rev1'])) {
            $this->connection = new ImapProtocol($this->validate_cert, $this->encryption);
            $this->connection->setConnectionTimeout($this->timeout);
            $this->connection->setProxy($this->proxy);
        } else {
            if (extension_loaded('imap') === false) {
                throw new ConnectionFailedException(
                    'Connection setup failed', 0, new ProtocolNotSupportedException($protocol.' is an unsupported protocol')
                );
            }

            $this->connection = new LegacyProtocol($this->validate_cert, $this->encryption);

            if (str_starts_with($protocol, 'legacy-')) {
                $protocol = substr($protocol, 7);
            }

            $this->connection->setProtocol($protocol);
        }

        if (ClientManager::get('options.debug')) {
            $this->connection->enableDebug();
        }

        if (! ClientManager::get('options.uid_cache')) {
            $this->connection->disableUidCache();
        }

        try {
            $this->connection->connect($this->host, $this->port);
        } catch (ErrorException|RuntimeException $e) {
            throw new ConnectionFailedException('connection setup failed', 0, $e);
        }

        $this->authenticate();

        return $this;
    }

    /**
     * Authenticate the current session.
     */
    protected function authenticate(): void
    {
        if ($this->authentication == 'oauth') {
            if (! $this->connection->authenticate($this->username, $this->password)->validatedData()) {
                throw new AuthFailedException;
            }
        } elseif (! $this->connection->login($this->username, $this->password)->validatedData()) {
            throw new AuthFailedException;
        }
    }

    /**
     * Disconnect from server.
     */
    public function disconnect(): Client
    {
        if ($this->isConnected()) {
            $this->connection->logout();
        }

        $this->active_folder = null;

        return $this;
    }

    /**
     * Get a folder instance by a folder name.
     */
    public function getFolder(string $folder_name, ?string $delimiter = null, bool $utf7 = false): ?Folder
    {
        // Set delimiter to false to force selection via getFolderByName (maybe useful for uncommon folder names)
        $delimiter = is_null($delimiter) ? ClientManager::get('options.delimiter', '/') : $delimiter;

        if (str_contains($folder_name, (string) $delimiter)) {
            return $this->getFolderByPath($folder_name, $utf7);
        }

        return $this->getFolderByName($folder_name);
    }

    /**
     * Get a folder instance by a folder name.
     *
     * @param  bool  $soft_fail  If true, it will return null instead of throwing an exception
     */
    public function getFolderByName($folder_name, bool $soft_fail = false): ?Folder
    {
        return $this->getFolders(false, null, $soft_fail)
            ->where('name', $folder_name)
            ->first();
    }

    /**
     * Get a folder instance by a folder path.
     *
     * @param  bool  $soft_fail  If true, it will return null instead of throwing an exception
     */
    public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?Folder
    {
        if (! $utf7) {
            $folder_path = EncodingAliases::convert($folder_path, 'utf-8', 'utf7-imap');
        }

        return $this->getFolders(false, null, $soft_fail)
            ->where('path', $folder_path)
            ->first();
    }

    /**
     * Get folders list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return flat array.
     *
     * @param  bool  $soft_fail  If true, it will return an empty collection instead of throwing an exception
     */
    public function getFolders(bool $hierarchical = true, ?string $parent_folder = null, bool $soft_fail = false): FolderCollection
    {
        $this->checkConnection();
        $folders = FolderCollection::make([]);

        $pattern = $parent_folder.($hierarchical ? '%' : '*');
        $items = $this->connection->folders('', $pattern)->validatedData();

        if (! empty($items)) {
            foreach ($items as $folder_name => $item) {
                $folder = new Folder($this, $folder_name, $item['delimiter'], $item['flags']);

                if ($hierarchical && $folder->hasChildren()) {
                    $pattern = $folder->full_name.$folder->delimiter.'%';

                    $children = $this->getFolders(true, $pattern, $soft_fail);
                    $folder->setChildren($children);
                }

                $folders->push($folder);
            }

            return $folders;
        } elseif (! $soft_fail) {
            throw new FolderFetchingException('Failed to fetch any folders');
        }

        return $folders;
    }

    /**
     * Get folders list.
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return flat array.
     *
     * @param  bool  $soft_fail  If true, it will return an empty collection instead of throwing an exception
     */
    public function getFoldersWithStatus(bool $hierarchical = true, ?string $parent_folder = null, bool $soft_fail = false): FolderCollection
    {
        $this->checkConnection();
        $folders = FolderCollection::make([]);

        $pattern = $parent_folder.($hierarchical ? '%' : '*');
        $items = $this->connection->folders('', $pattern)->validatedData();

        if (! empty($items)) {
            foreach ($items as $folder_name => $item) {
                $folder = new Folder($this, $folder_name, $item['delimiter'], $item['flags']);

                if ($hierarchical && $folder->hasChildren()) {
                    $pattern = $folder->full_name.$folder->delimiter.'%';

                    $children = $this->getFoldersWithStatus(true, $pattern, $soft_fail);
                    $folder->setChildren($children);
                }

                $folder->loadStatus();
                $folders->push($folder);
            }

            return $folders;
        } elseif (! $soft_fail) {
            throw new FolderFetchingException('Failed to fetch any folders');
        }

        return $folders;
    }

    /**
     * Open a given folder.
     */
    public function openFolder(string $folder_path, bool $force_select = false): array
    {
        if ($this->active_folder == $folder_path && $this->isConnected() && $force_select === false) {
            return [];
        }

        $this->checkConnection();

        $this->active_folder = $folder_path;

        return $this->connection->selectFolder($folder_path)->validatedData();
    }

    /**
     * Set active folder.
     */
    public function setActiveFolder(?string $folder_path = null): void
    {
        $this->active_folder = $folder_path;
    }

    /**
     * Get active folder.
     */
    public function getActiveFolder(): ?string
    {
        return $this->active_folder;
    }

    /**
     * Create a new Folder.
     */
    public function createFolder(string $folder_path, bool $expunge = true, bool $utf7 = false): Folder
    {
        $this->checkConnection();

        if (! $utf7) {
            $folder_path = EncodingAliases::convert($folder_path, 'utf-8', 'UTF7-IMAP');
        }

        $status = $this->connection->createFolder($folder_path)->validatedData();

        if ($expunge) {
            $this->expunge();
        }

        $folder = $this->getFolderByPath($folder_path, true);

        if ($status && $folder) {
            $event = $this->getEvent('folder', 'new');
            $event::dispatch($folder);
        }

        return $folder;
    }

    /**
     * Delete a given folder.
     */
    public function deleteFolder(string $folder_path, bool $expunge = true): array
    {
        $this->checkConnection();

        $folder = $this->getFolderByPath($folder_path);

        if ($this->active_folder == $folder->path) {
            $this->active_folder = null;
        }

        $status = $this->getConnection()->deleteFolder($folder->path)->validatedData();

        if ($expunge) {
            $this->expunge();
        }

        $event = $this->getEvent('folder', 'deleted');
        $event::dispatch($folder);

        return $status;
    }

    /**
     * Check a given folder.
     */
    public function checkFolder(string $folder_path): array
    {
        $this->checkConnection();

        return $this->connection->examineFolder($folder_path)->validatedData();
    }

    /**
     * Get the current active folder.
     */
    public function getFolderPath(): ?string
    {
        return $this->active_folder;
    }

    /**
     * Exchange identification information
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2971
     */
    public function Id(?array $ids = null): array
    {
        $this->checkConnection();

        return $this->connection->id($ids)->validatedData();
    }

    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     */
    public function getQuota(): array
    {
        $this->checkConnection();

        return $this->connection->getQuota($this->username)->validatedData();
    }

    /**
     * Retrieve the quota settings per user.
     */
    public function getQuotaRoot(string $quota_root = 'INBOX'): array
    {
        $this->checkConnection();

        return $this->connection->getQuotaRoot($quota_root)->validatedData();
    }

    /**
     * Delete all messages marked for deletion.
     */
    public function expunge(): array
    {
        $this->checkConnection();

        return $this->connection->expunge()->validatedData();
    }

    /**
     * Set the connection timeout.
     */
    public function setTimeout(int $timeout): ProtocolInterface
    {
        $this->timeout = $timeout;

        if ($this->isConnected()) {
            $this->connection->setConnectionTimeout($timeout);

            $this->reconnect();
        }

        return $this->connection;
    }

    /**
     * Get the connection timeout.
     */
    public function getTimeout(): int
    {
        $this->checkConnection();

        return $this->connection->getConnectionTimeout();
    }

    /**
     * Get the default message mask.
     */
    public function getDefaultMessageMask(): string
    {
        return $this->default_message_mask;
    }

    /**
     * Get the default events for a given section.
     */
    public function getDefaultEvents($section): array
    {
        if (isset($this->events[$section])) {
            return is_array($this->events[$section]) ? $this->events[$section] : [];
        }

        return [];
    }

    /**
     * Set the default message mask.
     *
     * @throws MaskNotFoundException
     */
    public function setDefaultMessageMask(string $mask): Client
    {
        if (class_exists($mask)) {
            $this->default_message_mask = $mask;

            return $this;
        }

        throw new MaskNotFoundException('Unknown mask provided: '.$mask);
    }

    /**
     * Get the default attachment mask.
     */
    public function getDefaultAttachmentMask(): string
    {
        return $this->default_attachment_mask;
    }

    /**
     * Set the default attachment mask.
     *
     * @throws MaskNotFoundException
     */
    public function setDefaultAttachmentMask(string $mask): Client
    {
        if (class_exists($mask)) {
            $this->default_attachment_mask = $mask;

            return $this;
        }

        throw new MaskNotFoundException('Unknown mask provided: '.$mask);
    }
}
