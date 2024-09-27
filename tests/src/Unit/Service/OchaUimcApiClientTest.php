<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_uimc\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ocha_uimc\Service\OchaUimcApiClient;
use Drupal\ocha_uimc\Service\OchaUimcApiClientInterface;
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
 * @coversDefaultClass \Drupal\ocha_uimc\Service\OchaUimcApiClient
 * @group ocha_uimc
 */
class OchaUimcApiClientTest extends UnitTestCase {

  /**
   * The OCHA UIMC API client.
   *
   * @var \Drupal\ocha_uimc\Service\OchaUimcApiClientInterface
   */
  protected OchaUimcApiClientInterface $apiClient;

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
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected KeyRepositoryInterface|ObjectProphecy $keyRepository;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->keyRepository = $this->prophesize(KeyRepositoryInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->time = $this->prophesize(TimeInterface::class);

    $this->apiClient = new OchaUimcApiClient(
      $this->httpClient->reveal(),
      $this->configFactory->reveal(),
      $this->keyRepository->reveal(),
      $this->loggerFactory->reveal(),
      $this->time->reveal()
    );
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithInvalidConfiguration(): void {
    $this->setTestConfig(registration_url: NULL);

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertFalse($result);
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithNoAccessToken(): void {
    $this->setTestConfig();

    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn(NULL);

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(400, [], NULL));

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertFalse($result);
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithApiError(): void {
    $this->setTestConfig();

    $key = $this->prophesize(KeyInterface::class);
    $key->getKeyValue()->willReturn(json_encode([
      'access_token' => 'valid_token',
      'expires_in' => 3600,
      'created' => time(),
    ]));
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($key->reveal());

    $this->time->getCurrentTime()->willReturn(time());

    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willReturn(new Response(400, [], '{"code": 400, "message": "Bad Request"}'));

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertFalse($result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithInvalidResponse(): void {
    $this->setTestConfig();

    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(200, [], '{"invalid_response": true}'));

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertNull($result);
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithRequestException(): void {
    $this->setTestConfig();

    $key = $this->prophesize(KeyInterface::class);
    $key->getKeyValue()->willReturn(json_encode([
      'access_token' => 'valid_token',
      'expires_in' => 3600,
      'created' => time(),
    ]));
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($key->reveal());

    $this->time->getCurrentTime()->willReturn(time());

    $exception = new RequestException('Error Communicating with Server', new Request('POST', 'test'), new Response(500, [], 'Server Error'));
    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willThrow($exception);

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertFalse($result);
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccountWithTimeout(): void {
    $this->setTestConfig(request_timeout: 1);

    $key = $this->prophesize(KeyInterface::class);
    $key->getKeyValue()->willReturn(json_encode([
      'access_token' => 'valid_token',
      'expires_in' => 3600,
      'created' => time(),
    ]));
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($key->reveal());

    $this->time->getCurrentTime()->willReturn(time());

    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willThrow(new ConnectException('Connection timed out', new Request('POST', 'test')));

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertFalse($result);
  }

  /**
   * @covers ::registerAccount
   */
  public function testRegisterAccount(): void {
    $this->setTestConfig();

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    // Mock the key repository to return a key with an expired token.
    $expiredKey = $this->prophesize(KeyInterface::class);
    $expiredKey->getKeyValue()->willReturn(json_encode([
      'access_token' => 'expired_token',
      'expires_in' => 3600,
      'created' => time() - 4000,
    ]));
    $expiredKey->setKeyValue(Argument::any())->shouldBeCalled();
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($expiredKey->reveal());

    // Mock the time service.
    $this->time->getCurrentTime()->willReturn(time());

    // Mock the HTTP client to return a new token and then a successful
    // registration.
    $this->httpClient->request('POST', 'https://api.example.com/token', Argument::any())
      ->willReturn(new Response(200, [], json_encode([
        'access_token' => 'new_token',
        'expires_in' => 3600,
      ])));
    $this->httpClient->request('POST', 'https://api.example.com/register', Argument::any())
      ->willReturn(new Response(200, [], '{"code": 200, "message": "Success"}'));

    $result = $this->apiClient->registerAccount('John', 'Doe', 'john.doe@example.com');
    $this->assertTrue($result);
  }

  /**
   * @covers ::getAccessToken
   */
  public function testGetAccessToken(): void {
    $tokenData = [
      'access_token' => 'test_token',
      'expires_in' => 3600,
      'created' => time(),
    ];

    $key = $this->prophesize(KeyInterface::class);
    $key->getKeyValue()->willReturn(json_encode($tokenData));
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($key->reveal());

    $this->time->getCurrentTime()->willReturn(time());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'getAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals('test_token', $result);
  }

  /**
   * @covers ::refreshAccessToken
   */
  public function testRefreshAccessTokenWithInvalidConfiguration(): void {
    $this->setTestConfig(token_url: NULL);

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'refreshAccessToken');
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

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'refreshAccessToken');
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

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error(Argument::cetera())->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'refreshAccessToken');
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

    $key = $this->prophesize(KeyInterface::class);
    $key->setKeyValue(Argument::any())->shouldBeCalled();
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($key->reveal());

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $this->time->getCurrentTime()->willReturn(time());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'refreshAccessToken');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->apiClient);

    $this->assertEquals('new_token', $result);
  }

