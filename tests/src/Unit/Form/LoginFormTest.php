<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_entraid\Unit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\honeypot\HoneypotService;
use Drupal\ocha_entraid\Exception\AccountNotFoundException;
use Drupal\ocha_entraid\Form\LoginForm;
use Drupal\ocha_entraid\Service\UimcApiClientInterface;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectClientEntityInterface;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\openid_connect\Plugin\OpenIDConnectClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Unit tests for the LoginForm.
 *
 * @coversDefaultClass \Drupal\ocha_entraid\Form\LoginForm
 * @group ocha_entraid
 */
class LoginFormTest extends UnitTestCase {

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
   * The mocked API client.
   *
   * @var \Drupal\ocha_entraid\Service\UimcApiClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected UimcApiClientInterface|MockObject $uimcApiClient;

  /**
   * The mocked Honeypot service.
   *
   * @var \Drupal\honeypot\HoneypotService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected HoneypotService|MockObject $honeypotService;

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
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ImmutableConfig|MockObject $config;

  /**
   * The form object being tested.
   *
   * @var \Drupal\ocha_entraid\Form\LoginForm
   */
  protected LoginForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->openIdConnectClaims = $this->createMock(OpenIDConnectClaims::class);
    $this->openIdConnectSession = $this->createMock(OpenIDConnectSessionInterface::class);
    $this->uimcApiClient = $this->createMock(UimcApiClientInterface::class);
    $this->honeypotService = $this->createMock(HoneypotService::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    // Mock the config factory.
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory->method('get')->willReturn($this->config);

    $this->form = new LoginForm(
      $this->entityTypeManager,
      $this->openIdConnectClaims,
      $this->openIdConnectSession,
      $this->uimcApiClient,
      $this->honeypotService
    );
    $this->form->setMessenger($this->messenger);
    $this->form->setConfigFactory($this->configFactory);

    $translation = $this->getStringTranslationStub();
    $this->form->setStringTranslation($translation);
  }

  /**
   * Tests that login explanation is added to the form when configured.
   *
   * @covers ::buildForm
   */
  public function testBuildFormAddsLoginExplanation(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();

    $config = $this->createMock(ImmutableConfig::class);
    $config->expects($this->once())
      ->method('get')
      ->with('login_explanation')
      ->willReturn('Test explanation');

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->expects($this->once())
      ->method('get')
      ->with('ocha_entraid.settings')
      ->willReturn($config);

    $this->form->setConfigFactory($config_factory);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('login_explanation', $built_form);
    $this->assertEquals('Test explanation', $built_form['login_explanation']['#markup']->__toString());
  }

  /**
   * Tests that honeypot protection is added to the form.
   *
   * @covers ::buildForm
   */
  public function testBuildFormAddsHoneypotProtection(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();

    $this->honeypotService->expects($this->once())
      ->method('addFormProtection')
      ->with(
        $this->isType('array'),
        $this->isInstanceOf(FormStateInterface::class),
        $this->equalTo(['honeypot', 'time_restriction'])
      );

    // Mock the config get calls.
    $this->config->expects($this->any())
      ->method('get')
      ->willReturn(NULL);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('email', $built_form);
    $this->assertArrayHasKey('create_account', $built_form);
    $this->assertArrayHasKey('actions', $built_form);
  }

  /**
   * Tests the form validation with invalid email.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidEmail(): void {
    $form = ['email' => ['#parents' => ['email']]];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues(['email' => 'invalid-email']);

    $this->form->validateForm($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());
    $this->assertCount(1, $form_state->getErrors());
  }

  /**
   * Tests the form validation with non-existent account.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithNonExistentAccount(): void {
    $form = ['email' => ['#parents' => ['email']]];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues(['email' => 'nonexistent@example.com']);

    $this->uimcApiClient->expects($this->once())
      ->method('addAccountToGroup')
      ->willThrowException(new AccountNotFoundException());

    $this->form->validateForm($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());

    $errors = $form_state->getErrors();
    $this->assertStringContainsString("We couldn't find your account.", (string) reset($errors));
  }

  /**
   * Tests the form validation with API error.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithApiError(): void {
    $form = ['email' => ['#parents' => ['email']]];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues(['email' => 'valid@example.com']);

    $this->uimcApiClient->expects($this->once())
      ->method('addAccountToGroup')
      ->willThrowException(new \Exception('API Error'));

    $this->form->validateForm($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());

    $errors = $form_state->getErrors();
    $this->assertStringContainsString('An error occured during the account verification', (string) reset($errors));
  }

  /**
   * Tests the form validation with valid email and existing account.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithValidEmail(): void {
    $form = ['email' => ['#parents' => ['email']]];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues(['email' => 'valid@example.com']);

    $this->uimcApiClient->expects($this->once())
      ->method('addAccountToGroup')
      ->willReturn(TRUE);

    $this->form->validateForm($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * Tests the form submission with successful EntraID client.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSuccess(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues(['email' => 'user@example.com']);

    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn(['entraid' => $client]);

    $plugin = $this->createMock(OpenIDConnectClientInterface::class);
    $client->expects($this->once())
      ->method('getPlugin')
      ->willReturn($plugin);

    $this->openIdConnectClaims->expects($this->once())
      ->method('getScopes')
      ->willReturn('email');

    $this->openIdConnectSession->expects($this->once())
      ->method('saveOp')
      ->with('login');

    $response = new RedirectResponse('/dummy-url');
    $plugin->expects($this->once())
      ->method('authorize')
      ->willReturn($response);

    $this->form->submitForm($form, $form_state);

    $this->assertInstanceOf(RedirectResponse::class, $form_state->getResponse());
  }

  /**
   * Tests the form submission with successful EntraID client and login_hint.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSuccessWithLoginHint(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();
    $email = 'user@example.com';
    $form_state->setValues(['email' => $email]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn(['entraid' => $client]);

    $plugin = $this->createMock(OpenIDConnectClientInterface::class);
    $client->expects($this->once())
      ->method('getPlugin')
      ->willReturn($plugin);

    $this->openIdConnectClaims->expects($this->once())
      ->method('getScopes')
      ->willReturn('email');

    $this->openIdConnectSession->expects($this->once())
      ->method('saveOp')
      ->with('login');

    $response = new RedirectResponse('/dummy-url');
    $plugin->expects($this->once())
      ->method('authorize')
      ->with(
        $this->anything(),
        $this->equalTo(['login_hint' => $email])
      )
      ->willReturn($response);

    $this->form->submitForm($form, $form_state);

    $this->assertInstanceOf(RedirectResponse::class, $form_state->getResponse());
  }

  /**
   * Tests the form submission with missing EntraID client.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormFailure(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues(['email' => 'user@example.com']);

    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('openid_connect_client')
      ->willReturn($storage);

    $storage->expects($this->once())
      ->method('loadByProperties')
      ->willReturn([]);

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with($this->callback(function ($message) {
        $expected_string = 'An error occurred during the login process. Please try again later or contact the administrator.';
        $actual_string = $message->getUntranslatedString();
        if ($expected_string !== $actual_string) {
          $this->fail("Expected message '$expected_string', but got '$actual_string'");
        }
        return TRUE;
      }));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('OpenID Connect client "entraid" not found.');

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->expects($this->once())
      ->method('get')
      ->with('ocha_entraid')
      ->willReturn($logger);

    $this->form->setLoggerFactory($logger_factory);

    $this->form->submitForm($form, $form_state);

    $this->assertEquals('ocha_entraid.form.login', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Tests the getFormId method.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('ocha_entraid_login_form', $this->form->getFormId());
  }

}
