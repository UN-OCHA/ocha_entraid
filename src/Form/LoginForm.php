<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\honeypot\HoneypotService;
use Drupal\ocha_entraid\Enum\UserMessage;
use Drupal\ocha_entraid\Exception\AccountNotFoundException;
use Drupal\ocha_entraid\Service\UimcApiClientInterface;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a login form for the ocha_entraid module.
 */
class LoginForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\openid_connect\OpenIDConnectClaims $openIdConnectClaims
   *   The OpenID Connect claims.
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $openIdConnectSession
   *   The OpenID Connect session service.
   * @param \Drupal\ocha_entraid\Service\UimcApiClientInterface $uimcApiClient
   *   The UIMC API client.
   * @param \Drupal\honeypot\HoneypotService $honeypotService
   *   The Honeypot service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OpenIDConnectClaims $openIdConnectClaims,
    protected OpenIDConnectSessionInterface $openIdConnectSession,
    protected UimcApiClientInterface $uimcApiClient,
    protected HoneypotService $honeypotService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('openid_connect.claims'),
      $container->get('openid_connect.session'),
      $container->get('ocha_entraid.uimc.api.client'),
      $container->get('honeypot'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_entraid_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Add the registration explanation message.
    if (!UserMessage::LOGIN_EXPLANATION->empty()) {
      $form['login_explanation'] = [
        '#type' => 'markup',

        '#markup' => UserMessage::LOGIN_EXPLANATION->label(),
        '#prefix' => '<div class="ocha-entraid-login-explanation">',
        '#suffix' => '</div>',
      ];
    }

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Enter your email address'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign in'),
      '#name' => 'submit',
      '#button_type' => 'primary',
    ];

    $form['create_account'] = [
      '#type' => 'link',
      '#title' => $this->t('Create a new account'),
      '#url' => Url::fromRoute('ocha_entraid.form.registration'),
      '#prefix' => '<div class="ocha-entraid-register">',
      '#suffix' => '</div>',
      '#weight' => 100,
    ];

    $honeypot_options = ['honeypot', 'time_restriction'];
    $this->honeypotService->addFormProtection($form, $form_state, $honeypot_options);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = $form_state->getValue('email');
    // Ensure the input is a proper email address.
    //
    // Note: this is more lax than the registration email verification because
    // this must accomodate accounts created outside of the UIMC registration
    // and even the UN system.
    if (!filter_var($email, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
      $form_state->setErrorByName('email', UserMessage::INVALID_EMAIL->label());
      return;
    }

    // Check if there is already an account with that email address and if it
    // is blocked, in which case we show an error.
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'mail' => $email,
    ]);
    if (!empty($users) && reset($users)->isBlocked()) {
      $form_state->setErrorByName('', UserMessage::LOGIN_ACCOUNT_BLOCKED->label());
      return;
    }

    // Try to add the email to the default group for the site if not already.
    try {
      $this->uimcApiClient->addAccountToGroup($email);
    }
    // If the account was not found, show such message.
    catch (AccountNotFoundException $exception) {
      // @todo we may want to give more instructions like checking the inbox
      // for any invitation email or to register a new account.
      $form_state->setErrorByName('', UserMessage::LOGIN_ACCOUNT_NOT_FOUND->label());
    }
    // For other exceptions (ex: API error), show a generic error message.
    catch (\Exception $exception) {
      $form_state->setErrorByName('', UserMessage::LOGIN_ACCOUNT_VERIFICATION_ERROR->label());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $client_entities = $this->entityTypeManager
      ->getStorage('openid_connect_client')
      ->loadByProperties(['id' => 'entraid', 'status' => 1]);

    // Redirect to the Entra ID sign-in page if we found the Entra ID client.
    if (isset($client_entities['entraid'])) {
      // Ensure the user will be redirected to the correct page set up in
      // OpenID Connect settings after completing the login process.
      $this->openIdConnectSession->saveDestination();

      /** @var \Drupal\openid_connect\OpenIDConnectClientEntityInterface $client */
      $client = $client_entities['entraid'];
      $plugin = $client->getPlugin();
      $scopes = $this->openIdConnectClaims->getScopes($plugin);
      $this->openIdConnectSession->saveOp('login');

      // Add the login_hint parameter with the email address to prepopulate the
      // account field on the Entra ID sign-in form.
      $response = $plugin->authorize($scopes, [
        'login_hint' => $form_state->getValue('email'),
      ]);

      $form_state->setResponse($response);
    }
    // Otherwise show an error message and redirect to the form.
    else {
      $this->getLogger('ocha_entraid')->error('OpenID Connect client "entraid" not found.');

      $this->messenger()->addError(UserMessage::LOGIN_REDIRECTION_ERROR->label());

      $form_state->setRedirect('ocha_entraid.form.login');
    }
  }

}