  /**
   * @covers ::isTokenExpired
   */
  public function testIsTokenExpired(): void {
    $this->time->getCurrentTime()->willReturn(1000);

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'isTokenExpired');
    $method->setAccessible(TRUE);

    $tokenData = [
      'created' => 500,
      'expires_in' => 400,
    ];
    $result = $method->invoke($this->apiClient, $tokenData);
    $this->assertTrue($result);

    $tokenData = [
      'created' => 500,
      'expires_in' => 600,
    ];
    $result = $method->invoke($this->apiClient, $tokenData);
    $this->assertFalse($result);
  }

  /**
   * @covers ::storeAccessToken
   */
  public function testStoreAccessTokenWithMissingKey(): void {
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn(NULL);

    $logger = $this->prophesize(LoggerChannelInterface::class);
    $logger->error('Failed to store access token: Key not found.')->shouldBeCalled();
    $this->loggerFactory->get('ocha_uimc')->willReturn($logger->reveal());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'storeAccessToken');
    $method->setAccessible(TRUE);
    $method->invoke($this->apiClient, ['access_token' => 'test_token', 'expires_in' => 3600]);
  }

  /**
   * @covers ::storeAccessToken
   */
  public function testStoreAccessToken(): void {
    $tokenData = [
      'access_token' => 'new_token',
      'expires_in' => 3600,
      'created' => time(),
    ];

    $key = $this->prophesize(KeyInterface::class);
    $key->setKeyValue(json_encode($tokenData))->shouldBeCalled();
    $this->keyRepository->getKey('ocha_uimc_api_access_token')->willReturn($key->reveal());

    $method = new \ReflectionMethod(OchaUimcApiClient::class, 'storeAccessToken');
    $method->setAccessible(TRUE);
    $method->invoke($this->apiClient, $tokenData);
  }

  /**
   * Set the test configuration.
   *
   * @param string|null $token_url
   *   The token_url config setting.
   * @param string|null $registration_url
   *   The registration_url config setting.
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
   */
  protected function setTestConfig(
    ?string $token_url = 'https://api.example.com/token',
    ?string $registration_url = 'https://api.example.com/register',
    ?string $username = 'test_user',
    ?string $password = 'test_pass',
    ?string $consumer_key = 'test_key',
    ?string $consumer_secret = 'test_secret',
    ?bool $send_email = FALSE,
    ?bool $verify_ssl = FALSE,
    ?int $request_timeout = 10,
  ): void {
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('token_url')->willReturn($token_url);
    $config->get('registration_url')->willReturn($registration_url);
    $config->get('username')->willReturn($username);
    $config->get('password')->willReturn($password);
    $config->get('consumer_key')->willReturn($consumer_key);
    $config->get('consumer_secret')->willReturn($consumer_secret);
    $config->get('verify_ssl')->willReturn($verify_ssl);
    $config->get('request_timeout')->willReturn($request_timeout);
    $config->get('send_email')->willReturn($send_email);

    $this->configFactory->get('ocha_uimc.settings')->willReturn($config->reveal());
  }

}
