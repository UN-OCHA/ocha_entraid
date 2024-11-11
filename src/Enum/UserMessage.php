<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Enum;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * User messages.
 */
enum UserMessage: string {

  case INVALID_EMAIL = 'invalid_email';
  case LOGIN_EXPLANATION = 'login_explanation';
  case LOGIN_ACCOUNT_NOT_FOUND = 'login_account_not_found';
  case LOGIN_ACCOUNT_VERIFICATION_ERROR = 'login_account_verification_error';
  case LOGIN_ERROR = 'login_error';
  case REGISTRATION_EXPLANATION = 'registration_explanation';
  case REGISTRATION_INVALID_FIRST_NAME = 'registration_invalid_first_name';
  case REGISTRATION_INVALID_LAST_NAME = 'registration_invalid_last_name';
  case REGISTRATION_INVALID_EMAIL = 'registration_invalid_email';
  case REGISTRATION_SUCCESS = 'registration_success';
  case REGISTRATION_SUCCESS_WITH_EMAIL = 'registration_success_with_email';
  case REGISTRATION_FAILURE = 'registration_failure';

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
