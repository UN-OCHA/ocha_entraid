<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ocha_entraid\Exception\AccountNotFoundException;
use Drupal\ocha_entraid\Helper\EncryptionHelper;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Client service for the UIMC API.
 */
class UimcApiClient implements UimcApiClientInterface {

  /**
   * Constructs a new AccessTokenManager object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function registerAccount(string $first_name, string $last_name, string $email): bool {
    try {
      $config = $this->config();
      $registration_url = $config->get('uimc_api.registration_url');
      $send_email = !empty($config->get('uimc_api.send_email'));
      $verify_ssl = !empty($config->get('uimc_api.verify_ssl'));
      $timeout = $config->get('uimc_api.request_timeout') ?? 10;

      if (empty($registration_url)) {
        throw new \Exception('Missing registration URL.');
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
        throw new \Exception($response_body, $response->getStatusCode());
      }

      // We only try to decode the response body if the request was successful.
      // In case of failure, the output may not be in JSON. For example, when
      // access to the endpoint is not allowed (ex: expired access token), then
      // the body is a SOAP message...
      $message = json_decode($response_body, TRUE);
      $message_code = $message['code'] ?? 0;
      $message_message = $message['message'] ?? '';

      // Unless there is a server error, the API responsåe code is 200 but it
      // doesn't mean the registration was accepted. We need to check the code
      // in the response's message itself.
      // The message code is 200 whether the account already exists or not.
      $exception = match (TRUE) {
        // The registration was successfully accepted. It doesn't mean it was
        // successful though because it's done asynchronously but that's the
        // best we can get.
        $message_code === 200 => NULL,
        // Any other problem.
        default => new \Exception($message_message ?: $response_body, $message_code),
      };

      if (!empty($exception)) {
        throw $exception;
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

      $this->logger()->error(strtr('Unable to register account: @code - @message', [
        '@code' => $exception->getCode(),
        '@message' => $message,
      ]));

      // Throw a generic exception.
      throw new \Exception('Unable to register account.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addAccountToGroup(string $email, ?string $group = NULL): bool {
    try {
      $config = $this->config();
      $group_management_url = $config->get('uimc_api.group_management_url');
      $verify_ssl = !empty($config->get('uimc_api.verify_ssl'));
      $timeout = $config->get('uimc_api.request_timeout') ?? 10;
      $group ??= $config->get('uimc_api.default_group');

      if (empty($group_management_url)) {
        throw new \Exception('Missing group management URL.');
      }

      if (empty($group) || !is_string($group)) {
        throw new \Exception('Missing or invalid group.');
      }

      $access_token = $this->getAccessToken();
      if (empty($access_token)) {
        throw new \Exception('Missing access token.');
      }

      $post_data = [
        'email' => $email,
        'groupName' => $group,
      ];

      $response = $this->httpClient()->request('POST', $group_management_url, [
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
        throw new \Exception($response_body, $response->getStatusCode());
      }

      // We only try to decode the response body if the request was successful.
      // In case of failure, the output may not be in JSON. For example, when
      // access to the endpoint is not allowed (ex: expired access token), then
      // the body is a SOAP message...
      $message = json_decode($response_body, TRUE);
      $message_code = (int) ($message['code'] ?? 0);
      $message_message = $message['message'] ?? '';

      // Unless there is a server error, the API response code is 200 but it
      // doesn't mean the registration was accepted. We need to check the code
      // in the response's message itself.
      $exception = match (TRUE) {
        // The account was successfully added to the group.
        $message_code === 204 => NULL,
        // The account is already in the group.
        $message_code === 400 && $message_message === 'User already member of given group' => NULL,
        // The account was not found.
        $message_code === 400 && $message_message === 'User not found' => new AccountNotFoundException($message_message . ': ' . $email),
        // Any other problem.
        default => new \Exception($message_message ?: $response_body, $message_code),
      };

      if (!empty($exception)) {
        throw $exception;
      }

      return TRUE;
    }
    // Account not found is not an issue per se, so we handle it separately.
    catch (AccountNotFoundException $exception) {
      $this->logger()->notice($exception->getMessage());
      // Rethrow the exception.
      throw $exception;
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

      $this->logger()->error(strtr('Unable to add account to group: @code - @message', [
        '@code' => $exception->getCode(),
        '@message' => $message,
      ]));

      // Throw a generic exception.
      throw new \Exception('Unable to add account to group.');
    }
  }

  /**
   * Retrieve the access token, refreshing if necessary.
   *
   * @return string|null
   *   The access token, or null if retrieval fails.
   */
  protected function getAccessToken(): ?string {
    $token_data = $this->retrieveAccessTokenData();

    if (!$token_data || $this->isTokenExpired($token_data)) {
      return $this->refreshAccessToken();
    }

    return $token_data['access_token'];
  }

