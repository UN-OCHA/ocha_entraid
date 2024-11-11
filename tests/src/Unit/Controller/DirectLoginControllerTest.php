<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_entraid\Unit\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ocha_entraid\Controller\DirectLoginController;
use Drupal\ocha_entraid\Enum\UserMessage;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectClientEntityInterface;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\openid_connect\Plugin\OpenIDConnectClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Test the direct login controller.
 *
 * @coversDefaultClass \Drupal\ocha_entraid\Controller\DirectLoginController
 * @group ocha_entraid
 */
class DirectLoginControllerTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface|MockObject $entityTypeManager;

  /**
   * The mocked OpenID Connect claims.
   *
   * @var \Drupal\openid_connect\OpenIDConnectClaims|\PHPUnit\Framework\MockObject\MockObject
   */
  protected OpenIDConnectClaims|MockObject $openIdConnectClaims;

  /**
   * The mocked OpenID Connect session.
   *
   * @var \Drupal\openid_connect\OpenIDConnectSessionInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected OpenIDConnectSessionInterface|MockObject $openIdConnectSession;

  /**
   * The mocked messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected MessengerInterface|MockObject $messenger;

  /**
   * The mocked config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface|MockObject $configFactory;

  /**
   * The URL generator service.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected UrlGeneratorInterface|MockObject $urlGenerator;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerChannelFactoryInterface|MockObject $loggerFactory;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ImmutableConfig|MockObject $config;

  /**
   * The container used for the tests.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected ContainerInterface $container;

  /**
   * The controller object being tested.
   *
   * @var \Drupal\ocha_entraid\Controller\DirectLoginController
   */
  protected DirectLoginController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the services.
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->openIdConnectClaims = $this->createMock(OpenIDConnectClaims::class);
    $this->openIdConnectSession = $this->createMock(OpenIDConnectSessionInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

    // Mock the config factory.
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')->willReturn($this->config);

    // Mock the string translation service.
    $translation = $this->getStringTranslationStub();

    // Create a mock container using a service provider.
    $container = new ContainerBuilder();

    // Register the services.
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('openid_connect.claims', $this->openIdConnectClaims);
    $container->set('openid_connect.session', $this->openIdConnectSession);
    $container->set('messenger', $this->messenger);
    $container->set('config.factory', $this->configFactory);
    $container->set('logger.factory', $this->loggerFactory);
    $container->set('url_generator', $this->urlGenerator);
    $container->set('string_translation', $translation);

    // Add our new container.
    \Drupal::setContainer($container);

    // Keep track of the container so we can update the config for example.
    $this->container = $container;

    // Create the controller to test.
    $this->controller = DirectLoginController::create($container);
  }

  /**
   * Tests the case when the Entra ID client is missing.
   *
   * @covers ::redirectLogin
   */
  public function testRedirectLoginMissingClient(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn([]);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with('OpenID Connect client "entraid" not found.');

    $this->loggerFactory->expects($this->once())
      ->method('get')
      ->with('ocha_entraid')
      ->willReturn($logger);

    $this->config->expects($this->once())
      ->method('getCacheContexts')
      ->willReturn([]);
    $this->config->expects($this->once())
      ->method('getCacheTags')
      ->willReturn([]);
    $this->config->expects($this->once())
      ->method('getCacheMaxAge')
      ->willReturn(0);

    $this->expectException(CacheableNotFoundHttpException::class);

    $this->controller->redirectLogin();
  }

  /**
   * Tests the case when an exception occurs during the login process.
   *
   * @covers ::redirectLogin
   */
  public function testRedirectLoginException(): void {
    $plugin = $this->createMock(OpenIDConnectClientInterface::class);
    $plugin->expects($this->once())
      ->method('authorize')
      ->willThrowException(new \Exception('Test exception'));

    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $client->expects($this->once())
      ->method('getPlugin')
      ->willReturn($plugin);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn(['entraid' => $client]);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $this->openIdConnectClaims->expects($this->once())
      ->method('getScopes')
      ->willReturn('email');

    $this->openIdConnectSession->expects($this->once())
      ->method('saveOp')
      ->with('login');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Error during direct login: Test exception');

    $this->loggerFactory->expects($this->once())
      ->method('get')
      ->with('ocha_entraid')
      ->willReturn($logger);

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with(UserMessage::LOGIN_REDIRECTION_ERROR->label());

    // Homepage.
    $this->urlGenerator
      ->expects($this->any())
      ->method('generateFromRoute')
      ->with('<front>', $this->anything(), $this->anything())
      ->willReturn('https://test.test/');

    $response = $this->controller->redirectLogin();

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('https://test.test/', $response->getTargetUrl());
  }

  /**
   * Tests that the destination is saved before redirection.
   *
   * @covers ::redirectLogin
   */
  public function testDestinationSaved(): void {
    $redirect_response = new RedirectResponse('/dummy-url');

    $plugin = $this->createMock(OpenIDConnectClientInterface::class);
    $plugin->expects($this->once())
      ->method('authorize')
      ->willReturn($redirect_response);

    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $client->expects($this->once())
      ->method('getPlugin')
      ->willReturn($plugin);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn(['entraid' => $client]);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $this->openIdConnectSession->expects($this->once())
      ->method('saveDestination');

    $this->openIdConnectClaims->expects($this->once())
      ->method('getScopes')
      ->willReturn('email');

    $this->openIdConnectSession->expects($this->once())
      ->method('saveOp')
      ->with('login');

    $response = $this->controller->redirectLogin();

    $this->assertEquals($response, $redirect_response);
  }

  /**
   * Tests a successful redirection.
   *
   * @covers ::redirectLogin
   */
  public function testRedirectLoginSuccess(): void {
    $redirect_response = new RedirectResponse('/dummy-url');

    $plugin = $this->createMock(OpenIDConnectClientInterface::class);
    $plugin->expects($this->once())
      ->method('authorize')
      ->willReturn($redirect_response);

    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $client->expects($this->once())
      ->method('getPlugin')
      ->willReturn($plugin);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn(['entraid' => $client]);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $this->openIdConnectClaims->expects($this->once())
      ->method('getScopes')
      ->willReturn('email');

    $this->openIdConnectSession->expects($this->once())
      ->method('saveOp')
      ->with('login');

    $response = $this->controller->redirectLogin();

    $this->assertEquals($response, $redirect_response);
  }

}
