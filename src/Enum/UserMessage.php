<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Enum;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * User messages.
 */
enum UserMessage: string {

  case InvalidEmail = 'invalid_email';
  case LoginExplanation = 'login_explanation';
  case LoginAccountBlocked = 'login_account_blocked';
  case LoginAccountNotFound = 'login_account_not_found';
  case LoginAccountVerificationError = 'login_account_verification_error';
  case LoginRedirectionError = 'login_redirection_error';
  case RegistrationExplanation = 'registration_explanation';
  case RegistrationInvalidFirstName = 'registration_invalid_first_name';
  case RegistrationInvalidLastName = 'registration_invalid_last_name';
  case RegistrationInvalidEmail = 'registration_invalid_email';
  case RegistrationSuccess = 'registration_success';
  case RegistrationSuccessWithEmail = 'registration_success_with_email';
  case RegistrationFailure = 'registration_failure';

  /**
   * Get the message associated with a case from the configuration.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translatable message.
   */
  public function label(): TranslatableMarkup {
    $config = \Drupal::config('ocha_entraid.settings');
    $message = $config?->get('messages.' . $this->value) ?: $this->value;
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return new TranslatableMarkup($message);
  }

  /**
   * Check if there is configuration message for the current case is empty.
   *
   * @return bool
   *   TRUE if there is no matching message or it is empty.
   */
  public function empty(): bool {
    $config = \Drupal::config('ocha_entraid.settings');
    $message = $config?->get('messages.' . $this->value);
    return empty($message);
  }

}
