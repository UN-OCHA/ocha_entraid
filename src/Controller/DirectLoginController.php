<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\ocha_entraid\Enum\UserMessage;
use Drupal\ocha_entraid\Exception\MissingEntraidClientException;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect to the Microsoft sign in page with no intermediate login form.
 */
class DirectLoginController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Constructs a new AuthController object.
   *
   * @param \Drupal\openid_connect\OpenIDConnectClaims $openIdConnectClaims
   *   The OpenID Connect claims.
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $openIdConnectSession
   *   The OpenID Connect session service.
   */
  public function __construct(
    protected OpenIDConnectClaims $openIdConnectClaims,
    protected OpenIDConnectSessionInterface $openIdConnectSession,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('openid_connect.claims'),
      $container->get('openid_connect.session'),
    );
  }

  /**
   * Redirect to the user login callback.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response to redirect to the Microsoft sign in page.
   *
   * @throws \Drupal\Core\Http\Exception\CacheableNotFoundHttpException
   *   A 404 exception if the entraid client is not set.
   */
  public function redirectLogin(): Response {
    try {
      $client_entities = $this->entityTypeManager()
        ->getStorage('openid_connect_client')
        ->loadByProperties(['id' => 'entraid']);

      if (!isset($client_entities['entraid'])) {
        throw new MissingEntraidClientException('OpenID Connect client "entraid" not found.');
      }

      // Ensure the user will be redirected to the correc page set up in
      // OpenID Connect settings after completing the login process.
      $this->openIdConnectSession->saveDestination();

      // Authorize and redirect to the Microsoft sign in page.
      $client = $client_entities['entraid'];
      $plugin = $client->getPlugin();
      $scopes = $this->openIdConnectClaims->getScopes($plugin);
      $this->openIdConnectSession->saveOp('login');
      $response = $plugin->authorize($scopes);

      return $response;
    }
    // No configured Entra ID client.
    catch (MissingEntraidClientException $exception) {
      $this->getLogger('ocha_entraid')->notice($exception->getMessage());

      // Since this is a missing client error, we can cache the Not Found
      // exception until the config is changed so reduce the load.
      $config = $this->config('openid_connect.client.entraid');
      $cacheable_metadata = new CacheableMetadata();
      $cacheable_metadata->addCacheableDependency($config);
      throw new CacheableNotFoundHttpException($cacheable_metadata);
    }
    // Other exception like a temporary issue during the login process.
    catch (\Exception $exception) {
      $this->getLogger('ocha_entraid')->error(strtr('Error during direct login: @message', [
        '@message' => $exception->getMessage(),
      ]));

      $this->messenger()->addError(UserMessage::LOGIN_REDIRECTION_ERROR->label());

      // Redirect to the homepage.
      return $this->redirect('<front>');
    }
  }

}
