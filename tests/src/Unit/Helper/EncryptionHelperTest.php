<?php

declare(strict_types=1);

namespace Drupal\Tests\ocha_entraid\Unit\Helper;

use Drupal\Tests\UnitTestCase;
use Drupal\ocha_entraid\Helper\EncryptionHelper;

/**
 * @coversDefaultClass \Drupal\ocha_entraid\Helper\EncryptionHelper
 * @group ocha_entraid
 */
class EncryptionHelperTest extends UnitTestCase {

  /**
   * Test data for encryption and decryption.
   *
   * @var string
   */
  protected string $testData = 'This is a test string to be encrypted and decrypted.';

  /**
   * @covers ::encrypt
   * @covers ::decrypt
   */
  public function testEncryptionAndDecryption(): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    // Test encryption.
    $encrypted = EncryptionHelper::encrypt($this->testData, $key);
    $this->assertNotEquals($this->testData, $encrypted);
    $this->assertNotFalse(base64_decode($encrypted, TRUE));

    // Test decryption.
    $decrypted = EncryptionHelper::decrypt($encrypted, $key);
    $this->assertEquals($this->testData, $decrypted);
  }

  /**
   * @covers ::encrypt
   */
  public function testEncryptionWithDifferentKeys(): void {
    $key1 = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $key2 = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    $encrypted1 = EncryptionHelper::encrypt($this->testData, $key1);
    $encrypted2 = EncryptionHelper::encrypt($this->testData, $key2);

    $this->assertNotEquals($encrypted1, $encrypted2);
  }

  /**
   * @covers ::decrypt
   */
  public function testDecryptionWithWrongKey(): void {
    $correctKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $wrongKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    $encrypted = EncryptionHelper::encrypt($this->testData, $correctKey);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Decryption failed.');
    EncryptionHelper::decrypt($encrypted, $wrongKey);
  }

  /**
   * @covers ::decrypt
   */
  public function testDecryptionWithInvalidData(): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $invalidEncrypted = base64_encode('This is not a valid encrypted string');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Decryption failed.');
    EncryptionHelper::decrypt($invalidEncrypted, $key);
  }

  /**
   * @covers ::encrypt
   * @covers ::decrypt
   */
  public function testEncryptionAndDecryptionWithEmptyString(): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $emptyString = '';

    $encrypted = EncryptionHelper::encrypt($emptyString, $key);
    $this->assertNotEquals($emptyString, $encrypted);

    $decrypted = EncryptionHelper::decrypt($encrypted, $key);
    $this->assertEquals($emptyString, $decrypted);
  }

  /**
   * @covers ::encrypt
   * @covers ::decrypt
   */
  public function testEncryptionAndDecryptionWithLongString(): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $longString = str_repeat('Long string test. ', 1000);

    $encrypted = EncryptionHelper::encrypt($longString, $key);
    $this->assertNotEquals($longString, $encrypted);

    $decrypted = EncryptionHelper::decrypt($encrypted, $key);
    $this->assertEquals($longString, $decrypted);
  }

}
