<?php

namespace Drupal\Tests\ocha_uimc\Unit\Form;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\honeypot\HoneypotService;
use Drupal\ocha_uimc\Form\OchaUimcRegistrationForm;
use Drupal\ocha_uimc\Service\OchaUimcApiClientInterface;

/**
 * Unit tests for the OchaUimcRegistrationForm.
 *
 * @coversDefaultClass \Drupal\ocha_uimc\Form\OchaUimcRegistrationForm
 * @group ocha_uimc
 */
class OchaUimcRegistrationFormTest extends UnitTestCase {

  /**
   * The mocked API client.
   *
   * @var \Drupal\ocha_uimc\Service\OchaUimcApiClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $apiClient;

  /**
   * The mocked Honeypot service.
   *
   * @var \Drupal\honeypot\HoneypotService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $honeypotService;

  /**
   * The mocked messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The form object being tested.
   *
   * @var \Drupal\ocha_uimc\Form\OchaUimcRegistrationForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->apiClient = $this->createMock(OchaUimcApiClientInterface::class);
    $this->honeypotService = $this->createMock(HoneypotService::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->form = new OchaUimcRegistrationForm($this->apiClient, $this->honeypotService);
    $this->form->setMessenger($this->messenger);

    $translation = $this->getStringTranslationStub();
    $this->form->setStringTranslation($translation);
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
   * Tests the form submission with failed API registration.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormFailure(): void {
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
      ->willReturn(FALSE);

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

    $this->form->submitForm($form, $form_state);

    $this->assertNull($form_state->getRedirect());
  }

}
