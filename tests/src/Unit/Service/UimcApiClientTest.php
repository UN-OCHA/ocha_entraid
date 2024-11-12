<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_entraid\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ocha_entraid\Exception\AccountNotFoundException;
use Drupal\ocha_entraid\Helper\EncryptionHelper;
use Drupal\ocha_entraid\Service\UimcApiClient;
use Drupal\ocha_entraid\Service\UimcApiClientInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Test the OCHA UIMC API client service.
 *
 * @coversDefaultClass \Drupal\ocha_entraid\Service\UimcApiClient
 * @group ocha_entraid
 */
class UimcApiClientTest extends UnitTestCase {

  /**
   * The OCHA UIMC API client.
   *
   * @var \Drupal\ocha_entraid\Service\UimcApiClientInterface
   */
  protected UimcApiClientInterface $apiClient;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected ClientInterface|ObjectProphecy $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected ConfigFactoryInterface|ObjectProphecy $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected StateInterface|ObjectProphecy $state;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected LoggerChannelFactoryInterface|ObjectProphecy $loggerFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected TimeInterface|ObjectProphecy $time;

  /**
   * The random encryption key for the current test.
   *
   * @var string
   */
  protected string $encryptionKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->encryptionKey = $this->getRandomEncryptionKey(TRUE);
    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->state = $this->prophesize(StateInterface::class);
    $this->time = $this->prophesize(TimeInterface::class);

