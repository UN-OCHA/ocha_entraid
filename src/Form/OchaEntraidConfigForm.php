<?php

namespace Drupal\ocha_entraid\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Config form for the Ocha EntraID module.
 */
class OchaEntraidConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['login_page'] = [
      '#type' => 'textarea',
      '#rows' => 20,
      '#title' => $this->t('Login Page'),
      '#description' => $this->t('The content for the login page. This should include links to EntraID paths and/or signup forms.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save the updated configuration.
    $config = $this->config('ocha_entraid.settings');
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ocha_entraid.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ocha_entraid_config_form';
  }

}
