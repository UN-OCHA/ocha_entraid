<?php

declare(strict_types=1);

namespace Drupal\ocha_uimc\Service;

/**
 * Interface for the client service for the UIMC API.
 */
interface OchaUimcApiClientInterface {

  /**
   * Register an account.
   *
   * @param string $first_name
   *   The first name.
   * @param string $last_name
   *   The last name.
   * @param string $email
   *   The email address.
   *
   * @return bool
   *   TRUE if the registration is successful.
   */
  public function registerAccount(string $first_name, string $last_name, string $email): bool;

}
