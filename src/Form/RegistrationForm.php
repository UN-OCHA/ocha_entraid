<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\honeypot\HoneypotService;
use Drupal\ocha_entraid\Enum\UserMessage;
use Drupal\ocha_entraid\Service\UimcApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registration form.
 */
class RegistrationForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\ocha_entraid\Service\UimcApiClientInterface $uimcApiClient
   *   The OCHA UIMC API client.
   * @param \Drupal\honeypot\HoneypotService $honeypotService
   *   The Honeypot service.
   */
  public function __construct(
    protected UimcApiClientInterface $uimcApiClient,
    protected HoneypotService $honeypotService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ocha_entraid.uimc.api.client'),
      $container->get('honeypot')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Add the registration explanation message.
    if (!UserMessage::REGISTRATION_EXPLANATION->empty()) {
      $form['registration_explanation'] = [
        '#type' => 'markup',
        '#markup' => UserMessage::REGISTRATION_EXPLANATION->label(),
        '#prefix' => '<div class="ocha-entraid-registration-explanation">',
        '#suffix' => '</div>',
      ];
    }

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
      '#maxlength' => 30,
      '#placeholder' => $this->t('Enter your first name'),
      '#description' => $this->t('Enter your first name using only letters, spaces, hyphens, or apostrophes. Maximum 30 characters.'),
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
      '#maxlength' => 30,
      '#placeholder' => $this->t('Enter your last name'),
      '#description' => $this->t('Enter your last name using only letters, spaces, hyphens, or apostrophes. Maximum 30 characters.'),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#placeholder' => $this->t('Enter your email address'),
      '#description' => $this->t('Enter a valid email address. Only letters, numbers, hyphens, and periods are allowed. Maximum 100 characters.'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register'),
      '#name' => 'submit',
      '#button_type' => 'primary',
    ];

    $honeypot_options = ['honeypot', 'time_restriction'];
    $this->honeypotService->addFormProtection($form, $form_state, $honeypot_options);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate first name.
    $first_name = $form_state->getValue('first_name');
    if (preg_match('/^[a-zA-Z \'-]{1,30}$/', $first_name) !== 1) {
      $form_state->setErrorByName('first_name', UserMessage::REGISTRATION_INVALID_FIRST_NAME->label());
    }

    // Validate last name.
    $last_name = $form_state->getValue('last_name');
    if (preg_match('/^[a-zA-Z \'-]{1,30}$/', $last_name) !== 1) {
      $form_state->setErrorByName('last_name', UserMessage::REGISTRATION_INVALID_LAST_NAME->label());
    }

    // Validate email.
    $email = $form_state->getValue('email');
    if (strlen($email) > 100 || preg_match('/^[a-zA-Z0-9.-]{1,64}@[a-zA-Z0-9.-]{1,255}$/', $email) !== 1) {
      $form_state->setErrorByName('email', UserMessage::REGISTRATION_INVALID_EMAIL->label());
    }
    // Additional email validation.
    elseif (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', UserMessage::INVALID_EMAIL->label());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $first_name = $form_state->getValue('first_name');
    $last_name = $form_state->getValue('last_name');
    $email = $form_state->getValue('email');

    // Register the account.
    try {
      $this->uimcApiClient->registerAccount($first_name, $last_name, $email);

      $send_email = $this->config('ocha_entraid.settings')?->get('uimc_api.send_email');
      if (empty($send_email)) {
        $this->messenger()->addStatus(UserMessage::REGISTRATION_SUCCESS->label());
      }
      else {
        $this->messenger()->addStatus(UserMessage::REGISTRATION_SUCCESS_WITH_EMAIL->label());
      }

      $form_state->setRedirect('user.login');
    }
    catch (\Exception $exception) {
      $this->getLogger('ocha_entraid')->error('Registration failed: @message', ['@message' => $exception->getMessage()]);

      $this->messenger()->addError(UserMessage::REGISTRATION_FAILURE->label());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_entraid_registration_form';
  }

}
