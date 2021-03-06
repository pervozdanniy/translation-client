<?php

namespace Translate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use function array_merge_recursive;

class ApiClient
{
    use ResolvesAliases;

    public const DEFAULT_API_URL = 'http://dev-api.translate.center/api/v1/';

    /**
     * @var array
     */
    protected $options;

    /**
     * @var CacheInterface
     */
    protected $storage;

    /**
     * @var Client|null
     */
    protected $httpClient;

    /**
     * @param array $options
     * @param CacheInterface $storage
     */
    public function __construct(array $options, CacheInterface $storage)
    {
        $this->processOptions($options);
        $this->storage = $storage;
    }

    /**
     * @param array $options
     */
    protected function processOptions(array $options): void
    {
        if (!isset($options['login'])) {
            throw new \InvalidArgumentException('Login is required!');
        }
        if (!isset($options['password'])) {
            throw new \InvalidArgumentException('Password is required!');
        }
        $options['http']['base_uri'] = $options['api'] ?? static::DEFAULT_API_URL;
        $options['maxAttempts'] = $options['maxAttempts'] ?? 3;

        $this->options = $options;
    }

    /**
     * @return Client
     */
    public function httpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client($this->options['http']);
        }
        return $this->httpClient;
    }

    /**
     * Sends authenticated request to API
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        static $attempt = 0;
        $this->ensureAuth();

        try {
            $response = $this->httpClient()->request(
                $method,
                $this->resolveAliases($uri),
                array_merge_recursive($this->getDefaultOptions(), $options)
            );
        } catch (RequestException $exception) {
            if ($attempt < $this->options['maxAttempts'] && $exception->getCode() === 401) {
                ++$attempt;
                return $this->reauthenticate()->request($method, $uri, $options);
            }

            throw $exception;
        }
        $attempt = 0;

        return $response;
    }

    /**
     * @return array
     */
    protected function getDefaultOptions(): array
    {
        return [
            'headers' => [
                'Authorization' => $this->resolveAliases('{authToken}'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return PromiseInterface
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function requestAsync(string $method, string $uri, array $options = []): PromiseInterface
    {
        $this->ensureAuth();
        $options = array_merge_recursive($this->getDefaultOptions(), $options);

        return $this->httpClient()->requestAsync($method, $this->resolveAliases($uri), $options);
    }

    /**
     * Sends request without any data manipulation
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    public function rawRequest(string $method, string $uri, array $options = []): ResponseInterface
    {
        return $this->httpClient()->request($method, $uri, $options);
    }

    /**
     * @param bool $forceReauthenticate
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    protected function ensureAuth($forceReauthenticate = false): void
    {
        if (!$forceReauthenticate && $this->hasAlias('authToken') && $this->hasAlias('userUuid')) {
            return;
        }

        $resp = $this->httpClient()->request('POST', 'login', [
            'json' => [
                'login' => $this->options['login'],
                'password' => $this->options['password']
            ]
        ]);

        $data = \GuzzleHttp\json_decode($resp->getBody(), true);
        $this->setAlias('authToken', $data['authToken']);
        $this->setAlias('userUuid', $data['userUuid']);
    }

    /**
     * @return $this
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function reauthenticate(): self
    {
        $this->ensureAuth(true);

        return $this;
    }
}
