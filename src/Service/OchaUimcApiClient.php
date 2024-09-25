<?php

declare(strict_types=1);

namespace Drupal\mymodule\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Client service for the UIMC API.
 */
class OchaUimcApiClient {

  /**
   * Constructs a new AccessTokenManager object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    KeyRepositoryInterface $keyRepository,
    LoggerChannelFactoryInterface $loggerFactory,
    TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function registerAccount(string $first_name, string $last_name, string $email): bool {
    try {
      $config = $this->config();

      $registration_url = $config->get('registration_url');
      $verify_ssl = !empty($config->get('verify_ssl'));

      $access_token = $this->getAccessToken();

      $post_data = [
        'fistName' => $first_name,
        'lastName' => $last_name,
        'email' => $email,
        'sendEmail' => TRUE,
      ];

      $response = $this->httpClient()->request('POST', $registration_url, [
        'headers' => [
          'Authorization' => "Bearer $access_token",
          'Content-Type' => 'application/json',
        ],
        'json' => $post_data,
        'verify' => $verify_ssl,
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Error while registering account: @message', [
          '@message' => (string) $response->getBody()->getContents(),
        ]);
      }

      return TRUE;
    }
    catch (\Exception $exception) {
      $this->logger()->error('Unable to register account: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
    return FALSE;
  }

  /**
   * Retrieves the access token, refreshing if necessary.
   *
   * @return string|null
   *   The access token, or null if retrieval fails.
   */
  protected function getAccessToken(): ?string {
    $key = $this->keyRepository->getKey('ocha_uimc_api_access_token');
    $token_data = $key ? json_decode($key->getKeyValue(), TRUE) : NULL;

    if (!$token_data || $this->isTokenExpired($token_data)) {
      return $this->refreshAccessToken();
    }

    return $token_data['access_token'];
  }

  /**
   * Checks if the token is expired.
   *
   * @param array $token_data
   *   The token data array.
   *
   * @return bool
   *   TRUE if the token is expired, FALSE otherwise.
   */
  protected function isTokenExpired(array $token_data): bool {
    $expiration_time = $token_data['created'] + $token_data['expires_in'];
    return $this->time()->getCurrentTime() >= $expiration_time;
  }

  /**
   * Refreshes the access token.
   *
   * @return string|null
   *   The new access token, or null if refresh fails.
   */
  protected function refreshAccessToken(): ?string {
    try {
      $config = $this->config();

      $token_url = $config->get('token_url');
      $username = $config->get('username');
      $password = $config->get('password');
      $consumer_key = $config->get('consumer_key');
      $consumer_secret = $config->get('consumer_secret');
      $verify_ssl = !empty($config->get('verify_ssl'));

      if (empty($token_url) || empty($username) || empty($password) || empty($consumer_key) || empty($consumer_secret)) {
        throw new \Exception('Invalid configuration.');
      }

      $auth_string = base64_encode("$consumer_key:$consumer_secret");
      $post_data = [
        'grant_type' => 'password',
        'username' => $username,
        'password' => $password,
      ];

      $response = $this->httpClient()->request('POST', $token_url, [
        'headers' => [
          'Authorization' => "Basic $auth_string",
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => $post_data,
        'verify' => $verify_ssl,
      ]);

      $body = json_decode($response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
      if (empty($body['access_token']) || empty($body['expires_in'])) {
        throw new \Exception('Invalid token.');
      }

      $token_data = [
        'access_token' => $body['access_token'],
        'expires_in' => $body['expires_in'],
        'created' => $this->time()->getCurrentTime(),
      ];

      $this->storeAccessToken($token_data);

      return $token;
    }
    catch (\Exception $exception) {
      $this->logger()->error('API token retrieval failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Stores the access token securely.
   *
   * @param array $token_data
   *   The token data to store.
   */
  protected function storeAccessToken(array $token_data) {
    $key = $this->keyRepository()->getKey('api_access_token');
    if ($key) {
      $key->setKeyValue(json_encode($token_data));
    }
    else {
      $this->logger()->error('Failed to store access token: Key not found.');
    }
  }

  /**
   * Get the key factory.
   *
   * @return \Drupal\key\KeyRepositoryInterface
   *   The key factory.
   */
  protected function keyFactory(): KeyRepositoryInterface {
    return $this->keyFactory;
  }

  /**
   * Get a config.
   *
   * @param string $name
   *   The config name.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config.
   */
  protected function config(string $name = 'ocha_uimc.settings'): ImmutableConfig {
    return $this->configFactory->get($name);
  }

  /**
   * Get the logger channel.
   *
   * @param string $name
   *   The logger channel name.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   *   The logger channel.
   */
  protected function logger(string $name = 'ocha_uimc'): LoggerChannelInterface {
    return $this->loggerFactory->get($name);
  }

  /**
   * Get the HTTP client.
   *
   * @return \GuzzleHttp\ClientInterface
   *   The HTTP client.
   */
  protected function httpClient(): ClientInterface {
    return $this->httpClient;
  }

  /**
   * Get the time service.
   *
   * @return \Drupal\Component\Datetime\TimeInterface
   *   The time service.
   */
  protected function time(): TimeInterface {
    return $this->time;
  }

}
