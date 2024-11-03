<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\honeypot\HoneypotService;
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
    $explanation = $this->config('ocha_entraid.settings')->get('login_explanation');
    if (!empty($explanation)) {
      $form['login_explanation'] = [
        '#type' => 'markup',
        '#markup' => Markup::create($explanation),
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
    if (!filter_var($email, \FILTER_VALIDATE_EMAIL, \FILTER_FLAG_EMAIL_UNICODE)) {
      $form_state->setErrorByName('email', $this->t('Invalid email address.'));
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
      $form_state->setErrorByName('', $this->t("We couldn't find your account."));
    }
    // For other exceptions (ex: API error), show a generic error message.
    catch (\Exception $exception) {
      $form_state->setErrorByName('', $this->t('An error occured during the account verification. Please try again later or contact the administrator.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $client_entities = $this->entityTypeManager
      ->getStorage('openid_connect_client')
      ->loadByProperties(['id' => 'entraid', 'status' => 1]);

    // Redirect to the EntraID sign-in page if we found the EntraID client.
    if (isset($client_entities['entraid'])) {
      /** @var \Drupal\openid_connect\OpenIDConnectClientEntityInterface $client */
      $client = $client_entities['entraid'];
      $plugin = $client->getPlugin();
      $scopes = $this->openIdConnectClaims->getScopes($plugin);
      $this->openIdConnectSession->saveOp('login');

      // Add the login_hint parameter with the email address to prepopulate the
      // account field on the EntraID sign-in form.
      $response = $plugin->authorize($scopes, [
        'login_hint' => $form_state->getValue('email'),
      ]);

      $form_state->setResponse($response);
    }
    // Otherwise show an error message and redirect to the form.
    else {
      $this->getLogger('ocha_entraid')->error('OpenID Connect client "entraid" not found.');

      $this->messenger()->addError($this->t('An error occurred during the login process. Please try again later or contact the administrator.'));

      $form_state->setRedirect('ocha_entraid.form.login');
    }
  }

}