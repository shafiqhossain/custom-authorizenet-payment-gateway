<?php

namespace Drupal\commerce_mrspay\Plugin\Commerce\PaymentGateway;

use AuthorizeNetPaymentProfile;
use Drupal\authnet\Service\AuthnetAimManager;
use Drupal\authnet\Service\AuthnetArbManager;
use Drupal\authnet\Service\AuthnetCimManager;
use Drupal\commerce_mrspay\Service\MrsPayHelper;
use Drupal\commerce_mrspay\Service\MrsPayMailManager;
use Drupal\commerce_payment\Annotation\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Mrspay payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_mrspay",
 *   label = "Mrspay",
 *   display_label = "Mrspay",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_mrspay\PluginForm\Mrspay\PaymentMethodAddForm",
 *   },
 *   modes = {
 *     "test" = @Translation("Sandbox"),
 *     "live" = @Translation("Production"),
 *   },
 *   payment_type = "payment_default",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex",
 *     "dinersclub",
 *     "discover",
 *     "jcb",
 *     "mastercard",
 *     "visa",
 *     "unionpay",
 *   },
 * )
 */
class Mrspay extends OnsitePaymentGatewayBase implements MrspayInterface {
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
   * The base helper service.
   *
   * @var \Drupal\mrs_base\Service\BaseHelper
   */
  protected $baseHelper;

  /**
   * The mrs pay helper service.
   *
   * @var MrspayHelper
   */
  protected $mrspayHelper;

  /**
   * The mrs pay mail manager service.
   *
   * @var MrspayMailManager
   */
  protected $mrspayMailManager;

  /**
   * The authorize.net CIM manager service.
   *
   * @var AuthnetCimManager
   */
  protected $authnetCimManager;

  /**
   * The authorize.net AIM manager service.
   *
   * @var AuthnetAimManager
   */
  protected $authnetAimManager;

  /**
   * The authorize.net ARB manager service.
   *
   * @var AuthnetArbManager
   */
  protected $authnetArbManager;

  /**
   * The card owner name.
   *
   * @var String
   */
  protected $ownerName;

  /**
   * The card first name.
   *
   * @var String
   */
  protected $firstName;

  /**
   * The card last name.
   *
   * @var String
   */
  protected $lastName;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->account = $container->get('current_user');
    $instance->database = $container->get('database');
    $instance->configFactory = $container->get('config.factory');
    $instance->logger = $container->get('logger.factory')->get('commerce_mrspay');
    $instance->baseHelper = $container->get('base.helper');
    $instance->mrspayHelper = $container->get('commerce_mrspay.helper');
    $instance->mrspayMailManager = $container->get('commerce_mrspay.mail_manager');
    $instance->authnetCimManager = $container->get('authnet.cim_manager');
    $instance->authnetAimManager = $container->get('authnet.aim_manager');
    $instance->authnetArbManager = $container->get('authnet.arb_manager');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $default_configuration = [
      'enable_credit_card_icons' => TRUE,
    ];
    return $default_configuration + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['enable_credit_card_icons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Credit Card Icons'),
      '#description' => $this->t('Enabling this setting will display credit card icons in the payment section during checkout.'),
      '#default_value' => $this->configuration['enable_credit_card_icons'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['enable_credit_card_icons'] = $values['enable_credit_card_icons'];
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    $paid_amount = $payment->getAmount();
    $currency = $paid_amount->getCurrencyCode();

    // Get order from payment
    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
    $order = $payment->getOrder();
    $amount = 0;
    if ($order) {
      $order_id = $order->id();
      $amount = $order->getTotalPrice()->getNumber();
    }

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());

    // Standard card info (visible in admin + customer UI)
    $gateway_data = json_decode($payment_method->getRemoteId(), TRUE);

    $last4 = $payment_method->get('card_number')->value;

    // Custom data
    $mrs_payment_type = isset($gateway_data['mrs_payment_type']) ? $gateway_data['mrs_payment_type'] : 0;
    $is_clinic_profile = isset($gateway_data['is_clinic_profile']) ? $gateway_data['is_clinic_profile'] : 0;
    $is_customer_profile = isset($gateway_data['is_customer_profile']) ? $gateway_data['is_customer_profile'] : 0;
    $clinic_nid = isset($gateway_data['clinic_nid']) ? $gateway_data['clinic_nid'] : 0;
    $card_first_name = $this->firstName;
    $card_last_name = $this->lastName;
    $first_name = $account->hasField('field_first_name') && !$account->get('field_first_name')->isEmpty() ? $account->get('field_first_name')->value : $card_first_name;
    $last_name = $account->hasField('field_last_name') && !$account->get('field_last_name')->isEmpty() ? $account->get('field_last_name')->value : $card_last_name;

