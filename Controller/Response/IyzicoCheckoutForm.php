<?php

namespace Iyzico\Iyzipay\Controller\Response;


use Iyzico\Iyzipay\Controller\IyzicoBase\IyzicoFormObjectGenerator;
use Iyzico\Iyzipay\Controller\IyzicoBase\IyzicoResponseObjectGenerator;
use Iyzico\Iyzipay\Controller\IyzicoBase\IyzicoPkiStringBuilder;
use Iyzico\Iyzipay\Controller\IyzicoBase\IyzicoRequest;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;





class IyzicoCheckoutForm extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{

    protected $_context;
    protected $_pageFactory;
    protected $_jsonEncoder;
    protected $_checkoutSession;
    protected $_customerSession;
    protected $_quote;
    protected $_cartManagement;
    protected $_resultRedirect;
    protected $_resultJsonFactory;
    protected $_quoteRepository;
    protected $_scopeConfig;
    protected $_iyziOrderFactory;
    protected $_iyziCardFactory;
    protected $_request;
    protected $_messageManager;
    protected $_guestCartManagement;
    protected $_storeManager;
    protected $_helper;




    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Json\EncoderInterface $encoder,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Iyzico\Iyzipay\Model\IyziOrderFactory $iyziOrderFactory,
        \Iyzico\Iyzipay\Model\IyziCardFactory $iyziCardFactory,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Checkout\Api\GuestPaymentInformationManagementInterface $guestCartManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Iyzico\Iyzipay\Helper\IyzicoHelper $helper


    ) {
        parent::__construct($context);
        $this->_resultJsonFactory = $resultJsonFactory;
        $this->_pageFactory = $pageFactory;
        $this->_jsonEncoder = $encoder;
        $this->_checkoutSession = $checkoutSession;
        $this->_customerSession = $customerSession;
        $this->_quote = $quote;
        $this->_cartManagement = $cartManagement;
        $this->_resultRedirect = $context->getResultFactory();
        $this->_quoteRepository = $quoteRepository;
        $this->_scopeConfig = $scopeConfig;
        $this->_iyziOrderFactory = $iyziOrderFactory;
        $this->_iyziCardFactory = $iyziCardFactory;
        $this->_request = $request;
        $this->_messageManager = $messageManager;
        $this->_guestCartManagement = $guestCartManagement;
        $this->_storeManager = $storeManager;
        $this->_helper = $helper;

    }



    public function execute()
    {
      return $this->iyzicoResponse();
    }




    /**
     * @param  $webhook
     * @param  $webhookPaymentConversationId
     * @param  $webhookToken
     * @return mixed
     */
    public function iyzicoResponse($webhook = null, $webhookPaymentConversationId= null, $webhookToken = null){




        $postData = $this->getRequest()->getPostValue();
        $resultRedirect = $this->_resultRedirect->create(ResultFactory::TYPE_REDIRECT);
        $this->_quote = $this->_checkoutSession->getQuote();
        //echo $postData['token'];
        //exit;





        if(!isset($postData['token']) && $webhook != 'webhook') {

            $errorMessage = __('Token not found');

                        /* Redirect Error */
            $this->_messageManager->addError($errorMessage);
            $resultRedirect->setPath('checkout/cart', ['_secure' => true]);
            return $resultRedirect;

        }

        if ($webhook == 'webhook'){
            $token = $webhookToken;
            $conversationId = $webhookPaymentConversationId;
        }
        else
        {
            $token = $postData['token']; /* Add Filterr */
            $conversationId  = "";


        }


        $customerId = 0;
        $apiKey = $this->_scopeConfig->getValue('payment/iyzipay/api_key');
        $secretKey = $this->_scopeConfig->getValue('payment/iyzipay/secret_key');
        $sandboxStatus = $this->_scopeConfig->getValue('payment/iyzipay/sandbox');
        $rand = uniqid();
        $baseUrl = 'https://api.iyzipay.com';
        $currency = $this->_storeManager->getStore()->getCurrentCurrency()->getCode();
        $this->_quote->setIyziCurrency($currency);


        if($sandboxStatus)
            $baseUrl = 'https://sandbox-api.iyzipay.com';

        $iyzicoResponseObject = new IyzicoResponseObjectGenerator();
        $iyzicoPkiStringBuilder = new IyzicoPkiStringBuilder();
        $iyzicoRequest = new IyzicoRequest();

        if($this->_customerSession->isLoggedIn()){
            $customerId = $this->_customerSession->getCustomerId();
        }

        $tokenDetailObject        = $iyzicoResponseObject->generateTokenDetailObject($conversationId,$token);
        $iyzicoPkiString          = $iyzicoPkiStringBuilder->pkiStringGenerate($tokenDetailObject);
        $authorization            = $iyzicoPkiStringBuilder->authorizationGenerate($iyzicoPkiString,$apiKey,$secretKey,$rand);
        $iyzicoJson               = json_encode($tokenDetailObject,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $requestResponse          = $iyzicoRequest->iyzicoCheckoutFormDetailRequest($baseUrl,$iyzicoJson,$authorization);



        if ($webhook == 'webhook' && $requestResponse->status == 'failure' && $requestResponse->paymentStatus != 'SUCCESS'){
                return $this->webhookHttpResponse($requestResponse->errorCode.'-'.$requestResponse->errorMessage, 404);
            }


        if($webhook == 'webhook' && $requestResponse->status == 'success' &&  $requestResponse->paymentStatus == 'SUCCESS')
        {


          $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

          $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
          $connection = $resource->getConnection();


          $tableName = $resource->getTableName('iyzico_order'); //gives table name with prefix
          //Select Data from table

          $sql = "Select * FROM " . $tableName." Where payment_id = ".$requestResponse->paymentId;

          $result = $connection->fetchAll($sql);

          if(!empty($result))
          {
            return $this->webhookHttpResponse("Order Exist - Sipari?? zaten var.", 200);
          }
        }


        $requestResponse->paymentId = isset($requestResponse->paymentId) ? (int) $requestResponse->paymentId : '';
        $requestResponse->paidPrice = isset($requestResponse->paidPrice) ? (float) $requestResponse->paidPrice : '';
        $requestResponse->basketId =  isset($requestResponse->basketId) ? (int) $requestResponse->basketId : '';

        /* Insert Order Log */
        $iyziOrderModel = $this->_iyziOrderFactory->create();
        $iyziOrderModel->setData('payment_id',$requestResponse->paymentId);
        $iyziOrderModel->setData('total_amount',$requestResponse->paidPrice);
        $iyziOrderModel->setData('order_id',$requestResponse->basketId);
        $iyziOrderModel->setData('status',$requestResponse->status);
        $iyziOrderModel->save($iyziOrderModel);




        /*Korumal?? havale eft */
        if($requestResponse->paymentStatus == 'INIT_BANK_TRANSFER' && $requestResponse->status == 'success'){
          $this->_quote->setCheckoutMethod($this->_cartManagement::METHOD_GUEST);
          $this->_cartManagement->placeOrder($this->_quote->getId());
          $resultRedirect->setPath('checkout/onepage/success', ['_secure' => true]);
          return $resultRedirect;
          }


        /* Error Redirect Start */
        if($requestResponse->paymentStatus != 'SUCCESS' || $requestResponse->status != 'success') {

            $errorMessage = isset($requestResponse->errorMessage) ? $requestResponse->errorMessage : 'Failed';

            if($requestResponse->status == 'success' && $requestResponse->paymentStatus == 'FAILURE') {
                $errorMessage = __('3D Security Error');

            }



            /* Redirect Error */
            $this->_messageManager->addError($errorMessage);
            $resultRedirect->setPath('checkout/cart', ['_secure' => true]);
            return $resultRedirect;

        }



        /* Order ID Confirmation */
        if($this->_quote->getId() != $requestResponse->basketId &&  $webhook != 'webhook') {

            $errorMessage = __('Order Not Match');

            /* Redirect Error */
            $this->_messageManager->addError($errorMessage);
            $resultRedirect->setPath('checkout/cart', ['_secure' => true]);
            return $resultRedirect;
        }



        /* Order Price Confirmation */
        $totalPrice = $this->_helper->priceParser(round($this->_quote->getGrandTotal(),2));
        if($totalPrice > $requestResponse->paidPrice) {
            /* Cancel Payment */
            $errorMessage = __('Order Price Not Match');

            /* Redirect Error */
            $this->_messageManager->addError($errorMessage);
            $resultRedirect->setPath('checkout/cart', ['_secure' => true]);
            return $resultRedirect;
        }


        /* Error Redirect End */

        $this->_quote->setIyziPaymentStatus('success');
        //echo $postData['token'];
        //exit;


        /* Card Save */
        if($customerId ) {
            if(isset($requestResponse->cardUserKey)) {
                $iyziCardFind = $this->_iyziCardFactory->create()->getCollection()
                                                ->addFieldToFilter('customer_id',$customerId)
                                                ->addFieldToFilter('api_key',$apiKey)
                                                ->addFieldToSelect('card_user_key');

                $iyziCardFind = $iyziCardFind->getData();

                $customerCardUserKey = !empty($iyziCardFind[0]['card_user_key']) ? $iyziCardFind[0]['card_user_key'] : null;

                if($requestResponse->cardUserKey != $customerCardUserKey) {

                    /* Customer Card Save */
                    $iyziCardModel = $this->_iyziCardFactory->create();
                    $iyziCardModel->setData('customer_id',$customerId);
                    $iyziCardModel->setData('card_user_key',$requestResponse->cardUserKey);
                    $iyziCardModel->setData('api_key',$apiKey);
                    $iyziCardModel->save($iyziCardModel);
                }
            }
        }


        $this->_quote->getPayment()->setMethod('iyzipay');
        $installmentFee = 0;


        if (isset($requestResponse->installment) && !empty($requestResponse->installment) && $requestResponse->installment > 1) {

            $installmentFee = $requestResponse->paidPrice - $this->_quote->getGrandTotal();
            $this->_quote->setInstallmentFee($installmentFee);
            $this->_quote->setInstallmentCount($requestResponse->installment);

        }



        /* Set Payment Id */
        $this->_quote->setIyzicoPaymentId($requestResponse->paymentId);
        if($webhook == 'webhook' && $requestResponse->status == 'success' &&  $requestResponse->paymentStatus == 'SUCCESS')
            {

              try {
                $this->_quote->setCheckoutMethod($this->_cartManagement::METHOD_GUEST);
                $this->_quote->setCustomerEmail($this->_customerSession->getEmail());
                $this->_cartManagement->placeOrder($requestResponse->basketId);
                return $this->webhookHttpResponse("Order Created by Webhook - Sipari?? webhook taraf??ndan olu??turuldu.", 200);
              }
              catch (\Exception $e) {
           return  $this->webhookHttpResponse("Order Created by Webhook - Sipari?? webhook taraf??ndan olu??turuldu.", 200);
       }

            }


        if($this->_customerSession->isLoggedIn()) {
            /* Place Order - Login Checkout */
            $this->_cartManagement->placeOrder($this->_quote->getId());

        } else {

            $this->_quote->setCheckoutMethod($this->_cartManagement::METHOD_GUEST);
            $this->_quote->setCustomerEmail($this->_customerSession->getEmail());
            $this->_cartManagement->placeOrder($this->_quote->getId());

        }


        $resultRedirect->setPath('checkout/onepage/success', ['_secure' => true]);
        return $resultRedirect;



    }

    /**
     * @param  $message
     * @param  $status
     * @return mixed
     */
    public function webhookHttpResponse($message , $status){
      $httpMessage = array('message' => $message , 'status' => $status);
      header('Content-Type: application/json, Status: '. $status, true, $status);
      echo json_encode($httpMessage);
      exit();

    }
}
