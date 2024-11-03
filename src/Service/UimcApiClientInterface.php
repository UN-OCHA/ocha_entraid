<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Service;

/**
 * Interface for the client service for the UIMC API.
 */
interface UimcApiClientInterface {

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
   *
   * @throws \Exception
   *   If the registration failed.
   */
  public function registerAccount(string $first_name, string $last_name, string $email): bool;

  /**
   * Add an account to a group.
   *
   * @param string $email
   *   Email address of the account.
   * @param ?string $group
   *   Group name. If not present, use the default group from the configuration.
   *
   * @return bool
   *   TRUE if the account was added to the group or already in the group.
   *
   * @throws \Drupal\ocha_entraid\Exception\AccountNotFoundException
   *   If no account matching the email address was found.
   * @throws \Exception
   *   If the account could not be added to the group.
   */
  public function addAccountToGroup(string $email, ?string $group = NULL): bool;

}
