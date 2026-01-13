<?php

namespace Drupal\makerspace_landing_page\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Makerspace Landing Page settings for this site.
 */
class MakerspaceLandingPageSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_landing_page_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['makerspace_landing_page.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('makerspace_landing_page.settings');

    $form['google_analytics_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Analytics Account ID'),
      '#default_value' => $config->get('google_analytics_account'),
      '#description' => $this->t('The Account ID (e.g., 28487167) used in the GA4 URL (a{AccountID}p{PropertyID}).'),
    ];

    $form['google_analytics_property'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Analytics Property ID'),
      '#default_value' => $config->get('google_analytics_property'),
      '#description' => $this->t('The Property ID (e.g., 354888128) used in the GA4 URL (a{AccountID}p{PropertyID}).'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('makerspace_landing_page.settings')
      ->set('google_analytics_account', $form_state->getValue('google_analytics_account'))
      ->set('google_analytics_property', $form_state->getValue('google_analytics_property'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
