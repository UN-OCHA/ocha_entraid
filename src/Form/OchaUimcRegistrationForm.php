<?php

declare(strict_types=1);

namespace Drupal\ocha_uimc\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\honeypot\HoneypotService;
use Drupal\ocha_uimc\Service\OchaUimcApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registration form for the Ocha UIMC module.
 */
class OchaUimcRegistrationForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\ocha_uimc\Service\OchaUimcApiClientInterface $ochaUimcApiClient
   *   The OCHA UIMC API client.
   * @param \Drupal\honeypot\HoneypotService $honeypotService
   *   The Honeypot service.
   */
  public function __construct(
    protected OchaUimcApiClientInterface $ochaUimcApiClient,
    protected HoneypotService $honeypotService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ocha_uimc.api.client'),
      $container->get('honeypot')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
      '#maxlength' => 30,
      '#placeholder' => $this->t('Enter your first name'),
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
      '#maxlength' => 30,
      '#placeholder' => $this->t('Enter your last name'),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#placeholder' => $this->t('Enter your email address'),
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

    if ($this->ochaUimcApiClient()->registerAccount($first_name, $last_name, $email)) {
      $this->messenger()->addStatus($this->t('Registration successful, please check your mailbox for further instructions.'));
      $form_state->setRedirect('user.login');
    }
    else {
      $this->messenger()->addError($this->t('Registration failed, please contact the administrator or try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_uimc_registration_form';
  }

  /**
   * Get the OCHA UIMC API client.
   *
   * @return \Drupal\ocha_uimc\Service\OchaUimcApiClientInterface
   *   The OCHA UIMC API client.
   */
  protected function ochaUimcApiClient(): OchaUimcApiClientInterface {
    return $this->ochaUimcApiClient;
  }

}
