<?php

declare(strict_types=1);

namespace Drupal\ocha_entraid\Helper;

/**
 * Helper class for encryption and decryption operations.
 */
class EncryptionHelper {

  /**
   * Encrypts the given data using sodium.
   *
   * @param string $data
   *   The data to encrypt.
   * @param string $key
   *   The encryption key.
   *
   * @return string
   *   The encrypted data.
   *
   * @throws \Exception
   *   If the encryption failed.
   */
  public static function encrypt(string $data, string $key): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($data, $nonce, $key);
    $encrypted = base64_encode($nonce . $cipher);
    sodium_memzero($key);
    return $encrypted;
  }

  /**
   * Decrypts the given data using sodium.
   *
   * @param string $encrypted
   *   The encrypted data.
   * @param string $key
   *   The encryption key.
   *
   * @return string
   *   The decrypted data.
   *
   * @throws \Exception
   *   If the decryption failed.
   */
  public static function decrypt(string $encrypted, string $key): string {
    $decoded = base64_decode($encrypted);
    $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
    $cipher = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, NULL, '8bit');
    $decrypted = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    sodium_memzero($key);
    if ($decrypted === FALSE) {
      throw new \Exception('Decryption failed.');
    }
    return $decrypted;
  }

}
