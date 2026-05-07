<?php

namespace Drupal\commerce_mrspay\Service;

use Drupal\commerce_cart\CartProvider;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;


/**
 * MRS Pay Helper Class.
 *
 * @package Drupal\commerce_mrspay
 */
class MrsPayHelper {
  use StringTranslationTrait;

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
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Cart provider service
   *
   * @var \Drupal\commerce_cart\CartProvider
   */
  protected $cartProvider;

  /**
   * The module extension list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $extensionList;

  /**
   * Constructs a new class.
   *
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
    CartProvider $cart_provider,
    ModuleExtensionList $extension_list
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('commerce_mrspay');
    $this->messenger = $messenger;
    $this->configFactory = $config_factory;
    $this->cartProvider = $cart_provider;
    $this->extensionList = $extension_list;
  }

  public function getCredirCardLogos() {
    $path = $this->extensionList->getPath('commerce_mrspay');;
    $base_url = \Drupal::request()->getBasePath();
    $image_path = $base_url . '/' . $path . '/assets//images';

    $card_list = '<div class="mrspay-card-list">';
    $card_list .= '<span>' . $this->t('We receive: ') . '</span>';
    $card_list .= '<ul>';
    $card_list .= '  <li><img src="/' . $image_path . '/cc_visa.png" width="48px" height="48px" alt="Visa Card" /></li>';
    $card_list .= '  <li><img src="/' . $image_path . '/cc_mastercard.png" width="48px" height="48px" alt="Master Card" /></li>';
    $card_list .= '  <li><img src="/' . $image_path . '/cc_amex.png" width="48px" height="48px" alt="Amex Card" /></li>';
    $card_list .= '  <li><img src="/' . $image_path . '/cc_discover.png" width="48px" height="48px" alt="Discovery Card" /></li>';
    $card_list .= '</ul>';
    $card_list .= '</div>';

    return $card_list;
  }

  public function isMedicalFoodType(OrderInterface $order = NULL) {
    $found_medical_foods = 0;

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    foreach ($order->getItems() as $order_item) {
      $product_variation = $order_item->getPurchasedEntity();
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      $product = $order_item->getPurchasedEntity()->getProduct();
      if ($product->bundle() =='medical_foods') {
        $found_medical_foods = 1;
        break;
      }
    }

    return $found_medical_foods;
  }


  public function isTheramineType(OrderInterface $order) {
    $found_theramine_products = 0;
    $theramine_product_ids = $this->configFactory->get('custom_orders.settings')->get('medela_theramine_products');
    $theramine_products = explode(',',$theramine_product_ids);

    $order_items = $order->getItems();

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    foreach ($order_items as $order_item) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
      $product = $order_item->getPurchasedEntity()->getProduct();
      if ($product->bundle() =='medical_foods') {
        // Check if theramine products
        if (in_array($product->id(),$theramine_products) && $order_item->getQuantity() > 1) {
          $found_theramine_products = 1;
        }
        break;
      }
    }

    return $found_theramine_products;
  }

  public function getCurrentOrder() {
    // Returns an array of carts, usually one.
    $carts = $this->cartProvider->getCarts();
    return !empty($carts) ? reset($carts) : NULL;
  }


  /**
   * {@inheritdoc}
   */
  public function getCustomerProfileIDs() {
    $order = $this->getCurrentOrder();
    $found_medical_foods = 0;
    if ($order && $this->isMedicalFoodType($order)) {
      $found_medical_foods = 1;
    }

    $customer_id = 0;
    $payment_id = 0;
    $is_clinic_profile = 0;
    $is_customer_profile = 0;
    $clinic_nid = 0;

    if ($this->account->isAnonymous() && $found_medical_foods) {
      // Nothing to do here
    }
    elseif ($this->account->isAuthenticated() && in_array('customer', $this->account->getRoles()) && $found_medical_foods) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $customer_id = $account->hasField('field_customer_id') && !$account->get('field_customer_id')->isEmpty() ?
        $account->get('field_customer_id')->value : 0;
      $payment_id = $account->hasField('field_payment_id') && !$account->get('field_payment_id')->isEmpty() ?
        $account->get('field_payment_id')->value : 0;
      $is_customer_profile = 1;
    }
    elseif ($this->account->isAuthenticated() && !in_array('customer', $this->account->getRoles()) && $found_medical_foods == 0) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $clinic_nid = $account->hasField('field_associated_clinic') && !$account->get('field_associated_clinic')->isEmpty() ?
        $account->get('field_associated_clinic')->target_id : 0;
      if ($clinic_nid) {
        /** @var \Drupal\node\NodeInterface $clinic */
        $clinic = $this->entityTypeManager->getStorage('node')->load($clinic_nid);
        if ($clinic) {
          $customer_id = $clinic->hasField('field_customer_id') && !$clinic->get('field_customer_id')->isEmpty() ?
            $clinic->get('field_customer_id')->value : 0;
          $payment_id = $clinic->hasField('field_payment_id') && !$clinic->get('field_payment_id')->isEmpty() ?
            $clinic->get('field_payment_id')->value : 0;
          $is_clinic_profile = 1;
        }
      }
    }
    elseif ($this->account->isAuthenticated() && in_array('administrator', $this->account->getRoles())) {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      if ($found_medical_foods) {
        $customer_id = $account->hasField('field_customer_id') && !$account->get('field_customer_id')->isEmpty() ?
          $account->get('field_customer_id')->value : 0;
        $payment_id = $account->hasField('field_payment_id') && !$account->get('field_payment_id')->isEmpty() ?
          $account->get('field_payment_id')->value : 0;
        $is_customer_profile = 1;
      }
      else {
        $clinic_nid = $account->hasField('field_associated_clinic') && !$account->get('field_associated_clinic')->isEmpty() ?
          $account->get('field_associated_clinic')->target_id : 0;
        if ($clinic_nid) {
          /** @var \Drupal\node\NodeInterface $clinic */
          $clinic = $this->entityTypeManager->getStorage('node')->load($clinic_nid);
          if ($clinic) {
            $customer_id = $clinic->hasField('field_customer_id') && !$clinic->get('field_customer_id')->isEmpty() ?
              $clinic->get('field_customer_id')->value : 0;
            $payment_id = $clinic->hasField('field_payment_id') && !$clinic->get('field_payment_id')->isEmpty() ?
              $clinic->get('field_payment_id')->value : 0;
            $is_clinic_profile = 1;
          }
        }
      }
    }
    elseif ($this->account->isAuthenticated() && $found_medical_foods == 0) {
      // Nothing to do here
    }

    return [
      'customer_id' => $customer_id,
      'payment_id' => $payment_id,
      'is_clinic_profile' => $is_clinic_profile,
      'is_customer_profile' => $is_customer_profile,
      'clinic_nid' => $clinic_nid,
    ];
  }

  public function checkStoreInvoiceStatus(int $clinic_nid) {
    if (empty($clinic_nid)) {
      return 0;
    }

    $value = $this->database->select('node__field_store_invoice_payment', 'n')
      ->condition('n.entity_id', $clinic_nid)
      ->condition('n.bundle', 'clinic')
      ->fields('n', ['field_store_invoice_payment_value'])
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($value == 'Yes') {
      return 1;
    }
    else {
      return 0;
    }
  }

  public function checkStorePaymentStatus(int $clinic_nid) {
    if (empty($clinic_nid)) {
      return 0;
    }

    $value = $this->database->select('node__field_enable_payment_mode_stored', 'n')
      ->condition('n.entity_id', $clinic_nid)
      ->condition('n.bundle', 'clinic')
      ->fields('n', ['field_enable_payment_mode_stored_value'])
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($value == 1) {
      return 1;
    }
    else {
      return 0;
    }
  }

  /**
   * Keyed array of MRS payment parameters
   */
  public function getPurchaseParameters(OrderInterface $order) {
    $currency_code = $order->getTotalPrice()->getCurrencyCode();
    $amount = $order->getTotalPrice()->getNumber();

    $billing_profile = $order->getBillingProfile();
    /** @var \Drupal\address\AddressInterface $address */
    $address = $billing_profile->get('address')->first();

    $profiles = $order->collectProfiles();
    $shipping_address = [];
    if (isset($profiles['shipping']) && !$profiles['shipping']->get('address')->isEmpty()) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
      $shipping_address = $profiles['shipping']->get('address')->first();
    }

    // Build the data array that will be translated into hidden form values.
    $data = [
      'merchant_order_id' => $order->id(),
      'amount' => $amount,

      // Populate the billing information page
      'first_name' => $address ? $address->getGivenName() : '',
      'last_name' => $address ? $address->getFamilyName() : '',
      'card_holder_name' => $address ? $address->getGivenName() . (!empty($address->getFamilyName()) ? ' ' . $address->getFamilyName() : '') : '',
      'city' => $address ? $address->getLocality() : '',
      'country' => $address ? $address->getCountryCode() : '',
      'state' => $address ? $address->getAdministrativeArea() : '',
      'zip' => $address ? $address->getPostalCode() : '',
      'street_address' => $address ? $address->getAddressLine1() : '',
      'street_address2' => $address ? $address->getAddressLine2() : '',
      'address' => $address ? substr($address->getAddressLine1() . ' ' . $address->getAddressLine2(), 0, 60) : '',
      'email' => $order->getEmail(),

      // Populate the shipping address
      'ship_name' => $shipping_address ? $shipping_address->getGivenName() . ' ' . $shipping_address->getFamilyName() : '',
      'ship_country' => $shipping_address ? $shipping_address->getCountryCode() : '',
      'ship_city' => $shipping_address ? $shipping_address->getLocality() : '',
      'ship_state' => $shipping_address && ($shipping_address->getCountryCode() == 'US' || $shipping_address->getCountryCode() == 'CA') ? $shipping_address->getAdministrativeArea() : 'XX',
      'ship_zip' => $shipping_address ? $shipping_address->getPostalCode() : '',
      'ship_street_address' => $shipping_address ? $shipping_address->getAddressLine1() : '',
      'ship_street_address2' => $shipping_address ? $shipping_address->getAddressLine2() : '',
    ];

    return $data;
  }

  public function getCardType(string $number): string {
    $number = preg_replace('/\D/', '', $number);

    $types = [
      'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
      'mastercard' => '/^(5[1-5][0-9]{14}|2(2[2-9][0-9]{12}|[3-6][0-9]{13}|7[01][0-9]{12}|720[0-9]{12}))$/',
      'amex' => '/^3[47][0-9]{13}$/',
      'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
      'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
      'jcb' => '/^(2131|1800|35\d{3})\d{11}$/',
    ];

    foreach ($types as $type => $regex) {
      if (preg_match($regex, $number)) {
        return $type;
      }
    }

    return 'unknown';
  }

}