    $this->apiClient = new UimcApiClient(
      $this->httpClient->reveal(),
      $this->configFactory->reveal(),
      $this->loggerFactory->reveal(),
      $this->state->reveal(),
      $this->time->reveal()
    );
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithInvalidConfiguration(): void {
    $this->setTestConfig(registration_url: NULL);

    $this->setLogger(TRUE, 'Unable to register account: 0 - Missing registration URL.');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to register account.');
    $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithNoAccessToken(): void {
    $this->setTestConfig();

    $this->state->get('ocha_entraid.uimc_api_access_token')->willReturn(NULL);

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(400, [], NULL));

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to register account.');
    $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithApiError(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willReturn(new Response(400, [], '{"code": 400, "message": "Bad Request"}'));

    $this->setLogger(TRUE, 'Unable to register account: 400 - {"code": 400, "message": "Bad Request"}');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to register account.');
    $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithInvalidResponse(): void {
    $this->setTestConfig();

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(200, [], '{"invalid_response": true}'));

    $this->setLogger(TRUE);

    $method = new \ReflectionMethod(UimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertNull($result);
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithRequestException(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $exception = new RequestException('Error Communicating with Server', new Request('POST', 'test'), new Response(500, [], 'Server Error'));
    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willThrow($exception);

    $this->setLogger(TRUE, 'Unable to register account: 500 - Server Error');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to register account.');
    $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithTimeout(): void {
    $this->setTestConfig(request_timeout: 1);

    $this->setTestAccessToken();

    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willThrow(new ConnectException('Connection timed out', new Request('POST', 'test')));

    $this->setLogger(TRUE, 'Unable to register account: 0 - Connection timed out');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to register account.');
    $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithNon200MessageCode(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willReturn(new Response(200, [], '{"code": 409, "message": "User already exists"}'));

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to register account.');
    $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountSuccess(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->setLogger(FALSE);

    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willReturn(new Response(200, [], '{"code": 200, "message": "Success"}'));

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertTrue($result);
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupAlreadyMember(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->setLogger(FALSE);

    $this->httpClient->request('POST', 'https://api.example.com/group', Argument::any())
      ->willReturn(new Response(200, [], '{"code": 400, "message": "' . UimcApiClient::USER_ALREADY_IN_GROUP . '"}'));

    $result = $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
    $this->assertTrue($result);
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupAccountNotFound(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->setLogger(FALSE);

    $this->httpClient->request('POST', 'https://api.example.com/group', Argument::any())
      ->willReturn(new Response(200, [], '{"code": 400, "message": "' . UimcApiClient::USER_NOT_FOUND . '"}'));

    $this->expectException(AccountNotFoundException::class);
    $this->expectExceptionMessage('User not found: nonexistent@example.com');
    $this->apiClient->addAccountToGroup('nonexistent@example.com', 'test_group');
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupWithInvalidConfiguration(): void {
    $this->setTestConfig(group_management_url: NULL);

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to add account to group.');
    $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupWithNoAccessToken(): void {
    $this->setTestConfig();

    $this->state->get('ocha_entraid.uimc_api_access_token')->willReturn(NULL);

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(400, [], NULL));

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to add account to group.');
    $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupWithApiError(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->httpClient->request('POST', 'https://api.example.com/group', Argument::any())
      ->willReturn(new Response(500, [], '{"code": 500, "message": "Internal Server Error"}'));

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to add account to group.');
    $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupWithRequestException(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $exception = new RequestException('Error Communicating with Server', new Request('POST', 'test'), new Response(500, [], 'Server Error'));
    $this->httpClient->request('POST', 'https://api.example.com/group', Argument::any())
      ->willThrow($exception);

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to add account to group.');
    $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupWithTimeout(): void {
    $this->setTestConfig(request_timeout: 1);

    $this->setTestAccessToken();

    $this->httpClient->request('POST', 'https://api.example.com/group', Argument::any())
      ->willThrow(new ConnectException('Connection timed out', new Request('POST', 'test')));

    $this->setLogger(TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Unable to add account to group.');
    $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
  }

  /**
   * @covers ::addAccountToGroup
   */
  public function testAddAccountToGroupSuccess(): void {
    $this->setTestConfig();

    $this->setTestAccessToken();

    $this->setLogger(FALSE);

    $this->httpClient->request('POST', 'https://api.example.com/group', Argument::any())
      ->willReturn(new Response(200, [], '{"code": 204, "message": "Success"}'));

    $result = $this->apiClient->addAccountToGroup('john.doe@example.com', 'test_group');
    $this->assertTrue($result);
  }

  /**
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenWithExpiredToken(): void {
    $this->setTestConfig();

    $this->setTestAccessToken([
      'access_token' => 'expired_token',
      'expires_in' => 3600,
      'created' => time() - 4000,
    ]);

    $this->setLogger(FALSE);

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(200, [], '{"access_token": "new_token", "expires_in": 3600}'));

    $this->state->set('ocha_entraid.uimc_api_access_token', Argument::type('string'))->shouldBeCalled();

    $method = new \ReflectionMethod(UimcApiClient::class, 'getAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals('new_token', $result);
  }

  /**
   * @covers ::getAccessToken
   */
  public function testGetAccessToken(): void {
    $this->setTestConfig();

    $this->setTestAccessToken([
      'access_token' => 'test_token',
      'expires_in' => 3600,
      'created' => time(),
    ]);

    $method = new \ReflectionMethod(UimcApiClient::class, 'getAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals('test_token', $result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithInvalidConfiguration(): void {
    $this->setTestConfig(token_url: NULL);

    $this->setLogger(TRUE);

    $method = new \ReflectionMethod(UimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertNull($result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithJsonDecodeError(): void {
    $this->setTestConfig();

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(200, [], 'Invalid JSON'));

    $this->setLogger(TRUE);

    $method = new \ReflectionMethod(UimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertNull($result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithNon200StatusCode(): void {
    $this->setTestConfig();

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(401, [], '{"error": "unauthorized"}'));

    $this->setLogger(TRUE);

    $method = new \ReflectionMethod(UimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertNull($result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithNonRequestException(): void {
    $this->setTestConfig();

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willThrow(new \RuntimeException('Unexpected error'));

    $this->setLogger(TRUE);

    $method = new \ReflectionMethod(UimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertNull($result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessToken(): void {
    $this->setTestConfig();

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(200, [], '{"access_token": "new_token", "expires_in": 3600}'));

    // Expect the state to be set with the new token data.
    $this->state->set('ocha_entraid.uimc_api_access_token', Argument::type('string'))->shouldBeCalled();

    $this->setLogger(FALSE);

    $this->time->getCurrentTime()->willReturn(time());

    $method = new \ReflectionMethod(UimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals('new_token', $result);

    // Verify that the state was set with encrypted data.
    $encryption_key = $this->getRandomEncryptionKey();
    $this->state->set('ocha_entraid.uimc_api_access_token', Argument::that(function ($value) use ($encryption_key) {
      try {
        $decrypted = EncryptionHelper::decrypt($value, $encryption_key);
        $data = json_decode($decrypted, TRUE);
        return isset($data['access_token']) && $data['access_token'] === 'new_token';
      }
      catch (\Exception $exception) {
        return FALSE;
      }
    }))->shouldHaveBeenCalled();
  }

  /**
   * @covers ::isTokenExpired
   */
  public function testIsTokenExpiredEdgeCase(): void {
    $this->time->getCurrentTime()->willReturn(1000);

    $method = new \ReflectionMethod(UimcApiClient::class, 'isTokenExpired');
    $method->setAccessible(TRUE);

    $token_data = [
      'created' => 500,
      'expires_in' => 500,
    ];
    $result = $method->invoke($this->apiClient, $token_data);
    $this->assertTrue($result);
  }

  /**
   * @covers ::isTokenExpired
   */
  public function testIsTokenExpired(): void {
    $this->time->getCurrentTime()->willReturn(1000);

    $method = new \ReflectionMethod(UimcApiClient::class, 'isTokenExpired');
    $method->setAccessible(TRUE);

    $token_data = [
      'created' => 500,
      'expires_in' => 400,
    ];
    $result = $method->invoke($this->apiClient, $token_data);
    $this->assertTrue($result);

    $token_data = [
      'created' => 500,
      'expires_in' => 600,
    ];
    $result = $method->invoke($this->apiClient, $token_data);
    $this->assertFalse($result);
  }

  /**
   * @covers ::storeAccessTokenData
   */
  public function testStoreAccessTokenData(): void {
    $this->setTestConfig();

    $token_data = [
      'access_token' => 'new_token',
      'expires_in' => 3600,
      'created' => time(),
    ];

    $this->state->set('ocha_entraid.uimc_api_access_token', Argument::type('string'))->shouldBeCalled();

    $this->setLogger(FALSE);

    $method = new \ReflectionMethod(UimcApiClient::class, 'storeAccessTokenData');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient, $token_data);
    $this->assertTrue($result);
  }

  /**
   * @covers ::retrieveAccessTokenData
   */
  public function testRetrieveAccessTokenData(): void {
    $this->setTestConfig();

    $token_data = $this->setTestAccessToken([
      'access_token' => 'test_token',
      'expires_in' => 3600,
      'created' => time(),
    ]);

    $method = new \ReflectionMethod(UimcApiClient::class, 'retrieveAccessTokenData');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals($token_data, $result);
  }

  /**
   * @covers ::getEncryptionKey
   */
  public function testGetEncryptionKey(): void {
    $encryption_key = $this->getRandomEncryptionKey();

    $this->setTestConfig(encryption_key: $encryption_key);

    $method = new \ReflectionMethod(UimcApiClient::class, 'getEncryptionKey');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals($encryption_key, $result);
  }

  /**
   * @covers ::getEncryptionKey
   */
  public function testGetEncryptionKeyMissing(): void {
    $this->setTestConfig(encryption_key: NULL);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Encryption key not found in configuration.');

    $method = new \ReflectionMethod(UimcApiClient::class, 'getEncryptionKey');
    $method->setAccessible(TRUE);
    $method->invoke($this->apiClient);
  }

  /**
   * Helper method to get the encryption key.
   *
   * @param bool $reset
   *   Reset the encryption key if any.
   *
   * @return string
   *   The encryption key.
   */
  private function getRandomEncryptionKey(bool $reset = FALSE): string {
    if ($reset || !isset($this->encryptionKey)) {
      $this->encryptionKey = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
    return $this->encryptionKey;
  }

  /**
   * Set the test configuration.
   *
   * @param string|null $token_url
   *   The token_url config setting.
   * @param string|null $registration_url
   *   The registration_url config setting.
   * @param string|null $group_management_url
   *   The group_management_url config setting.
   * @param string|null $username
   *   The username config setting.
   * @param string|null $password
   *   The password config setting.
   * @param string|null $consumer_key
   *   The consumer_key config setting.
   * @param string|null $consumer_secret
   *   The consumer_secret config setting.
   * @param bool|null $send_email
   *   The send_email config setting.
   * @param bool|null $verify_ssl
   *   The verify_ssl config setting.
   * @param int|null $request_timeout
   *   The request_timeout config setting.
   * @param string|null $encryption_key
   *   The encryption_key config setting.
   */
  protected function setTestConfig(
    ?string $token_url = 'https://api.example.com/token',
    ?string $registration_url = 'https://api.example.com/register',
    ?string $group_management_url = 'https://api.example.com/group',
    ?string $username = 'test_user',
    ?string $password = 'test_pass',
    ?string $consumer_key = 'test_key',
    ?string $consumer_secret = 'test_secret',
    ?bool $send_email = FALSE,
    ?bool $verify_ssl = FALSE,
    ?int $request_timeout = 10,
    ?string $encryption_key = '',
  ): void {
    $encryption_key = $encryption_key === '' ? $this->getRandomEncryptionKey() : $encryption_key;

    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('uimc_api.token_url')->willReturn($token_url);
    $config->get('uimc_api.registration_url')->willReturn($registration_url);
    $config->get('uimc_api.group_management_url')->willReturn($group_management_url);
    $config->get('uimc_api.username')->willReturn($username);
    $config->get('uimc_api.password')->willReturn($password);
    $config->get('uimc_api.consumer_key')->willReturn($consumer_key);
    $config->get('uimc_api.consumer_secret')->willReturn($consumer_secret);
    $config->get('uimc_api.verify_ssl')->willReturn($verify_ssl);
    $config->get('uimc_api.request_timeout')->willReturn($request_timeout);
    $config->get('uimc_api.send_email')->willReturn($send_email);
    $config->get('uimc_api.encryption_key')->willReturn($encryption_key);

    $this->configFactory->get('ocha_entraid.settings')->willReturn($config->reveal());
  }

  /**
   * Helper method to set up a test access token.
   *
   * @param ?array $token_data
   *   The token data.
   * @param ?string $encryption_key
   *   The encryption key. If NULL, try to get it from the configuration.
   *
   * @return array
   *   The token data.
   */
  private function setTestAccessToken(
    ?array $token_data = NULL,
    ?string $encryption_key = NULL,
  ): array {
    $token_data ??= [
      'access_token' => 'valid_token',
      'expires_in' => 3600,
      'created' => time(),
    ];

    $encryption_key ??= $this->getRandomEncryptionKey();

    $encrypted_token_data = EncryptionHelper::encrypt(json_encode($token_data), $encryption_key);

    $this->state->get('ocha_entraid.uimc_api_access_token')->willReturn($encrypted_token_data);

    $this->time->getCurrentTime()->willReturn(time());

    return $token_data;
  }

  /**
   * Set the default logger.
   *
   * @param bool $error
   *   Whether to log an error or not.
   * @param ?string $message
   *   Optional error message.
   */
  protected function setLogger(bool $error = TRUE, ?string $message = NULL): void {
    $logger = $this->prophesize(LoggerChannelInterface::class);
    if ($error) {
      $logger->error($message ?? Argument::cetera())->shouldBeCalled();
    }
    $this->loggerFactory->get('ocha_entraid')->willReturn($logger->reveal());
  }

}