    $customer_profile_id = isset($gateway_data['customer_profile_id']) ? $gateway_data['customer_profile_id'] : 0;
    $customer_payment_profile_id = isset($gateway_data['customer_payment_profile_id']) ? $gateway_data['customer_payment_profile_id'] : 0;
    $customer_shipping_address_id = 0;

    // Find the product type
    $has_medical_foods = $this->mrspayHelper->isMedicalFoodType($order);
    $has_theramine_products = $this->mrspayHelper->isTheramineType($order);;

    // Update order state and fields.
    if ($this->account->isAuthenticated() && in_array('customer', $this->account->getRoles()) && $has_medical_foods) {
      if ($mrs_payment_type == 0) {  // New Credit Card
        $response = $this->authnetCimManager->cimTransactionAuthorizeAndCapture($customer_profile_id, $customer_payment_profile_id, $customer_shipping_address_id, $amount, [], 'ORD-' . $order->id());
        if ($response['status'] == 1) {
          $transaction_id = $response['transaction_id'];
          // $subscription_id = $response['subscription_id'];

          $order->set('field_subscription_no', '');
          $order->set('field_payment_type', $mrs_payment_type);

          // Save the billing info - aim
          $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
            'type' => 'billing_info',
            'field_tx_number' => $transaction_id,
            'field_tx_date' => date('Y-m-d'),
            'field_rx_amount' => $amount,
            'field_tx_status' => 'Successful',
            'field_cc_last4' => $last4,
            'field_sequence_number' => 0,
            'field_transaction_type' => 1,  // Auto
          ]);
          $paragraph->save();

          $order->field_billing_info[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];

          // Now create recurring for subscription
          $theramine_recurring_option = $this->configFactory->get('custom_orders.settings')->get('medela_theramine_recurring_option');
          if ($theramine_recurring_option && $has_theramine_products) {
            $subscription_name = 'Theramine profile of ' . $first_name . ' ' . $last_name;
            $subscription_start_date = date('Y-m-d', strtotime('+1 months'));

            $response = $this->authnetArbManager->cimArbCreateSubscription($customer_profile_id, $customer_payment_profile_id, 0, $subscription_name, $amount, $subscription_start_date, 1, 'months', 99, 'THR-' . $order->id());
            $subscription_id = '';
            if ($response['status'] == 1) {
              $subscription_id = $response['subscription_id'];
              $order->set('field_subscription_no', $subscription_id);
              $order->set('field_subscription_type', 2);  // Unlimited
            }
          }
          $order->save();

          // Assign the payment remote id by transaction id
          $payment->setRemoteId($transaction_id);
        }
        else {
          $this->logger->error( print_r($response, TRUE));
          $message = $this->t('Transaction failed: @reason', [
            '@reason' => $response['message'],
          ]);
          $this->messenger()->addError($message);
          throw HardDeclineException::createForPayment($payment, $message);
        }
      }
      elseif ($mrs_payment_type == 1) {  // Stored Credit Card
        if (empty($customer_profile_id) || empty($customer_payment_profile_id)) {
          throw PaymentGatewayException::createForPayment($payment, $this->t('No stored profile found.'));
        }

        // Check and update billTo first name and last name
        $response = $this->authnetCimManager->cimGetCustomerPaymentProfile($customer_profile_id, $customer_payment_profile_id);
        if ($response['status'] == 0) {
          throw PaymentGatewayException::createForPayment($payment, $this->t('Failed to retrieve payment profile.'));
        }

        $cc_number = (isset($response['profile_data']['card_number']) ? trim($response['profile_data']['card_number']) : '');
        $billTo_firstName = (isset($response['profile_data']['customer_first_name']) ? trim($response['profile_data']['customer_first_name']) : '');
        $billTo_lastName = (isset($response['profile_data']['customer_last_name']) ? trim($response['profile_data']['customer_last_name']) : '');
        $billTo_address = (isset($response['profile_data']['customer_address']) ? trim($response['profile_data']['customer_address']) : '');
        $billTo_city = (isset($response['profile_data']['customer_city']) ? trim($response['profile_data']['customer_city']) : '');
        $billTo_state = (isset($response['profile_data']['customer_state']) ? trim($response['profile_data']['customer_state']) : '');
        $billTo_zip = (isset($response['profile_data']['customer_zip']) ? trim($response['profile_data']['customer_zip']) : '');

        if (empty($billTo_firstName) || empty($billTo_lastName)) {
          $first_name = preg_replace('#\d+#', '', $first_name);
          $last_name = preg_replace('#\d+#', '', $last_name);

          $billTo_firstName = (!empty($billTo_firstName) ? $billTo_firstName : $first_name);
          $billTo_lastName = (!empty($billTo_lastName) ? $billTo_lastName : (!empty($last_name) ? $last_name : $billTo_firstName));

          $paymentProfile = new AuthorizeNetPaymentProfile;
          $paymentProfile->customerType = "individual";
          $paymentProfile->payment->creditCard->cardNumber = $cc_number;
          $paymentProfile->payment->creditCard->expirationDate = "XXXX";
          $paymentProfile->billTo->firstName = $billTo_firstName;
          $paymentProfile->billTo->lastName = $billTo_lastName;
          $paymentProfile->billTo->address = $billTo_address;
          $paymentProfile->billTo->city = $billTo_city;
          $paymentProfile->billTo->state = $billTo_state;
          $paymentProfile->billTo->zip = $billTo_zip;

          $result = $this->authnetCimManager->cimRepairCustomerPaymentProfile($customer_profile_id, $customer_payment_profile_id, $paymentProfile, "none");
          if ($result['status'] == 0) {
            throw PaymentGatewayException::createForPayment($payment, $this->t('Failed to update payment profile.'));
          }
        }

        $response = $this->authnetCimManager->cimTransactionAuthorizeAndCapture($customer_profile_id, $customer_payment_profile_id, 0, $amount, [], 'ORD-' . $order->id());
        if ($response['status'] == 1) {
          $transaction_id = $response['transaction_id'];
          // $subscription_id = $response['subscription_id'];

          $order->set('field_subscription_no', '');
          $order->set('field_payment_type', $mrs_payment_type);

          // Save the billing info - aim
          $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
            'type' => 'billing_info',
            'field_tx_number' => $transaction_id,
            'field_tx_date' => date('Y-m-d'),
            'field_rx_amount' => $amount,
            'field_tx_status' => 'Successful',
            'field_cc_last4' => '',
            'field_sequence_number' => 0,
            'field_transaction_type' => 1,  // Auto
          ]);
          $paragraph->save();

          $order->field_billing_info[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];

          // Now create recurring for subscription
          $theramine_recurring_option = $this->configFactory->get('custom_orders.settings')->get('medela_theramine_recurring_option');
          if ($theramine_recurring_option && $has_theramine_products) {
            $subscription_name = 'Theramine profile of ' . $first_name . ' ' . $last_name;
            $subscription_start_date = date('Y-m-d', strtotime('+1 months'));

            $response = $this->authnetArbManager->cimArbCreateSubscription($customer_profile_id, $customer_payment_profile_id, '', $subscription_name, $amount, $subscription_start_date, 1, 'months', 99, 'THR-' . $order->id());
            if ($response['status'] == 1) {
              $subscription_id = $response['subscription_id'];
              $order->set('field_subscription_no', $subscription_id);
              $order->set('field_subscription_type', 2);  // Unlimited
            }
          }
          $order->save();

          // Assign the payment remote id by transaction id
          $payment->setRemoteId($transaction_id);
        }
        else {
          $this->logger->error( print_r($response, TRUE));
          $message = $this->t('Transaction failed: @reason', [
            '@reason' => $response['message'],
          ]);
          $this->messenger()->addError($message);
          throw HardDeclineException::createForPayment($payment, $message);
        }
      }
      else {
        throw HardDeclineException::createForPayment($payment, 'Payment type is not valid.');
      }
    }
    elseif ($this->account->id() && !in_array('customer', $this->account->getRoles()) && $has_medical_foods == 0) {
      if ($mrs_payment_type == 0) {  // New Credit Card
        $response = $this->authnetCimManager->cimTransactionAuthorizeAndCapture($customer_profile_id, $customer_payment_profile_id, 0, $amount, [], 'ORD-' . $order->id());
        if ($response['status'] == 1) {
          $transaction_id = $response['transaction_id'];
          // $subscription_id = $response['subscription_id'];

          $order->set('field_subscription_no', '');
          $order->set('field_payment_type', $mrs_payment_type);

          // Save the billing info - aim
          $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
            'type' => 'billing_info',
            'field_tx_number' => $transaction_id,
            'field_tx_date' => date('Y-m-d'),
            'field_rx_amount' => $amount,
            'field_tx_status' => 'Successful',
            'field_cc_last4' => $last4,
            'field_sequence_number' => 0,
            'field_transaction_type' => 1,  // Auto
          ]);
          $paragraph->save();

          $order->field_billing_info[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];
          $order->save();

          // Assign the payment remote id by transaction id
          $payment->setRemoteId($transaction_id);
        }
        else {
          $this->logger->error( print_r($response, TRUE));
          throw HardDeclineException::createForPayment($payment, $this->t('Transaction failed: @reason', [
            '@reason' => $response['message'],
          ]));
        }
      }
      elseif ($mrs_payment_type == 2) {  // Clinic Stored Credit Card
        if (empty($customer_profile_id) || empty($customer_payment_profile_id)) {
          throw PaymentGatewayException::createForPayment($payment, $this->t('No stored profile found.'));
        }

        // Check and update billTo first name and last name
        $response = $this->authnetCimManager->cimGetCustomerPaymentProfile($customer_profile_id, $customer_payment_profile_id);
        if ($response['status'] == 0) {
          throw PaymentGatewayException::createForPayment($payment, $this->t('Failed to retrieve payment profile.'));
        }

        $cc_number = (isset($response['profile_data']['card_number']) ? trim($response['profile_data']['card_number']) : '');
        $billTo_firstName = (isset($response['profile_data']['customer_first_name']) ? trim($response['profile_data']['customer_first_name']) : '');
        $billTo_lastName = (isset($response['profile_data']['customer_last_name']) ? trim($response['profile_data']['customer_last_name']) : '');
        $billTo_address = (isset($response['profile_data']['customer_address']) ? trim($response['profile_data']['customer_address']) : '');
        $billTo_city = (isset($response['profile_data']['customer_city']) ? trim($response['profile_data']['customer_city']) : '');
        $billTo_state = (isset($response['profile_data']['customer_state']) ? trim($response['profile_data']['customer_state']) : '');
        $billTo_zip = (isset($response['profile_data']['customer_zip']) ? trim($response['profile_data']['customer_zip']) : '');

        if (empty($billTo_firstName) || empty($billTo_lastName)) {
          $billTo_firstName = (!empty($billTo_firstName) ? $billTo_firstName : $first_name);
          $billTo_lastName = (!empty($billTo_lastName) ? $billTo_lastName : (!empty($last_name) ? $last_name : $billTo_firstName));

          $paymentProfile = new AuthorizeNetPaymentProfile;
          $paymentProfile->customerType = "individual";
          $paymentProfile->payment->creditCard->cardNumber = $cc_number;
          $paymentProfile->payment->creditCard->expirationDate = "XXXX";
          $paymentProfile->billTo->firstName = $billTo_firstName;
          $paymentProfile->billTo->lastName = $billTo_lastName;
          $paymentProfile->billTo->address = $billTo_address;
          $paymentProfile->billTo->city = $billTo_city;
          $paymentProfile->billTo->state = $billTo_state;
          $paymentProfile->billTo->zip = $billTo_zip;

          $result = $this->authnetCimManager->cimRepairCustomerPaymentProfile($customer_profile_id, $customer_payment_profile_id, $paymentProfile, "none");
          if ($result['status'] == 0) {
            throw PaymentGatewayException::createForPayment($payment, $this->t('Failed to update payment profile.'));
          }
        }

        $response = $this->authnetCimManager->cimTransactionAuthorizeAndCapture($customer_profile_id, $customer_payment_profile_id, 0, $amount, [], 'ORD-' . $order->id());
        if ($response['status'] == 1) {
          $transaction_id = $response['transaction_id'];
          // $subscription_id = $response['subscription_id'];

          $order->set('field_subscription_no', '');
          $order->set('field_payment_type', $mrs_payment_type);

          // Save the billing info - aim
          $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
            'type' => 'billing_info',
            'field_tx_number' => $transaction_id,
            'field_tx_date' => date('Y-m-d'),
            'field_rx_amount' => $amount,
            'field_tx_status' => 'Successful',
            'field_cc_last4' => '',
            'field_sequence_number' => 0,
            'field_transaction_type' => 1,  // Auto
          ]);
          $paragraph->save();

          $order->field_billing_info[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];
          $order->save();

          // Assign the payment remote id by transaction id
          $payment->setRemoteId($transaction_id);
        }
        else {
          $this->logger->error( print_r($response, TRUE));
          $message = $this->t('Transaction failed: @reason', [
            '@reason' => $response['message'],
          ]);
          $this->messenger()->addError($message);
          throw HardDeclineException::createForPayment($payment, $message);
        }
      }
      elseif ($mrs_payment_type == 3) {  // Invoice
        // Nothing to do, just save it
        $transaction_id = '';

        $order->set('field_subscription_no', '');
        $order->set('field_payment_type', $mrs_payment_type);

        // Save the billing info - aim
        $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
          'type' => 'billing_info',
          'field_tx_number' => $transaction_id,
          'field_tx_date' => date('Y-m-d'),
          'field_rx_amount' => $amount,
          'field_tx_status' => 'Invoice',
          'field_cc_last4' => '',
          'field_sequence_number' => 0,
          'field_transaction_type' => 1,  // Auto
        ]);
        $paragraph->save();

        $order->field_billing_info[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        $order->save();

        // Assign the payment remote id by transaction id
        $payment->setRemoteId($transaction_id);
      }
      else {
        throw HardDeclineException::createForPayment($payment, 'Payment type is not valid.');
      }
    }
    else {
      $response = $this->authnetCimManager->cimTransactionAuthorizeAndCapture($customer_profile_id, $customer_payment_profile_id, 0, $amount, [], 'ORD-' . $order->id());
      if ($response['status'] == 1) {
        $transaction_id = $response['transaction_id'];
        // $subscription_id = $response['subscription_id'];

        $order->set('field_subscription_no', '');
        $order->set('field_payment_type', $mrs_payment_type);

        // Save the billing info - aim
        $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
          'type' => 'billing_info',
          'field_tx_number' => $transaction_id,
          'field_tx_date' => date('Y-m-d'),
          'field_rx_amount' => $amount,
          'field_tx_status' => 'Successful',
          'field_cc_last4' => $last4,
          'field_sequence_number' => 0,
          'field_transaction_type' => 1,  // Auto
        ]);
        $paragraph->save();

        $order->field_billing_info[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        $order->save();

        // Assign the payment remote id by transaction id
        $payment->setRemoteId($transaction_id);
      }
      else {
        $this->logger->error( print_r($response, TRUE));
        $message = $this->t('Transaction failed: @reason', [
          '@reason' => $response['message'],
        ]);
        $this->messenger()->addError($message);
        throw HardDeclineException::createForPayment($payment, $message);
      }
    }

    $payment->setState('completed');
    $payment->save();

    // Load the billing profile.
    $billing_profile = $order->getBillingProfile();

    $address1 = $address2 = $city = $state = $zipcode = $first_name = $last_name = $full_name = '';
    $country = 'US';
    if ($billing_profile) {
      /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */

      // Get the address field (AddressFieldItem).
      $address = $billing_profile->get('address')->first();

      if ($address) {
        $address1 = $address->getAddressLine1();
        $address2 = $address->getAddressLine2();
        $city = $address->getLocality();
        $state = $address->getAdministrativeArea();
        $zipcode = $address->getPostalCode();
        $country = $address->getCountryCode();
        $first_name = $address->getGivenName();
        $last_name = $address->getFamilyName();
        $full_name = $first_name . ' ' . $last_name;
      }
    }

    $billing_address = $address1 . ' ' . $address2;
    $billing_city = $city;
    $billing_state = $state;
    $billing_zip = $zipcode;

    $account->set('field_billing_address', $billing_address);
    $account->set('field_billing_city', $billing_city);
    $account->set('field_billing_state', $billing_state);
    $account->set('field_billing_zip', $billing_zip);
    $account->save();

    // Send notification to Order Manager
    if (in_array('order_manager', $account->getRoles()) || in_array('administrator', $account->getRoles())) {
      $clinic_nid = $order->hasField('field_clinic_name') && !$order->get('field_clinic_name')->isEmpty() ?
        $order->get('field_clinic_name')->target_id : 0;
    }
    else {
      $clinic_nid = $account->hasField('field_associated_clinic') && !$account->get('field_associated_clinic')->isEmpty() ?
        $account->get('field_associated_clinic')->target_id : 0;
    }

    /** @var \Drupal\node\NodeInterface $clinic */
    $clinic = $this->entityTypeManager->getStorage('node')->load($clinic_nid);
    $this->mrspayMailManager->sendOrderReceiptMail($order, $clinic, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'order_id', 'mrs_payment_type',
    ];
    foreach ($required_keys as $required_key) {
      if (!isset($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());

    // Required: validate that we have everything we need
    $mrs_payment_type = isset($payment_details['mrs_payment_type']) ? $payment_details['mrs_payment_type'] : 0;
    $force_create_profile = isset($payment_details['force_create_profile']) ? $payment_details['force_create_profile'] : 0;

    $first_name = !empty($payment_details['owner']['first_name']) ? trim($payment_details['owner']['first_name']) : '';
    $last_name = !empty($payment_details['owner']['last_name']) ? trim($payment_details['owner']['last_name']) : '';

    // Get the existing customer profile id, payment id
    $customer_profile_data = $this->mrspayHelper->getCustomerProfileIDs();
    $customer_profile_id = isset($customer_profile_data['customer_id']) ? $customer_profile_data['customer_id'] : 0;
    $payment_profile_id = isset($customer_profile_data['payment_id']) ? $customer_profile_data['payment_id'] : 0;
    $is_clinic_profile = isset($customer_profile_data['is_clinic_profile']) ? $customer_profile_data['is_clinic_profile'] : 0;
    $is_customer_profile = isset($customer_profile_data['is_customer_profile']) ? $customer_profile_data['is_customer_profile'] : 0;
    $clinic_nid = isset($customer_profile_data['clinic_nid']) ? $customer_profile_data['clinic_nid'] : 0;
    if ($clinic_nid) {
      /** @var \Drupal\node\NodeInterface $clinic */
      $clinic = $this->entityTypeManager->getStorage('node')->load($clinic_nid);
    }

    $gateway_data = [];

    $profile_name = $first_name . ' ' . $last_name;
    $customer_id = 'Customer-' . $account->id();
    $email = $account->getEmail();;

    // $validation_mode = 'testMode';
    $validation_mode = 'none';

    if ($mrs_payment_type == 0) { // New Credit Card
      if (empty($customer_profile_id)) {
        $response_cprofile = $this->authnetCimManager->cimCreateCustomerProfile($profile_name, $customer_id, $email);

        if ($response_cprofile['status'] == 1) {
          $customer_profile_id = $response_cprofile['customer_profile_id'];

          // Save the profile
          if ($is_customer_profile) {
            $account->set('field_customer_id', $customer_profile_id);
            $account->save();
          }
          elseif ($is_clinic_profile && $clinic_nid && $clinic) {
            $clinic->set('field_customer_id', $customer_profile_id);
            $clinic->save();
          }
        }
        else {
          $error_message = $response_cprofile['message'];
          if (strpos($error_message, 'duplicate record') !== false) {
            preg_match('/ID\s+(\d+)/', $error_message, $matches);
            $duplicate_profile_id = isset($matches[1]) ? $matches[1] : 0;
            if ($duplicate_profile_id) {
              throw new PaymentGatewayException('Customer profile creation failed, due to duplicate customer profile ID: ' . $duplicate_profile_id. '. Please update manually.');
            }
            else {
              throw new PaymentGatewayException('Customer profile creation failed, due to duplicate customer profile ID.');
            }
          }
          else {
            throw new PaymentGatewayException('Customer profile creation failed: ' . $response_cprofile['message']);
          }
        }
      }
      elseif (!empty($customer_profile_id) && $force_create_profile) {
        $response_cprofile = $this->authnetCimManager->cimCreateCustomerProfile($profile_name, $customer_id, $email);

        if ($response_cprofile['status'] == 1) {
          $customer_profile_id = $response_cprofile['customer_profile_id'];

          // Save the profile
          if ($is_customer_profile) {
            $account->set('field_customer_id', $customer_profile_id);
            $account->save();
          }
          elseif ($is_clinic_profile && $clinic_nid && $clinic) {
            $clinic->set('field_customer_id', $customer_profile_id);
            $clinic->save();
          }
        }
        else {
          $error_message = $response_cprofile['message'];
          if (strpos($error_message, 'duplicate record') !== false) {
            preg_match('/ID\s+(\d+)/', $error_message, $matches);
            $duplicate_profile_id = isset($matches[1]) ? $matches[1] : 0;
            if ($duplicate_profile_id) {
              throw new PaymentGatewayException('Customer profile creation failed, due to duplicate customer profile ID: ' . $duplicate_profile_id. '. Please update manually.');
            }
            else {
              throw new PaymentGatewayException('Customer profile creation failed, due to duplicate customer profile ID.');
            }
          }
          else {
            throw new PaymentGatewayException('Customer profile creation failed: ' . $response_cprofile['message']);
          }
        }
      }

      if (!empty($customer_profile_id) && empty($payment_profile_id)) {
        $response_pprofile = $this->authnetCimManager->cimCreateCustomerPaymentProfile($customer_profile_id, $payment_details['number'], $payment_details['expiration']['month'], $payment_details['expiration']['year'], $payment_details['security_code'],$validation_mode, $first_name, $last_name, '', '', '', '');

        if (isset($response_pprofile['status']) && $response_pprofile['status'] == 1) {
          $payment_profile_id = $response_pprofile['customer_payment_profile_id'];

          // Save the profile
          if ($is_customer_profile) {
            $account->set('field_payment_id', $payment_profile_id);
            $account->set('field_card_first_name', $first_name);
            $account->set('field_card_last_name', $last_name);
            $account->set('field_card_number_1', substr($payment_details['number'], -4));
            $account->set('field_expiry_month_1', $payment_details['expiration']['month']);
            $account->set('field_expiry_year_1', $payment_details['expiration']['year']);
            $account->save();
            $account->save();
          }
          elseif ($is_clinic_profile && $clinic_nid && $clinic) {
            $clinic->set('field_payment_id', $payment_profile_id);
            $clinic->set('field_card_first_name', $first_name);
            $clinic->set('field_card_last_name', $last_name);
            $clinic->set('field_card_number_1', substr($payment_details['number'], -4));
            $clinic->set('field_expiry_month_1', $payment_details['expiration']['month']);
            $clinic->set('field_expiry_year_1', $payment_details['expiration']['year']);
            $clinic->save();
            $clinic->save();
          }
        }
        else {
          $error_message = $response_pprofile['message'];
          if (strpos($error_message, 'duplicate record') !== false) {
            preg_match('/ID\s+(\d+)/', $error_message, $matches);
            $duplicate_profile_id = isset($matches[1]) ? $matches[1] : 0;
            if ($duplicate_profile_id) {
              throw new PaymentGatewayException('Payment profile creation failed, due to duplicate payment profile ID: ' . $duplicate_profile_id. '. Please update manually.');
            }
            else {
              throw new PaymentGatewayException('Payment profile creation failed, due to duplicate payment profile ID.');
            }
          }
          else {
            throw new PaymentGatewayException('Payment profile creation failed: ' . $response_pprofile['message']);
          }
        }
      }
      elseif (!empty($customer_profile_id) && !empty($payment_profile_id) && $force_create_profile) {
        $response_pprofile = $this->authnetCimManager->cimCreateCustomerPaymentProfile($customer_profile_id, $payment_details['number'], $payment_details['expiration']['month'], $payment_details['expiration']['year'], $payment_details['security_code'],$validation_mode, $first_name, $last_name, '', '', '', '');

        if (isset($response_pprofile['status']) && $response_pprofile['status'] == 1) {
          $payment_profile_id = $response_pprofile['customer_payment_profile_id'];

          // Save the profile
          if ($is_customer_profile) {
            $account->set('field_payment_id', $payment_profile_id);
            $account->set('field_card_first_name', $first_name);
            $account->set('field_card_last_name', $last_name);
            $account->set('field_card_number_1', substr($payment_details['number'], -4));
            $account->set('field_expiry_month_1', $payment_details['expiration']['month']);
            $account->set('field_expiry_year_1', $payment_details['expiration']['year']);
            $account->save();
            $account->save();
          }
          elseif ($is_clinic_profile && $clinic_nid && $clinic) {
            $clinic->set('field_payment_id', $payment_profile_id);
            $clinic->set('field_card_first_name', $first_name);
            $clinic->set('field_card_last_name', $last_name);
            $clinic->set('field_card_number_1', substr($payment_details['number'], -4));
            $clinic->set('field_expiry_month_1', $payment_details['expiration']['month']);
            $clinic->set('field_expiry_year_1', $payment_details['expiration']['year']);
            $clinic->save();
            $clinic->save();
          }
        }
        else {
          $error_message = $response_pprofile['message'];
          if (strpos($error_message, 'duplicate record') !== false) {
            preg_match('/ID\s+(\d+)/', $error_message, $matches);
            $duplicate_profile_id = isset($matches[1]) ? $matches[1] : 0;
            if ($duplicate_profile_id) {
              throw new PaymentGatewayException('Payment profile creation failed, due to duplicate payment profile ID: ' . $duplicate_profile_id. '. Please update manually.');
            }
            else {
              throw new PaymentGatewayException('Payment profile creation failed, due to duplicate payment profile ID.');
            }
          }
          else {
            throw new PaymentGatewayException('Payment profile creation failed: ' . $response_pprofile['message']);
          }
        }
      }

      // Store the profile ids
      $gateway_data['customer_profile_id'] = $customer_profile_id;
      $gateway_data['customer_payment_profile_id'] = $payment_profile_id;
    }
    elseif ($mrs_payment_type == 1) { // Stored Credit Card
      if (empty($customer_profile_id) || empty($payment_profile_id)) {
        throw new PaymentGatewayException('Customer profile and/or payment profile are empty.');
      }

      // Store the profile ids
      $gateway_data['customer_profile_id'] = $customer_profile_id;
      $gateway_data['customer_payment_profile_id'] = $payment_profile_id;
    }
    elseif ($mrs_payment_type == 2) { // Clinic Stored Credit Card
      if (empty($customer_profile_id) || empty($payment_profile_id)) {
        throw new PaymentGatewayException('Customer profile and/or payment profile are empty.');
      }

      // Store the profile ids
      $gateway_data['customer_profile_id'] = $customer_profile_id;
      $gateway_data['customer_payment_profile_id'] = $payment_profile_id;
    }
    elseif ($mrs_payment_type == 3) { // Invoice
      $gateway_data['customer_profile_id'] = $customer_profile_id;
      $gateway_data['customer_payment_profile_id'] = $payment_profile_id;
    }

    $last4 = '';
    if ($mrs_payment_type == 0) { // New Credit Card
      $number = preg_replace('/\D/', '', $payment_details['number']);
      $last4  = substr($number, -4);

      // Save standard Commerce fields (so they show in UI and are reusable)
      $payment_method->set('card_type', $this->mrspayHelper->getCardType($number));
      $payment_method->set('card_number', $last4);
      $payment_method->set('card_exp_month', $payment_details['expiration']['month']);
      $payment_method->set('card_exp_year', $payment_details['expiration']['year']);

      // Optional: save owner name (not a base field → use setData())
      $this->ownerName = (isset($payment_details['owner']['first_name']) ? trim($payment_details['owner']['first_name']) : '') . ' ' . (isset($payment_details['owner']['last_name']) ? trim($payment_details['owner']['last_name']) : '');
      $this->firstName = isset($payment_details['owner']['first_name']) ? trim($payment_details['owner']['first_name']) : '';
      $this->lastName = isset($payment_details['owner']['last_name']) ? trim($payment_details['owner']['last_name']) : '';
    }

    // Save custom fields
    $gateway_data['mrs_payment_type'] = $mrs_payment_type;
    $gateway_data['force_create_profile'] = $force_create_profile;
    $gateway_data['is_clinic_profile'] = $is_clinic_profile;
    $gateway_data['is_customer_profile'] = $is_customer_profile;
    $gateway_data['clinic_nid'] = $clinic_nid;

    $payment_method->setRemoteId(json_encode($gateway_data));
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    if ($amount !== NULL) {
      $payment->setAmount($amount);
    }

    try {
    }
    catch (Exception $e) {

    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $response = $this->authnetAimManager->aimVoid($payment->getRemoteId());
    if ($response['status'] == 1) {
      $payment->setState('authorization_voided');
      $payment->save();
    }
    else {
      throw HardDeclineException::createForPayment($payment, $this->t('Transaction void failed: @reason', [
        '@reason' => $response['message'],
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $response = $this->authnetAimManager->aimRefund($payment->getRemoteId(), $payment->getAmount()->getNumber());
    if ($response['status'] == 1) {
      $payment->setState('refunded');
      $payment->save();
    }
    else {
      throw HardDeclineException::createForPayment($payment, $this->t('Transaction refund failed: @reason', [
        '@reason' => $response['message'],
      ]));
    }
  }

  /**
   * Maps the Square credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Square credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType(string $card_type) {
    $map = [
      'AMERICAN_EXPRESS' => 'amex',
      'CHINA_UNIONPAY' => 'unionpay',
      'DISCOVER_DINERS' => 'dinersclub',
      'DISCOVER' => 'discover',
      'JCB' => 'jcb',
      'MASTERCARD' => 'mastercard',
      'VISA' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

}
