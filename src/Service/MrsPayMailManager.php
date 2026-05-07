<?php

namespace Drupal\commerce_mrspay\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\mrs_base\Service\BaseHelper;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\symfony_mailer\Address;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\symfony_mailer\MailerHelperTrait;
use Drupal\user\Entity\User;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserInterface;

/**
 * Mrs Pay Mail Manager Class.
 *
 * @package Drupal\rx_store
 */
class MrsPayMailManager {
  use StringTranslationTrait;
  use MailerHelperTrait;

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
   * @var \Drupal\Core\Logger\LoggerChannelInterface
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
   * Stream Wrapper Manager service.
   *
   * @var StreamWrapperManagerInterface $streamWrapperManager
   */
  protected $streamWrapperManager;

  /**
   * Symfony Mail Factory.
   *
   * @var \Drupal\symfony_mailer\EmailFactory
   */
  protected $mailFactory;

  /**
   * The base helper service.
   *
   * @var \Drupal\mrs_base\Service\BaseHelper
   */
  protected $baseHelper;

  /**
   * Constructs a new class.
   *
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    EmailFactoryInterface $mail_factory,
    BaseHelper $base_helper
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('commerce_mrspay');
    $this->messenger = $messenger;
    $this->configFactory = $config_factory;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->mailFactory = $mail_factory;
    $this->baseHelper = $base_helper;
  }

  public function sendOrderReceiptMail(OrderInterface $order, ?NodeInterface $clinic, UserInterface $account) {
    $module = 'commerce_mrspay';
    $key = 'send_order_receipt';

    // To
    $full_name = $account->hasField('field_full_name') && !$account->get('field_full_name')->isEmpty() ?
      $account->get('field_full_name')->value : $account->getEmail();

    $field_rep_uid = $clinic && $clinic->hasField('field_acc') && !$clinic->get('field_acc')->isEmpty() ?
      $clinic->get('field_acc')->target_id : 0;
    if ($field_rep_uid) {
      /** @var \Drupal\user\UserInterface $field_rep_account */
      $field_rep_account = $this->entityTypeManager->getStorage('user')->load($field_rep_uid);
    }

    $clinical_support_uid = $clinic && $clinic->hasField('field_acct') && !$clinic->get('field_acct')->isEmpty() ?
      $clinic->get('field_acct')->target_id : 0;
    if ($clinical_support_uid) {
      /** @var \Drupal\user\UserInterface $clinical_support_account */
      $clinical_support_account = $this->entityTypeManager->getStorage('user')->load($clinical_support_uid);
    }

    $lang_code = $this->account->getPreferredLangcode();

    $to = [];
    $to[] = new Address($account->getEmail(), $account->getAccountName(), $lang_code);

    if ($field_rep_uid && $field_rep_account) {
      $to[] = new Address($field_rep_account->getEmail(), $field_rep_account->getAccountName(), $lang_code);
    }

    if ($clinical_support_uid && $clinical_support_account) {
      $to[] = new Address($clinical_support_account->getEmail(), $clinical_support_account->getAccountName(), $lang_code);
    }

    /*
    // Email to Order manager for all Order is moved to commerce_mrspay module under hook_commerce_checkout_complete
    $order_manager_mail = $this->configFactory->get('custom_rx.settings')->get('medela_order_manager_mail');
    if (!empty($order_manager_mail)) {
      $to[] = new Address($order_manager_mail, '', $lang_code);
    }
    */

    $from_name = $this->configFactory->get('system.site')->get('name');
    $from_mail = $this->configFactory->get('system.site')->get('mail');
    if (empty($from_mail)) {
      $from_mail = 'info@medelasolutions.com';
    }

    $subject = 'Order Receipt for ' . $order->getOrderNumber();

    $shipments = $order->get('shipments')->referencedEntities();
    $address1 = $address2 = $city = $state = $zip = '';
    $country = 'US';

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = null;
    if ($shipments) {
      $shipment = reset($shipments);
      $shipping_profile = $shipment->getShippingProfile();

      if ($shipping_profile) {
        $address = $shipping_profile->get('address')->first();

        if ($address) {
          $address1 = $address->getAddressLine1();
          $address2 = $address->getAddressLine2();
          $city = $address->getLocality();
          $state = $address->getAdministrativeArea();
          $zip = $address->getPostalCode();
          $country = $address->getCountryCode();
        }
      }
    }

    // Order total.
    $order_total = $order->getTotalPrice()->getNumber();
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $order_total = number_format($order_total, 2) . ' ' . $currency;

    // Load all payments for this order.
    if ($order->hasField('field_payment_type') && $order->get('field_payment_type')->value == 0) {
      $payment_method_name = 'Credit Card';
    }
    elseif ($order->hasField('field_payment_type') && $order->get('field_payment_type')->value == 3) {
      $payment_method_name = 'Invoice';
    }
    else {
      $payment_method_name = 'Credit Card';
    }

    $body  = 'Order Number: <b>' . $order->getOrderNumber() . '</b><br />';
    $body .= 'Order Date: <b>' . date('Y-m-d', $order->getCreatedTime()) . '</b><br />';
    $body .= 'Order Creator: <b>' . $full_name . '</b><br />';
    $body .= '<br />';
    $body .= 'Associated Clinic Name: <br />';
    $body .= '<b>' . ($clinic ? $clinic->getTitle() : '') . '</b><br />';
    $body .= '<br />';
    $body .= '<br />';
    $body .= '<br />';
    $body .= 'Shipping Address:'.'<br />';
    $body .= $address1 . '<br />';
    $body .= $address2 . '<br />';
    $body .= $city . ', ' . $state . ' ' . $zip . ' ' . $country . '<br />';
    $body .= '<br />';
    $body .= '<br />';
    $body .= '<h3>Order Products</h3><br />';
    $body .= '<table cellspacing="0" cellpadding="5" style="border:1px solid #ccc;width:100%;">';
    $body .= '<thead>';
    $body .= '  <tr>';
    $body .= '    <th style="background-color:#2b96cc;color: #fff;padding: 5px 5px;border:1px solid #ccc;">Product Title</th>';
    $body .= '    <th style="background-color:#2b96cc;color: #fff;padding: 5px 5px;border:1px solid #ccc;">SKU</th>';
    $body .= '    <th style="background-color:#2b96cc;color: #fff;padding: 5px 5px;border:1px solid #ccc;">Quantity</th>';
    $body .= '    <th style="background-color:#2b96cc;color: #fff;padding: 5px 5px;border:1px solid #ccc;">Price</th>';
    $body .= '  </tr>';
    $body .= '</thead>';
    $body .= '<tbody>';

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $item */
    foreach ($order->getItems() as $item) {
      /** @var \Drupal\commerce_product\Entity\ProductInterface $pd */
      $pd = $item->getPurchasedEntity()->getProduct();

      $body .= '<tr>';
      $body .= '  <td style="color: #000;padding: 5px 5px;border:1px solid #ccc;">' . $pd->getTitle() . '</td>';
      $body .= '  <td style="color: #000;padding: 5px 5px;border:1px solid #ccc;">' . $item->getTitle() . '</td>';
      $body .= '  <td style="color: #000;padding: 5px 5px;border:1px solid #ccc;">' . number_format($item->getQuantity()) . '</td>';
      $body .= '  <td style="color: #000;padding: 5px 5px;border:1px solid #ccc;">$' . number_format($item->getUnitPrice()->getNumber(), 2) . '</td>';
      $body .= '</tr>';
    }

    $body .= '  <tr>';
    $body .= '    <td colspan="3" style="color: #000;padding: 5px 5px;border:1px solid #ccc;font-weight:bold;">Total:</td>';
    $body .= '    <td style="color: #000;padding: 5px 5px;border:1px solid #ccc;"><b>' . $order_total . '</b></td>';
    $body .= '  </tr>';
    $body .= '</tbody>';
    $body .= '</table>';
    $body .= '<br />';
    $body .= '<br />';
    $body .= 'Payment Method: <b>' . $payment_method_name . '</b><br />';
    $body .= '<br />';
    $body .= '<br />';

    $mail = $this->mailFactory->newTypedEmail($module, $key, [
      'id' => $module,
      'send' => TRUE,
      'module' => $module,
      'key' => $key,
      'subject' => $subject,
      'body' => [Markup::create($body)],
    ]);
    $from = new Address($from_mail, '"' . $from_name . '"', $lang_code);
    $mail->setTo($to);
    $mail->setFrom($from);
    $mail->setSubject($subject, FALSE);
    $mail->setBody(['#markup' => Markup::create($body)]);
    $mail->setTextBody($this->helper()->htmlToText($body));
    $status = $mail->send();

    if ($status) {
      $this->logger->info('Order receipt email sent successfully.');
      return 1;
    }
    else {
      $this->logger->error('Failed to send Order receipt mail.');

      return 0;
    }
  }


  /**
   * Replace tokens
   */
  public function replaceTokens($key, $text, $context = []) {
    $base_url = \Drupal::request()->getSchemeAndHttpHost();

    if ($key == 'send_order_receipt') {
      /** @var \Drupal\node\NodeInterface $clinic */
      $clinic = isset($context['clinic']) ? $context['clinic'] : null;
      /** @var \Drupal\user\UserInterface $account */
      $account = isset($context['account']) ? $context['account'] : null;
      $full_name = isset($context['full_name']) ? $context['full_name'] : null;

      $text = str_replace('%site-url%',$base_url, $text);
      $text = str_replace('%sender-full-name%',$full_name, $text);
      $text = str_replace('%sender-name%',$account->getAccountName(), $text);
      $text = str_replace('%clinic-title%',$clinic->getTitle(), $text);
    }

    return $text;
  }

}
