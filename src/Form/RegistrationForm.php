<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\honeypot\HoneypotService;
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
    $explanation = $this->config('ocha_entraid.settings')->get('registration_explanation');
    if (!empty($explanation)) {
      $form['registration_explanation'] = [
        '#type' => 'markup',
        '#markup' => Markup::create($explanation),
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
      $form_state->setErrorByName('first_name', $this->t('First name must contain only letters, spaces, hyphens, or apostrophes and be no longer than 30 characters.'));
    }

    // Validate last name.
    $last_name = $form_state->getValue('last_name');
    if (preg_match('/^[a-zA-Z \'-]{1,30}$/', $last_name) !== 1) {
      $form_state->setErrorByName('last_name', $this->t('Last name must contain only letters, spaces, hyphens, or apostrophes and be no longer than 30 characters.'));
    }

    // Validate email.
    $email = $form_state->getValue('email');
    if (strlen($email) > 100 || preg_match('/^[a-zA-Z0-9.-]{1,64}@[a-zA-Z0-9.-]{1,255}$/', $email) !== 1) {
      $form_state->setErrorByName('email', $this->t('Email must contain only letters, numbers, hyphens, or periods and be no longer than 100 characters.'));
    }
    // Additional email validation.
    elseif (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
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

      $this->messenger()->addStatus($this->t('Registration successful, please check your mailbox for further instructions.'));

      $form_state->setRedirect('user.login');
    }
    catch (\Exception $exception) {
      $this->getLogger('ocha_entraid')->error('Registration failed: @message', ['@message' => $exception->getMessage()]);

      $this->messenger()->addError($this->t('Registration failed, please contact the administrator or try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_entraid_registration_form';
  }

}
