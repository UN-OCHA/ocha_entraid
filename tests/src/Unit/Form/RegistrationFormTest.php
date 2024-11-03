<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_entraid\Unit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\honeypot\HoneypotService;
use Drupal\ocha_entraid\Form\RegistrationForm;
use Drupal\ocha_entraid\Service\UimcApiClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the RegistrationForm.
 *
 * @coversDefaultClass \Drupal\ocha_entraid\Form\RegistrationForm
 * @group ocha_entraid
 */
class RegistrationFormTest extends UnitTestCase {

  /**
   * The mocked API client.
   *
   * @var \Drupal\ocha_entraid\Service\UimcApiClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected UimcApiClientInterface|MockObject $apiClient;

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
   * @var \Drupal\ocha_entraid\Form\RegistrationForm
   */
  protected RegistrationForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->apiClient = $this->createMock(UimcApiClientInterface::class);
    $this->honeypotService = $this->createMock(HoneypotService::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    // Mock the config factory.
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory->method('get')->willReturn($this->config);

    $this->form = new RegistrationForm(
      $this->apiClient,
      $this->honeypotService,
    );
    $this->form->setMessenger($this->messenger);
    $this->form->setConfigFactory($this->configFactory);

    $translation = $this->getStringTranslationStub();
    $this->form->setStringTranslation($translation);
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

    $this->assertArrayHasKey('first_name', $built_form);
    $this->assertArrayHasKey('last_name', $built_form);
    $this->assertArrayHasKey('email', $built_form);
    $this->assertArrayHasKey('actions', $built_form);
  }

  /**
   * Tests that registration explanation is added to the form when configured.
   *
   * @covers ::buildForm
   */
  public function testBuildFormAddsRegistrationExplanation(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();

    $config = $this->createMock(ImmutableConfig::class);
    $config->expects($this->once())
      ->method('get')
      ->with('registration_explanation')
      ->willReturn('Test explanation');

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->expects($this->once())
      ->method('get')
      ->with('ocha_entraid.settings')
      ->willReturn($config);

    $this->form->setConfigFactory($config_factory);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('registration_explanation', $built_form);
    $this->assertEquals('Test explanation', $built_form['registration_explanation']['#markup']->__toString());
  }

  /**
   * Tests the form validation with invalid data.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidData(): void {
    $form = [
      'first_name' => ['#parents' => ['first_name']],
      'last_name' => ['#parents' => ['last_name']],
      'email' => ['#parents' => ['email']],
    ];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues([
      'first_name' => 'John123',
      'last_name' => 'Doe456',
      'email' => 'invalid-email',
    ]);

    $this->form->validateForm($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());
    $this->assertCount(3, $form_state->getErrors());
  }

  /**
   * Tests the form validation with valid data.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithValidData(): void {
    $form = [
      'first_name' => ['#parents' => ['first_name']],
      'last_name' => ['#parents' => ['last_name']],
      'email' => ['#parents' => ['email']],
    ];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues([
      'first_name' => 'John',
      'last_name' => 'Doe',
      'email' => 'john.doe@example.com',
    ]);

    $this->form->validateForm($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
    $this->assertEmpty($form_state->getErrors());
  }

  /**
   * Tests the form submission with API exception.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormApiException(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues([
      'first_name' => 'John',
      'last_name' => 'Doe',
      'email' => 'john.doe@example.com',
    ]);

    $this->apiClient->expects($this->once())
      ->method('registerAccount')
      ->willThrowException(new \Exception('API Error'));

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with($this->callback(function ($message) {
        $expected_string = 'Registration failed, please contact the administrator or try again later.';
        $actual_string = $message->getUntranslatedString();
        if ($expected_string !== $actual_string) {
          $this->fail("Expected message '$expected_string', but got '$actual_string'");
        }
        return TRUE;
      }));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('Registration failed: @message', ['@message' => 'API Error']);

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->expects($this->once())
      ->method('get')
      ->with('ocha_entraid')
      ->willReturn($logger);

    $this->form->setLoggerFactory($logger_factory);

    $this->form->submitForm($form, $form_state);
  }

  /**
   * Tests the form submission with successful API registration.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSuccess(): void {
    $form = [];
    $form_state = new FormState();
    $form_state->clearErrors();
    $form_state->setValues([
      'first_name' => 'John',
      'last_name' => 'Doe',
      'email' => 'john.doe@example.com',
    ]);

    $this->apiClient->expects($this->once())
      ->method('registerAccount')
      ->with('John', 'Doe', 'john.doe@example.com')
      ->willReturn(TRUE);

    $this->messenger->expects($this->once())
      ->method('addStatus')
      ->with($this->callback(function ($message) {
        $expected_string = 'Registration successful, please check your mailbox for further instructions.';
        $actual_string = $message->getUntranslatedString();
        if ($expected_string !== $actual_string) {
          $this->fail("Expected message '$expected_string', but got '$actual_string'");
        }
        return TRUE;
      }));

    $this->form->submitForm($form, $form_state);

    $this->assertEquals('user.login', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Tests the getFormId method.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('ocha_entraid_registration_form', $this->form->getFormId());
  }

}