<?php

namespace Drupal\commerce_stock_reserve\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class SettingsForm.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_stock_reserve.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_stock_reserve_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_stock_reserve.settings');

    $form['cart_expiration_enable'] = [
      '#type' => 'checkbox',
      '#title' => t('Delete any stock-reserved order items from abandoned carts to free up stock again'),
      '#default_value' => !empty($config->get('cart_expiration')) ? $config->get('cart_expiration') : TRUE,
    ];

    $form['cart_expiration'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['interval'],
      ],
      '#states' => [
        'visible' => [
          ':input[name="cart_expiration_enable"]' => ['checked' => TRUE],
        ],
      ],
      '#open' => TRUE,
    ];

    $form['cart_expiration']['number'] = [
      '#type' => 'number',
      '#title' => t('Interval'),
      '#default_value' => !empty($config->get('cart_expiration_number')) ? $config->get('cart_expiration_number') : 1,
      '#required' => TRUE,
      '#min' => 1,
    ];

    $form['cart_expiration']['unit'] = [
      '#type' => 'select',
      '#title' => t('Unit'),
      '#title_display' => 'invisible',
      '#default_value' => !empty($config->get('cart_expiration_unit')) ? $config->get('cart_expiration_unit') : 'day',
      '#options' => [
        'minute' => t('Minute'),
        'hour' => t('Hour'),
        'day' => t('Day'),
        'month' => t('Month'),
      ],
      '#required' => TRUE,
    ];

    $form['cart_expiration']['message_enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Show message to user and on cart page about cart expiry.'),
      '#default_value' => !empty($config->get('message_enabled')) ? $config->get('message_enabled') : TRUE,
    ];

    if (!empty($config->get('message_text'))) {
      $message = $config->get('message_text');
    } else {
      $message = "Some items in your cart are stock controlled and will automatically be removed "
        . "from your cart if not purchased within [interval] of adding to the cart if not purchased.";
    }

    $form['cart_expiration']['message_text'] = [
      '#type' => 'textarea',
      '#title' => t('Message for user.'),
      '#states' => [
        'visible' => [
          ':input[name="message_enabled"]' => ['checked' => TRUE],
        ],
      ],
      '#description' => $this->t('Enter [interval] to include the time interval.'),
      '#default_value' => $message,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('commerce_stock_reserve.settings')
      ->set('cart_expiration', $form_state->getValue('cart_expiration_enable'))
      ->set('cart_expiration_number', $form_state->getValue('number'))
      ->set('cart_expiration_unit', $form_state->getValue('unit'))
      ->set('message_enabled', $form_state->getValue('message_enabled'))
      ->set('message_text', $form_state->getValue('message_text'))
      ->save();
  }
}