  /**
   * Check if the token is expired.
   *
   * @param array $token_data
   *   The token data array.
   *
   * @return bool
   *   TRUE if the token is expired, FALSE otherwise.
   */
  protected function isTokenExpired(array $token_data): bool {
    // @todo review because tokens received from the UIMC API seems to be
    // usable for a much short period than what `expires_in` indicates.
    $expiration_time = $token_data['created'] + $token_data['expires_in'];
    return $this->time()->getCurrentTime() >= $expiration_time;
  }

  /**
   * Refresh the access token.
   *
   * @return string|null
   *   The new access token, or null if refresh fails.
   */
  protected function refreshAccessToken(): ?string {
    try {
      $config = $this->config();
      $token_url = $config->get('uimc_api.token_url');
      $username = $config->get('uimc_api.username');
      $password = $config->get('uimc_api.password');
      $consumer_key = $config->get('uimc_api.consumer_key');
      $consumer_secret = $config->get('uimc_api.consumer_secret');
      $verify_ssl = !empty($config->get('uimc_api.verify_ssl'));
      $timeout = $config->get('uimc_api.request_timeout') ?? 10;

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
      $this->storeAccessTokenData($token_data);

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

      $this->logger()->error(strtr('API token retrieval failed:  @code - @message', [
        '@code' => $exception->getCode(),
        '@message' => $message,
      ]));
    }

    return NULL;
  }

  /**
   * Store the access token securely.
   *
   * @param array $token_data
   *   The token data to store.
   *
   * @return bool
   *   TRUE if the token could be stored.
   */
  protected function storeAccessTokenData(array $token_data): bool {
    try {
      $key = $this->getEncryptionKey();
      $encrypted_data = EncryptionHelper::encrypt(json_encode($token_data), $key);
      $this->state()->set('ocha_entraid.uimc_api_access_token', $encrypted_data);
      return TRUE;
    }
    catch (\Exception $exception) {
      $this->logger()->error(strtr('Failed to store access token: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return FALSE;
    }
  }

  /**
   * Retrieve the stored token data.
   *
   * @return array|null
   *   The token data, or NULL if not found or decryption fails.
   */
  protected function retrieveAccessTokenData(): ?array {
    $encrypted_data = $this->state()->get('ocha_entraid.uimc_api_access_token');
    if (!$encrypted_data) {
      return NULL;
    }

    try {
      $key = $this->getEncryptionKey();
      $decrypted_data = EncryptionHelper::decrypt($encrypted_data, $key);
      return json_decode($decrypted_data, TRUE, 512, \JSON_THROW_ON_ERROR);
    }
    catch (\Exception $exception) {
      $this->logger()->error(strtr('Failed to retrieve access token: @message', [
        '@message' => $exception->getMessage(),
      ]));
      return NULL;
    }
  }

  /**
   * Retrieve the encryption key from the configuration.
   *
   * @return string
   *   The encryption key.
   *
   * @throws \Exception
   */
  protected function getEncryptionKey(): string {
    $key = $this->config()->get('uimc_api.encryption_key');
    if (empty($key)) {
      throw new \Exception('Encryption key not found in configuration.');
    }
    return $key;
  }

  /**
   * Get the state service.
   *
   * @return \Drupal\Core\State\StateInterface
   *   The state service.
   */
  protected function state(): StateInterface {
    return $this->state;
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
  protected function config(string $name = 'ocha_entraid.settings'): ImmutableConfig {
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
  protected function logger(string $name = 'ocha_entraid'): LoggerChannelInterface {
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
