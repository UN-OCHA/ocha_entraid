<?php

namespace Drupal\Tests\ocha_entraid\Unit\Enum;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ocha_entraid\Enum\UserMessage;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for the UserMessage enum.
 *
 * @coversDefaultClass \Drupal\ocha_entraid\Enum\UserMessage
 * @group ocha_entraid
 */
class UserMessageTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected ConfigFactoryInterface|ObjectProphecy $configFactory;

  /**
   * The mocked string translation service.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected ObjectProphecy $stringTranslation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->stringTranslation = $this->prophesize(TranslationInterface::class);

    // Mock the string translation method to return the input string.
    $this->stringTranslation->translateString(Argument::type(TranslatableMarkup::class))
      ->will(function ($args) {
        // Return the input string as-is for testing.
        return $args[0]->getUntranslatedString();
      });

    // Mock the Drupal service container.
    $container = $this->prophesize(ContainerInterface::class);
    $container->get('config.factory')->willReturn($this->configFactory->reveal());
    $container->get('string_translation')->willReturn($this->stringTranslation->reveal());

    \Drupal::setContainer($container->reveal());
  }

  /**
   * @covers ::label
   */
  public function testLabel(): void {
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('messages.invalid_email')->willReturn('Invalid email address');
    $config->get('messages.login_redirection_error')->willReturn(NULL);

    $this->configFactory->get('ocha_entraid.settings')->willReturn($config->reveal());

    // Test with a configured message.
    $result = UserMessage::InvalidEmail->label();
    $this->assertInstanceOf(TranslatableMarkup::class, $result);
    $this->assertEquals('Invalid email address', (string) $result);

    // Test with a non-configured message (fallback to enum value).
    $result = UserMessage::LoginRedirectionError->label();
    $this->assertInstanceOf(TranslatableMarkup::class, $result);
    $this->assertEquals('login_redirection_error', (string) $result);
  }

  /**
   * @covers ::empty
   */
  public function testEmpty(): void {
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('messages.invalid_email')->willReturn('Invalid email address');
    $config->get('messages.login_redirection_error')->willReturn(NULL);
    $config->get('messages.registration_success')->willReturn('');

    $this->configFactory->get('ocha_entraid.settings')->willReturn($config->reveal());

    // Test with a non-empty configured message.
    $this->assertFalse(UserMessage::InvalidEmail->empty());

    // Test with a NULL configured message.
    $this->assertTrue(UserMessage::LoginRedirectionError->empty());

    // Test with an empty string configured message.
    $this->assertTrue(UserMessage::RegistrationSuccess->empty());
  }

}
