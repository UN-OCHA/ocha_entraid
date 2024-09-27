<?php

declare(strict_types=1);

namespace Drupal\ocha_uimc\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Client service for the UIMC API.
 */
class OchaUimcApiClient implements OchaUimcApiClientInterface {

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
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function registerAccount(string $first_name, string $last_name, string $email): bool {
    try {
      $config = $this->config();
      $registration_url = $config->get('registration_url');
      $send_email = !empty($config->get('send_email'));
      $verify_ssl = !empty($config->get('verify_ssl'));
      $timeout = $config->get('request_timeout') ?? 10;

      if (empty($registration_url)) {
        throw new \Exception('Invalid configuration.');
      }

      $access_token = $this->getAccessToken();
      if (empty($access_token)) {
        throw new \Exception('Missing access token.');
      }

      $post_data = [
        'firstName' => $first_name,
        'lastName' => $last_name,
        'email' => $email,
        'sendEmail' => $send_email,
      ];

      $response = $this->httpClient()->request('POST', $registration_url, [
        'headers' => [
          'Authorization' => "Bearer $access_token",
          'Content-Type' => 'application/json; charset=utf-8',
        ],
        'json' => $post_data,
        'verify' => $verify_ssl,
        'timeout' => $timeout,
      ]);

      $response_body = (string) $response->getBody()->getContents();

      // A failure can be due to many things included an invalid or expired
      // access token.
      if ($response->getStatusCode() !== 200) {
        throw new \Exception(strtr('Error while registering account: @message', [
          '@message' => $response_body,
        ]), $response->getStatusCode());
      }

      // We only try to decode the response body if the request was successful.
      // In case of failure, the output may not be in JSON. For example, when
      // access to the endpoint is not allowed (ex: expired access token), then
      // the body is a SOAP message...
      $message = json_decode($response_body, TRUE);
      $message_code = $message['code'] ?? 0;

      // Unless there is a server error, the API response code is 200 but it
      // doesn't mean the registration was accepted. We need to check the code
      // in the response's message itself.
      if ($message_code !== 200) {
        throw new \Exception(strtr('Error while registering account: @message', [
          '@message' => $response_body,
        ]), $message_code);
      }

      return TRUE;
    }
    // Log any error message.
    catch (\Exception $exception) {
      // Guzzle truncates the response message in case of error but it can
      // be retrieved with via the attached response object.
      if ($exception instanceof RequestException && $exception->hasResponse()) {
        $message = (string) $exception->getResponse()->getBody()->getContents();
      }
      else {
        $message = $exception->getMessage();
      }

      // Ensure we don't leak the access token.
      if (isset($access_token)) {
        $message = strtr($message, [
          $access_token ?? 'ACCESS_TOKEN' => 'REDACTED_ACCESS_TOKEN',
        ]);
      }

      $this->logger()->error('Unable to register account: @code - @message', [
        '@code' => $exception->getCode(),
        '@message' => $message,
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
      $timeout = $config->get('request_timeout') ?? 10;

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
        'timeout' => $timeout,
      ]);

      $response_body = (string) $response->getBody()->getContents();

      // Try to decode the body to retrieve the access_token.
      $body = json_decode($response_body, TRUE, flags: \JSON_THROW_ON_ERROR);
      if (empty($body['access_token']) || empty($body['expires_in'])) {
        throw new \Exception('Invalid or missing access token.');
      }

      $token_data = [
        'access_token' => $body['access_token'],
        'expires_in' => $body['expires_in'],
        'created' => $this->time()->getCurrentTime(),
      ];

      // @todo the `expires_in` property seems buggy. It's like 6 months but
      // actually the token is only valid for a very short duration. This may
      // only be on the test environment and will need further investigation.
      $this->storeAccessToken($token_data);

      return $body['access_token'];
    }
    // Log any error message.
    catch (\Exception $exception) {
      // Guzzle truncates the response message in case of error but it can
      // be retrieved with via the attached response object.
      if ($exception instanceof RequestException && $exception->hasResponse()) {
        $message = (string) $exception->getResponse()->getBody()->getContents();
      }
      else {
        $message = $exception->getMessage();
      }

      // Ensure we don't leak credentials.
      $message = strtr($message, [
        $username ?? 'USERNAME' => 'REDACTED_USERNAME',
        $password ?? 'PASSWORD' => 'REDACTED_PASSWORD',
        $auth_string ?? 'AUTH_STRING' => 'REDACTED_AUTHSTRING',
      ]);

      $this->logger()->error('API token retrieval failed:  @code - @message', [
        '@code' => $exception->getCode(),
        '@message' => $message,
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
    $key = $this->keyRepository()->getKey('ocha_uimc_api_access_token');
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
  protected function keyRepository(): KeyRepositoryInterface {
    return $this->keyRepository;
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
