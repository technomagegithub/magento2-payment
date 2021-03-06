<?php

namespace PurchasedAt\Magento2Payment\Block\Payment;

use Magento\Customer\Model\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use PurchasedAt\API;
use PurchasedAt\APIClient;

class Finish extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    protected $_cart ;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $_orderConfig;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface,
     */
    protected $_quoteRepository;

    /**
     * @var \Magento\Quote\Model\QuoteFactory,
     */
    protected $_quoteFactory;

    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    protected $_quoteManagement;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $_quote;

    /**
     * @var \Magento\Framework\App\Http\Context
     */
    protected $httpContext;

    /** @var \PurchasedAt\Magento2Payment\Model\PurchasedatModel */
    protected $_patModel;

    /**
     * Helper
     *
     * @var \PurchasedAt\Magento2Payment\Helper\Data
     */
    protected $_helper;

    /**
     * Finish constructor.
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Checkout\Model\Cart $cart
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param Order\Config $orderConfig
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Magento\Quote\Model\QuoteFactory $quoteFactory
     * @param \Magento\Quote\Model\QuoteManagement $quoteManagement
     * @param \Magento\Framework\App\Http\Context $httpContext
     * @param \PurchasedAt\Magento2Payment\Model\PurchasedatModel $patModel
     * @param \PurchasedAt\Magento2Payment\Helper\Data $helper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Model\Order\Config $orderConfig,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Framework\App\Http\Context $httpContext,
        \PurchasedAt\Magento2Payment\Model\PurchasedatModel $patModel,
        \PurchasedAt\Magento2Payment\Helper\Data $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_checkoutSession = $checkoutSession;
        $this->_cart = $cart ;
        $this->_orderFactory = $orderFactory;
        $this->_orderConfig = $orderConfig;
        $this->orderSender = $orderSender;
        $this->_quoteRepository = $quoteRepository;
        $this->_quoteFactory = $quoteFactory;
        $this->_quoteManagement = $quoteManagement;
        $this->_isScopePrivate = true;
        $this->httpContext = $httpContext;
        $this->_patModel = $patModel;
        $this->_helper = $helper;
    }

    /**
     * Initialize data and prepare it for output
     *
     * @return string
     */
    protected function _beforeToHtml()
    {
        $this->prepareBlockData();
        return parent::_beforeToHtml();
    }

    /**
     * Prepares block data and process the response.
     * If the purchase process was successful, create the order, otherwise show the error message
     *
     * @return void
     */
    protected function prepareBlockData()
    {
        $order_id = -1 ;
        $api_key = $this->_helper->getConfig('payment/purchasedat/api_key');
        $apiClient = new APIClient($api_key);

		// verify the redirect comes from purchased.at
		// and fetch the corresponding transaction
        $result = $apiClient->fetchTransactionForRedirect();

        $error_message = "" ;
        $result_message = "" ;
        if(!$result->success) {
            $error_message = "Request failed: " . $result->errorCode;
        }
        else
        {
            $transaction = $result->result;

			// handle transactions that cannot become
			// successful anymore
            if( $transaction->getState()!='successful' &&
                $transaction->getState()!='pending' ) {
                $error_message = sprintf('Transaction not successful: id=%s, ' .
                    'state=%s<br>',
                    $transaction->getId(),
                    $transaction->getState()
                );
                if ($transaction->isTest()) {
                    $error_message .= "<br><strong>This was a TEST transaction</strong><br />" ;
                }
            }
            else
            {
                //Everything went okay
                $customer = $transaction->getCustomer();
                $price    = $transaction->getPrice();

				// Save quote to order

                $quote = $this->_cart->getQuote();
                $quote->setPaymentMethod('purchasedat'); //payment method
                $om = \Magento\Framework\App\ObjectManager::getInstance();
                $customerSession = $om->get('Magento\Customer\Model\Session');
                if(!$customerSession->isLoggedIn()) {
                    $quote->setCustomerId(null)
                        ->setCustomerEmail($customer->getEmail())
                        ->setCustomerIsGuest(true)
                        ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
                }
                $quote->save(); //Now Save quote and your quote is ready

                // Set Sales Order Payment
                $quote->getPayment()->importData(['method' => 'purchasedat']);

                // Collect Totals & Save Quote
                $quote->collectTotals()->save();
                $order = $this->_quoteManagement->submit($quote);
                $order->setEmailSent(0);
                if($order->getEntityId()){
                    $order_id = $order->getRealOrderId();
                } else {
                    $order_id =-1;
                }
                $this->orderSender->send($order);
                $order->setBaseTotalPaid($price->getGross());
                $order->setTotalPaid($this->convertPrice($price->getGross()));

				// pending transactions are awaiting payment
				// and can become successful later
                if( $transaction->getState() == 'pending' ) {
                    $result_message = 'We received your order, but are still ' .
                        'waiting for confirmation of the payment.<br>';
                }

                $result_message .= sprintf('Transaction details:<br />Id:%s<br />Customer:%s (country:%s)<br /> ' .
                    'Total price:%s %s',
                    $transaction->getId(),
                    $customer->getEmail(),
                    $customer->getCountry(),
                    $price->getGross(),
                    $price->getCurrency()) ;

                if ($transaction->isTest()) {
                    $result_message .= "<br><strong>This was a TEST transaction</strong><br />" ;
                }
                $this->_patModel->createMagentoTransaction($order, $result->result) ;
            }
        }
        $this->addData(
            [
                'error_message' => $error_message,
                'result_message' => $result_message,
                'order_id'  => $order_id

            ]
        );
    }
    
    /**
     * Convert a base price to the current currency, or to $currency, and return it
     * @param float $amount
     * @param object $store = null
     * @param object $currency = null
     */
    public function convertPrice($amount, $store = null, $currency = null)
    {
    	$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    	$priceCurrencyObject = $objectManager->get('Magento\Framework\Pricing\PriceCurrencyInterface');
    	$storeManager = $objectManager->get('Magento\Store\Model\StoreManagerInterface');
    	if ($store == null) {
    		$store = $storeManager->getStore()->getStoreId();
    	}
    	$rate = $priceCurrencyObject->convert($amount, $store, $currency);
    	return $rate ;
    }
}
