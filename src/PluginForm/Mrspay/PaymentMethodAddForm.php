<?php

namespace Drupal\commerce_mrspay\PluginForm\Mrspay;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Payment method add form for Square.
 */
class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var AccountInterface $account
   */
  protected $account;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The commerce cart provider service.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  protected $cartProvider;

  /**
   * The base helper service.
   *
   * @var \Drupal\mrs_base\Service\BaseHelper
   */
  protected $baseHelper;

  /**
   * The mrspay helper service.
   *
   * @var \Drupal\commerce_mrspay\Service\MrspayHelper
   */
  protected $mrspayHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->account = $container->get('current_user');
    $instance->database = $container->get('database');
    $instance->configFactory = $container->get('config.factory');
    $instance->logger = $container->get('logger.factory')->get('commerce_mrspay');
    $instance->cartProvider = $container->get('commerce_cart.cart_provider');
    $instance->baseHelper = $container->get('base.helper');
    $instance->mrspayHelper = $container->get('commerce_mrspay.helper');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_mrspay\Plugin\Commerce\PaymentGateway\Mrspay $plugin */
    $plugin = $this->plugin;

    $element['#attached']['library'][] = 'commerce_mrspay/mrspay-style';

    // Display credit card logos in checkout form.
    if ($plugin->getConfiguration()['enable_credit_card_icons']) {
      $element['#attached']['library'][] = 'commerce_mrspay/credit_card_icons';
      $element['#attached']['library'][] = 'commerce_payment/payment_method_icons';

      $supported_credit_cards = [];
      foreach ($plugin->getCreditCardTypes() as $credit_card) {
        $supported_credit_cards[] = $credit_card->getId();
      }

      $element['credit_card_logos'] = [
        '#theme' => 'commerce_mrspay_credit_card_logos',
        '#credit_cards' => $supported_credit_cards,
      ];
    }

    $order = $this->mrspayHelper->getCurrentOrder();
    $found_medical_foods = 0;
    if ($order && $this->mrspayHelper->isMedicalFoodType($order)) {
      $found_medical_foods = 1;
    }

    $element['card_swipe_status_message'] = [
      '#markup' => '',
      '#prefix' => '<div id="card-swipe-status-message">',
      '#suffix' => '</div>',
    ];
    $element['card_swipe_overlay'] = [
      '#markup' => '',
      '#prefix' => '<div id="card-swipe-overlay">',
      '#suffix' => '</div>',
    ];
    $element['card_swipe_failure'] = [
      '#markup' => '',
      '#prefix' => '<div id="card-swipe-failure">',
      '#suffix' => '</div>',
    ];
    $element['card_swipe_success'] = [
      '#markup' => '',
      '#prefix' => '<div id="card-swipe-success">',
      '#suffix' => '</div>',
    ];
    $element['card_swipe_properties'] = [
      '#markup' => '',
      '#prefix' => '<div id="card-swipe-properties">',
      '#suffix' => '</div>',
    ];

    $pay_types = [];

    if ($this->account->isAnonymous() && $found_medical_foods) {
      $pay_types[0] = t('New Credit Card');
    }
    elseif ($this->account->isAuthenticated() && in_array('customer', $this->account->getRoles()) && $found_medical_foods) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $clinic_nid = $account->hasField('field_associated_clinic') && !$account->get('field_associated_clinic')->isEmpty() ?
        $account->get('field_associated_clinic')->target_id : 0;
      $customer_id = $account->hasField('field_customer_id') && !$account->get('field_customer_id')->isEmpty() ?
        $account->get('field_customer_id')->value : 0;
      $payment_id = $account->hasField('field_payment_id') && !$account->get('field_payment_id')->isEmpty() ?
        $account->get('field_payment_id')->value : 0;

      $pay_types[0] = t('New Credit Card');

      if ($customer_id && $payment_id) {
        $pay_types[1] = t('Stored Credit Card');
      }
    }
    /*
    elseif ($this->account->isAuthenticated() && !empty(array_intersect(['administrator', 'order_manager'], $this->account->getRoles())) && $found_medical_foods == 0) {
      // Nothing to do here
    }
    */
    elseif ($this->account->isAuthenticated() && !in_array('customer', $this->account->getRoles()) && $found_medical_foods == 0) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $clinic_nid = $account->hasField('field_associated_clinic') && !$account->get('field_associated_clinic')->isEmpty() ?
        $account->get('field_associated_clinic')->target_id : 0;
      /** @var \Drupal\node\NodeInterface $clinic */
      $clinic = $this->entityTypeManager->getStorage('node')->load($clinic_nid);
      $customer_id = $clinic->hasField('field_customer_id') && !$clinic->get('field_customer_id')->isEmpty() ?
        $clinic->get('field_customer_id')->value : 0;
      $payment_id = $clinic->hasField('field_payment_id') && !$clinic->get('field_payment_id')->isEmpty() ?
        $clinic->get('field_payment_id')->value : 0;

      $pay_types[0] = $this->t('New Credit Card');

      if ($customer_id && $payment_id) {
        if ($this->mrspayHelper->checkStorePaymentStatus($clinic_nid)) {
          $pay_types[2] = $this->t('Clinic Stored Credit Card');
        }
        if ($this->mrspayHelper->checkStoreInvoiceStatus($clinic_nid)) {
          $pay_types[3] = $this->t('Invoice');
        }
      }
      elseif (empty($customer_id) || empty($payment_id)) {
        if ($this->mrspayHelper->checkStoreInvoiceStatus($clinic_nid)) {
          $pay_types[3] = $this->t('Invoice');
        }
      }
    }
    elseif ($this->account->isAuthenticated() && in_array('administrator', $this->account->getRoles())) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $clinic_nid = 0;
      if ($found_medical_foods) {
        $customer_id = $account->hasField('field_customer_id') && !$account->get('field_customer_id')->isEmpty() ?
          $account->get('field_customer_id')->value : 0;
        $payment_id = $account->hasField('field_payment_id') && !$account->get('field_payment_id')->isEmpty() ?
          $account->get('field_payment_id')->value : 0;
      }
      else {
        $clinic_nid = $account->hasField('field_associated_clinic') && !$account->get('field_associated_clinic')->isEmpty() ?
          $account->get('field_associated_clinic')->target_id : 0;
        /** @var \Drupal\node\NodeInterface $clinic */
        $clinic = $this->entityTypeManager->getStorage('node')->load($clinic_nid);
        $customer_id = $clinic->hasField('field_customer_id') && !$clinic->get('field_customer_id')->isEmpty() ?
          $clinic->get('field_customer_id')->value : 0;
        $payment_id = $clinic->hasField('field_payment_id') && !$clinic->get('field_payment_id')->isEmpty() ?
          $clinic->get('field_payment_id')->value : 0;
      }

      $pay_types[0] = $this->t('New Credit Card');

      if ($customer_id && $payment_id && $found_medical_foods) {
        $pay_types[1] = t('Stored Credit Card');
      }
      elseif ($customer_id && $payment_id && $found_medical_foods == 0) {
        if ($this->mrspayHelper->checkStorePaymentStatus($clinic_nid)) {
          $pay_types[2] = $this->t('Clinic Stored Credit Card');
        }
        if ($this->mrspayHelper->checkStoreInvoiceStatus($clinic_nid)) {
          $pay_types[3] = $this->t('Invoice');
        }
      }
      elseif (empty($customer_id) || empty($payment_id)) {
        if ($this->mrspayHelper->checkStoreInvoiceStatus($clinic_nid)) {
          $pay_types[3] = $this->t('Invoice');
        }
      }
    }
    elseif ($this->account->isAuthenticated() && $found_medical_foods == 0) {
      $pay_types[0] = $this->t('New Credit Card');
    }

    $payment_method_type = $form_state->hasValue('payment_method_type') ? $form_state->getValue('payment_method_type') : 0;
    $element['mrs_payment_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment Type'),
      '#options' => $pay_types,
      '#default_value' => $payment_method_type,
      '#required' => TRUE,
    ];

    // Only show credit card fields if New Credit Card is selected.
    $element['card_details'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Credit Card Details'),
      '#states' => [
        'visible' => [
          ':input[name="payment_information[add_payment_method][payment_details][mrs_payment_type]"]' => ['value' => 0],
        ],
      ],
    ];

    $element['card_details']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#default_value' => $form_state->hasValue('first_name') ? $form_state->getValue('first_name') : '',
      '#size' => 40,
      '#maxlength' => 255,
      '#required' => FALSE,
      '#prefix' => '<div class="mrspay-credit-card-name-wrapper">',
    ];
    $element['card_details']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#default_value' => $form_state->hasValue('last_name') ? $form_state->getValue('last_name') : '',
      '#size' => 40,
      '#maxlength' => 255,
      '#required' => FALSE,
      '#suffix' => '</div>',
    ];

    $element['card_details']['card_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Card Number'),
      '#default_value' => $form_state->hasValue('cc_number') ? $form_state->getValue('cc_number') : '',
      '#size' => 40,
      '#maxlength' => 50,
      '#required' => FALSE,
    ];
    $months = $this->baseHelper->getCardMonths(1);
    $element['card_details']['expire_month'] = [
      '#type' => 'select',
      '#title' => $this->t('Expire Month'),
      '#default_value' => $form_state->hasValue('expire_month') ? $form_state->getValue('expire_month') : date('m'),
      '#options' => $months,
      '#required' => FALSE,
      '#prefix' => '<div class="mrspay-credit-card-expiry-wrapper">',
    ];
    $years = $this->baseHelper->getCardYears(1);
    $element['card_details']['expire_year'] = [
      '#type' => 'select',
      '#title' => $this->t('Expire Year'),
      '#default_value' => $form_state->hasValue('expire_year') ? $form_state->getValue('expire_year') : date('Y'),
      '#options' => $years,
      '#required' => FALSE,
    ];
    $element['card_details']['card_cvv'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CVV'),
      '#required' => FALSE,
      '#size' => 40,
      '#maxlength' => 50,
      '#suffix' => '</div',
    ];

    $element['force_create_profile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force create customer profile and payment profile'),
      '#default_value' => 0,
      '#return_value' => 1,
      '#states' => [
        'visible' => [
          ':input[name="payment_information[add_payment_method][payment_details][mrs_payment_type]"]' => ['value' => 0],
        ],
      ],
    ];

    return $element;
  }


  /**
   * {@inheritdoc}
   */
  public function validateCreditCardForm(array &$element, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // DO NOT call parent::submitConfigurationForm()
    // DO NOT call $this->submitCreditCardForm() — it does nothing here
    $values = $form_state->getValue($form['#parents']);

    // Build the array exactly the way your plugin expects
    $payment_details = [
      'mrs_payment_type' => $values['payment_details']['mrs_payment_type'],
      'force_create_profile' => !empty($values['payment_details']['force_create_profile']) ? 1 : 0,
    ];

    // Attempt to get order from route during checkout.
    $order = \Drupal::routeMatch()->getParameter('commerce_order') ?: \Drupal::routeMatch()->getParameter('order');
    if ($order instanceof OrderInterface) {
      $payment_details['order_id'] = $order->id();
    }

    // Only add card data when "New Credit Card" is selected
    if ($values['payment_details']['mrs_payment_type'] == 0) {
      $payment_details += [
        'type'  => $this->mrspayHelper->getCardType($values['payment_details']['card_details']['card_number']),
        'number' => preg_replace('/\D/', '', $values['payment_details']['card_details']['card_number']),
        'expiration'     => [
          'month' => $values['payment_details']['card_details']['expire_month'],
          'year'  => $values['payment_details']['card_details']['expire_year'],
        ],
        'security_code'  => $values['payment_details']['card_details']['card_cvv'],
        'owner' => [
          'first_name' => $values['payment_details']['card_details']['first_name'],
          'last_name'  => $values['payment_details']['card_details']['last_name'],
        ],
      ];
    }

    // This line is the whole point — triggers the plugin
    $this->plugin->createPaymentMethod($this->entity, $payment_details);
  }
}
