<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render a login page with configurable content.
 */
class LoginController extends ControllerBase {
  use StringTranslationTrait;

  /**
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    RequestStack $request_stack,
  ) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
    );
  }

  /**
   * Get the current request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   Current request.
   */
  public function getRequest(): Request {
    return $this->requestStack->getCurrentRequest();
  }

  /**
   * Create a login page.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A pretty login page with text and links.
   */
  public function loginPage(): Response {

    // Link defaults.
    $link_options = [
      'absolute' => FALSE,
    ];

    // Get the destination param. Re-use if *if* set.
    $destination = $request->query->get('destination');
    if (isset($destination)) {
      $options['destination'] = $destination;
    }

    $params = [
      '@login-direct' => Url::fromRoute('ocha_entraid.login.direct', $link_options),
      '@login-form'   => Url::fromRoute('ocha_entraid.login.form', $link_options),
      '@register'     => Url::fromRoute('ocha_entraid.registration.form', $link_options),
    ];

    // Yes, this needs to come from config (file or db)
    $message = $this->t(
      "<p><strong><a href=\"@login-direct\">Log in with your UN Agency email</a></strong></p>p>UN Secretariat and UN Agency personnel can use their email credentials to log in. Everyone else should use Humanitarian ID.</p>" .
      "<p><strong><a href=\"@login-form\">Log In with non-UN email</a> / <a href=\"@register\">Create new account</a></strong></p>",
      $params
    );

    return [
      '#type' => 'inline_template',
      '#template' => '<div class="ocha-entraid-login">{{ message }}</div>',
      '#context' => ['message' => $message],
    ];
  }

}
